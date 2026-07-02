<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Service;

use Configuration;
use Db;

class MigrationService
{
    /**
     * Config key mapping: MJVP_* → PPVENIPAK_*
     */
    private const CONFIG_MAP = [
        'MJVP_API_USER' => 'PPVENIPAK_API_USER',
        'MJVP_API_PASS' => 'PPVENIPAK_API_PASS',
        'MJVP_API_ID' => 'PPVENIPAK_API_ID',
        'MJVP_API_LIVE_MODE' => 'PPVENIPAK_LIVE_MODE',
        'MJVP_SENDER_NAME' => 'PPVENIPAK_SENDER_NAME',
        'MJVP_SHOP_COMPANY_CODE' => 'PPVENIPAK_SENDER_COMPANY_CODE',
        'MJVP_SHOP_CONTACT' => 'PPVENIPAK_SENDER_CONTACT',
        'MJVP_SHOP_COUNTRY_CODE' => 'PPVENIPAK_SENDER_COUNTRY',
        'MJVP_SHOP_CITY' => 'PPVENIPAK_SENDER_CITY',
        'MJVP_SHOP_ADDRESS' => 'PPVENIPAK_SENDER_ADDRESS',
        'MJVP_SHOP_POSTCODE' => 'PPVENIPAK_SENDER_POSTCODE',
        'MJVP_SHOP_PHONE' => 'PPVENIPAK_SENDER_PHONE',
        'MJVP_SHOP_EMAIL' => 'PPVENIPAK_SENDER_EMAIL',
        'MJVP_COURIER_DOOR_CODE' => 'PPVENIPAK_SHOW_DOOR_CODE',
        'MJVP_COURIER_WAREHOUSE_NUMBER' => 'PPVENIPAK_SHOW_WAREHOUSE_NO',
        'MJVP_COURIER_CABINET_NUMBER' => 'PPVENIPAK_SHOW_CABINET_NO',
        'MJVP_COURIER_DELIVERY_TIME' => 'PPVENIPAK_SHOW_DELIVERY_TIME',
        'MJVP_COURIER_CALL_BEFORE_DELIVERY' => 'PPVENIPAK_SHOW_CALL_BEFORE',
        'MJVP_RETURN_SERVICE' => 'PPVENIPAK_ENABLE_RETURN_SERVICE',
        'MJVP_RETURN_DAYS' => 'PPVENIPAK_RETURN_DAYS',
        'MJVP_COURIER_DELIVERY_TIME_NWD' => 'PPVENIPAK_NWD_ENABLED',
        'MJVP_COURIER_DELIVERY_TIME_NWD10' => 'PPVENIPAK_NWD10_ENABLED',
        'MJVP_COURIER_DELIVERY_TIME_NWD12' => 'PPVENIPAK_NWD12_ENABLED',
        'MJVP_COURIER_DELIVERY_TIME_NWD8_14' => 'PPVENIPAK_NWD8_14_ENABLED',
        'MJVP_COURIER_DELIVERY_TIME_NWD14_17' => 'PPVENIPAK_NWD14_17_ENABLED',
        'MJVP_COURIER_DELIVERY_TIME_NWD18_22' => 'PPVENIPAK_NWD18_22_ENABLED',
        'MJVP_CARRIER_DISABLE_PASSPHRASE' => 'PPVENIPAK_DISABLE_PASSPHRASE',
    ];

    /**
     * Label format mapping: Mijora uses 'a6', we use 'other' for thermal.
     */
    private const LABEL_FORMAT_MAP = [
        'a6' => 'other',
        'a4' => 'a4',
    ];

    /**
     * Detect if mijoravenipak is installed.
     *
     * @return array|null Detection info or null if not found
     */
    public function detect(): ?array
    {
        $db = Db::getInstance();

        // Check if module exists in ps_module
        $row = $db->getRow(
            'SELECT `id_module`, `name`, `version`, `active`
             FROM `' . _DB_PREFIX_ . 'module`
             WHERE `name` = \'mijoravenipak\''
        );

        if (!$row) {
            return null;
        }

        return [
            'module_name' => 'mijoravenipak',
            'display_name' => 'Venipak (Mijora)',
            'version' => $row['version'] ?? 'unknown',
            'active' => (bool) ($row['active'] ?? false),
            'id_module' => (int) $row['id_module'],
            'matched_case' => 'mijoravenipak v' . ($row['version'] ?? '?'),
        ];
    }

