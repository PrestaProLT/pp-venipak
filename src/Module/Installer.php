<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Module;

use Configuration;
use Country;
use Db;
use Shop;

class Installer
{
    /**
     * Countries Venipak handles as plain domestic. Other ISOs are still seeded
     * onto the warehouse but the admin will need to decide which Venipak service
     * to route through (international/global).
     */
    private const SUPPORTED_COUNTRIES = ['LT', 'LV', 'EE', 'PL'];

    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        $ok = $this->registerHooks()
            && $this->createTables()
            && $this->createOrderStates()
            && $this->createCarriers()
            && $this->setDefaults()
            && $this->createDefaultWarehouseFromShop()
            && $this->installCarrierOverride();

        if ($ok) {
            // The module ships Symfony admin routes/controllers. Clear the
            // Symfony cache so those routes are compiled into the router right
            // away — otherwise the first "Configure" click (which redirects to
            // ps_ppvenipak_dashboard) throws RouteNotFoundException until the
            // cache is rebuilt.
            \Tools::clearSf2Cache();
        }

        return $ok;
    }

    /**
     * Manage `override/classes/Carrier.php` ourselves so the install does not
     * fail when sibling carrier modules ship the same generic override (PS's
     * built-in override merger rejects duplicate method declarations even
     * when the bodies are identical).
     */
    private function installCarrierOverride(): bool
    {
        $manager = new CarrierOverrideManager(dirname(__DIR__, 2));
        $result = $manager->install();

        // Failure to install is informational — surface to the admin via the
        // module's standard "logger" hook if available, but don't block the
        // module install.
        if (!$result['success'] && class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog(
                'PPVenipak: ' . $result['message'],
                2,
                null,
                'CarrierOverrideManager'
            );
        }

        return true;
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
        $this->module->addOrderState(
            'PPVENIPAK_STATE_READY',
            [
                'en' => 'Venipak shipment ready',
                'lt' => 'Venipak siunta paruošta',
            ],
            '#FCEAA8'
        );

        $this->module->addOrderState(
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
        $carrierIds = $this->module->createCarriers();
        $this->installCarrierLogos(is_array($carrierIds) ? $carrierIds : []);

        return true;
    }

    /**
     * Give each newly created carrier the module's logo. Without this PrestaShop
     * renders the carrier with a blank placeholder in the carrier list and at
     * checkout. The logo is copied to img/s/{id_carrier}.jpg — PrestaShop serves
     * that file directly, and it copies it forward automatically when a merchant
     * edits the carrier (which clones it to a new id).
     */
    private function installCarrierLogos(array $carrierIds): void
    {
        $logo = _PS_MODULE_DIR_ . $this->module->name . '/views/img/carrier_logo.png';

        if (!file_exists($logo) || !defined('_PS_SHIP_IMG_DIR_')) {
            return;
        }

        foreach ($carrierIds as $idCarrier) {
            $idCarrier = (int) $idCarrier;
            if ($idCarrier > 0) {
                @copy($logo, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
            }
        }
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
            'PPVENIPAK_LOG_RETENTION_DAYS' => '30',
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
        // Per-store auth mode defaults ON: each shop in a multistore should
        // hold its own Venipak credentials by default. Merchants who want a
        // single shared credential set can flip this off from the All Shops
        // context where the toggle is visible.
        Configuration::updateGlobalValue('PPVENIPAK_STORE_AUTH_MODE', '1');

        return true;
    }

    /**
     * Pre-fill the first warehouse using PrestaShop's shop contact details so
     * the admin doesn't have to retype it. Idempotent — only runs when no
     * warehouse exists yet for the active shop. Skips silently when the shop
     * contacts are empty (admin can add a warehouse manually later).
     */
    private function createDefaultWarehouseFromShop(): bool
    {
        $idShop = (int) (Shop::getContextShopID() ?: Configuration::get('PS_SHOP_DEFAULT'));
        if ($idShop <= 0) {
            $idShop = 1;
        }

        $existing = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
             WHERE `id_shop` = ' . $idShop
        );

        if ((int) $existing > 0) {
            return true;
        }

        $shopName = trim((string) Configuration::get('PS_SHOP_NAME'));
        if ($shopName === '') {
            // Admin can add a warehouse manually from the Warehouses tab.
            return true;
        }

        $iso = '';
        $countryId = (int) Configuration::get('PS_SHOP_COUNTRY_ID');
        if ($countryId > 0 && class_exists('Country')) {
            $iso = strtoupper((string) Country::getIsoById($countryId));
        }
        $countryCode = $iso !== '' ? $iso : 'LT';
        if (!in_array($countryCode, self::SUPPORTED_COUNTRIES, true)) {
            // Country is outside Venipak's domestic set; default to LT and let
            // the admin correct it. Still create the warehouse so the install
            // produces a usable starting point.
            $countryCode = 'LT';
        }

        $address = trim(
            (string) Configuration::get('PS_SHOP_ADDR1') . ' '
            . (string) Configuration::get('PS_SHOP_ADDR2')
        );

        $payload = [
            'name' => pSQL(mb_substr($shopName, 0, 60)),
            'company_code' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_REGISTRATION_NUMBER'), 0, 16)),
            'contact' => pSQL(mb_substr($shopName, 0, 40)),
            'country_code' => pSQL($countryCode),
            'city' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_CITY'), 0, 50)),
            'address' => pSQL(mb_substr($address, 0, 255)),
            'zip_code' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_CODE'), 0, 10)),
            'phone' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_PHONE'), 0, 15)),
            'id_shop' => $idShop,
            'is_default' => 1,
        ];

        return (bool) Db::getInstance()->insert('ppvenipak_warehouse', $payload);
    }
}
