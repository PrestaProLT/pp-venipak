<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Module;

use Configuration;
use Db;

class Installer
{
    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        return $this->registerHooks()
            && $this->createTables()
            && $this->createOrderStates()
            && $this->createCarriers()
            && $this->setDefaults();
    }

    private function registerHooks(): bool
    {
        foreach ($this->module->hooks as $hook) {
            if (!$this->module->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    private function createTables(): bool
    {
        $sqlFile = dirname(__FILE__, 3) . '/sql/install.sql';

        if (!file_exists($sqlFile)) {
            return false;
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        $statements = array_filter(
            array_map('trim', explode(";\n", $sql)),
            fn (string $s): bool => $s !== ''
        );

        $db = Db::getInstance();

        foreach ($statements as $statement) {
            if (!$db->execute($statement)) {
                return false;
            }
        }

        return true;
    }

    private function createOrderStates(): bool
    {
        $this->module->createOrderState(
            'PPVENIPAK_STATE_READY',
            [
                'en' => 'Venipak shipment ready',
                'lt' => 'Venipak siunta paruošta',
            ],
            '#FCEAA8'
        );

        $this->module->createOrderState(
            'PPVENIPAK_STATE_ERROR',
            [
                'en' => 'Venipak shipment error',
                'lt' => 'Venipak siuntos klaida',
            ],
            '#F24017'
        );

        return true;
    }

    private function createCarriers(): bool
    {
        $this->module->createCarriers();

        return true;
    }

    private function setDefaults(): bool
    {
        $defaults = [
            'PPVENIPAK_LIVE_MODE' => '0',
            'PPVENIPAK_LABEL_FORMAT' => 'a4',
            'PPVENIPAK_RETURN_DAYS' => '14',
            'PPVENIPAK_NWD_ENABLED' => '1',
            'PPVENIPAK_COD_MODULES' => 'ps_cashondelivery',
            'PPVENIPAK_CRON_TOKEN' => bin2hex(random_bytes(16)),
        ];

        foreach ($defaults as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        // Global scope configs (not shop-specific)
        Configuration::updateGlobalValue('PPVENIPAK_PACK_COUNTER', '0');
        Configuration::updateGlobalValue(
            'PPVENIPAK_MANIFEST_COUNTER',
            json_encode(['counter' => 0, 'date' => ''])
        );

        return true;
    }
}
