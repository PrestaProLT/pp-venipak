<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.1 — clean up legacy module config in favour of:
 *   - the Warehouses CRUD page (replaces "Sender Address" config block)
 *   - PrestaShop's native ps_carrier.url field (replaces PPVENIPAK_TRACKING_URL)
 *
 * Steps (idempotent — safe to run more than once):
 * 1. Per shop with PPVENIPAK_SENDER_NAME set and no warehouse, copy the legacy
 *    sender values into a default warehouse row.
 * 2. Backfill ps_carrier.url for our carriers when it's empty so customers
 *    see a working "Track shipment" link on their order page.
 * 3. Delete the legacy PPVENIPAK_SENDER_*, PPVENIPAK_INCLUDE_SENDER, and
 *    PPVENIPAK_TRACKING_URL keys.
 *
 * Existing warehouses are never overwritten; merchants who already use the
 * Warehouses tab keep their data unchanged. Existing carriers with a custom
 * url set in Shipping → Carriers → Edit are left alone.
 */
function upgrade_module_1_0_1(Module $module): bool
{
    $trackingUrl = 'https://www.venipak.lt/track/?trackingNumber=@';

    $db = Db::getInstance();

    $shopIds = Shop::getShops(true, null, true);
    if (!is_array($shopIds) || empty($shopIds)) {
        $shopIds = [(int) Configuration::get('PS_SHOP_DEFAULT')];
    }

    foreach ($shopIds as $idShop) {
        $idShop = (int) $idShop;
        if ($idShop <= 0) {
            continue;
        }

        $hasWarehouse = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
             WHERE `id_shop` = ' . $idShop
        );

        if ($hasWarehouse > 0) {
            continue;
        }

        $name = trim((string) Configuration::get('PPVENIPAK_SENDER_NAME', null, null, $idShop));
        if ($name === '') {
            continue;
        }

        $country = strtoupper((string) Configuration::get('PPVENIPAK_SENDER_COUNTRY', null, null, $idShop)) ?: 'LT';

        $payload = [
            'name' => pSQL(mb_substr($name, 0, 60)),
            'company_code' => pSQL(mb_substr((string) Configuration::get('PPVENIPAK_SENDER_COMPANY_CODE', null, null, $idShop), 0, 16)),
            'contact' => pSQL(mb_substr((string) Configuration::get('PPVENIPAK_SENDER_CONTACT', null, null, $idShop), 0, 40)),
            'country_code' => pSQL($country),
            'city' => pSQL(mb_substr((string) Configuration::get('PPVENIPAK_SENDER_CITY', null, null, $idShop), 0, 50)),
            'address' => pSQL(mb_substr((string) Configuration::get('PPVENIPAK_SENDER_ADDRESS', null, null, $idShop), 0, 255)),
            'zip_code' => pSQL(mb_substr((string) Configuration::get('PPVENIPAK_SENDER_POSTCODE', null, null, $idShop), 0, 10)),
            'phone' => pSQL(mb_substr((string) Configuration::get('PPVENIPAK_SENDER_PHONE', null, null, $idShop), 0, 15)),
            'id_shop' => $idShop,
            'is_default' => 1,
        ];

        $db->insert('ppvenipak_warehouse', $payload);
    }

    // Backfill ps_carrier.url on existing carriers owned by this module.
    // Find every (live + soft-deleted) carrier whose external_module_name is
    // ours and whose url is empty, then set the tracking URL.
    $rows = $db->executeS(
        'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
         WHERE `external_module_name` = \'' . pSQL($module->name) . '\'
         AND (`url` IS NULL OR `url` = \'\')'
    );

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $carrier = new Carrier((int) $row['id_carrier']);
            if (!Validate::isLoadedObject($carrier)) {
                continue;
            }
            $carrier->url = $trackingUrl;
            $carrier->update();
        }
    }

    $legacyKeys = [
        'PPVENIPAK_SENDER_NAME',
        'PPVENIPAK_SENDER_COMPANY_CODE',
        'PPVENIPAK_SENDER_CONTACT',
        'PPVENIPAK_SENDER_COUNTRY',
        'PPVENIPAK_SENDER_CITY',
        'PPVENIPAK_SENDER_ADDRESS',
        'PPVENIPAK_SENDER_POSTCODE',
        'PPVENIPAK_SENDER_PHONE',
        'PPVENIPAK_SENDER_EMAIL',
        'PPVENIPAK_INCLUDE_SENDER',
        'PPVENIPAK_TRACKING_URL',
    ];

    foreach ($legacyKeys as $key) {
        Configuration::deleteByName($key);
    }

    return true;
}
