<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Carrier;

use Configuration;

class ManifestNumberGenerator
{
    /**
     * Generate the next manifest number.
     * Counter stored as JSON: {"counter": N, "date": "YYMMDD"}
     * Resets to 1 when date changes.
     *
     * Format: {API_ID}{YYMMDD}{3-digit-serial}
     * Example: 07789260317001
     */
    public function generate(): string
    {
        $apiId = trim((string) Configuration::get('PPVENIPAK_API_ID'));
        $today = date('ymd');
        $json = Configuration::getGlobalValue('PPVENIPAK_MANIFEST_COUNTER');
        $data = json_decode((string) $json, true) ?: ['counter' => 0, 'date' => ''];

        if ($data['date'] !== $today) {
            $data = ['counter' => 1, 'date' => $today];
        } else {
            $data['counter']++;
        }

        Configuration::updateGlobalValue('PPVENIPAK_MANIFEST_COUNTER', json_encode($data));

        return $apiId . $today . sprintf('%03d', $data['counter']);
    }
}
