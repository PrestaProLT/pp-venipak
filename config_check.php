<?php

/**
 * Venipak pack-counter diagnostic / remediation runner.
 *
 * CLI (recommended):
 *   php modules/ppvenipak/config_check.php
 *   php modules/ppvenipak/config_check.php --set-counter=100000
 *   php modules/ppvenipak/config_check.php --dedupe
 *
 * Browser (read-only report only; needs the access token printed below):
 *   /modules/ppvenipak/config_check.php?token=XXXXXXXX
 *
 * The browser entry is read-only on purpose — remediation (--set-counter,
 * --dedupe) is CLI-only so it can't be triggered by a stray URL.
 */

require_once dirname(__DIR__, 2) . '/config/config.inc.php';
require_once __DIR__ . '/src/Diagnostics/VenipakConfigCheck.php';

use PrestaShop\Module\PPVenipak\Diagnostics\VenipakConfigCheck;

$isCli = (PHP_SAPI === 'cli');

// Browser access is gated by a token derived from the shop cookie key so the
// config dump is not world-readable.
$accessToken = substr(hash('sha256', _COOKIE_KEY_ . '|ppvenipak-config-check'), 0, 16);

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    if ((string) ($_GET['token'] ?? '') !== $accessToken) {
        http_response_code(403);
        echo "Forbidden.\n\n";
        echo "Run from CLI:  php modules/ppvenipak/config_check.php\n";
        echo "Or in a browser add ?token=<access token> (find it via the CLI run).\n";
        exit;
    }
}

$check = new VenipakConfigCheck();

// Parse simple flags (CLI only for anything that writes).
$args = $isCli ? array_slice($argv, 1) : [];
$setCounter = null;
$dedupe = false;

foreach ($args as $arg) {
    if (strpos($arg, '--set-counter=') === 0) {
        $setCounter = (int) substr($arg, strlen('--set-counter='));
    } elseif ($arg === '--dedupe') {
        $dedupe = true;
    }
}

$output = '';

if ($dedupe) {
    $output .= $check->dedupe() . "\n";
}

if ($setCounter !== null) {
    $output .= $check->setCounter($setCounter) . "\n";
}

$output .= $check->report();

if ($isCli && !$dedupe && $setCounter === null) {
    $output .= "\nBrowser access token (for ?token=...): " . $accessToken . "\n";
}

echo $output;