    /**
     * Preview what will be migrated — counts of records, config keys, etc.
     *
     * @return array Preview data
     */
    public function preview(): array
    {
        $db = Db::getInstance();
        $preview = [
            'tables' => [],
            'config_keys' => 0,
            'carriers' => [],
            'order_states' => [],
        ];

        // Check tables
        $tables = [
            'mjvp_orders' => 'Orders',
            'mjvp_warehouse' => 'Warehouses',
            'mjvp_manifest' => 'Manifests',
        ];

        foreach ($tables as $table => $label) {
            $exists = $this->tableExists($table);
            $rows = 0;
            if ($exists) {
                $rows = (int) $db->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . bqSQL($table) . '`'
                );
            }
            $preview['tables'][$table] = [
                'label' => $label,
                'exists' => $exists,
                'rows' => $rows,
            ];
        }

        // Count config keys that have values
        $configCount = 0;
        foreach (self::CONFIG_MAP as $mjvpKey => $ppKey) {
            $val = Configuration::get($mjvpKey);
            if ($val !== false && $val !== null && $val !== '') {
                $configCount++;
            }
        }
        $preview['config_keys'] = $configCount;

        // Check carriers
        $courierRef = Configuration::get('MJVP_COURIER_ID_REFERENCE');
        $pickupRef = Configuration::get('MJVP_PICKUP_ID_REFERENCE');
        $preview['carriers'] = [
            'courier_ref' => $courierRef ?: null,
            'pickup_ref' => $pickupRef ?: null,
        ];

        // Check order states
        $preview['order_states'] = [
            'ready' => Configuration::get('MJVP_ORDER_STATE_READY') ?: null,
            'error' => Configuration::get('MJVP_ORDER_STATE_ERROR') ?: null,
        ];

        // Count already migrated
        $preview['already_migrated'] = $this->countAlreadyMigrated();

