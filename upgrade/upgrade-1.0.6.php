<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.6 — add attempt_count + previous_attempts columns to
 * ppvenipak_order so a single PrestaShop order can be re-shipped with a
 * fresh Venipak label (e.g. wrong item dispatched, returned, re-sent).
 *
 * - attempt_count starts at 1 for every existing row (the original label).
 * - previous_attempts is a JSON list capturing prior tracking_numbers /
 *   manifest_id / timestamps each time the merchant regenerates a label.
 *
 * Idempotent: bails if either column is already there (rerunning the
 * upgrade after a partial failure must not throw).
 */
function upgrade_module_1_0_6(Module $module): bool
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'ppvenipak_order';

    $columns = $db->executeS("SHOW COLUMNS FROM `" . $table . "`") ?: [];
    $existing = array_column($columns, 'Field');

    if (!in_array('attempt_count', $existing, true)) {
        $db->execute(
            'ALTER TABLE `' . $table . '`
             ADD COLUMN `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `error`'
        );
    }

    if (!in_array('previous_attempts', $existing, true)) {
        $db->execute(
            'ALTER TABLE `' . $table . '`
             ADD COLUMN `previous_attempts` TEXT DEFAULT NULL AFTER `attempt_count`'
        );
    }

    return true;
}
