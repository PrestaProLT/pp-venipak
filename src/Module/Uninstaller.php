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
            && $this->deleteConfigurations();
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

    private function deleteConfigurations(): bool
    {
        $db = Db::getInstance();

        $rows = $db->executeS(
            'SELECT `name` FROM `' . bqSQL(_DB_PREFIX_) . 'configuration` WHERE `name` LIKE \'PPVENIPAK\\_%\''
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                Configuration::deleteByName(pSQL($row['name']));
            }
        }

        return true;
    }
}
