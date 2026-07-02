<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Module;

/**
 * Custom override manager for the localized-carrier-name override.
 *
 * Why a custom manager instead of PrestaShop's built-in override auto-install?
 *
 * PrestaShop's `Override::add()` mechanism scans every module's
 * `override/classes/` directory at install time and merges files. When two
 * modules ship overrides that declare the same method, the second install
 * fails with "method already overridden in class". For carrier modules built
 * on the same `prestapro/pp-common` base, every module ships the *same*
 * generic `Carrier::getCarriers()` override — the auto-installer can't tell
 * "two modules want this exact method" from "two modules conflict".
 *
 * This manager bypasses that path entirely:
 *
 *   1. The template lives at modules/<module>/data/overrides/Carrier.php
 *      (not the PS-scanned `override/` directory) so PS leaves it alone.
 *   2. install() decides per case:
 *        - target file missing  → write the template verbatim
 *        - target has our marker → another sibling module already wrote it,
 *                                 skip silently (we share ownership)
 *        - target exists, no marker, no getCarriers() method → inject our
 *                                 method into the existing class body
 *        - target exists with a foreign getCarriers() definition → leave it
 *                                 alone (respect the other override)
 *   3. uninstall() leaves the file in place — other carrier modules likely
 *      still depend on it. (Future enhancement: ownership counter.)
 *
 * After any successful install, the project's compiled override cache must be
 * cleared. PS9 keeps that cache under `var/cache` and will regenerate on the
 * next admin request, so the simplest reliable trigger is touching one of the
 * cache directories or relying on PS's own cache-bust on module install.
 */
class CarrierOverrideManager
{
    /**
     * Marker prefix embedded in the template; presence in an existing override
     * file means "we (or a sibling carrier module) already wrote this".
     * Matched as a substring so any version (v1, v2, v3, …) counts as ours.
     */
    private const MARKER = '@ppcommon-localized-carrier-names';

    /**
     * Pattern that captures our marker version. Used to upgrade older versions
     * of the override in place when the shipped template is newer.
     */
    private const MARKER_VERSION_PATTERN = '/@ppcommon-localized-carrier-names\s+v(\d+)/';

    private const TEMPLATE_RELATIVE_PATH = 'data/overrides/Carrier.php';
    private const TARGET_RELATIVE_PATH = 'classes/Carrier.php';

    private string $moduleDir;
    private string $overrideDir;

