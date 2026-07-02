<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.10 — merchant-disableable pickup points.
 *
 * Adds the `enabled` flag to the terminal cache so a merchant can hide
 * individual pickup points from checkout (Terminals admin screen). Defaults to
 * 1 so every existing terminal stays visible after the upgrade.
 */
function upgrade_module_1_0_10(Module $module): bool
{
    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'ppvenipak_terminal`
         ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `cod_enabled`'
    );
}
