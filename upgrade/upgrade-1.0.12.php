<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.12 — carrier logos + migration UI.
 *
 * Back-fills the carrier logo onto the already-created carriers so existing
 * installs get the logo too (new installs get it during createCarriers). The
 * migration UI change is template/asset only and needs no data migration.
 */
function upgrade_module_1_0_12(Module $module): bool
{
    $logo = _PS_MODULE_DIR_ . 'ppvenipak/views/img/carrier_logo.png';

    if (file_exists($logo) && defined('_PS_SHIP_IMG_DIR_')) {
        foreach (['PPVENIPAK_COURIER_ID', 'PPVENIPAK_PICKUP_ID'] as $key) {
            $idCarrier = (int) Configuration::get($key);
            if ($idCarrier > 0) {
                @copy($logo, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
            }
        }
    }

    return true;
}
