<?php

declare(strict_types=1);

namespace PrestaPro\Common\Migration;

use Carrier;
use Configuration;
use Db;
use Module;

abstract class AbstractLegacyMigrator
{
    protected Db $db;

    public function __construct(
        protected \Module $module,
    ) {
        $this->db = Db::getInstance();
    }

    /**
     * Must return the legacy modules registry.
     * See carrier-module-interface-requirements.md for the expected format.
     *
     * @return array<string, array{name: string, versions: array}>
     */
    abstract protected function getLegacyModules(): array;

    /**
     * Must return the config prefix for this module (e.g. 'PPVENIPAK').
     */
    abstract protected function getConfigPrefix(): string;

    /**
     * Must return the table prefix for this module (e.g. 'ppvenipak').
     */
    abstract protected function getTablePrefix(): string;

    /**
     * Detect installed legacy modules.
     *
     * @return array<int, array{module_name: string, display_name: string, version: string, matched_case: string, config: array}>
     */
    public function detectLegacyModules(): array
    {
        $found = [];

        foreach ($this->getLegacyModules() as $moduleName => $definition) {
            if (!Module::isInstalled($moduleName)) {
                continue;
            }

            $instance = Module::getInstanceByName($moduleName);

            if (!$instance) {
                continue;
            }

            $version = $instance->version ?? '0.0.0';
            $resolved = $this->resolveVersion($definition['versions'], $version);

            if ($resolved === null) {
                continue;
            }

            $found[] = [
                'module_name' => $moduleName,
                'display_name' => $definition['name'],
                'version' => $version,
                'matched_case' => $resolved['matched_case'],
                'config' => $resolved['config'],
            ];
        }

        return $found;
    }

    /**
     * Preview migration: counts of what would be migrated.
     *
     * @return array<string, mixed>
     */
    public function previewMigration(string $moduleName, array $versionConfig): array
    {
        $preview = [
            'module_name' => $moduleName,
            'config_keys' => count($versionConfig['config_keys'] ?? []),
            'tables' => [],
        ];

        foreach ($versionConfig['tables'] ?? [] as $tableName => $tableConfig) {
            $fullTable = _DB_PREFIX_ . $tableName;

            if (!$this->tableExists($fullTable)) {
                $preview['tables'][$tableName] = ['exists' => false, 'rows' => 0];
                continue;
            }

            $count = (int) $this->db->getValue(
                'SELECT COUNT(*) FROM `' . bqSQL($fullTable) . '`'
            );

            $preview['tables'][$tableName] = ['exists' => true, 'rows' => $count];
        }

        if (!empty($versionConfig['carrier_keys'])) {
            $preview['carriers'] = [];

            foreach ($versionConfig['carrier_keys'] as $type => $configKey) {
                $ref = (int) Configuration::get($configKey);
                $preview['carriers'][$type] = $ref > 0 ? $ref : null;
            }
        }

        return $preview;
    }

    /**
     * Migrate configuration keys from legacy module.
     * Copies values — never deletes the source.
     */
    public function migrateConfiguration(array $configKeyMap): int
    {
        $migrated = 0;

        foreach ($configKeyMap as $oldKey => $newKey) {
            $value = Configuration::get($oldKey);

            if ($value === false || $value === null) {
                continue;
            }

            Configuration::updateValue($newKey, $value);
            $migrated++;
        }

        return $migrated;
    }

    /**
     * Adopt a legacy carrier by creating a new carrier with the same id_reference.
     * The old carrier is disabled (active=0) but NOT deleted.
     *
     * @return int New carrier ID, or 0 on failure
     */
    public function adoptLegacyCarrier(int $legacyIdReference, string $newModuleName): int
    {
        $legacyCarrier = Carrier::getCarrierByReference($legacyIdReference);

        if (!$legacyCarrier) {
            return 0;
        }

        if (is_array($legacyCarrier)) {
            $legacyCarrier = reset($legacyCarrier);
        }

        $newCarrier = new Carrier();
        $newCarrier->name = $legacyCarrier->name;
        $newCarrier->active = true;
        $newCarrier->is_free = $legacyCarrier->is_free;
        $newCarrier->shipping_handling = $legacyCarrier->shipping_handling;
        $newCarrier->range_behavior = $legacyCarrier->range_behavior;
        $newCarrier->shipping_method = $legacyCarrier->shipping_method;
        $newCarrier->is_module = true;
        $newCarrier->external_module_name = $newModuleName;
        $newCarrier->need_range = $legacyCarrier->need_range;
        $newCarrier->delay = $legacyCarrier->delay;

        if (!$newCarrier->add()) {
            return 0;
        }

        // Force same id_reference as legacy carrier
        $this->db->update(
            'carrier',
            ['id_reference' => (int) $legacyCarrier->id_reference],
            'id_carrier = ' . (int) $newCarrier->id
        );

        // Copy zone assignments
        $zones = $this->db->executeS(
            'SELECT id_zone FROM `' . _DB_PREFIX_ . 'carrier_zone`
             WHERE id_carrier = ' . (int) $legacyCarrier->id
        );

        if ($zones) {
            foreach ($zones as $zone) {
                $newCarrier->addZone((int) $zone['id_zone']);
            }
        }

        // Copy group assignments
        $groups = $this->db->executeS(
            'SELECT id_group FROM `' . _DB_PREFIX_ . 'carrier_group`
             WHERE id_carrier = ' . (int) $legacyCarrier->id
        );

        if ($groups) {
            foreach ($groups as $group) {
                $this->db->insert('carrier_group', [
                    'id_carrier' => (int) $newCarrier->id,
                    'id_group' => (int) $group['id_group'],
                ]);
            }
        }

        // Disable legacy carrier
        $legacyCarrier->active = false;
        $legacyCarrier->update();

        return (int) $newCarrier->id;
    }

