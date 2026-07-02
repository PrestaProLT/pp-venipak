<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.5 — register actionPresentPaymentOptions, the server-side
 * filter that removes COD payment options when the cart's selected Venipak
 * pickup terminal can't accept cash on delivery.
 *
 * Replaces the previous client-side cod-gate.js approach so the restriction
 * works regardless of theme / DOM structure / JS being available.
 */
function upgrade_module_1_0_5(Module $module): bool
{
    $shopIds = Shop::getCompleteListOfShopsID();
    if (!is_array($shopIds) || empty($shopIds)) {
        $shopIds = null;
    }

    try {
        $module->registerHook('actionPresentPaymentOptions', $shopIds);
    } catch (\Throwable $e) {
        PrestaShopLogger::addLog(
            'PPVenipak upgrade-1.0.5: registerHook failed: ' . $e->getMessage(),
            2,
            null,
            'PPVenipak'
        );
    }

    return true;
}
