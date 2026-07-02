<?php
/**
 * Generic localized-carrier-name override.
 *
 * PrestaShop stores carrier names in `ps_carrier.name` as a single-language
 * field, but customers shopping in different locales expect localized names.
 * Carrier modules built on `prestapro/pp-common` already expose a
 * `public function getCarrierName(int $idCarrier, int $idLang): string`
 * method (defined in `AbstractPPCarrier`) that returns the per-language name
 * stored as `{MODULE_PREFIX}_{KEY}_NAME_{idLang}` configuration keys.
 *
 * Two interception points are needed because PrestaShop reads carrier names
 * via two different code paths:
 *
 *   1. `Carrier::getCarriers($idLang, ...)` — used by admin lists and a few
 *      back-office helpers. The override post-processes each row and
 *      replaces `name` with the localized value.
 *
 *   2. `new Carrier($id, $idLang)` — used by the storefront checkout (every
 *      delivery option goes through this). ObjectModel hydrates `name` from
 *      `ps_carrier` directly (single-language), so we override the
 *      constructor and re-apply the localized name once the module is known.
 *
 * Because this override is fully generic (no module name is hardcoded), every
 * pp-common-based carrier module can ship this same file. When multiple
 * carrier modules install the file with identical content, PrestaShop's
 * override installer treats them as the same override and skips the duplicate
 * after the first install.
 */
class Carrier extends CarrierCore
{
    /* @ppcommon-localized-carrier-names v3 — managed by CarrierOverrideManager */

    /**
     * Re-entrancy guard. AbstractPPCarrier::getCarrierName() falls back to
     * `new Carrier($id)` when no localized config row exists; without this
     * flag that constructs the override → calls getCarrierName() → recurses
     * forever, exhausting PHP memory at install time.
     *
     * @var array<int, true>
     */
    private static $resolvingCarrierIds = [];

    /**
     * Localize the carrier name for every newly hydrated Carrier instance.
     * The PrestaShop checkout loads carriers with `new Carrier($id, $idLang)`
     * and reads `$carrier->name` directly, completely bypassing
     * `Carrier::getCarriers()`. Without this override the customer always
     * sees the raw `ps_carrier.name` regardless of language.
     */
    public function __construct($id_carrier = null, $id_lang = null, $id_shop = null, $translator = null)
    {
        parent::__construct($id_carrier, $id_lang, $id_shop, $translator);

        if (!$this->id || empty($this->external_module_name)) {
            return;
        }

        if (isset(self::$resolvingCarrierIds[(int) $this->id])) {
            return;
        }

        // Default to the context language when no explicit lang was passed —
        // matches what PS itself does for ObjectModel lookups.
        if ($id_lang === null && Context::getContext() && Context::getContext()->language) {
            $id_lang = (int) Context::getContext()->language->id;
        }
        if (!$id_lang) {
            return;
        }

        $module = Module::getInstanceByName($this->external_module_name);
        if (!$module || !method_exists($module, 'getCarrierName')) {
            return;
        }

        self::$resolvingCarrierIds[(int) $this->id] = true;
        try {
            $localized = (string) $module->getCarrierName((int) $this->id, (int) $id_lang);
        } catch (\Throwable $e) {
            return;
        } finally {
            unset(self::$resolvingCarrierIds[(int) $this->id]);
        }

        if ($localized !== '') {
            $this->name = $localized;
        }
    }

    public static function getCarriers(
        $id_lang,
        $active = false,
        $delete = false,
        $id_zone = false,
        $ids_group = null,
        $modules_filters = self::PS_CARRIERS_ONLY
    ) {
        $rows = parent::getCarriers($id_lang, $active, $delete, $id_zone, $ids_group, $modules_filters);

        if (!is_array($rows) || empty($rows)) {
            return $rows;
        }

        $moduleCache = [];

        foreach ($rows as &$row) {
            $moduleName = trim((string) ($row['external_module_name'] ?? ''));
            if ($moduleName === '') {
                continue;
            }

            if (!array_key_exists($moduleName, $moduleCache)) {
                $module = Module::getInstanceByName($moduleName);
                $moduleCache[$moduleName] = ($module && method_exists($module, 'getCarrierName'))
                    ? $module
                    : null;
            }

            $module = $moduleCache[$moduleName];
            if ($module === null) {
                continue;
            }

            try {
                $localized = (string) $module->getCarrierName(
                    (int) $row['id_carrier'],
                    (int) $id_lang
                );
            } catch (\Throwable $e) {
                continue;
            }

            if ($localized !== '') {
                $row['name'] = $localized;
            }
        }
        unset($row);

        return $rows;
    }
}
