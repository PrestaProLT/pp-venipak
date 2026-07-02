<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Carrier;

use Configuration;

class PackNumberGenerator
{
    /**
     * Legacy single global counter key. Kept as a shared "floor" — see
     * counterKey()/currentCounter() — so the high-water mark set before
     * per-API-ID counters existed (including the migrated legacy value and any
     * manual admin bump) still applies to every sequence.
     */
    private const COUNTER_PREFIX = 'PPVENIPAK_PACK_COUNTER';

    /**
     * Generate the next pack number and increment the counter.
     *
     * Format: V{API_ID}E{7-digit-serial}
     * Example: V07789E0000001
     *
     * The API ID is configured per shop, so read it for $idShop — an unscoped
     * read in the "All shops" admin context returns empty and yields a malformed
     * number like "VE0000004" that Venipak rejects (code 41).
     *
     * @param int|null $idShop Shop whose Venipak API ID to embed (null = context)
     */
    public function generate(?int $idShop = null): string
    {
        $apiId = trim((string) Configuration::get('PPVENIPAK_API_ID', null, null, $idShop));
        $key = $this->counterKey($apiId);
        $counter = $this->currentCounter($apiId) + 1;
        Configuration::updateGlobalValue($key, $counter);

        return 'V' . $apiId . 'E' . sprintf('%07d', $counter);
    }

    /**
     * Generate multiple pack numbers at once.
     *
     * @param int      $count  Number of pack numbers to generate
     * @param int|null $idShop Shop whose Venipak API ID to embed (null = context)
     *
     * @return string[] Array of generated pack numbers
     */
    public function generateMultiple(int $count, ?int $idShop = null): array
    {
        if ($count < 1) {
            return [];
        }

        $apiId = trim((string) Configuration::get('PPVENIPAK_API_ID', null, null, $idShop));
        $key = $this->counterKey($apiId);
        $counter = $this->currentCounter($apiId);

        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $counter++;
            $numbers[] = 'V' . $apiId . 'E' . sprintf('%07d', $counter);
        }

        Configuration::updateGlobalValue($key, $counter);

        return $numbers;
    }

    /**
     * Raise the API ID's counter so the next generated serial is strictly
     * greater than $serial.
     *
     * Self-heals Venipak code 45 ("pack number reserved"): when the counter has
     * fallen behind the serials Venipak already holds — a legacy range that was
     * under-seeded, or a counter rolled back by a DB restore — jump past the
     * reserved serial so the next number stops colliding. Wasted serials are
     * harmless; the V{API_ID}E{7-digit} space holds ten million.
     *
     * Returns true when the counter actually moved.
     */
    public function advancePast(int $serial, ?int $idShop = null): bool
    {
        if ($serial < 1) {
            return false;
        }

        $apiId = trim((string) Configuration::get('PPVENIPAK_API_ID', null, null, $idShop));

        if ($serial <= $this->currentCounter($apiId)) {
            return false;
        }

        Configuration::updateGlobalValue($this->counterKey($apiId), $serial);

        return true;
    }

    /**
     * Extract the serial from a pack number string (format V{API_ID}E{serial}).
     * Returns 0 when the string doesn't match.
     */
    public static function serialFromPackNo(string $packNo): int
    {
        if (preg_match('/E(\d+)$/', trim($packNo), $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * The serial counter is keyed by API ID, not by shop.
     *
     * Venipak enforces uniqueness on the whole pack number (V{API_ID}E{serial}),
     * i.e. per sender ID. Keying the serial by API ID means shops that share a
     * sender ID automatically share one sequence (no duplicate numbers →
     * Venipak codes 42/43/45) while shops with distinct sender IDs run
     * independent sequences (no wasted numbers). No "share vs per-shop" toggle
     * is needed — the API ID already encodes the rule.
     *
     * An empty API ID would produce a malformed number anyway; fall back to the
     * legacy global key so we never create a dangling "..._"-suffixed row.
     */
    private function counterKey(string $apiId): string
    {
        return $apiId === '' ? self::COUNTER_PREFIX : self::COUNTER_PREFIX . '_' . $apiId;
    }

    /**
     * Current high-water serial for an API ID.
     *
     * The legacy global counter is a shared floor: it carries the high-water
     * mark from before per-API-ID counters existed and stays an admin escape
     * hatch — bumping PPVENIPAK_PACK_COUNTER lifts every sequence past a range
     * Venipak has reserved. The per-API-ID value wins once it climbs above the
     * floor.
     */
    private function currentCounter(string $apiId): int
    {
        $key = $this->counterKey($apiId);
        $counter = (int) Configuration::getGlobalValue($key);

        if ($key !== self::COUNTER_PREFIX) {
            $counter = max($counter, (int) Configuration::getGlobalValue(self::COUNTER_PREFIX));
        }

        return $counter;
    }
}
