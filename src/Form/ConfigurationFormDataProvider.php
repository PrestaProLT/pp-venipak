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
        'api_user' => 'API_USER',
        'api_pass' => 'API_PASS',
        'api_id' => 'API_ID',
        'live_mode' => 'LIVE_MODE',
        'show_door_code' => 'SHOW_DOOR_CODE',
        'show_cabinet_no' => 'SHOW_CABINET_NO',
        'show_warehouse_no' => 'SHOW_WAREHOUSE_NO',
        'show_delivery_time' => 'SHOW_DELIVERY_TIME',
        'show_call_before' => 'SHOW_CALL_BEFORE',
        'enable_return_service' => 'ENABLE_RETURN_SERVICE',
        'return_days' => 'RETURN_DAYS',
        'label_format' => 'LABEL_FORMAT',
        'disable_passphrase' => 'DISABLE_PASSPHRASE',
    ];

    /**
     * Multilang fields: form field => PPVENIPAK_ config key suffix.
     * (Carrier names moved to CarrierFormDataProvider.)
     */
    private const MULTILANG_MAP = [];

    /**
     * Global-scope fields: stored at global level, not per-shop.
     */
    private const GLOBAL_MAP = [
        'store_auth_mode' => 'STORE_AUTH_MODE',
    ];

    /**
     * Form fields whose blank submission should NOT overwrite the saved value.
     * PasswordType re-renders empty for security; submitting the form without
     * retyping the password would otherwise wipe it.
     */
    private const PRESERVE_IF_BLANK = ['api_pass'];

    private const CONFIG_PREFIX = 'PPVENIPAK_';

    public function getData(): array
    {
        $data = [];

        // CONFIG_MAP fields normally live per-shop. When per-store auth is
        // disabled the same keys are stored at global scope, so prefer the
        // global value first to make sure the form always reads back what
        // the merchant just typed in CONTEXT_ALL — Configuration::get would
        // happily return a stale per-shop value left over from when the
        // toggle was on.
        $sharedMode = !(bool) Configuration::getGlobalValue('PPVENIPAK_' . self::GLOBAL_MAP['store_auth_mode']);

        foreach (self::CONFIG_MAP as $formField => $configSuffix) {
            $configKey = self::CONFIG_PREFIX . $configSuffix;
            if ($sharedMode) {
                $value = Configuration::getGlobalValue($configKey);
                if ($value === false || $value === '') {
                    $value = Configuration::get($configKey);
                }
            } else {
                $value = Configuration::get($configKey);
            }
            $data[$formField] = $value !== false ? $value : '';
        }

        // Global-scope values
        foreach (self::GLOBAL_MAP as $formField => $configSuffix) {
            $value = Configuration::getGlobalValue(self::CONFIG_PREFIX . $configSuffix);
            $data[$formField] = $value !== false ? $value : '';
        }

        // Set defaults for fields that may not have been saved yet
        if ($data['return_days'] === '') {
            $data['return_days'] = '14';
        }
        if ($data['label_format'] === '') {
            $data['label_format'] = 'a4';
        }

        // Multilang values — stored as suffixed keys (PPVENIPAK_COURIER_NAME_{idLang})
        // so they match the format AbstractPPCarrier::getCarrierName() reads.
        $languages = Language::getLanguages(false);

        foreach (self::MULTILANG_MAP as $formField => $configSuffix) {
            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $multilangValues = [];

            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $locale = $language['locale'];
                $value = Configuration::get($configKey . '_' . $idLang);
                $multilangValues[$locale] = $value !== false ? $value : '';
            }

            $data[$formField] = $multilangValues;
        }

        return $data;
    }

    public function setData(array $data): array
    {
        $errors = [];

        // Save global-scope values first so the rest of this method sees
        // the toggle's *new* value and routes the per-shop fields to the
        // right scope (per-shop when on, global when off).
        foreach (self::GLOBAL_MAP as $formField => $configSuffix) {
            if (!array_key_exists($formField, $data)) {
                continue;
            }

            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $value = $data[$formField];

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (!Configuration::updateGlobalValue($configKey, (string) $value)) {
                $errors[] = sprintf('Could not update global configuration key: %s', $configKey);
            }
        }

        // After saving the toggle: shared mode (auth-mode OFF) writes the
        // per-shop fields globally so a single set of credentials is reused
        // by every shop. Per-shop mode (auth-mode ON) keeps the existing
        // per-shop scoping.
        $sharedMode = !(bool) Configuration::getGlobalValue('PPVENIPAK_' . self::GLOBAL_MAP['store_auth_mode']);

        foreach (self::CONFIG_MAP as $formField => $configSuffix) {
            if (!array_key_exists($formField, $data)) {
                continue;
            }

            $configKey = self::CONFIG_PREFIX . $configSuffix;
            $value = $data[$formField];

            // Password fields: Symfony's PasswordType always re-renders empty
            // for security. If the admin submitted blank, treat that as "keep
            // the existing password" rather than overwriting with empty.
            if (in_array($formField, self::PRESERVE_IF_BLANK, true)
                && (is_string($value) ? trim($value) === '' : empty($value))
            ) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $saved = $sharedMode
                ? Configuration::updateGlobalValue($configKey, (string) $value)
                : Configuration::updateValue($configKey, (string) $value);

            if (!$saved) {
                $errors[] = sprintf('Could not update configuration key: %s', $configKey);
            }
        }

        // Save multilang values as suffixed keys (PPVENIPAK_COURIER_NAME_1, _2, …)
        // so AbstractPPCarrier::getCarrierName() can read them directly.
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
