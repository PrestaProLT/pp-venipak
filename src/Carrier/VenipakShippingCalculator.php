<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Carrier;

use Address;
use Cart;
use Configuration;
use Country;
use Db;

class VenipakShippingCalculator
{
    /**
     * Check if Venipak carriers should be disabled for this cart.
     * Returns true if carriers should be HIDDEN.
     *
     * Checks: if PPVENIPAK_DISABLE_PASSPHRASE is set, queries each product's
     * description from ps_product_lang. If any contains the passphrase, return true.
     */
    public function shouldDisableCarriers(int $idCart, int $idLang): bool
    {
        $passphrase = Configuration::get('PPVENIPAK_DISABLE_PASSPHRASE');

        if (empty($passphrase)) {
            return false;
        }

        $cart = new Cart($idCart);

        if (!$cart->id) {
            return false;
        }

        $products = $cart->getProducts();

        if (empty($products)) {
            return false;
        }

        $productIds = array_map(static fn (array $p): int => (int) $p['id_product'], $products);

        $db = Db::getInstance();
        $sql = 'SELECT pl.`description`
                FROM `' . _DB_PREFIX_ . 'product_lang` pl
                WHERE pl.`id_product` IN (' . implode(',', $productIds) . ')
                AND pl.`id_lang` = ' . $idLang . '
                AND pl.`id_shop` = ' . (int) $cart->id_shop;

        $rows = $db->executeS($sql);

        if (!is_array($rows)) {
            return false;
        }

        $passphraseLower = mb_strtolower($passphrase);

        foreach ($rows as $row) {
            $description = mb_strtolower((string) ($row['description'] ?? ''));

            if ($description !== '' && str_contains($description, $passphraseLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if pickup terminals are available for the delivery country.
     * Used to hide the pickup carrier when no terminals match.
     */
    public function hasAvailableTerminals(int $idCart): bool
    {
        $cart = new Cart($idCart);

        if (!$cart->id || !$cart->id_address_delivery) {
            return false;
        }

        $address = new Address($cart->id_address_delivery);
        $country = new Country($address->id_country);
        $countryCode = strtoupper($country->iso_code);

        if (empty($countryCode)) {
            return false;
        }

        $db = Db::getInstance();

        // Check if any terminals exist for this country
        $terminalCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_terminal`
             WHERE `country_code` = \'' . pSQL($countryCode) . '\''
        );

        if ($terminalCount === 0) {
            return false;
        }

        // Check weight filtering: get cart total weight and verify
        // at least some terminals can accept it
        $totalWeight = (float) $cart->getTotalWeight();

        // Convert from grams to kg if needed
        if (strtolower((string) Configuration::get('PS_WEIGHT_UNIT')) === 'g') {
            $totalWeight /= 1000;
        }

        // Check if at least one terminal can handle this weight
        // Terminals with size_limit = 0 have no weight restriction
        $availableCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_terminal`
             WHERE `country_code` = \'' . pSQL($countryCode) . '\'
             AND (`size_limit` = 0 OR `size_limit` >= ' . (float) $totalWeight . ')'
        );

        return $availableCount > 0;
    }
}
