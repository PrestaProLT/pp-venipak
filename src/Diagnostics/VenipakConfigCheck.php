<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Diagnostics;

use Configuration;
use Db;

/**
 * Diagnostic for the Venipak pack-number counters.
 *
 * Pack numbers are V{API_ID}E{serial}. Venipak enforces uniqueness on the whole
 * string per sender (API ID), so the serial counter must (a) sit above every
 * number the sender has ever used and (b) persist across requests as it climbs.
 *
 * Codes 42 ("already in use") and 45 ("reserved") mean the generated serial
 * collides with Venipak's records. If the counter also fails to persist, every
 * fresh label attempt restarts from the same low value and can never escape the
 * used range — the symptom this tool exists to pin down.
 *
 * Read-only by default. report() never writes anything except the persistence
 * self-test, which writes and immediately deletes a throwaway key. The two
 * remediation helpers (setCounter / dedupe) only run when called explicitly.
 */
class VenipakConfigCheck
{
    private const FLOOR_KEY = 'PPVENIPAK_PACK_COUNTER';
    private const PERSIST_TEST_KEY = 'PPVENIPAK_PERSIST_SELFTEST';

    /** @var Db */
    private $db;

    /** @var string */
    private $table;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->table = _DB_PREFIX_ . 'configuration';
    }

    /**
     * Build the full plain-text diagnostic report.
     */
    public function report(): string
    {
        $out = [];
        $out[] = $this->line();
        $out[] = 'VENIPAK CONFIG CHECK  ' . date('Y-m-d H:i:s');
        $out[] = $this->line();
        $out[] = 'PrestaShop ' . _PS_VERSION_
            . ' | dev mode: ' . (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? 'ON' : 'off')
            . ' | cache: ' . (defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_ ? 'ON' : 'off');
        $out[] = '';

        $out[] = $this->sectionApiIds();
        $out[] = $this->sectionCounterRows();
        $out[] = $this->sectionNextNumbers();
        $out[] = $this->sectionLegacyCounters();
        $out[] = $this->sectionPersistenceTest();
        $out[] = $this->sectionRecommendations();

        return implode("\n", $out) . "\n";
    }

    // -- sections ------------------------------------------------------------

    private function sectionApiIds(): string
    {
        $rows = $this->rowsForName('PPVENIPAK_API_ID');

        $lines = ['API IDs (sender) per shop', $this->thin()];
        if (empty($rows)) {
            $lines[] = '  (none configured)';
        }
        foreach ($rows as $r) {
            $lines[] = sprintf(
                '  shop_group=%s shop=%s  API_ID=%s',
                $this->scope($r['id_shop_group']),
                $this->scope($r['id_shop']),
                $r['value']
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionCounterRows(): string
    {
        $rows = $this->db->executeS(
            'SELECT id_configuration, name, value, id_shop_group, id_shop, date_upd
             FROM `' . $this->table . '`
             WHERE name = "' . self::FLOOR_KEY . '" OR name LIKE "' . self::FLOOR_KEY . '\_%"
             ORDER BY name, id_shop'
        ) ?: [];

        $lines = ['Pack counter rows (' . self::FLOOR_KEY . '[_API_ID])', $this->thin()];
        if (empty($rows)) {
            $lines[] = '  (no counter rows at all — sequences would start from 1)';
        }

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']][] = $r;
            $lines[] = sprintf(
                '  [#%s] %s = %s  (shop_group=%s shop=%s, upd %s)',
                $r['id_configuration'],
                $r['name'],
                $r['value'],
                $this->scope($r['id_shop_group']),
                $this->scope($r['id_shop']),
                $r['date_upd']
            );
        }

        // Duplicate rows for the same key are the classic cause of a counter
        // that "never advances": one row is read while another is updated.
        $dupes = [];
        foreach ($byName as $name => $list) {
            if (count($list) > 1) {
                $dupes[] = $name . ' (' . count($list) . ' rows)';
            }
        }
        if (!empty($dupes)) {
            $lines[] = '';
            $lines[] = '  !! DUPLICATE rows detected — get/update may target different rows:';
            foreach ($dupes as $d) {
                $lines[] = '     - ' . $d;
            }
            $lines[] = '     Fix with: --dedupe (keeps the highest value per key)';
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionNextNumbers(): string
    {
        $floor = (int) Configuration::getGlobalValue(self::FLOOR_KEY);
        $apiIds = $this->distinctApiIds();

        $lines = [
            'Next pack number per sender (floor ' . self::FLOOR_KEY . ' = ' . $floor . ')',
            $this->thin(),
        ];
        if (empty($apiIds)) {
            $lines[] = '  (no API IDs configured)';
        }
        foreach ($apiIds as $apiId) {
            $perKey = self::FLOOR_KEY . '_' . $apiId;
            $perVal = (int) Configuration::getGlobalValue($perKey);
            $effective = max($perVal, $floor);
            $next = $effective + 1;
            $lines[] = sprintf(
                '  API_ID %s: per-sender=%d, effective=%d  ->  next = V%sE%07d',
                $apiId,
                $perVal,
                $effective,
                $apiId,
                $next
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionLegacyCounters(): string
    {
        $rows = $this->rowsForName('MJVP_COUNTER_PACKS');

        $lines = ['Legacy MJVP_COUNTER_PACKS (migration seed source)', $this->thin()];
        if (empty($rows)) {
            $lines[] = '  (none — nothing was migrated from the old mjvp module)';
        }
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['value']);
            $lines[] = sprintf(
                '  shop_group=%s shop=%s  = %s',
                $this->scope($r['id_shop_group']),
                $this->scope($r['id_shop']),
                $r['value']
            );
        }
        if (!empty($rows)) {
            $lines[] = '  -> legacy MAX = ' . $max
                . ' (the floor should sit at or above this, plus headroom)';
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionPersistenceTest(): string
    {
        $lines = ['Persistence self-test (does updateGlobalValue reach the DB?)', $this->thin()];

        $marker = (string) (time() % 1000000);
        Configuration::updateGlobalValue(self::PERSIST_TEST_KEY, $marker);

        $viaApi = (string) Configuration::getGlobalValue(self::PERSIST_TEST_KEY);
        $viaDb = (string) $this->db->getValue(
            'SELECT value FROM `' . $this->table . '`
             WHERE name = "' . self::PERSIST_TEST_KEY . '"
               AND id_shop IS NULL AND id_shop_group IS NULL'
        );
        $rowCount = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->table . '` WHERE name = "' . self::PERSIST_TEST_KEY . '"'
        );

        // Clean up the throwaway key.
        $this->db->execute('DELETE FROM `' . $this->table . '` WHERE name = "' . self::PERSIST_TEST_KEY . '"');

        $lines[] = '  wrote value      : ' . $marker;
        $lines[] = '  read (cache/API) : ' . $viaApi;
        $lines[] = '  read (direct DB) : ' . $viaDb;
        $lines[] = '  rows written     : ' . $rowCount;

        if ($viaDb === $marker && $rowCount === 1) {
            $lines[] = '  => PASS: writes persist to the DB. A counter that still resets across';
            $lines[] = '           requests then points to duplicate rows (see above) or another';
            $lines[] = '           code path/cron overwriting it — not a raw write failure.';
        } elseif ($rowCount > 1) {
            $lines[] = '  => FAIL: the test key wrote MULTIPLE rows — Configuration is splitting';
            $lines[] = '           writes by scope. Counter updates land on rows reads ignore.';
        } else {
            $lines[] = '  => FAIL: the value did NOT reach the DB (' . ($viaDb === '' ? 'no row' : 'mismatch')
                . '). updateGlobalValue is not persisting on this server — the counter';
            $lines[] = '           cannot climb across requests. This is the root cause.';
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionRecommendations(): string
    {
        $floor = (int) Configuration::getGlobalValue(self::FLOOR_KEY);
        $legacyMax = (int) $this->db->getValue(
            'SELECT MAX(CAST(value AS UNSIGNED)) FROM `' . $this->table . '` WHERE name = "MJVP_COUNTER_PACKS"'
        );

        $lines = ['Recommendations', $this->thin()];
        $lines[] = '  Venipak rejected V30283E0001441..0016446 (codes 42/45), so sender 30283';
        $lines[] = '  has used numbers well past 16446. The floor is currently ' . $floor . '.';
        $lines[] = '';
        $lines[] = '  1. Push the counter above the whole used range and verify it sticks:';
        $lines[] = '         php modules/ppvenipak/config_check.php --set-counter=100000';
        $lines[] = '     Re-run this report afterwards — "next" should read V30283E0100001 and';
        $lines[] = '     the value must survive a second run (proving persistence).';
        if ($legacyMax > 0) {
            $lines[] = '     (legacy MAX seen = ' . $legacyMax . '; pick a value comfortably above it.)';
        }
        $lines[] = '';
        $lines[] = '  2. If the persistence test FAILED or duplicates were listed, run --dedupe';
        $lines[] = '     first, then --set-counter, then confirm with a re-run.';

        return implode("\n", $lines) . "\n";
    }

    // -- remediation (explicit opt-in) --------------------------------------

    /**
     * Push the global floor and every per-sender counter up to $value.
     * currentCounter() already takes max(perSender, floor), but we set both so
     * the state is unambiguous after the bump.
     *
     * @return string Action log.
     */
    public function setCounter(int $value): string
    {
        if ($value < 1) {
            return "Refusing to set counter below 1.\n";
        }

        $log = ['Setting counters to ' . $value, $this->thin()];

        Configuration::updateGlobalValue(self::FLOOR_KEY, $value);
        $log[] = '  ' . self::FLOOR_KEY . ' = ' . $value;

        foreach ($this->distinctApiIds() as $apiId) {
            $key = self::FLOOR_KEY . '_' . $apiId;
            Configuration::updateGlobalValue($key, $value);
            $log[] = '  ' . $key . ' = ' . $value;
        }

        $log[] = '';
        $log[] = 'Done. Re-run without flags to confirm the values persisted.';

        return implode("\n", $log) . "\n";
    }

    /**
     * Collapse duplicate rows for each counter key down to a single global row
     * holding the highest value. Removes the get/update ambiguity that stalls a
     * counter.
     *
     * @return string Action log.
     */
    public function dedupe(): string
    {
        $rows = $this->db->executeS(
            'SELECT id_configuration, name, value, id_shop_group, id_shop
             FROM `' . $this->table . '`
             WHERE name = "' . self::FLOOR_KEY . '" OR name LIKE "' . self::FLOOR_KEY . '\_%"
             ORDER BY name'
        ) ?: [];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']][] = $r;
        }

        $log = ['De-duplicating counter rows', $this->thin()];
        $changed = false;

        foreach ($byName as $name => $list) {
            if (count($list) <= 1) {
                continue;
            }

            $changed = true;
            $maxValue = 0;
            foreach ($list as $r) {
                $maxValue = max($maxValue, (int) $r['value']);
            }

            // Delete every row for this key, then write one clean global row.
            $this->db->execute(
                'DELETE FROM `' . $this->table . '` WHERE name = "' . pSQL($name) . '"'
            );
            Configuration::updateGlobalValue($name, $maxValue);

            $log[] = sprintf('  %s: %d rows -> 1 global row = %d', $name, count($list), $maxValue);
        }

        if (!$changed) {
            $log[] = '  No duplicates found — nothing to do.';
        }

        return implode("\n", $log) . "\n";
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @return string[] distinct configured API IDs
     */
    private function distinctApiIds(): array
    {
        $rows = $this->db->executeS(
            'SELECT DISTINCT value FROM `' . $this->table . '` WHERE name = "PPVENIPAK_API_ID"'
        ) ?: [];

        $ids = [];
        foreach ($rows as $r) {
            $id = trim((string) $r['value']);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function rowsForName(string $name): array
    {
        return $this->db->executeS(
            'SELECT value, id_shop_group, id_shop
             FROM `' . $this->table . '`
             WHERE name = "' . pSQL($name) . '"
             ORDER BY id_shop'
        ) ?: [];
    }

    private function scope($value): string
    {
        return ($value === null || $value === '') ? 'NULL' : (string) $value;
    }

    private function line(): string
    {
        return str_repeat('=', 72);
    }

    private function thin(): string
    {
        return str_repeat('-', 72);
    }
}