        return $preview;
    }

    /**
     * Execute the full migration.
     *
     * @param bool $dryRun If true, don't actually write anything
     * @return array{success: bool, log: array, errors: array}
     */
    public function migrate(bool $dryRun = false): array
    {
        $log = [];
        $errors = [];

        try {
            // 1. Migrate config keys
            $configResult = $this->migrateConfig($dryRun);
            $log = array_merge($log, $configResult['log']);

            // 2. Migrate label format
            $labelResult = $this->migrateLabelFormat($dryRun);
            $log = array_merge($log, $labelResult['log']);

            // 3. Migrate pack counter
            $counterResult = $this->migrateCounters($dryRun);
            $log = array_merge($log, $counterResult['log']);

            // 4. Migrate warehouses
            $warehouseResult = $this->migrateWarehouses($dryRun);
            $log = array_merge($log, $warehouseResult['log']);
            $errors = array_merge($errors, $warehouseResult['errors']);

            // 5. Migrate orders
            $orderResult = $this->migrateOrders($dryRun);
            $log = array_merge($log, $orderResult['log']);
            $errors = array_merge($errors, $orderResult['errors']);

            // 6. Migrate manifests
            $manifestResult = $this->migrateManifests($dryRun);
            $log = array_merge($log, $manifestResult['log']);
            $errors = array_merge($errors, $manifestResult['errors']);

            // 7. Adopt carriers
            $carrierResult = $this->adoptCarriers($dryRun);
            $log = array_merge($log, $carrierResult['log']);
            $errors = array_merge($errors, $carrierResult['errors']);

            // 8. Adopt order states
            $stateResult = $this->adoptOrderStates($dryRun);
            $log = array_merge($log, $stateResult['log']);

            // 9. Disable legacy module
            if (!$dryRun) {
                $this->disableLegacyModule();
                $log[] = 'Disabled mijoravenipak module.';
            } else {
                $log[] = '[dry-run] Would disable mijoravenipak module.';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Migration failed: ' . $e->getMessage();
        }

        // Save migration log
        if (!$dryRun && !empty($log)) {
            $this->saveMigrationLog($log, $errors);
        }

        return [
            'success' => empty($errors),
            'log' => $log,
            'errors' => $errors,
        ];
    }

    private function migrateConfig(bool $dryRun): array
    {
        $log = [];

        foreach (self::CONFIG_MAP as $mjvpKey => $ppKey) {
            $value = Configuration::get($mjvpKey);

            if ($value === false || $value === null || $value === '') {
                continue;
            }

            // Don't overwrite if ppvenipak already has a value set
            $existing = Configuration::get($ppKey);
            if ($existing !== false && $existing !== null && $existing !== '') {
                $log[] = sprintf('Skipped %s → %s (already set)', $mjvpKey, $ppKey);
                continue;
            }

            if (!$dryRun) {
                Configuration::updateValue($ppKey, $value);
            }

            $log[] = sprintf('%s %s → %s', $dryRun ? '[dry-run]' : 'Migrated', $mjvpKey, $ppKey);
        }

        return ['log' => $log];
    }

    private function migrateLabelFormat(bool $dryRun): array
    {
        $log = [];
        $mjvpFormat = Configuration::get('MJVP_LABEL_SIZE');

        if ($mjvpFormat === false || $mjvpFormat === null) {
            return ['log' => $log];
        }

        $existing = Configuration::get('PPVENIPAK_LABEL_FORMAT');
        if ($existing !== false && $existing !== null && $existing !== '') {
            $log[] = 'Skipped label format (already set)';
            return ['log' => $log];
        }

        $mapped = self::LABEL_FORMAT_MAP[strtolower($mjvpFormat)] ?? 'other';

        if (!$dryRun) {
            Configuration::updateValue('PPVENIPAK_LABEL_FORMAT', $mapped);
        }

        $log[] = sprintf('%s label format: %s → %s', $dryRun ? '[dry-run]' : 'Migrated', $mjvpFormat, $mapped);

        return ['log' => $log];
    }

    private function migrateCounters(bool $dryRun): array
    {
        $log = [];

        // Pack counter — seed the shared floor (PPVENIPAK_PACK_COUNTER).
        //
        // PackNumberGenerator now keys the serial per API ID, and every
        // per-API-ID sequence inherits this global value as a floor. The legacy
        // module wrote MJVP_COUNTER_PACKS per shop *and* globally (the rows can
        // disagree, and shared-sender shops overlapped), so take the MAX across
        // every stored row — that is the true high-water mark Venipak has
        // reserved, and seeding the floor with it keeps all sequences clear of
        // the legacy range regardless of which API ID a number was used under.
        $legacyPack = (int) Db::getInstance()->getValue(
            'SELECT MAX(CAST(value AS UNSIGNED)) FROM `' . _DB_PREFIX_ . "configuration` WHERE name = 'MJVP_COUNTER_PACKS'"
        );
        if ($legacyPack > 0) {
            $currentPP = (int) Configuration::getGlobalValue('PPVENIPAK_PACK_COUNTER');
            // Only adopt if legacy counter is higher (prevents going backward)
            if ($legacyPack > $currentPP) {
                if (!$dryRun) {
                    Configuration::updateGlobalValue('PPVENIPAK_PACK_COUNTER', $legacyPack);
                }
                $log[] = sprintf('%s pack counter floor: %d → %d', $dryRun ? '[dry-run]' : 'Migrated', $currentPP, $legacyPack);
            }
        }

        // Manifest counter
        $mjvpManifest = Configuration::get('MJVP_COUNTER_MANIFEST');
        if ($mjvpManifest !== false && $mjvpManifest !== null) {
            $data = json_decode($mjvpManifest, true);
            if (is_array($data) && isset($data['counter'])) {
                $currentPP = Configuration::getGlobalValue('PPVENIPAK_MANIFEST_COUNTER');
                $currentData = json_decode((string) $currentPP, true) ?: ['counter' => 0, 'date' => ''];

                if ((int) ($data['counter'] ?? 0) > (int) ($currentData['counter'] ?? 0)) {
                    if (!$dryRun) {
                        Configuration::updateGlobalValue('PPVENIPAK_MANIFEST_COUNTER', $mjvpManifest);
                    }
                    $log[] = sprintf('%s manifest counter', $dryRun ? '[dry-run]' : 'Migrated');
                }
            }
        }

        return ['log' => $log];
    }

    private function migrateWarehouses(bool $dryRun): array
    {
        $log = [];
        $errors = [];
        $db = Db::getInstance();

        if (!$this->tableExists('mjvp_warehouse')) {
            $log[] = 'Skipped warehouses (table not found)';
            return ['log' => $log, 'errors' => $errors];
        }

        $rows = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'mjvp_warehouse`'
        ) ?: [];

        if (empty($rows)) {
            $log[] = 'No warehouses to migrate.';
            return ['log' => $log, 'errors' => $errors];
        }

        $migrated = 0;
        foreach ($rows as $row) {
            // Check if already migrated (by name + shop)
            $exists = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
                 WHERE `name` = \'' . pSQL($row['name']) . '\'
                 AND `id_shop` = ' . (int) ($row['id_shop'] ?? 1)
            );

            if ($exists) {
                continue;
            }

            if (!$dryRun) {
                $db->insert('ppvenipak_warehouse', [
                    'name' => pSQL($row['name']),
                    'company_code' => pSQL($row['company_code'] ?? ''),
                    'contact' => pSQL($row['contact'] ?? ''),
                    'country_code' => pSQL($row['country_code'] ?? 'LT'),
                    'city' => pSQL($row['city'] ?? ''),
                    'address' => pSQL($row['address'] ?? ''),
                    'zip_code' => pSQL($row['zip_code'] ?? ''),
                    'phone' => pSQL($row['phone'] ?? ''),
                    'id_shop' => (int) ($row['id_shop'] ?? 1),
                    'is_default' => (int) ($row['default_on'] ?? 0),
                ]);
            }

            $migrated++;
        }

        $log[] = sprintf('%s %d warehouse(s)', $dryRun ? '[dry-run] Would migrate' : 'Migrated', $migrated);

        return ['log' => $log, 'errors' => $errors];
    }

    private function migrateOrders(bool $dryRun): array
    {
        $log = [];
        $errors = [];
        $db = Db::getInstance();

        if (!$this->tableExists('mjvp_orders')) {
            $log[] = 'Skipped orders (table not found)';
            return ['log' => $log, 'errors' => $errors];
        }

        // Get orders that haven't been migrated yet
        $sql = 'SELECT mo.* FROM `' . _DB_PREFIX_ . 'mjvp_orders` mo
                LEFT JOIN `' . _DB_PREFIX_ . 'ppvenipak_order` po ON po.`id_order` = mo.`id_order`
                WHERE mo.`id_order` > 0
                AND po.`id_ppvenipak_order` IS NULL';

        $rows = $db->executeS($sql) ?: [];

        if (empty($rows)) {
            $log[] = 'No new orders to migrate.';
            return ['log' => $log, 'errors' => $errors];
        }

        $migrated = 0;
        foreach ($rows as $row) {
            // Map other_info → extra_fields
            $otherInfo = json_decode((string) ($row['other_info'] ?? '{}'), true) ?: [];
            $extraFields = json_encode([
                'door_code' => $otherInfo['door_code'] ?? '',
                'cabinet_number' => $otherInfo['cabinet_number'] ?? '',
                'warehouse_number' => $otherInfo['warehouse_number'] ?? '',
                'delivery_time' => $otherInfo['delivery_time'] ?? '',
                'carrier_call' => (int) ($otherInfo['carrier_call'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);

            // Map terminal_info — Mijora uses slightly different field names
            $terminalInfo = null;
            if (!empty($row['terminal_info'])) {
                $ti = json_decode($row['terminal_info'], true);
                if (is_array($ti)) {
                    $terminalInfo = json_encode([
                        'name' => $ti['name'] ?? '',
                        'code' => $ti['company_code'] ?? '',
                        'display_name' => $ti['name'] ?? '',
                        'address' => $ti['address'] ?? '',
                        'city' => $ti['city'] ?? '',
                        'zip' => $ti['post_code'] ?? '',
                        'country_code' => $ti['country'] ?? '',
                        'cod_enabled' => (int) ($ti['is_cod'] ?? 0),
                        'terminal_type' => 1,
                    ], JSON_UNESCAPED_UNICODE);
                }
            }

            // Map labels_numbers → tracking_numbers (same format: JSON array)
            $trackingNumbers = $row['labels_numbers'] ?? null;

            $now = date('Y-m-d H:i:s');

            if (!$dryRun) {
                $db->insert('ppvenipak_order', [
                    'id_cart' => (int) $row['id_cart'],
                    'id_order' => (int) $row['id_order'],
                    'manifest_id' => !empty($row['manifest_id']) ? pSQL($row['manifest_id']) : null,
                    'order_weight' => (float) ($row['order_weight'] ?? 0),
                    'cod_amount' => (float) ($row['cod_amount'] ?? 0),
                    'packages' => (int) ($row['packages'] ?? 1),
                    'is_cod' => (int) ($row['is_cod'] ?? 0),
                    'id_carrier' => (int) ($row['id_carrier_ref'] ?? 0),
                    'country_code' => pSQL($row['country_code'] ?? ''),
                    'terminal_id' => !empty($row['terminal_id']) ? (int) $row['terminal_id'] : null,
                    'id_warehouse' => (int) ($row['warehouse_id'] ?? 0),
                    'terminal_info' => $terminalInfo ? pSQL($terminalInfo) : null,
                    'status' => pSQL($row['status'] ?? 'new'),
                    'extra_fields' => pSQL((string) $extraFields),
                    'tracking_numbers' => $trackingNumbers ? pSQL($trackingNumbers) : null,
                    'error' => pSQL($row['error'] ?? ''),
                    'date_add' => pSQL($row['last_select'] ?? $now),
                    'date_upd' => pSQL($now),
                ]);
            }

            $migrated++;
        }

        $log[] = sprintf('%s %d order(s)', $dryRun ? '[dry-run] Would migrate' : 'Migrated', $migrated);

        return ['log' => $log, 'errors' => $errors];
    }

    private function migrateManifests(bool $dryRun): array
    {
        $log = [];
        $errors = [];
        $db = Db::getInstance();

        if (!$this->tableExists('mjvp_manifest')) {
            $log[] = 'Skipped manifests (table not found)';
            return ['log' => $log, 'errors' => $errors];
        }

        $sql = 'SELECT mm.* FROM `' . _DB_PREFIX_ . 'mjvp_manifest` mm
                LEFT JOIN `' . _DB_PREFIX_ . 'ppvenipak_manifest` pm ON pm.`manifest_id` = mm.`manifest_id`
                WHERE pm.`id_ppvenipak_manifest` IS NULL';

        $rows = $db->executeS($sql) ?: [];

        if (empty($rows)) {
            $log[] = 'No new manifests to migrate.';
            return ['log' => $log, 'errors' => $errors];
        }

        $migrated = 0;
        foreach ($rows as $row) {
            if (!$dryRun) {
                $db->insert('ppvenipak_manifest', [
                    'manifest_id' => pSQL($row['manifest_id']),
                    'id_shop' => (int) ($row['id_shop'] ?? 1),
                    'id_warehouse' => (int) ($row['id_warehouse'] ?? 0),
                    'total_weight' => (float) ($row['shipment_weight'] ?? 0),
                    'call_comment' => pSQL($row['call_comment'] ?? ''),
                    'arrival_from' => $row['arrival_date_from'] ?? null,
                    'arrival_to' => $row['arrival_date_to'] ?? null,
                    'closed' => (int) ($row['closed'] ?? 0),
                    'date_add' => pSQL($row['date_add'] ?? date('Y-m-d H:i:s')),
                ]);
            }

            $migrated++;
        }

        $log[] = sprintf('%s %d manifest(s)', $dryRun ? '[dry-run] Would migrate' : 'Migrated', $migrated);

        return ['log' => $log, 'errors' => $errors];
    }

    private function adoptCarriers(bool $dryRun): array
    {
        $log = [];
        $errors = [];

        $mjvpCourierRef = Configuration::get('MJVP_COURIER_ID_REFERENCE');
        $mjvpPickupRef = Configuration::get('MJVP_PICKUP_ID_REFERENCE');

        if (!$mjvpCourierRef && !$mjvpPickupRef) {
            $log[] = 'No legacy carrier references found to adopt.';
            return ['log' => $log, 'errors' => $errors];
        }

        $db = Db::getInstance();

        // Adopt courier carrier
        if ($mjvpCourierRef) {
            $ppCourierRef = Configuration::get('PPVENIPAK_COURIER_ID_REF');

            if ($ppCourierRef && (int) $ppCourierRef !== (int) $mjvpCourierRef) {
                // ppvenipak already has its own carrier — update the legacy carrier to point to ppvenipak
                if (!$dryRun) {
                    $db->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'carrier`
                         SET `external_module_name` = \'ppvenipak\',
                             `active` = 1
                         WHERE `id_reference` = ' . (int) $mjvpCourierRef . '
                         AND `deleted` = 0
                         ORDER BY `id_carrier` DESC LIMIT 1'
                    );
                    // Store the legacy reference for ppvenipak
                    Configuration::updateValue('PPVENIPAK_COURIER_ID_REF', (int) $mjvpCourierRef);
                }
                $log[] = sprintf('%s courier carrier ref %d', $dryRun ? '[dry-run] Would adopt' : 'Adopted', (int) $mjvpCourierRef);
            } elseif (!$ppCourierRef) {
                // No ppvenipak carrier yet — adopt the legacy one
                if (!$dryRun) {
                    $db->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'carrier`
                         SET `external_module_name` = \'ppvenipak\',
                             `active` = 1
                         WHERE `id_reference` = ' . (int) $mjvpCourierRef . '
                         AND `deleted` = 0
                         ORDER BY `id_carrier` DESC LIMIT 1'
                    );
                    Configuration::updateValue('PPVENIPAK_COURIER_ID_REF', (int) $mjvpCourierRef);

                    // Also save the carrier id
                    $carrierId = (int) $db->getValue(
                        'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
                         WHERE `id_reference` = ' . (int) $mjvpCourierRef . '
                         AND `deleted` = 0
                         ORDER BY `id_carrier` DESC'
                    );
                    if ($carrierId) {
                        Configuration::updateValue('PPVENIPAK_COURIER_ID', $carrierId);
                    }
                }
                $log[] = sprintf('%s courier carrier ref %d', $dryRun ? '[dry-run] Would adopt' : 'Adopted', (int) $mjvpCourierRef);
            } else {
                $log[] = 'Courier carrier already adopted (same reference).';
            }
        }

        // Adopt pickup carrier
        if ($mjvpPickupRef) {
            $ppPickupRef = Configuration::get('PPVENIPAK_PICKUP_ID_REF');

            if (!$ppPickupRef) {
                if (!$dryRun) {
                    $db->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'carrier`
                         SET `external_module_name` = \'ppvenipak\',
                             `active` = 1
                         WHERE `id_reference` = ' . (int) $mjvpPickupRef . '
                         AND `deleted` = 0
                         ORDER BY `id_carrier` DESC LIMIT 1'
                    );
                    Configuration::updateValue('PPVENIPAK_PICKUP_ID_REF', (int) $mjvpPickupRef);

                    $carrierId = (int) $db->getValue(
                        'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
                         WHERE `id_reference` = ' . (int) $mjvpPickupRef . '
                         AND `deleted` = 0
                         ORDER BY `id_carrier` DESC'
                    );
                    if ($carrierId) {
                        Configuration::updateValue('PPVENIPAK_PICKUP_ID', $carrierId);
                    }
                }
                $log[] = sprintf('%s pickup carrier ref %d', $dryRun ? '[dry-run] Would adopt' : 'Adopted', (int) $mjvpPickupRef);
            } else {
                $log[] = 'Pickup carrier already adopted.';
            }
        }

        return ['log' => $log, 'errors' => $errors];
    }

    private function adoptOrderStates(bool $dryRun): array
    {
        $log = [];

        // Reuse the same PS order states if ppvenipak hasn't created its own
        $mjvpReady = Configuration::get('MJVP_ORDER_STATE_READY');
        $mjvpError = Configuration::get('MJVP_ORDER_STATE_ERROR');

        if ($mjvpReady && !(int) Configuration::get('PPVENIPAK_STATE_READY')) {
            if (!$dryRun) {
                Configuration::updateValue('PPVENIPAK_STATE_READY', (int) $mjvpReady);
            }
            $log[] = sprintf('%s order state "ready" (ID %d)', $dryRun ? '[dry-run] Would adopt' : 'Adopted', (int) $mjvpReady);
        }

        if ($mjvpError && !(int) Configuration::get('PPVENIPAK_STATE_ERROR')) {
            if (!$dryRun) {
                Configuration::updateValue('PPVENIPAK_STATE_ERROR', (int) $mjvpError);
            }
            $log[] = sprintf('%s order state "error" (ID %d)', $dryRun ? '[dry-run] Would adopt' : 'Adopted', (int) $mjvpError);
        }

        return ['log' => $log];
    }

    private function disableLegacyModule(): void
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module`
             SET `active` = 0
             WHERE `name` = \'mijoravenipak\''
        );
    }

    private function countAlreadyMigrated(): int
    {
        if (!$this->tableExists('mjvp_orders')) {
            return 0;
        }

        $count = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'mjvp_orders` mo
             INNER JOIN `' . _DB_PREFIX_ . 'ppvenipak_order` po ON po.`id_order` = mo.`id_order`
             WHERE mo.`id_order` > 0'
        );

        return (int) $count;
    }

    private function tableExists(string $table): bool
    {
        $result = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = \'' . pSQL(_DB_PREFIX_ . $table) . '\''
        );

        return (int) $result > 0;
    }

    private function saveMigrationLog(array $log, array $errors): void
    {
        $logEntry = [
            'date' => date('Y-m-d H:i:s'),
            'log' => $log,
            'errors' => $errors,
        ];

        Configuration::updateValue(
            'PPVENIPAK_MIGRATION_LOG',
            json_encode($logEntry, JSON_UNESCAPED_UNICODE)
        );
    }
}
