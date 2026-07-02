<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use Configuration;
use Language;
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

/**
 * Reads/writes the configuration keys backing the Carriers tab — module enable
 * flag, multilanguage carrier names (suffixed keys consumed by pp-common's
 * AbstractPPCarrier::getCarrierName), and the per-delivery-window switches.
 */
class CarrierFormDataProvider implements FormDataProviderInterface
{
    private const CONFIG_MAP = [
        'enabled' => 'ENABLED',
        'nwd_enabled' => 'NWD_ENABLED',
        'nwd10_enabled' => 'NWD10_ENABLED',
        'nwd12_enabled' => 'NWD12_ENABLED',
        'nwd8_14_enabled' => 'NWD8_14_ENABLED',
        'nwd14_17_enabled' => 'NWD14_17_ENABLED',
        'nwd18_22_enabled' => 'NWD18_22_ENABLED',
        'tswd_enabled' => 'TSWD_ENABLED',
        'tswd17_enabled' => 'TSWD17_ENABLED',
        'sat_enabled' => 'SAT_ENABLED',
    ];

    private const MULTILANG_MAP = [
        'courier_name' => 'COURIER_NAME',
        'pickup_name' => 'PICKUP_NAME',
    ];

    private const CONFIG_PREFIX = 'PPVENIPAK_';

    public function getData(): array
    {
        $data = [];

        foreach (self::CONFIG_MAP as $formField => $configSuffix) {
            $value = Configuration::get(self::CONFIG_PREFIX . $configSuffix);
            $data[$formField] = $value !== false ? $value : '';
        }

        // Multilang values stored as suffixed keys (PPVENIPAK_COURIER_NAME_{idLang})
        // so they match the format pp-common's AbstractPPCarrier::getCarrierName reads.
        // TranslatableType expects id_lang-keyed values (its child fields are
        // named after id_lang), so the form populates with the saved data only
        // when this array uses int id_lang keys — not locale strings.
        $languages = Language::getLanguages(false);

        foreach (self::MULTILANG_MAP as $formField => $configSuffix) {
            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $multilangValues = [];

            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $value = Configuration::get($configKey . '_' . $idLang);
                $multilangValues[$idLang] = $value !== false ? $value : '';
            }

            $data[$formField] = $multilangValues;
        }

        return $data;
    }

    public function setData(array $data): array
    {
        $errors = [];

        foreach (self::CONFIG_MAP as $formField => $configSuffix) {
            if (!array_key_exists($formField, $data)) {
                continue;
            }

            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $value = $data[$formField];

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (!Configuration::updateValue($configKey, (string) $value)) {
                $errors[] = sprintf('Could not update configuration key: %s', $configKey);
            }
        }

        $languages = Language::getLanguages(false);

        foreach (self::MULTILANG_MAP as $formField => $configSuffix) {
            if (!array_key_exists($formField, $data)) {
                continue;
            }

            $configKey = self::CONFIG_PREFIX . $configSuffix;

            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $locale = $language['locale'];

                if (isset($data[$formField][$locale])) {
                    $value = (string) $data[$formField][$locale];
                } elseif (isset($data[$formField][$idLang])) {
                    $value = (string) $data[$formField][$idLang];
                } else {
                    $value = '';
                }

                if (!Configuration::updateValue($configKey . '_' . $idLang, $value)) {
                    $errors[] = sprintf('Could not update key: %s_%d', $configKey, $idLang);
                }
            }
        }

        return $errors;
    }
}
