<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Db;
use PrestaShop\Module\PPVenipak\Api\CourierInvitationXmlBuilder;
use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;
use PrestaShop\Module\PPVenipak\Service\ApiLogger;
use PrestaShop\Module\PPVenipak\Service\LabelGenerationService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends FrameworkBundleAdminController
{
    /**
     * Generate labels for one or more orders.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function generateLabel(Request $request): JsonResponse
    {
        $orderIds = $this->parseOrderIds($request);

        if (empty($orderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'No order IDs provided.'], 400);
        }

        try {
            $service = new LabelGenerationService();
            $result = $service->generateForOrders($orderIds);

            return new JsonResponse([
                'success' => $result['success'],
                'results' => $result['results'],
                'errors' => $result['errors'],
                'message' => $result['success']
                    ? sprintf('Labels generated for %d order(s).', count($result['results']))
                    : implode("\n\n", $result['errors']),
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException('OrderController::generateLabel', $e);

            return new JsonResponse([
                'success' => false,
                'message' => 'Label generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Print/download label PDF for an order.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function printLabel(Request $request): Response
    {
        $orderId = (int) $request->query->get('id_order', 0);

        if ($orderId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid order ID.'], 400);
        }

        $trackingNumbers = $this->getTrackingNumbers($orderId);

        if (empty($trackingNumbers)) {
            return new JsonResponse(['success' => false, 'message' => 'No tracking numbers found. Generate labels first.'], 404);
        }

        try {
            $shopId = $this->getOrderShopId($orderId);
            $apiClient = (new VenipakApiClient())->setShopId($shopId ?: null);
            $format = Configuration::get('PPVENIPAK_LABEL_FORMAT', null, null, $shopId ?: null) ?: 'a4';
            $pdf = $apiClient->printLabel($trackingNumbers, $format);

            if (empty($pdf)) {
                return new JsonResponse(['success' => false, 'message' => 'Empty PDF response from API.'], 500);
            }

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="venipak-label-' . $orderId . '.pdf"',
                'Content-Length' => strlen($pdf),
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException('OrderController::printLabel', $e, 'ws/print_label', $orderId);

            return new JsonResponse(['success' => false, 'message' => 'Print failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Print/download manifest PDF.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function printManifest(Request $request): Response
    {
        $manifestId = (string) $request->query->get('manifest_id', '');

        if (empty($manifestId)) {
            return new JsonResponse(['success' => false, 'message' => 'No manifest ID provided.'], 400);
        }

        try {
            $apiClient = new VenipakApiClient();
            $pdf = $apiClient->printManifest($manifestId);

            if (empty($pdf)) {
                return new JsonResponse(['success' => false, 'message' => 'Empty PDF response from API.'], 500);
            }

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="venipak-manifest-' . $manifestId . '.pdf"',
                'Content-Length' => strlen($pdf),
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException(
                'OrderController::printManifest',
                $e,
                'ws/print_list',
                0,
                ['manifest_id' => $manifestId]
            );

            return new JsonResponse(['success' => false, 'message' => 'Print failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Close a manifest (mark as closed, no more orders can be added).
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function closeManifest(Request $request): JsonResponse
    {
        $manifestId = (string) $request->request->get('manifest_id', '');

        if (empty($manifestId)) {
            return new JsonResponse(['success' => false, 'message' => 'No manifest ID provided.'], 400);
        }

        try {
            $db = Db::getInstance();
            $db->update('ppvenipak_manifest', [
                'closed' => 1,
            ], '`manifest_id` = \'' . pSQL($manifestId) . '\'');

            return new JsonResponse([
                'success' => true,
                'message' => 'Manifest closed successfully.',
                'manifest_id' => $manifestId,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Request courier pickup for a manifest.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function callCourier(Request $request): JsonResponse
    {
        $manifestId = (string) $request->request->get('manifest_id', '');
        $hourFrom = (string) $request->request->get('hour_from', '');
        $minFrom = (string) $request->request->get('min_from', '0');
        $hourTo = (string) $request->request->get('hour_to', '');
        $minTo = (string) $request->request->get('min_to', '0');
        $comment = mb_substr(trim((string) $request->request->get('comment', '')), 0, 50);

        if (empty($manifestId) || empty($hourFrom) || empty($hourTo)) {
            return new JsonResponse(['success' => false, 'message' => 'Manifest ID and time window are required.'], 400);
        }

        // Validate time gap (minimum 2 hours)
        $fromMinutes = ((int) $hourFrom * 60) + (int) $minFrom;
        $toMinutes = ((int) $hourTo * 60) + (int) $minTo;
        if (($toMinutes - $fromMinutes) < 120) {
            return new JsonResponse(['success' => false, 'message' => 'Minimum 2-hour gap required between pickup times.'], 400);
        }

        // Same-day cutoff: Venipak rejects same-day pickup requests submitted after 15:00.
        $pickupDate = (string) $request->request->get('date', date('Y-m-d'));
        if ($pickupDate === date('Y-m-d') && (int) date('H') >= 15) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Same-day courier pickup must be requested before 15:00. Please book for tomorrow.',
            ], 400);
        }

        try {
            // Load manifest and warehouse data
            $manifest = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_manifest`
                 WHERE `manifest_id` = \'' . pSQL($manifestId) . '\''
            );

            if (!$manifest) {
                return new JsonResponse(['success' => false, 'message' => 'Manifest not found.'], 404);
            }

            $sender = $this->getSenderData((int) $manifest['id_warehouse']);
            if ($sender === null) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No warehouse configured. Add a default warehouse in the Warehouses tab before booking a courier.',
                ], 400);
            }

            $builder = new CourierInvitationXmlBuilder();
            $xml = $builder->build([
                'sender' => $sender,
                'weight' => (float) $manifest['total_weight'],
                'volume' => 0.05, // Default volume estimate
                'date' => $pickupDate,
                'hour_from' => str_pad($hourFrom, 2, '0', STR_PAD_LEFT),
                'min_from' => str_pad($minFrom, 2, '0', STR_PAD_LEFT),
                'hour_to' => str_pad($hourTo, 2, '0', STR_PAD_LEFT),
                'min_to' => str_pad($minTo, 2, '0', STR_PAD_LEFT),
                'comment' => $comment,
            ]);

            $apiClient = new VenipakApiClient();
            $response = $apiClient->submitShipmentXml($xml);

            if (isset($response['error'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => (string) $response['error'],
                    'errors' => $response['errors'] ?? [],
                ], 500);
            }

            // Update manifest with courier call details
            $arrivalFrom = date('Y-m-d') . ' ' . str_pad($hourFrom, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minFrom, 2, '0', STR_PAD_LEFT) . ':00';
            $arrivalTo = date('Y-m-d') . ' ' . str_pad($hourTo, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minTo, 2, '0', STR_PAD_LEFT) . ':00';

            Db::getInstance()->update('ppvenipak_manifest', [
                'call_comment' => pSQL($comment),
                'arrival_from' => pSQL($arrivalFrom),
                'arrival_to' => pSQL($arrivalTo),
            ], '`manifest_id` = \'' . pSQL($manifestId) . '\'');

            return new JsonResponse([
                'success' => true,
                'message' => 'Courier pickup requested successfully.',
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException(
                'OrderController::callCourier',
                $e,
                'import/send.php',
                0,
                ['manifest_id' => $manifestId]
            );

            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get tracking info for an order.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function tracking(Request $request): JsonResponse
    {
        $orderId = (int) $request->query->get('id_order', 0);

        if ($orderId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid order ID.'], 400);
        }

        $trackingNumbers = $this->getTrackingNumbers($orderId);

        if (empty($trackingNumbers)) {
            return new JsonResponse([
                'success' => true,
                'tracking' => [],
                'message' => 'No tracking numbers found.',
            ]);
        }

        try {
            $apiClient = (new VenipakApiClient())->setShopId($this->getOrderShopId($orderId) ?: null);
            $allTracking = [];

            foreach ($trackingNumbers as $trackingNo) {
                $events = $apiClient->getTracking($trackingNo);
                $allTracking[$trackingNo] = [
                    'events' => $events,
                    'state' => $apiClient->derivePackState($events),
                ];
            }

            // Update local status from latest tracking data
            $this->updateLocalStatus($orderId, $allTracking);

            return new JsonResponse([
                'success' => true,
                'tracking' => $allTracking,
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException(
                'OrderController::tracking',
                $e,
                'tracking.venipak.com/api/v1/events',
                $orderId
            );

            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get Venipak order data for the admin sidebar panel.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function orderData(Request $request): JsonResponse
    {
        $orderId = (int) $request->query->get('id_order', 0);

        if ($orderId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid order ID.'], 400);
        }

        $vo = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` = ' . (int) $orderId
        );

        if (!$vo) {
            return new JsonResponse(['success' => false, 'message' => 'Not a Venipak order.'], 404);
        }

        // Load manifest info if exists
        $manifest = null;
        if (!empty($vo['manifest_id'])) {
            $manifest = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_manifest`
                 WHERE `manifest_id` = \'' . pSQL($vo['manifest_id']) . '\''
            );
        }

        return new JsonResponse([
            'success' => true,
            'order' => [
                'status' => $vo['status'],
                'tracking_numbers' => json_decode((string) ($vo['tracking_numbers'] ?? '[]'), true) ?: [],
                'manifest_id' => $vo['manifest_id'],
                'terminal_info' => json_decode((string) ($vo['terminal_info'] ?? '{}'), true) ?: null,
                'extra_fields' => json_decode((string) ($vo['extra_fields'] ?? '{}'), true) ?: null,
                'order_weight' => (float) $vo['order_weight'],
                'packages' => (int) $vo['packages'],
                'is_cod' => (bool) $vo['is_cod'],
                'cod_amount' => (float) $vo['cod_amount'],
                'error' => $vo['error'] ?? '',
            ],
            'manifest' => $manifest ? [
                'manifest_id' => $manifest['manifest_id'],
                'closed' => (bool) $manifest['closed'],
                'total_weight' => (float) $manifest['total_weight'],
                'arrival_from' => $manifest['arrival_from'],
                'arrival_to' => $manifest['arrival_to'],
            ] : null,
        ]);
    }

    /**
     * Update the editable shipment fields (weight, packages, COD flag) for a
     * Venipak order. Only allowed before the label is generated — once Venipak
     * has the shipment the parcels exist on their side and these values are
     * fixed.
     *
     * Accepts any subset of weight / packages / is_cod. Toggling is_cod
     * recomputes cod_amount from the PrestaShop order total (or zeroes it).
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function updateShipmentDetails(Request $request): JsonResponse
    {
        $orderId = (int) $request->request->get('id_order', 0);

        if ($orderId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid order ID.'], 400);
        }

        $db = Db::getInstance();
        $vo = $db->getRow(
            'SELECT `status`, `id_carrier` FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` = ' . $orderId
        );

        if (!$vo) {
            return new JsonResponse(['success' => false, 'message' => 'Not a Venipak order.'], 404);
        }

        if (!in_array((string) $vo['status'], ['new', 'error'], true)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Shipment details are locked once the label has been generated.',
            ], 409);
        }

        $update = [];

        if ($request->request->has('weight')) {
            $weight = (float) str_replace(',', '.', (string) $request->request->get('weight'));
            if ($weight <= 0 || $weight > 999) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Weight must be between 0.001 and 999 kg.',
                ], 400);
            }
            $update['order_weight'] = $weight;
        }

        if ($request->request->has('packages')) {
            $packages = (int) $request->request->get('packages');
            if ($packages < 1 || $packages > 99) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Package count must be between 1 and 99.',
                ], 400);
            }
            $update['packages'] = $packages;
        }

        if ($request->request->has('is_cod')) {
            $isCod = (int) (bool) $request->request->get('is_cod');
            $update['is_cod'] = $isCod;

            if ($isCod) {
                $orderTotal = (float) $db->getValue(
                    'SELECT `total_paid_tax_incl` FROM `' . _DB_PREFIX_ . 'orders`
                     WHERE `id_order` = ' . $orderId
                );
                $update['cod_amount'] = $orderTotal;
            } else {
                $update['cod_amount'] = 0;
            }
        }

        // Change the pickup point (terminal). Only valid on pickup-point orders;
        // the new terminal_info JSON is rebuilt to the exact shape the checkout
        // writes (see ajax.php::saveTerminalSelection) so label generation and
        // the customer-facing terminal display stay consistent.
        if ($request->request->has('terminal_id')) {
            $pickupRef = (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF');
            if ((int) $vo['id_carrier'] !== $pickupRef) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Pickup point can only be changed on pickup-point orders.',
                ], 400);
            }

            $terminalId = (int) $request->request->get('terminal_id');
            if ($terminalId <= 0) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid pickup point.'], 400);
            }

            // No LIMIT 1 — Db::getRow() appends its own, and a second one is a
            // SQL syntax error ("near 'LIMIT 1'").
            $terminal = $db->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_terminal`
                 WHERE `terminal_id` = ' . $terminalId
            );
            if (!$terminal) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Pickup point not found. Sync terminals and try again.',
                ], 404);
            }

            $update['terminal_id'] = $terminalId;
            $update['country_code'] = (string) $terminal['country_code'];
            $update['terminal_info'] = json_encode([
                'name' => $terminal['name'],
                'code' => $terminal['code'],
                'display_name' => $terminal['display_name'],
                'address' => $terminal['address'],
                'city' => $terminal['city'],
                'zip' => $terminal['zip'],
                'country_code' => $terminal['country_code'],
                'cod_enabled' => (int) $terminal['cod_enabled'],
                'terminal_type' => (int) $terminal['terminal_type'],
            ], JSON_UNESCAPED_UNICODE);
        }

        if (empty($update)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Nothing to update.',
            ], 400);
        }

        $update['date_upd'] = date('Y-m-d H:i:s');
        $db->update('ppvenipak_order', $update, '`id_order` = ' . $orderId);

        return new JsonResponse([
            'success' => true,
            'message' => 'Shipment details updated.',
            'updated' => $update,
            'terminal_changed' => array_key_exists('terminal_info', $update),
        ]);
    }

    /**
     * JSON list of pickup points (terminals) for the order-panel picker, so the
     * merchant can change an order's pickup point. Filtered by country (the
     * order's destination) and an optional free-text query, capped at 50 rows.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function terminals(Request $request): JsonResponse
    {
        // POST body (not query string) — see the route comment: a GET with the
        // search term in the URL was blocked by Cloudflare's WAF on live.
        $country = strtoupper((string) $request->request->get('country', 'LT'));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $country = 'LT';
        }

        $search = trim((string) $request->request->get('q', ''));

        $db = Db::getInstance();
        $where = ['`country_code` = \'' . pSQL($country) . '\''];

        if ($search !== '') {
            $needle = '%' . pSQL($search, true) . '%';
            $where[] = '(`display_name` LIKE \'' . $needle . '\''
                . ' OR `name` LIKE \'' . $needle . '\''
                . ' OR `code` LIKE \'' . $needle . '\''
                . ' OR `city` LIKE \'' . $needle . '\''
                . ' OR `address` LIKE \'' . $needle . '\''
                . ' OR `zip` LIKE \'' . $needle . '\')';
        }

        $rows = $db->executeS(
            'SELECT `terminal_id`, `display_name`, `name`, `address`, `city`, `zip`,
                    `country_code`, `cod_enabled`, `terminal_type`
             FROM `' . _DB_PREFIX_ . 'ppvenipak_terminal`
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY `city` ASC, `display_name` ASC
             LIMIT 50'
        ) ?: [];

        $terminals = array_map(static function (array $t): array {
            return [
                'terminal_id' => (int) $t['terminal_id'],
                'display_name' => (string) $t['display_name'],
                'name' => (string) $t['name'],
                'address' => (string) $t['address'],
                'city' => (string) $t['city'],
                'zip' => (string) $t['zip'],
                'country_code' => (string) $t['country_code'],
                'cod_enabled' => (int) $t['cod_enabled'],
                'terminal_type' => (int) $t['terminal_type'],
            ];
        }, $rows);

        return new JsonResponse(['success' => true, 'terminals' => $terminals]);
    }

    /**
     * Reset a Venipak order so a fresh label can be generated for it.
     * Used when the wrong item was shipped, the parcel came back, etc. —
     * the merchant doesn't want to lose the PrestaShop order, just to
     * issue another shipment for it.
     *
     * Effect:
     *   - Snapshots current tracking_numbers + manifest_id into the
     *     `previous_attempts` JSON list (preserves shipment history).
     *   - Bumps `attempt_count` so the next label-gen sends doc_no=REF-2
     *     (Venipak rejects duplicate doc_no values — see
     *     LabelGenerationService::processWarehouseGroup).
     *   - Clears tracking_numbers, manifest_id, error and resets status
     *     to 'new' so the merchant can click "Send to Venipak" again on
     *     the order panel and the standard flow takes over.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function regenerateLabel(Request $request): JsonResponse
    {
        $orderId = (int) $request->request->get('id_order', 0);

        if ($orderId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid order ID.'], 400);
        }

        $db = Db::getInstance();
        $vo = $db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` = ' . $orderId
        );

        if (!$vo) {
            return new JsonResponse(['success' => false, 'message' => 'Not a Venipak order.'], 404);
        }

        // Refuse to regenerate orders that never earned a label — there's
        // nothing to archive and the merchant should just hit "Send to
        // Venipak" instead.
        $hasLabel = !empty($vo['tracking_numbers']) && $vo['tracking_numbers'] !== '[]';
        if (!$hasLabel) {
            return new JsonResponse([
                'success' => false,
                'message' => 'This order has no existing label — use "Send to Venipak" to generate the first one.',
            ], 409);
        }

        $previous = [];
        if (!empty($vo['previous_attempts'])) {
            $decoded = json_decode((string) $vo['previous_attempts'], true);
            if (is_array($decoded)) {
                $previous = $decoded;
            }
        }

        $previous[] = [
            'attempt' => (int) ($vo['attempt_count'] ?? 1),
            'tracking_numbers' => json_decode((string) $vo['tracking_numbers'], true) ?: [],
            'manifest_id' => (string) ($vo['manifest_id'] ?? ''),
            'archived_at' => date('Y-m-d H:i:s'),
        ];

        $db->update('ppvenipak_order', [
            'attempt_count' => (int) ($vo['attempt_count'] ?? 1) + 1,
            'previous_attempts' => pSQL((string) json_encode($previous, JSON_UNESCAPED_UNICODE), true),
            'tracking_numbers' => null,
            'manifest_id' => null,
            'status' => 'new',
            'error' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_order` = ' . $orderId);

        return new JsonResponse([
            'success' => true,
            'message' => 'Label reset. Click "Send to Venipak" to generate a new label.',
            'attempt_count' => (int) ($vo['attempt_count'] ?? 1) + 1,
        ]);
    }

    private function parseOrderIds(Request $request): array
    {
        // Symfony 6.x: InputBag::get() rejects array defaults — use ::all() to
        // pull a plural value, or ::get() with a scalar default for one value.
        $ids = $request->request->all('order_ids');

        if (empty($ids)) {
            $idsString = (string) $request->request->get('order_ids', '');
            if ($idsString !== '') {
                $ids = json_decode($idsString, true) ?: [];
            }
        }

        // Also accept a single order_id field.
        if (empty($ids)) {
            $singleId = (int) $request->request->get('id_order', 0);
            if ($singleId > 0) {
                $ids = [$singleId];
            }
        }

        return array_map('intval', array_filter((array) $ids));
    }

    /**
     * Shop id of an order, used to bind the Venipak API client to that shop's
     * per-shop account so requests authenticate correctly even from the
     * "All shops" admin context. Returns 0 when the order can't be found.
     */
    private function getOrderShopId(int $orderId): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_order` = ' . (int) $orderId
        );
    }

    private function getTrackingNumbers(int $orderId): array
    {
        $json = Db::getInstance()->getValue(
            'SELECT `tracking_numbers` FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` = ' . (int) $orderId
        );

        if (empty($json)) {
            return [];
        }

        $numbers = json_decode($json, true);

        return is_array($numbers) ? $numbers : [];
    }

    private function updateLocalStatus(int $orderId, array $allTracking): void
    {
        if (empty($allTracking)) {
            return;
        }

        // Get the latest state from the first tracking number
        $first = reset($allTracking);
        if (empty($first['state'])) {
            return;
        }

        $state = $first['state'];
        $packStatus = $state['pack_status'] ?? null;
        if ($packStatus === null) {
            return;
        }

        // Per Postman docs: do not collapse to "delivered" on pack_status=9
        // unless event=Delivered (forwarding/returning/LDG also reach 9).
        $newStatus = $state['is_final']
            ? 'delivered'
            : (strtolower(trim((string) $state['pack_status_text'])) ?: 'in_transit');

        Db::getInstance()->update('ppvenipak_order', [
            'status' => pSQL($newStatus),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_order` = ' . (int) $orderId);

        // Apply the merchant's pack_status → PS order state mapping. Each
        // entry in PPVENIPAK_STATE_* is either 0 ("don't change") or a
        // valid OrderState id. Skip when:
        //   - no mapping configured for this code
        //   - the order already has that state (avoids spamming
        //     order_history rows)
        $mappedStateId = $this->resolvePsStateForPackStatus(
            (int) $packStatus,
            (string) ($state['event'] ?? ''),
            (bool) ($state['is_final'] ?? false),
            $this->getOrderShopId($orderId) ?: null
        );

        if ($mappedStateId > 0) {
            $order = new \Order($orderId);
            if ($order->id && (int) $order->current_state !== $mappedStateId) {
                $order->setCurrentState($mappedStateId);
            }
        }
    }

    /**
     * Translate a Venipak pack_status into a PrestaShop order-state id by
     * reading the mapping the merchant configured on the "Order states"
     * tab. Returns 0 when no mapping exists for the given code (caller
     * skips the state update).
     *
     * Codes 5 and 6 share a single config slot (`AT_PICKUP_POINT`) because
     * Venipak emits both as "parcel arrived at locker / shop, awaiting
     * customer collection" — the merchant rarely wants two different PS
     * states for them.
     */
    private function resolvePsStateForPackStatus(int $packStatus, string $event, bool $isFinal, ?int $idShop = null): int
    {
        // The mapping is configured per shop, so read it for the order's shop —
        // an unscoped read from the "All shops" admin context would pull another
        // shop's (or an empty) mapping and move the order to the wrong state.
        switch ($packStatus) {
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_AT_SENDER:
                return (int) Configuration::get('PPVENIPAK_STATE_AT_SENDER', null, null, $idShop);
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_PICKED_UP:
                return (int) Configuration::get('PPVENIPAK_STATE_PICKED_UP', null, null, $idShop);
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_AT_TERMINAL:
                return (int) Configuration::get('PPVENIPAK_STATE_AT_TERMINAL', null, null, $idShop);
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_OUT_FOR_DELIVERY:
                return (int) Configuration::get('PPVENIPAK_STATE_OUT_FOR_DELIVERY', null, null, $idShop);
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_AT_PICKUP_POINT_5:
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_AT_PICKUP_POINT_6:
                return (int) Configuration::get('PPVENIPAK_STATE_AT_PICKUP_POINT', null, null, $idShop);
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_IN_TRANSIT:
                return (int) Configuration::get('PPVENIPAK_STATE_IN_TRANSIT', null, null, $idShop);
            case \PrestaShop\Module\PPVenipak\Api\VenipakApiClient::PACK_STATUS_DELIVERED:
                // Only honour the "delivered" mapping when Venipak's event
                // text is literally "Delivered" — otherwise pack_status=9
                // can mean forwarding / returning / LDG, none of which are
                // a delivered-to-customer signal.
                return $isFinal ? (int) Configuration::get('PPVENIPAK_STATE_DELIVERED', null, null, $idShop) : 0;
            default:
                return 0;
        }
    }

    /**
     * Resolve a warehouse for a courier-invitation. Tries the given warehouse,
     * then the shop's default warehouse. Returns null when nothing is
     * configured — callers must surface a "no warehouse configured" error.
     */
    private function getSenderData(int $warehouseId): ?array
    {
        $db = Db::getInstance();
        $warehouse = null;

        if ($warehouseId > 0) {
            $warehouse = $db->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
                 WHERE `id_ppvenipak_warehouse` = ' . $warehouseId
            );
        }

        if (!$warehouse) {
            // Per-shop default lookup: prefer the current admin shop, fall
            // back to legacy global (id_shop=0). Never another shop's row.
            $idShop = (int) (\Shop::getContextShopID() ?: 0);
            $warehouse = $db->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
                 WHERE `is_default` = 1
                   AND (`id_shop` = ' . $idShop . ' OR `id_shop` = 0)
                 ORDER BY (`id_shop` = ' . $idShop . ') DESC'
            );
        }

        if (!$warehouse) {
            return null;
        }

        return [
            'name' => $warehouse['name'],
            'company_code' => $warehouse['company_code'],
            'country' => $warehouse['country_code'],
            'city' => $warehouse['city'],
            'address' => $warehouse['address'],
            'post_code' => $warehouse['zip_code'],
            'contact_person' => $warehouse['contact'],
            'contact_tel' => $warehouse['phone'],
        ];
    }
}
