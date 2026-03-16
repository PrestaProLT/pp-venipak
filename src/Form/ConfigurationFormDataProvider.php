<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use Configuration;
use Language;
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

class ConfigurationFormDataProvider implements FormDataProviderInterface
{
    /**
     * Configuration key mapping: form field => PPVENIPAK_ config key suffix.
     */
    private const CONFIG_MAP = [
        'enabled' => 'ENABLED',
        'tracking_url' => 'TRACKING_URL',
        'api_user' => 'API_USER',
        'api_pass' => 'API_PASS',
        'api_id' => 'API_ID',
        'live_mode' => 'LIVE_MODE',
        'sender_name' => 'SENDER_NAME',
        'sender_company_code' => 'SENDER_COMPANY_CODE',
        'sender_contact' => 'SENDER_CONTACT',
        'sender_country' => 'SENDER_COUNTRY',
        'sender_city' => 'SENDER_CITY',
        'sender_address' => 'SENDER_ADDRESS',
        'sender_postcode' => 'SENDER_POSTCODE',
        'sender_phone' => 'SENDER_PHONE',
        'sender_email' => 'SENDER_EMAIL',
        'include_sender' => 'INCLUDE_SENDER',
        'show_door_code' => 'SHOW_DOOR_CODE',
        'show_cabinet_no' => 'SHOW_CABINET_NO',
        'show_warehouse_no' => 'SHOW_WAREHOUSE_NO',
        'show_delivery_time' => 'SHOW_DELIVERY_TIME',
        'show_call_before' => 'SHOW_CALL_BEFORE',
        'enable_return_service' => 'ENABLE_RETURN_SERVICE',
        'return_days' => 'RETURN_DAYS',
        'nwd_enabled' => 'NWD_ENABLED',
        'nwd10_enabled' => 'NWD10_ENABLED',
        'nwd12_enabled' => 'NWD12_ENABLED',
        'nwd8_14_enabled' => 'NWD8_14_ENABLED',
        'nwd14_17_enabled' => 'NWD14_17_ENABLED',
        'nwd18_22_enabled' => 'NWD18_22_ENABLED',
        'label_format' => 'LABEL_FORMAT',
        'disable_passphrase' => 'DISABLE_PASSPHRASE',
        'cod_modules' => 'COD_MODULES',
    ];

    /**
     * Multilang fields: form field => PPVENIPAK_ config key suffix.
     */
    private const MULTILANG_MAP = [
        'courier_name' => 'COURIER_NAME',
        'pickup_name' => 'PICKUP_NAME',
    ];

    private const CONFIG_PREFIX = 'PPVENIPAK_';

    public function getData(): array
    {
        $data = [];

        // Scalar configuration values
        foreach (self::CONFIG_MAP as $formField => $configSuffix) {
            $value = Configuration::get(self::CONFIG_PREFIX . $configSuffix);
            $data[$formField] = $value !== false ? $value : '';
        }

        // Set defaults for fields that may not have been saved yet
        if ($data['tracking_url'] === '') {
            $data['tracking_url'] = 'https://www.venipak.lt/track/?trackingNumber={tracking_number}';
        }
        if ($data['return_days'] === '') {
            $data['return_days'] = '14';
        }
        if ($data['cod_modules'] === '') {
            $data['cod_modules'] = 'ps_cashondelivery';
        }
        if ($data['label_format'] === '') {
            $data['label_format'] = 'a4';
        }

        // Multilang values
        $languages = Language::getLanguages(false);

        foreach (self::MULTILANG_MAP as $formField => $configSuffix) {
            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $multilangValues = [];

            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $locale = $language['locale'];
                $value = Configuration::get($configKey, $idLang);
                $multilangValues[$locale] = $value !== false ? $value : '';
            }

            $data[$formField] = $multilangValues;
        }

        return $data;
    }

    public function setData(array $data): array
    {
        $errors = [];

        // Save scalar configuration values
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

        // Save multilang values
        $languages = Language::getLanguages(false);

        foreach (self::MULTILANG_MAP as $formField => $configSuffix) {
            if (!array_key_exists($formField, $data)) {
                continue;
            }

            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $langValues = [];

            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $locale = $language['locale'];

                if (isset($data[$formField][$locale])) {
                    $langValues[$idLang] = $data[$formField][$locale];
                } elseif (isset($data[$formField][$idLang])) {
                    $langValues[$idLang] = $data[$formField][$idLang];
                } else {
                    $langValues[$idLang] = '';
                }
            }

            if (!Configuration::updateValue($configKey, $langValues)) {
                $errors[] = sprintf('Could not update multilang configuration key: %s', $configKey);
            }
        }

        return $errors;
    }
}
