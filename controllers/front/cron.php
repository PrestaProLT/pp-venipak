<?php

declare(strict_types=1);

use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;
use PrestaShop\Module\PPVenipak\Carrier\TerminalSync;
use PrestaShop\Module\PPVenipak\Service\ApiLogger;

class ppvenipakCronModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        // Verify cron token
        $token = Tools::getValue('token');
        $expectedToken = Configuration::get('PPVENIPAK_CRON_TOKEN');

        if (empty($expectedToken) || $token !== $expectedToken) {
            header('HTTP/1.1 403 Forbidden');
            die('Invalid token');
        }

        $action = Tools::getValue('action');

        switch ($action) {
            case 'terminals':
                $this->syncTerminals();
                break;
            case 'tracking':
                $this->syncTracking();
                break;
            case 'cleanup_logs':
                $this->cleanupLogs();
                break;
            default:
                header('HTTP/1.1 400 Bad Request');
                die('Unknown action');
        }
    }

    /**
     * Delete log rows older than PPVENIPAK_LOG_RETENTION_DAYS.
     */
    private function cleanupLogs(): void
    {
        try {
            $retention = (int) Configuration::get('PPVENIPAK_LOG_RETENTION_DAYS') ?: 30;
            $deleted = ApiLogger::cleanup($retention);
            $this->ajaxResponse([
                'success' => true,
                'deleted' => $deleted,
                'retention_days' => $retention,
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException('cron:cleanupLogs', $e);
            $this->ajaxResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync all terminals from the Venipak API.
     */
    private function syncTerminals(): void
    {
        try {
            $apiClient = new VenipakApiClient();
            $terminalSync = new TerminalSync($apiClient);

            $count = $terminalSync->syncAll();

            $this->ajaxResponse([
                'success' => true,
                'message' => sprintf('Synced %d terminals.', $count),
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException('cron:syncTerminals', $e);
            $this->ajaxResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * How many days back the cron looks. Older orders stay manual — they
     * rarely change state spontaneously and polling them on every cron run
     * burns API quota. Customers who need a status refresh on an old order
     * can use the order panel's "Refresh tracking" button.
     */
    private const CRON_LOOKBACK_DAYS = 7;

    /**
     * Sync tracking statuses for active shipments placed within the last
     * CRON_LOOKBACK_DAYS days. Updates both ppvenipak_order.status and the
     * PrestaShop order state per the merchant's mapping (Order states tab).
     */
    private function syncTracking(): void
    {
        try {
            $apiClient = new VenipakApiClient();
            $db = Db::getInstance();
            $table = _DB_PREFIX_ . bqSQL('ppvenipak_order');
            $psOrderTable = _DB_PREFIX_ . 'orders';

            // Active shipments only (skip delivered + new + error), with
            // tracking numbers, placed within the lookback window. Joining
            // on ps_orders gives us the customer-facing date, which is what
            // merchants think of when they say "recent orders".
            $sql = 'SELECT v.`id_ppvenipak_order`, v.`id_order`, v.`tracking_numbers`, v.`status`
                    FROM `' . $table . '` v
                    INNER JOIN `' . $psOrderTable . '` o ON o.`id_order` = v.`id_order`
                    WHERE v.`status` NOT IN (\'delivered\', \'new\', \'error\')
                      AND v.`tracking_numbers` IS NOT NULL
                      AND v.`tracking_numbers` <> \'\'
                      AND v.`tracking_numbers` <> \'[]\'
                      AND o.`date_add` >= DATE_SUB(NOW(), INTERVAL ' . self::CRON_LOOKBACK_DAYS . ' DAY)';

            $orders = $db->executeS($sql);

            if (!is_array($orders)) {
                $orders = [];
            }

            $updated = 0;
            $stateChanges = 0;
            $errors = 0;

            foreach ($orders as $order) {
                $trackingNumbers = json_decode((string) $order['tracking_numbers'], true);

                if (!is_array($trackingNumbers) || empty($trackingNumbers)) {
                    continue;
                }

                $firstTracking = (string) $trackingNumbers[0];

                try {
                    $events = $apiClient->getTracking($firstTracking);

                    if (empty($events)) {
                        continue;
                    }

                    $state = $apiClient->derivePackState($events);
                    if ($state['pack_status'] === null) {
                        continue;
                    }

                    // Per Postman docs, do not mark delivered on pack_status=9
                    // unless event=Delivered (forwarding/returning/LDG also hit 9).
                    $newStatus = $state['is_final']
                        ? 'delivered'
                        : (strtolower(trim((string) $state['pack_status_text'])) ?: 'in_transit');

                    if ($newStatus !== $order['status']) {
                        $db->update(
                            bqSQL('ppvenipak_order'),
                            [
                                'status' => pSQL($newStatus),
                                'date_upd' => date('Y-m-d H:i:s'),
                            ],
                            '`id_ppvenipak_order` = ' . (int) $order['id_ppvenipak_order']
                        );

                        ++$updated;
                    }

                    // Apply the merchant's pack_status -> PS order state
                    // mapping (Order states tab). Same semantics as the
                    // order panel's Refresh-tracking button.
                    if ($this->applyPsStateMapping(
                        (int) $order['id_order'],
                        (int) $state['pack_status'],
                        (bool) ($state['is_final'] ?? false)
                    )) {
                        ++$stateChanges;
                    }
                } catch (\Throwable $e) {
                    ApiLogger::logException(
                        'cron:syncTracking',
                        $e,
                        'tracking.venipak.com/api/v1/events',
                        (int) $order['id_order'],
                        ['tracking_no' => $firstTracking]
                    );
                    ++$errors;
                }
            }

            $this->ajaxResponse([
                'success' => true,
                'message' => sprintf(
                    'Processed %d orders within last %d days: %d tracking updates, %d order-state changes, %d errors.',
                    count($orders),
                    self::CRON_LOOKBACK_DAYS,
                    $updated,
                    $stateChanges,
                    $errors
                ),
                'total' => count($orders),
                'lookback_days' => self::CRON_LOOKBACK_DAYS,
                'updated' => $updated,
                'state_changes' => $stateChanges,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            ApiLogger::logException('cron:syncTracking', $e);
            $this->ajaxResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply the merchant's pack_status -> PS order-state mapping if one is
     * configured for the given code. Returns true when the order state was
     * actually changed (so the cron summary can count real state moves
     * vs no-ops).
     */
    private function applyPsStateMapping(int $idOrder, int $packStatus, bool $isFinal): bool
    {
        $configKey = match ($packStatus) {
            VenipakApiClient::PACK_STATUS_AT_SENDER         => 'PPVENIPAK_STATE_AT_SENDER',
            VenipakApiClient::PACK_STATUS_PICKED_UP         => 'PPVENIPAK_STATE_PICKED_UP',
            VenipakApiClient::PACK_STATUS_AT_TERMINAL       => 'PPVENIPAK_STATE_AT_TERMINAL',
            VenipakApiClient::PACK_STATUS_OUT_FOR_DELIVERY  => 'PPVENIPAK_STATE_OUT_FOR_DELIVERY',
            VenipakApiClient::PACK_STATUS_AT_PICKUP_POINT_5,
            VenipakApiClient::PACK_STATUS_AT_PICKUP_POINT_6 => 'PPVENIPAK_STATE_AT_PICKUP_POINT',
            VenipakApiClient::PACK_STATUS_IN_TRANSIT        => 'PPVENIPAK_STATE_IN_TRANSIT',
            VenipakApiClient::PACK_STATUS_DELIVERED         => $isFinal ? 'PPVENIPAK_STATE_DELIVERED' : null,
            default                                         => null,
        };

        if ($configKey === null) {
            return false;
        }

        $stateId = (int) Configuration::get($configKey);
        if ($stateId <= 0) {
            return false;
        }

        $order = new Order($idOrder);
        if (!$order->id || (int) $order->current_state === $stateId) {
            return false;
        }

        $order->setCurrentState($stateId);

        return true;
    }

    /**
     * Output a JSON response and terminate.
     *
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     */
    private function ajaxResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
