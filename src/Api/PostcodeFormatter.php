<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

class PostcodeFormatter
{
    private const POSTCODE_LENGTHS = [
        'LT' => 5,
        'EE' => 5,
        'PL' => 5,
        'LV' => 4,
    ];

    /**
     * Format postcode for Venipak API.
     * LV and PL: strip non-numeric characters (e.g., "LV-1001" → "1001", "00-950" → "00950").
     * LT and EE: keep as-is.
     */
    public static function format(string $postcode, string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        $postcode = trim($postcode);

        if (in_array($countryCode, ['LV', 'PL'], true)) {
            return preg_replace('/[^0-9]/', '', $postcode);
        }

        return $postcode;
    }

    /**
     * Validate postcode length per country.
     * LT: 5, EE: 5, PL: 5, LV: 4.
     * Returns true for unknown countries (no validation applied).
     */
    public static function isValid(string $postcode, string $countryCode): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        $formatted = self::format($postcode, $countryCode);

        if (!isset(self::POSTCODE_LENGTHS[$countryCode])) {
            return true;
        }

        $expectedLength = self::POSTCODE_LENGTHS[$countryCode];
        $numericOnly = preg_replace('/[^0-9]/', '', $formatted);

        return strlen($numericOnly) === $expectedLength;
    }
}
