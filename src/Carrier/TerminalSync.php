<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Carrier;

use Db;
use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;

class TerminalSync
{
    private const COUNTRIES = ['LT', 'LV', 'EE', 'PL'];

    private const TABLE = 'ppvenipak_terminal';

    public function __construct(
        private readonly VenipakApiClient $apiClient,
    ) {
    }

    /**
     * Sync all terminals for all supported countries.
     * Returns count of terminals synced.
     */
    public function syncAll(): int
    {
        $total = 0;

        foreach (self::COUNTRIES as $countryCode) {
            $total += $this->syncCountry($countryCode);
        }

        return $total;
    }

    /**
     * Sync terminals for a single country.
     *
     * Incremental upsert rather than delete-and-reinsert: each terminal is
     * inserted or, when it already exists (matched on the
     * `terminal_id` + `country_code` unique key), updated in place. Only
     * terminals that have disappeared from the API response are deleted. This
     * avoids churning every row on each sync and — because the `enabled`
     * column is deliberately left out of the UPDATE set — preserves any
     * terminals the merchant has manually disabled.
     *
     * If the API returns nothing, existing rows are left untouched (a transient
     * API failure must not wipe the cache).
     */
    public function syncCountry(string $countryCode): int
    {
        $countryCode = strtoupper($countryCode);

        if (!in_array($countryCode, self::COUNTRIES, true)) {
            return 0;
        }

        $terminals = $this->apiClient->getPickupPoints($countryCode);

        if (empty($terminals)) {
            return 0;
        }

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . bqSQL(self::TABLE);
        $now = date('Y-m-d H:i:s');

        // Columns refreshed on every sync. `terminal_id` + `country_code` form
        // the unique key and `enabled` is merchant-controlled, so none of those
        // three appear in the ON DUPLICATE KEY UPDATE set.
        $updateColumns = [
            'name', 'code', 'display_name', 'address', 'city', 'zip',
            'terminal_type', 'size_limit', 'max_height', 'max_width',
            'max_length', 'cod_enabled', 'lat', 'lng', 'working_hours', 'date_upd',
        ];
        $updateClause = implode(', ', array_map(
            static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)',
            $updateColumns
        ));

        $seenIds = [];
        $count = 0;

        foreach ($terminals as $terminal) {
            if (!is_array($terminal) || empty($terminal['id'])) {
                continue;
            }

            $terminalId = (int) $terminal['id'];
            $seenIds[] = $terminalId;

            $workingHours = '';
            if (!empty($terminal['working_hours']) && is_array($terminal['working_hours'])) {
                $encoded = json_encode($terminal['working_hours']);
                $workingHours = $encoded !== false ? $encoded : '';
            }

            // pSQL-escaped values; floats render locale-independently on PHP 8.
            $values = [
                $terminalId,
                '\'' . pSQL((string) ($terminal['name'] ?? '')) . '\'',
                '\'' . pSQL((string) ($terminal['code'] ?? '')) . '\'',
                '\'' . pSQL((string) ($terminal['display_name'] ?? '')) . '\'',
                '\'' . pSQL((string) ($terminal['address'] ?? '')) . '\'',
                '\'' . pSQL((string) ($terminal['city'] ?? '')) . '\'',
                '\'' . pSQL((string) ($terminal['zip'] ?? '')) . '\'',
                '\'' . pSQL($countryCode) . '\'',
                (int) ($terminal['type'] ?? 1),
                (int) ($terminal['size_limit'] ?? 0),
                (float) ($terminal['max_height'] ?? 0),
                (float) ($terminal['max_width'] ?? 0),
                (float) ($terminal['max_length'] ?? 0),
                (int) ($terminal['cod_enabled'] ?? 0),
                (float) ($terminal['lat'] ?? 0),
                (float) ($terminal['lng'] ?? 0),
                '\'' . pSQL($workingHours) . '\'',
                '\'' . pSQL($now) . '\'',
            ];

            $db->execute(
                'INSERT INTO `' . $table . '`'
                . ' (`terminal_id`, `name`, `code`, `display_name`, `address`, `city`, `zip`,'
                . ' `country_code`, `terminal_type`, `size_limit`, `max_height`, `max_width`,'
                . ' `max_length`, `cod_enabled`, `lat`, `lng`, `working_hours`, `date_upd`)'
                . ' VALUES (' . implode(', ', $values) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $updateClause
            );

            ++$count;
        }