    public function __construct(string $moduleDir, ?string $overrideDir = null)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
        $this->overrideDir = rtrim(
            $overrideDir ?? (defined('_PS_OVERRIDE_DIR_') ? _PS_OVERRIDE_DIR_ : ''),
            '/'
        );
    }

    /**
     * Install the override. Idempotent.
     *
     * @return array{success: bool, action: string, message: string}
     */
    public function install(): array
    {
        if ($this->overrideDir === '') {
            return $this->fail('PrestaShop override directory not resolved.');
        }

        $templatePath = $this->moduleDir . '/' . self::TEMPLATE_RELATIVE_PATH;
        $targetPath = $this->overrideDir . '/' . self::TARGET_RELATIVE_PATH;

        if (!is_file($templatePath)) {
            return $this->fail('Override template missing at ' . $templatePath);
        }

        $template = file_get_contents($templatePath);
        if ($template === false) {
            return $this->fail('Could not read override template.');
        }

        if (!$this->ensureDir(dirname($targetPath))) {
            return $this->fail('Could not create override directory at ' . dirname($targetPath));
        }

        // Case 1 — file does not exist yet, write template verbatim.
        if (!file_exists($targetPath)) {
            return $this->write($targetPath, $template, 'created');
        }

        $existing = file_get_contents($targetPath);
        if ($existing === false) {
            return $this->fail('Could not read existing override at ' . $targetPath);
        }

        // Case 2 — our marker is already present (sibling module installed it
        // or an older version of this module wrote it). If the shipped template
        // version is newer, overwrite to pick up bug fixes; otherwise skip.
        if (str_contains($existing, self::MARKER)) {
            $existingVersion = $this->extractMarkerVersion($existing);
            $templateVersion = $this->extractMarkerVersion($template);

            if ($templateVersion !== null && $existingVersion !== null
                && $templateVersion > $existingVersion) {
                return $this->write(
                    $targetPath,
                    $template,
                    'upgraded v' . $existingVersion . '→v' . $templateVersion
                );
            }

            return $this->ok('skipped', 'Override already installed (v' . ($existingVersion ?? '?') . ').');
        }

        // Case 3 — file exists with a foreign getCarriers() definition; leave it alone.
        if ($this->hasGetCarriersMethod($existing)) {
            return $this->ok(
                'skipped-foreign',
                'A different override already defines Carrier::getCarriers(); not overwriting.'
            );
        }

        // Case 4 — file exists, no getCarriers, no marker → inject our method.
        $methodBlock = $this->extractMethodBlock($template);
        if ($methodBlock === null) {
            return $this->fail('Could not extract method block from template.');
        }

        $merged = $this->injectMethodIntoClass($existing, $methodBlock);
        if ($merged === null) {
            return $this->fail('Could not locate the Carrier class body in the existing override.');
        }

        return $this->write($targetPath, $merged, 'injected');
    }

    /**
     * Uninstall — currently a no-op. Other carrier modules typically still
     * need the override; removing it would silently break their localized
     * names. If precise lifecycle becomes important, replace this with an
     * ownership-counter that removes the method/file when the last carrier
     * module is uninstalled.
     *
     * @return array{success: bool, action: string, message: string}
     */
    public function uninstall(): array
    {
        return $this->ok('retained', 'Override left in place for sibling carrier modules.');
    }

    private function hasGetCarriersMethod(string $content): bool
    {
        return preg_match('/\bpublic\s+static\s+function\s+getCarriers\s*\(/', $content) === 1;
    }

    private function extractMarkerVersion(string $content): ?int
    {
        if (preg_match(self::MARKER_VERSION_PATTERN, $content, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extract the method block (signature + body) from the template's class.
     * Relies on the template's marker comment being directly above the method.
     */
    private function extractMethodBlock(string $template): ?string
    {
        $markerPos = strpos($template, self::MARKER);
        if ($markerPos === false) {
            return null;
        }

        // Find the start of the comment line containing the marker.
        $lineStart = strrpos(substr($template, 0, $markerPos), "\n");
        $blockStart = $lineStart === false ? 0 : $lineStart + 1;

        // Find the closing brace of the method by counting braces from the
        // first opening brace after the signature.
        $bodyStart = strpos($template, '{', $markerPos);
        if ($bodyStart === false) {
            return null;
        }

        $depth = 0;
        $len = strlen($template);
        $bodyEnd = null;
        for ($i = $bodyStart; $i < $len; $i++) {
            $ch = $template[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $bodyEnd = $i;
                    break;
                }
            }
        }

        if ($bodyEnd === null) {
            return null;
        }

        return substr($template, $blockStart, $bodyEnd - $blockStart + 1);
    }

    /**
     * Inject $methodBlock just before the final closing brace of the class.
     * Returns the merged content or null if the class boundary can't be found.
     */
    private function injectMethodIntoClass(string $existing, string $methodBlock): ?string
    {
        // Match the last "}" at the end of the file (allowing trailing whitespace).
        if (preg_match('/}\s*$/', $existing) !== 1) {
            return null;
        }

        $insertion = "\n    " . str_replace("\n", "\n    ", trim($methodBlock)) . "\n";

        return preg_replace('/}\s*$/', $insertion . "}\n", $existing, 1);
    }

    private function ensureDir(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return @mkdir($path, 0755, true) || is_dir($path);
    }

    private function write(string $path, string $content, string $action): array
    {
        $written = @file_put_contents($path, $content);
        if ($written === false) {
            return $this->fail('Could not write override at ' . $path);
        }

        // Clear any stale opcache entry so the new method is picked up immediately.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return $this->ok($action, 'Override ' . $action . ' at ' . $path);
    }

    private function ok(string $action, string $message): array
    {
        return ['success' => true, 'action' => $action, 'message' => $message];
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'action' => 'error', 'message' => $message];
    }
}
