<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.3 — move the admin order panel from displayAdminOrderSide
 * (narrow right column, ~33% width) to displayAdminOrderMainBottom (full
 * main column, ~67% width). The action button row + tracking list never fit
 * cleanly in the side panel, so the panel was getting clipped.
 *
 * Idempotent — registerHook is a no-op when already registered, and
 * unregisterHook silently succeeds when the hook isn't bound.
 */
function upgrade_module_1_0_3(Module $module): bool
{
    $shopIds = Shop::getCompleteListOfShopsID();
    if (!is_array($shopIds) || empty($shopIds)) {
        $shopIds = null;
    }

    // Best-effort: never fail the upgrade because of a hook-bookkeeping
    // hiccup. If registerHook returns false (already-registered, ambiguous
    // shop context, etc.) we still want the version bump to stick — the next
    // request will simply not render the panel and the merchant can re-bind
    // the hook from Module Positions.
    try {
        $module->registerHook('displayAdminOrderMainBottom', $shopIds);
    } catch (\Throwable $e) {
        PrestaShopLogger::addLog(
            'PPVenipak upgrade-1.0.3: registerHook failed: ' . $e->getMessage(),
            2,
            null,
            'PPVenipak'
        );
    }

    try {
        $module->unregisterHook('displayAdminOrderSide');
    } catch (\Throwable $e) {
        // Old hook may already be gone — ignore.
    }

    return true;
}