        // Prune terminals that are no longer returned by the API for this
        // country (closed points), leaving everything else — including the
        // merchant's enabled/disabled choices — intact.
        if (!empty($seenIds)) {
            $db->execute(
                'DELETE FROM `' . $table . '`'
                . ' WHERE `country_code` = \'' . pSQL($countryCode) . '\''
                . ' AND `terminal_id` NOT IN (' . implode(',', array_map('intval', $seenIds)) . ')'
            );
        }

        return $count;
    }

    /**
     * Get terminals for a country from local DB.
     * Optionally filter by city or postcode.
     */
    public function getTerminals(string $countryCode, ?string $city = null, ?string $postcode = null): array
    {
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . bqSQL(self::TABLE);

        // `enabled = 1` keeps merchant-disabled terminals out of the checkout
        // list; disabled points remain in the table and admin UI, just hidden
        // from customers.
        $sql = 'SELECT * FROM `' . $table . '` WHERE `country_code` = \'' . pSQL(strtoupper($countryCode)) . '\''
            . ' AND `enabled` = 1';

        if ($city !== null && $city !== '') {
            $sql .= ' AND `city` = \'' . pSQL($city) . '\'';
        }

        if ($postcode !== null && $postcode !== '') {
            $sql .= ' AND `zip` = \'' . pSQL($postcode) . '\'';
        }

        $sql .= ' ORDER BY `city` ASC, `display_name` ASC';

        $results = $db->executeS($sql);

        return is_array($results) ? $results : [];
    }

    /**
     * Get a single terminal by its Venipak ID.
     */
    public function getTerminalById(int $terminalId): ?array
    {
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . bqSQL(self::TABLE);

        $sql = 'SELECT * FROM `' . $table . '` WHERE `terminal_id` = ' . $terminalId;

        $result = $db->getRow($sql);

        if (!is_array($result) || empty($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Filter terminals by cart weight and product dimensions.
     *
     * @param array $terminals Terminal list
     * @param float $totalWeight Cart total weight in kg
     * @param array $products Array of products with width/height/depth in cm
     * @return array Filtered terminals
     */
    public function filterByConstraints(array $terminals, float $totalWeight, array $products): array
    {
        $filtered = [];

        foreach ($terminals as $terminal) {
            // Weight check: skip terminals where size_limit > 0 and weight exceeds it
            $sizeLimit = (int) ($terminal['size_limit'] ?? 0);
            if ($sizeLimit > 0 && $totalWeight > $sizeLimit) {
                continue;
            }

            // Dimensions check: skip terminals where products don't fit
            $maxH = (float) ($terminal['max_height'] ?? 0);
            $maxW = (float) ($terminal['max_width'] ?? 0);
            $maxL = (float) ($terminal['max_length'] ?? 0);

            // If terminal has dimension constraints, check if products fit
            if ($maxH > 0 && $maxW > 0 && $maxL > 0 && !empty($products)) {
                if (!$this->productsFitInTerminal($products, $maxH, $maxW, $maxL)) {
                    continue;
                }
            }

            $filtered[] = $terminal;
        }

        return $filtered;
    }

    /**
     * Check if products fit in a terminal's dimensions.
     * Tests stacking combinations x rotation permutations.
     * Terminal dimensions are in meters, product dimensions in cm.
     */
    private function productsFitInTerminal(array $products, float $maxH, float $maxW, float $maxL): bool
    {
        // Convert product dimensions from cm to meters
        $productDims = [];
        foreach ($products as $product) {
            $w = (float) ($product['width'] ?? 0) / 100.0;
            $h = (float) ($product['height'] ?? 0) / 100.0;
            $d = (float) ($product['depth'] ?? 0) / 100.0;

            // Skip products with no dimensions (assume they fit)
            if ($w <= 0 && $h <= 0 && $d <= 0) {
                continue;
            }

            // Ensure non-zero minimums for products with partial dimensions
            $w = max($w, 0.001);
            $h = max($h, 0.001);
            $d = max($d, 0.001);

            // Account for quantity
            $qty = max(1, (int) ($product['quantity'] ?? 1));
            for ($i = 0; $i < $qty; ++$i) {
                $productDims[] = [$w, $h, $d];
            }
        }

        // No products with dimensions — they all fit
        if (empty($productDims)) {
            return true;
        }

        // Single product: test all 6 rotation permutations
        if (count($productDims) === 1) {
            return $this->fitsInRotation($productDims[0], $maxH, $maxW, $maxL);
        }

        // Multiple products: try stacking along each axis (height, width, length)
        // For each stacking axis, sum that dimension and take max of the other two
        return $this->tryStackingCombinations($productDims, $maxH, $maxW, $maxL);
    }

    /**
     * Test if a single item fits in the terminal in any rotation.
     *
     * @param array $dims [w, h, d] in meters
     * @param float $maxH Terminal max height in meters
     * @param float $maxW Terminal max width in meters
     * @param float $maxL Terminal max length in meters
     * @return bool
     */
    private function fitsInRotation(array $dims, float $maxH, float $maxW, float $maxL): bool
    {
        // All 6 permutations of [w, h, d] mapped to [height, width, length]
        $permutations = [
            [$dims[0], $dims[1], $dims[2]],
            [$dims[0], $dims[2], $dims[1]],
            [$dims[1], $dims[0], $dims[2]],
            [$dims[1], $dims[2], $dims[0]],
            [$dims[2], $dims[0], $dims[1]],
            [$dims[2], $dims[1], $dims[0]],
        ];

        foreach ($permutations as $perm) {
            if ($perm[0] <= $maxH && $perm[1] <= $maxW && $perm[2] <= $maxL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try stacking all products along each axis and check if they fit.
     * For each stacking axis: sum that dimension, take max of the other two.
     * Tests all rotation permutations for each product.
     *
     * @param array $productDims Array of [w, h, d] in meters
     * @param float $maxH Terminal max height
     * @param float $maxW Terminal max width
     * @param float $maxL Terminal max length
     * @return bool
     */
    private function tryStackingCombinations(array $productDims, float $maxH, float $maxW, float $maxL): bool
    {
        // Stacking axis: 0 = height, 1 = width, 2 = length
        for ($axis = 0; $axis < 3; ++$axis) {
            // For each product, find the best rotation for this stacking direction
            if ($this->tryStackOnAxis($productDims, $axis, $maxH, $maxW, $maxL)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try stacking products on a given axis.
     * For each product, try all rotations and pick the one that minimizes
     * the stacking axis while fitting within the other two axes.
     *
     * @param array $productDims Array of [w, h, d] in meters
     * @param int $stackAxis 0=height, 1=width, 2=length
     * @param float $maxH Terminal max height
     * @param float $maxW Terminal max width
     * @param float $maxL Terminal max length
     * @return bool
     */
    private function tryStackOnAxis(array $productDims, int $stackAxis, float $maxH, float $maxW, float $maxL): bool
    {
        $limits = [$maxH, $maxW, $maxL];
        $stackLimit = $limits[$stackAxis];

        // Other two axes
        $otherAxes = array_values(array_diff([0, 1, 2], [$stackAxis]));
        $limit1 = $limits[$otherAxes[0]];
        $limit2 = $limits[$otherAxes[1]];

        $totalStack = 0.0;
        $maxOther1 = 0.0;
        $maxOther2 = 0.0;

        foreach ($productDims as $dims) {
            $bestFound = false;
            $bestStack = PHP_FLOAT_MAX;
            $bestO1 = 0.0;
            $bestO2 = 0.0;

            // Try all 6 rotations
            $permutations = [
                [$dims[0], $dims[1], $dims[2]],
                [$dims[0], $dims[2], $dims[1]],
                [$dims[1], $dims[0], $dims[2]],
                [$dims[1], $dims[2], $dims[0]],
                [$dims[2], $dims[0], $dims[1]],
                [$dims[2], $dims[1], $dims[0]],
            ];

            foreach ($permutations as $perm) {
                $pStack = $perm[$stackAxis];
                $pO1 = $perm[$otherAxes[0]];
                $pO2 = $perm[$otherAxes[1]];

                // Check that the non-stacking dimensions fit
                if ($pO1 <= $limit1 && $pO2 <= $limit2) {
                    if ($pStack < $bestStack) {
                        $bestFound = true;
                        $bestStack = $pStack;
                        $bestO1 = $pO1;
                        $bestO2 = $pO2;
                    }
                }
            }

            if (!$bestFound) {
                return false;
            }

            $totalStack += $bestStack;
            $maxOther1 = max($maxOther1, $bestO1);
            $maxOther2 = max($maxOther2, $bestO2);
        }

        return $totalStack <= $stackLimit && $maxOther1 <= $limit1 && $maxOther2 <= $limit2;
    }
}
