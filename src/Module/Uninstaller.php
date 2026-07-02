<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Module;

use Configuration;
use Db;

class Uninstaller
{
    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        return $this->deleteCarriers()
            && $this->unregisterHooks()
            && $this->deleteConfigurations()
            && $this->releaseCarrierOverride();
    }

    /**
     * Release the localized-carrier-name override. Currently a no-op so
     * sibling carrier modules keep their localized names. See
     * CarrierOverrideManager for the rationale.
     */
    private function releaseCarrierOverride(): bool
    {
        $manager = new CarrierOverrideManager(dirname(__DIR__, 2));
        $manager->uninstall();

        return true;
    }

    private function deleteCarriers(): bool
    {
        $this->module->deleteCarriers();

        return true;
    }

    private function unregisterHooks(): bool
    {
        foreach ($this->module->hooks as $hook) {
            $this->module->unregisterHook($hook);
        }

        return true;
    }

    /**
     * Configuration keys that survive an uninstall.
     *
     * The order-state ID pointers are kept so that a subsequent reinstall can
     * reuse the existing OrderState rows (pp-common's createOrderState is
     * idempotent based on these keys). Without this exclusion, every
     * install/uninstall cycle creates a new pair of OrderStates and the
     * `ps_order_state` table accumulates orphaned duplicates — eventually
     * causing PS's status-dropdown form to throw "data-background-color does
     * not exist" because one of the duplicate choices' attr array is empty.
     */
    private const PRESERVE_KEYS = [
        'PPVENIPAK_STATE_READY',
        'PPVENIPAK_STATE_ERROR',
        'PPVENIPAK_COURIER_ID_REF',
        'PPVENIPAK_PICKUP_ID_REF',
    ];

    private function deleteConfigurations(): bool
    {
        $db = Db::getInstance();

        $rows = $db->executeS(
            'SELECT `name` FROM `' . bqSQL(_DB_PREFIX_) . 'configuration` WHERE `name` LIKE \'PPVENIPAK\\_%\''
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (in_array($row['name'], self::PRESERVE_KEYS, true)) {
                    continue;
                }
                Configuration::deleteByName(pSQL($row['name']));
            }
        }

        return true;
    }
}
