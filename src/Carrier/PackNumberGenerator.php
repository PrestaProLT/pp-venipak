<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Carrier;

use Configuration;

class PackNumberGenerator
{
    /**
     * Generate the next pack number and increment the global counter.
     * Uses Configuration with global scope (null shop) to avoid multishop conflicts.
     *
     * Format: V{API_ID}E{7-digit-serial}
     * Example: V07789E0000001
     */
    public function generate(): string
    {
        $apiId = Configuration::get('PPVENIPAK_API_ID');
        $counter = (int) Configuration::getGlobalValue('PPVENIPAK_PACK_COUNTER');
        $counter++;
        Configuration::updateGlobalValue('PPVENIPAK_PACK_COUNTER', $counter);

        return 'V' . trim((string) $apiId) . 'E' . sprintf('%07d', $counter);
    }

    /**
     * Generate multiple pack numbers at once.
     *
     * @param int $count Number of pack numbers to generate
     *
     * @return string[] Array of generated pack numbers
     */
    public function generateMultiple(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $apiId = trim((string) Configuration::get('PPVENIPAK_API_ID'));
        $counter = (int) Configuration::getGlobalValue('PPVENIPAK_PACK_COUNTER');

        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $counter++;
            $numbers[] = 'V' . $apiId . 'E' . sprintf('%07d', $counter);
        }

        Configuration::updateGlobalValue('PPVENIPAK_PACK_COUNTER', $counter);

        return $numbers;
    }
}
