<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.2 — register the actionFrontControllerSetMedia hook so the
 * pickup-point selector loads its JS/CSS on the checkout page. The hook was
 * missing from 1.0.0/1.0.1, which left the pickup template visible but inert
 * (no terminals fetched, no map, no selection saved).
 *
 * Idempotent — Module::registerHook() is a no-op when the hook is already
 * registered for this module/shop pair.
 */
function upgrade_module_1_0_2(Module $module): bool
{
    // Register for every shop in the install, not just the current admin
    // context, so multistore setups don't miss the JS/CSS on shops 2..N.
    $shopIds = Shop::getCompleteListOfShopsID();
    if (!is_array($shopIds) || empty($shopIds)) {
        $shopIds = null;
    }

    if (!$module->registerHook('actionFrontControllerSetMedia', $shopIds)) {
        return false;
    }

    return true;
}
