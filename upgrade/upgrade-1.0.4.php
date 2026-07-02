<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.4 — register actionObjectOrderUpdateAfter so the module can
 * keep its ppvenipak_order row in sync when a merchant changes the carrier
 * on an existing order in admin. Without this, the dashboard shows the
 * stale service type and label generation builds the wrong shipment.
 *
 * Best-effort: never fail the upgrade because of a hook-bookkeeping hiccup.
 */
function upgrade_module_1_0_4(Module $module): bool
{
    $shopIds = Shop::getCompleteListOfShopsID();
    if (!is_array($shopIds) || empty($shopIds)) {
        $shopIds = null;
    }

    try {
        $module->registerHook('actionObjectOrderUpdateAfter', $shopIds);
    } catch (\Throwable $e) {
        PrestaShopLogger::addLog(
            'PPVenipak upgrade-1.0.4: registerHook failed: ' . $e->getMessage(),
            2,
            null,
            'PPVenipak'
        );
    }

    return true;
}