    /**
     * Build legacy map table entry.
     */
    public function addLegacyMapEntry(
        int $legacyCarrierId,
        string $legacyModuleName,
        int $ppCarrierId,
        int $idReference,
    ): bool {
        $table = $this->getTablePrefix() . '_legacy_map';

        return $this->db->insert($table, [
            'id_legacy_carrier' => $legacyCarrierId,
            'id_legacy_module' => pSQL($legacyModuleName),
            'id_pp_carrier' => $ppCarrierId,
            'id_reference' => $idReference,
            'migrated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if an order belongs to an adopted legacy carrier.
     */
    public function isLegacyOrder(int $idOrder): bool
    {
        $table = _DB_PREFIX_ . $this->getTablePrefix() . '_legacy_map';

        if (!$this->tableExists($table)) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM `' . bqSQL($table) . '` lm
                INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_carrier = lm.id_legacy_carrier
                WHERE o.id_order = ' . (int) $idOrder;

        return (int) $this->db->getValue($sql) > 0;
    }

    /**
     * Re-activate legacy carriers on PP module uninstall.
     */
    public function restoreLegacyCarriers(): void
    {
        $table = _DB_PREFIX_ . $this->getTablePrefix() . '_legacy_map';

        if (!$this->tableExists($table)) {
            return;
        }

        $entries = $this->db->executeS(
            'SELECT DISTINCT id_legacy_carrier FROM `' . bqSQL($table) . '`'
        );

        if (!$entries) {
            return;
        }

        foreach ($entries as $entry) {
            $carrier = new Carrier((int) $entry['id_legacy_carrier']);

            if (Validate::isLoadedObject($carrier)) {
                $carrier->active = true;
                $carrier->update();
            }
        }
    }

    /**
     * Log a migration event.
     */
    public function logMigration(
        string $legacyModule,
        string $dataType,
        int $recordsMigrated,
        ?string $error = null,
    ): void {
        $table = $this->getTablePrefix() . '_migration_log';

        $this->db->insert($table, [
            'legacy_module' => pSQL($legacyModule),
            'data_type' => pSQL($dataType),
            'records_migrated' => $recordsMigrated,
            'error' => $error !== null ? pSQL($error) : null,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Match installed version to the most specific version range.
     */
    private function resolveVersion(array $versions, string $installedVersion): ?array
    {
        // Sort keys so more specific ranges are checked first
        $keys = array_keys($versions);
        usort($keys, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($keys as $key) {
            $config = $versions[$key];
            $range = $config['range'] ?? '*';

            if ($range === '*' || $this->versionInRange($installedVersion, $range)) {
                return [
                    'matched_case' => $key,
                    'config' => $config,
                ];
            }
        }

        return null;
    }

    private function versionInRange(string $version, string $range): bool
    {
        // Simple range parsing: ">=1.0.0 <2.0.0" or ">=1.0.0"
        $parts = preg_split('/\s+/', trim($range));

        foreach ($parts as $part) {
            if (preg_match('/^(>=|<=|>|<|=)(.+)$/', $part, $matches)) {
                $operator = $matches[1];
                $target = $matches[2];

                if (!version_compare($version, $target, $operator)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function tableExists(string $fullTableName): bool
    {
        $result = $this->db->executeS(
            'SHOW TABLES LIKE \'' . pSQL($fullTableName) . '\''
        );

        return !empty($result);
    }
}
