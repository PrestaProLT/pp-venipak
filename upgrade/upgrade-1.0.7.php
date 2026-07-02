<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.7 — key the pack-number serial counter per API ID.
 *
 * Pack numbers are V{API_ID}E{serial} and Venipak enforces uniqueness on the
 * whole string (i.e. per sender ID). The serial used to live in a single
 * global counter (PPVENIPAK_PACK_COUNTER); now PackNumberGenerator keeps one
 * sequence per API ID — shops sharing a sender ID share a counter, shops with
 * distinct sender IDs run independent sequences.
 *
 * Seed each per-API-ID counter from the current global value so no sequence
 * restarts below the high-water mark Venipak has already reserved. The global
 * key is left in place as a shared floor / admin escape hatch (bumping it lifts
 * every sequence past a reserved range), so this upgrade is purely about making
 * the per-API-ID rows explicit.
 *
 * Idempotent: re-running only ever raises a per-API-ID counter to the floor,
 * never lowers an established sequence.
 */
function upgrade_module_1_0_7(Module $module): bool
{
    $db = Db::getInstance();

    $floor = (int) Configuration::getGlobalValue('PPVENIPAK_PACK_COUNTER');

    $apiIds = $db->executeS(
        'SELECT DISTINCT value FROM `' . _DB_PREFIX_ . "configuration` WHERE name = 'PPVENIPAK_API_ID'"
    ) ?: [];

    foreach ($apiIds as $row) {
        $apiId = trim((string) ($row['value'] ?? ''));
        if ($apiId === '') {
            continue;
        }

        $key = 'PPVENIPAK_PACK_COUNTER_' . $apiId;
        $current = (int) Configuration::getGlobalValue($key);
        if ($floor > $current) {
            Configuration::updateGlobalValue($key, $floor);
        }
    }

    return true;
}
