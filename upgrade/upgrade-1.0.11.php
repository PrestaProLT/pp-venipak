<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.11 — register admin routes after upgrade.
 *
 * Clears the Symfony cache so the module's admin routes (e.g.
 * ps_ppvenipak_dashboard) are recompiled into the router. Without this, opening
 * the module right after an install/upgrade could throw RouteNotFoundException
 * from getContent() until the cache was rebuilt manually.
 */
function upgrade_module_1_0_11(Module $module): bool
{
    Tools::clearSf2Cache();

    return true;
}
