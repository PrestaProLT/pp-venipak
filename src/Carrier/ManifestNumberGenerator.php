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
     *
     * Per Venipak spec, the date portion must fall within today..today+6 days.
     * We always use today() so that constraint is automatically satisfied.
     * If future scheduling is ever introduced, validate the +6-day window here.
     *
     * The API ID is per shop, so read it for $idShop — an unscoped read in the
     * "All shops" admin context returns empty and produces a malformed manifest
     * number Venipak rejects.
     *
     * @param int|null $idShop Shop whose Venipak API ID to embed (null = context)
     */
    public function generate(?int $idShop = null): string
    {
        $apiId = trim((string) Configuration::get('PPVENIPAK_API_ID', null, null, $idShop));
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
