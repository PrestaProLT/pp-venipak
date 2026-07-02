<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Service;

use Address;
use Carrier;
use Configuration;
use Country;
use Currency;
use Customer;
use Db;
use Order;
use OrderState;
use PrestaShop\Module\PPVenipak\Api\PostcodeFormatter;
use PrestaShop\Module\PPVenipak\Api\ShipmentXmlBuilder;
use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;
use PrestaShop\Module\PPVenipak\Carrier\ManifestNumberGenerator;
use PrestaShop\Module\PPVenipak\Carrier\PackNumberGenerator;
use Validate;

class LabelGenerationService
{
    private VenipakApiClient $apiClient;
    private PackNumberGenerator $packGenerator;
    private ManifestNumberGenerator $manifestGenerator;
    private PostcodeFormatter $postcodeFormatter;

    public function __construct()
    {
        $this->apiClient = new VenipakApiClient();
        $this->packGenerator = new PackNumberGenerator();
        $this->manifestGenerator = new ManifestNumberGenerator();
        $this->postcodeFormatter = new PostcodeFormatter();
    }

    /**
     * Generate labels for one or more orders.
     * Groups by warehouse, creates manifests, submits XML to Venipak API.
     *
     * @param int[] $orderIds
     * @return array{success: bool, results: array, errors: array}
     */
    public function generateForOrders(array $orderIds): array
    {
        $db = Db::getInstance();
        $results = [];
        $errors = [];

        // Load venipak order records
        $venipakOrders = $this->loadVenipakOrders($orderIds);

        if (empty($venipakOrders)) {
            return ['success' => false, 'results' => [], 'errors' => ['No Venipak orders found for the given order IDs.']];
        }

        // Filter out already-registered orders
        $toProcess = [];
        foreach ($venipakOrders as $vo) {
            if ($vo['status'] === 'registered') {
                $errors[] = sprintf('Order #%d already has labels generated.', (int) $vo['id_order']);
                continue;
            }
            $toProcess[] = $vo;
        }

        if (empty($toProcess)) {
            return ['success' => false, 'results' => $results, 'errors' => $errors];
        }

        // Group by warehouse
        $groups = [];
        foreach ($toProcess as $vo) {
            $warehouseId = (int) $vo['id_warehouse'];
            $groups[$warehouseId][] = $vo;
        }

        foreach ($groups as $warehouseId => $groupOrders) {
            $result = $this->processWarehouseGroup($warehouseId, $groupOrders);

            if ($result['success']) {
                $results = array_merge($results, $result['orders']);
            } else {
                $errors[] = $result['error'];
                // Mark all orders in this group as error
                foreach ($groupOrders as $vo) {
                    $this->updateOrderStatus(
                        (int) $vo['id_ppvenipak_order'],
                        'error',
                        $result['error']
                    );
                    $this->setPrestaShopOrderStatus((int) $vo['id_order'], 'error');
                }
            }
        }

        return [
            'success' => empty($errors),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Process a group of orders for a single warehouse.
     */
    private function processWarehouseGroup(int $warehouseId, array $venipakOrders): array
    {
        // Resolve this batch's shop from the first order's id_shop. Orders are
        // grouped by warehouse and a warehouse belongs to a single shop, so the
        // batch is same-shop. Needed for: the default-warehouse fallback,
        // binding the API client to this shop's Venipak account, and — crucially
        // — the pack/manifest numbers, which embed this shop's API ID. In the
        // "All shops" context an unscoped read is empty, producing malformed
        // numbers like "VE0000004" that Venipak rejects (code 41).
        $batchShopId = 0;
        if (!empty($venipakOrders)) {
            $batchShopId = (int) Db::getInstance()->getValue(
                'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'orders`
                 WHERE `id_order` = ' . (int) $venipakOrders[0]['id_order']
            );
        }

        $this->apiClient->setShopId($batchShopId ?: null);

        // Find or create manifest (its number embeds the shop's API ID).
        $manifestId = $this->findOrCreateManifest($warehouseId, $batchShopId);

        $warehouse = $this->loadWarehouse($warehouseId, $batchShopId);

        if (!$warehouse) {
            return [
                'success' => false,
                'error' => 'No warehouse configured. Add a default warehouse in the Warehouses tab before generating labels.',
                'errors' => [],
            ];
        }

        $missing = self::validateWarehouseFields($warehouse);
        if (!empty($missing)) {
            return [
                'success' => false,
                'error' => sprintf(
                    'Warehouse "%s" is missing required fields: %s. Edit it in the Warehouses tab before generating labels — Venipak will reject shipments otherwise.',
                    $warehouse['name'] ?? '(unnamed)',
                    implode(', ', $missing)
                ),
                'errors' => [],
            ];
        }

        // Shop-scoped so a label built from the "All shops" context carries the
        // order's own shop name and return-service settings, matching the
        // per-shop Venipak account the request is sent under.
        $shopName = Configuration::get('PS_SHOP_NAME', null, null, $batchShopId) ?: 'Shop';
        $enableReturn = (bool) Configuration::get('PPVENIPAK_ENABLE_RETURN_SERVICE', null, null, $batchShopId);
        $returnDays = (int) Configuration::get('PPVENIPAK_RETURN_DAYS', null, null, $batchShopId);

        // Self-heal Venipak code 45 ("pack number reserved"). If the persisted
        // serial counter has fallen behind the numbers Venipak already holds
        // (under-seeded legacy range, or a counter rolled back by a DB restore),
        // every freshly generated number lands inside a reserved block. Each
        // iteration jumps the counter well past the reserved serial Venipak
        // reported and rebuilds the batch with higher numbers — escaping the
        // block within a single request, so it heals even where the counter
        // looked "stuck" on the same number across manual retries.
        $maxPackAttempts = 6;
        $orderPackMap = [];
        $response = null;

        for ($packAttempt = 1; $packAttempt <= $maxPackAttempts; ++$packAttempt) {
        $xmlBuilder = new ShipmentXmlBuilder($manifestId, $shopName);

        // Track pack numbers per order for response mapping
        $orderPackMap = [];
        $allPackNumbers = [];

        foreach ($venipakOrders as $vo) {
            $orderId = (int) $vo['id_order'];
            $order = new Order($orderId);
            $address = new Address($order->id_address_delivery);
            $customer = new Customer($order->id_customer);
            $country = new Country($address->id_country);
            $currency = new Currency($order->id_currency);

            $carrierRef = (int) $vo['id_carrier'];
            $isPickup = $this->isPickupCarrier($carrierRef);

            // Generate pack numbers (each embeds the shop's API ID).
            $packCount = max(1, (int) $vo['packages']);
            $packNumbers = $this->packGenerator->generateMultiple($packCount, $batchShopId ?: null);
            $orderPackMap[$orderId] = $packNumbers;
            $allPackNumbers = array_merge($allPackNumbers, $packNumbers);

            // Build consignor block from the resolved warehouse. Spec marks
            // consignor optional, but always emitting it from a real warehouse
            // gives carriers consistent return-label data.
            $consignor = [
                'name' => $warehouse['name'],
                'company_code' => $warehouse['company_code'],
                'country' => $warehouse['country_code'],
                'city' => $warehouse['city'],
                'address' => $warehouse['address'],
                'post_code' => $this->postcodeFormatter->format(
                    $warehouse['zip_code'],
                    $warehouse['country_code']
                ),
                'contact_person' => $warehouse['contact'],
                'contact_tel' => $warehouse['phone'],
            ];

            // Build consignee (recipient)
            if ($isPickup) {
                $consignee = $this->buildPickupConsignee($vo, $address, $customer);
            } else {
                $consignee = $this->buildCourierConsignee($address, $customer, $country);
            }

            // Build attributes
            $extraFields = json_decode((string) ($vo['extra_fields'] ?? '{}'), true) ?: [];
            $attribute = $this->buildAttribute($vo, $extraFields, $enableReturn, $returnDays, $currency);

            // Build packs. doc_no is the merchant reference Venipak echoes
            // back; Venipak rejects shipments whose doc_no it has already
            // seen. When a label is regenerated for the same order (wrong
            // item shipped, return, etc.) attempt_count > 1 — append it
            // as a `-N` suffix so each attempt's doc_no is unique.
            $attemptCount = max(1, (int) ($vo['attempt_count'] ?? 1));
            $docNo = $order->reference . ($attemptCount > 1 ? '-' . $attemptCount : '');

            $weight = (float) $vo['order_weight'];
            $volume = $this->calculateOrderVolume($order);
            $packs = [];
            foreach ($packNumbers as $i => $packNo) {
                $packs[] = [
                    'pack_no' => $packNo,
                    'doc_no' => $docNo,
                    'weight' => round($weight / $packCount, 3),
                    'volume' => round($volume / $packCount, 6),
                ];
            }

            $shipmentData = [
                'consignee' => $consignee,
                'attribute' => $attribute,
                'packs' => $packs,
            ];

            if ($consignor) {
                $shipmentData['consignor'] = $consignor;
            }

            // When return_service is enabled, Venipak requires a matching
            // <return_consignee> block (error code 115). Use the same
            // warehouse address as the consignor — the parcel returns to
            // wherever it shipped from. Without this, even contracts that
            // permit return service reject the shipment.
            if ($enableReturn && $returnDays > 0 && $consignor) {
                $shipmentData['return_consignee'] = $consignor;
            }

            $xmlBuilder->addShipment($shipmentData);
        }

        // Submit to API. We pass the per-order id list so the API client
        // can stamp `id_order` on each error log row — without it, the
        // batch-level rejection would log with id_order=0 and
        // ApiLogger::clearForOrder() couldn't wipe it on later success.
        $xml = $xmlBuilder->toString();
        $batchOrderIds = array_map(static function (array $vo): int {
            return (int) $vo['id_order'];
        }, $venipakOrders);

        try {
            $response = $this->apiClient->submitShipmentXml($xml, $batchOrderIds);
        } catch (\Throwable $e) {
            // Transport-level failure: log once per order in the batch so
            // these too can be reaped on a successful retry.
            foreach ($batchOrderIds as $orderId) {
                ApiLogger::logException(
                    'LabelGenerationService::processWarehouseGroup',
                    $e,
                    'import/send.php',
                    $orderId,
                    ['manifest_id' => $manifestId, 'order_count' => count($venipakOrders)]
                );
            }

            return ['success' => false, 'error' => 'API error: ' . $e->getMessage()];
        }

        // Success — leave the self-heal loop and save results below.
        if (!isset($response['error'])) {
            break;
        }

        // Taken pack numbers — code 45 ("reserved") and 42 ("already in use")
        // both mean the serial collides with Venipak's records. Collect every
        // reported serial and jump the API ID's counter well past the highest
        // one. The step is large and grows per attempt so a deep occupied range
        // (a sender with thousands of historical numbers) is cleared in one or
        // two round-trips rather than creeping through it number by number.
        $reservedSerials = [];
        foreach (($response['errors'] ?? []) as $err) {
            if (in_array((int) ($err['code'] ?? 0), [42, 45], true)) {
                $serial = PackNumberGenerator::serialFromPackNo((string) ($err['pack'] ?? ''));
                if ($serial > 0) {
                    $reservedSerials[] = $serial;
                }
            }
        }

        if (!empty($reservedSerials) && $packAttempt < $maxPackAttempts) {
            $target = max($reservedSerials) + ($packAttempt * 25000);
            $this->packGenerator->advancePast($target, $batchShopId ?: null);

            ApiLogger::log(
                'warning',
                'LabelGenerationService::processWarehouseGroup',
                sprintf(
                    'Venipak reported reserved pack number(s) %s; advanced counter past %d (attempt %d/%d) and retrying.',
                    implode(', ', $reservedSerials),
                    $target,
                    $packAttempt,
                    $maxPackAttempts
                ),
                ['reserved' => $reservedSerials, 'manifest_id' => $manifestId]
            );

            continue;
        }

        // Not a reserved-number error, or out of retry attempts.
        return [
            'success' => false,
            'error' => (string) $response['error'],
            'errors' => $response['errors'] ?? [],
        ];
        } // end self-heal loop

        // Process success — save tracking numbers and update statuses
        $this->saveManifestRecord($manifestId, $warehouseId, $venipakOrders);
        $processedOrders = [];

        foreach ($venipakOrders as $vo) {
            $orderId = (int) $vo['id_order'];
            $packNumbers = $orderPackMap[$orderId] ?? [];

            $this->saveOrderResult(
                (int) $vo['id_ppvenipak_order'],
                $orderId,
                $manifestId,
                $packNumbers
            );

            $this->setPrestaShopOrderStatus($orderId, 'ready');

            // Save first tracking number to PrestaShop order_carrier
            if (!empty($packNumbers)) {
                $this->saveTrackingToOrderCarrier($orderId, $packNumbers[0]);
            }

            $processedOrders[] = [
                'id_order' => $orderId,
                'tracking_numbers' => $packNumbers,
                'manifest_id' => $manifestId,
            ];
        }

        return ['success' => true, 'orders' => $processedOrders];
    }

    private function loadVenipakOrders(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $orderIds));

        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` IN (' . $ids . ')'
        ) ?: [];
    }

    private function loadWarehouse(int $warehouseId, int $fallbackShopId = 0): ?array
    {
        if ($warehouseId <= 0) {
            // Default-warehouse fallback: prefer the shop's own default,
            // then a legacy global (id_shop=0) entry. Never silently fall
            // through to another shop's default — that would leak sender
            // addresses across shops in multistore.
            $row = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
                 WHERE `is_default` = 1
                   AND (`id_shop` = ' . $fallbackShopId . ' OR `id_shop` = 0)
                 ORDER BY (`id_shop` = ' . $fallbackShopId . ') DESC'
            );

            return $row ?: null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
             WHERE `id_ppvenipak_warehouse` = ' . (int) $warehouseId
        );

        return $row ?: null;
    }

    /**
     * Pre-flight check that the warehouse has the fields Venipak requires for
     * the consignor block (codes 70–74). Returns a list of human-readable field
     * names that are blank — empty list means we're good to ship.
     *
     * Public + static so AdminHooks can warn the merchant on the order side
     * panel before they even click "Generate label".
     *
     * @return string[]
     */
    public static function validateWarehouseFields(array $warehouse): array
    {
        $required = [
            'name' => 'Name',
            'country_code' => 'Country',
            'city' => 'City',
            'address' => 'Address',
            'zip_code' => 'Postal code',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            if (empty(trim((string) ($warehouse[$key] ?? '')))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    private function findOrCreateManifest(int $warehouseId, ?int $idShop = null): string
    {
        $db = Db::getInstance();
        $today = date('Y-m-d');

        // Look for today's open manifest for this warehouse
        $existing = $db->getValue(
            'SELECT `manifest_id` FROM `' . _DB_PREFIX_ . 'ppvenipak_manifest`
             WHERE `id_warehouse` = ' . (int) $warehouseId . '
             AND `closed` = 0
             AND DATE(`date_add`) = \'' . pSQL($today) . '\'
             ORDER BY `id_ppvenipak_manifest` DESC'
        );

        if ($existing) {
            return (string) $existing;
        }

        return $this->manifestGenerator->generate($idShop);
    }

    private function isPickupCarrier(int $carrierRef): bool
    {
        $pickupRef = (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF');

        return $carrierRef === $pickupRef;
    }


    private function buildPickupConsignee(array $vo, Address $address, Customer $customer): array
    {
        $terminalInfo = json_decode((string) ($vo['terminal_info'] ?? '{}'), true) ?: [];

        return [
            'name' => $terminalInfo['name'] ?? '',
            'company_code' => $terminalInfo['code'] ?? '',
            'country' => $terminalInfo['country_code'] ?? $vo['country_code'],
            'city' => $terminalInfo['city'] ?? '',
            'address' => $terminalInfo['address'] ?? '',
            'post_code' => $this->postcodeFormatter->format(
                $terminalInfo['zip'] ?? '',
                $terminalInfo['country_code'] ?? $vo['country_code']
            ),
            'contact_person' => trim($address->firstname . ' ' . $address->lastname),
            'contact_tel' => $address->phone_mobile ?: $address->phone,
            'contact_email' => $customer->email,
        ];
    }

    private function buildCourierConsignee(Address $address, Customer $customer, Country $country): array
    {
        $name = trim($address->firstname . ' ' . $address->lastname);
        if (!empty($address->company)) {
            $name = $address->company;
        }

        $fullAddress = trim($address->address1 . ' ' . ($address->address2 ?? ''));

        return [
            'name' => $name,
            'company_code' => $address->dni ?: ($address->vat_number ?: ''),
            'country' => $country->iso_code,
            'city' => $address->city,
            'address' => $fullAddress,
            'post_code' => $this->postcodeFormatter->format($address->postcode, $country->iso_code),
            'contact_person' => trim($address->firstname . ' ' . $address->lastname),
            'contact_tel' => $address->phone_mobile ?: $address->phone,
            'contact_email' => $customer->email,
        ];
    }

    private function buildAttribute(array $vo, array $extraFields, bool $enableReturn, int $returnDays, Currency $currency): array
    {
        $deliveryType = $extraFields['delivery_time'] ?? 'nwd';
        if (empty($deliveryType)) {
            $deliveryType = 'nwd';
        }

        $attribute = [
            'delivery_type' => $deliveryType,
            'return_doc' => '0',
        ];

        // Extra fields for courier
        if (!empty($extraFields['door_code'])) {
            $attribute['comment_door_code'] = $extraFields['door_code'];
        }
        if (!empty($extraFields['cabinet_number'])) {
            $attribute['comment_office_no'] = $extraFields['cabinet_number'];
        }
        if (!empty($extraFields['warehouse_number'])) {
            $attribute['comment_warehous_no'] = $extraFields['warehouse_number'];
        }
        if (!empty($extraFields['carrier_call'])) {
            $attribute['comment_call'] = '1';
        }

        // COD
        if ((int) $vo['is_cod'] && (float) $vo['cod_amount'] > 0) {
            $attribute['cod'] = number_format((float) $vo['cod_amount'], 2, '.', '');
            $attribute['cod_type'] = $currency->iso_code;
        }

        // Return service
        if ($enableReturn && $returnDays > 0) {
            $attribute['return_service'] = (string) $returnDays;
        }

        return $attribute;
    }

    private function calculateOrderVolume(Order $order): float
    {
        $products = $order->getProducts();
        $volume = 0.0;

        if (!is_array($products)) {
            return $volume;
        }

        foreach ($products as $product) {
            $w = (float) ($product['width'] ?? 0);
            $h = (float) ($product['height'] ?? 0);
            $d = (float) ($product['depth'] ?? 0);
            $qty = (int) ($product['product_quantity'] ?? 1);

            // Convert cm to meters for volume calculation (cm³ → m³)
            $volume += ($w / 100) * ($h / 100) * ($d / 100) * $qty;
        }

        return $volume;
    }

    private function saveManifestRecord(string $manifestId, int $warehouseId, array $venipakOrders): void
    {
        $db = Db::getInstance();

        // Check if manifest already exists in DB
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_manifest`
             WHERE `manifest_id` = \'' . pSQL($manifestId) . '\''
        );

        $totalWeight = 0.0;
        foreach ($venipakOrders as $vo) {
            $totalWeight += (float) $vo['order_weight'];
        }

        if ($exists) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'ppvenipak_manifest`
                 SET `total_weight` = `total_weight` + ' . $totalWeight . '
                 WHERE `manifest_id` = \'' . pSQL($manifestId) . '\''
            );
        } else {
            $db->insert('ppvenipak_manifest', [
                'manifest_id' => pSQL($manifestId),
                'id_shop' => (int) Configuration::get('PS_SHOP_DEFAULT'),
                'id_warehouse' => $warehouseId,
                'total_weight' => $totalWeight,
                'closed' => 0,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function saveOrderResult(int $venipakOrderId, int $orderId, string $manifestId, array $packNumbers): void
    {
        Db::getInstance()->update('ppvenipak_order', [
            'manifest_id' => pSQL($manifestId),
            'tracking_numbers' => pSQL(json_encode($packNumbers)),
            'status' => 'registered',
            'error' => '',
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_ppvenipak_order` = ' . $venipakOrderId);

        // Order is officially shipped — discard the request/response logs
        // for it. They only mattered while we were debugging failures; once
        // tracking numbers exist the technical payload is dead weight. We
        // pass the PS order reference too so legacy rows logged with
        // id_order=0 (before the per-order plumbing) get wiped via the
        // <doc_no> match in the stored request body.
        $orderRef = (string) Db::getInstance()->getValue(
            'SELECT `reference` FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_order` = ' . $orderId
        );

        ApiLogger::clearForOrder($orderId, $orderRef !== '' ? $orderRef : null);
    }

    private function updateOrderStatus(int $venipakOrderId, string $status, string $error = ''): void
    {
        Db::getInstance()->update('ppvenipak_order', [
            'status' => pSQL($status),
            'error' => pSQL($error),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_ppvenipak_order` = ' . $venipakOrderId);
    }

    private function setPrestaShopOrderStatus(int $orderId, string $type): void
    {
        try {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $stateKey = $type === 'ready' ? 'PPVENIPAK_STATE_READY' : 'PPVENIPAK_STATE_ERROR';
            // Read scoped to the order's shop (the mapping may be configured
            // per shop in multistore); Configuration::get falls back to the
            // global value when no per-shop row exists.
            $stateId = (int) Configuration::get($stateKey, null, null, (int) $order->id_shop);

            if ($stateId <= 0) {
                return;
            }

            // Guard against a corrupted mapping pointing at a non-existent
            // order state (e.g. an id left over from a migration). Applying it
            // would push the order into a blank/unknown state — better to leave
            // the order untouched and record why.
            if (!Validate::isLoadedObject(new OrderState($stateId))) {
                ApiLogger::log(
                    'error',
                    'LabelGenerationService::setPrestaShopOrderStatus',
                    sprintf(
                        'PPVENIPAK_STATE_%s is set to order state #%d which does not exist — order #%d left unchanged. Fix the mapping in PPVenipak → Order states.',
                        strtoupper($type),
                        $stateId,
                        $orderId
                    ),
                    ['target_state' => $type, 'state_id' => $stateId],
                    $orderId
                );

                return;
            }

            if ((int) $order->current_state !== $stateId) {
                $order->setCurrentState($stateId);
            }
        } catch (\Throwable $e) {
            ApiLogger::logException(
                'LabelGenerationService::setPrestaShopOrderStatus',
                $e,
                '',
                $orderId,
                ['target_state' => $type]
            );
        }
    }

    private function saveTrackingToOrderCarrier(int $orderId, string $trackingNumber): void
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'order_carrier`
             SET `tracking_number` = \'' . pSQL($trackingNumber) . '\'
             WHERE `id_order` = ' . (int) $orderId . '
             ORDER BY `id_order_carrier` DESC
             LIMIT 1'
        );
    }
}
