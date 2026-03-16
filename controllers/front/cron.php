<?php

declare(strict_types=1);

use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;
use PrestaShop\Module\PPVenipak\Carrier\TerminalSync;

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
            default:
                header('HTTP/1.1 400 Bad Request');
                die('Unknown action');
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
            $this->ajaxResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync tracking statuses for all undelivered orders with tracking numbers.
     */
    private function syncTracking(): void
    {
        try {
            $apiClient = new VenipakApiClient();
            $db = Db::getInstance();
            $table = _DB_PREFIX_ . bqSQL('ppvenipak_order');

            // Query orders that are not delivered and have tracking numbers
            $sql = 'SELECT `id_ppvenipak_order`, `id_order`, `tracking_numbers`, `status`
                    FROM `' . $table . '`
                    WHERE `status` != \'delivered\'
                    AND `tracking_numbers` IS NOT NULL
                    AND `tracking_numbers` != \'\'
                    AND `tracking_numbers` != \'[]\'';

            $orders = $db->executeS($sql);

            if (!is_array($orders)) {
                $orders = [];
            }

            $updated = 0;
            $errors = 0;

            foreach ($orders as $order) {
                $trackingNumbers = json_decode((string) $order['tracking_numbers'], true);

                if (!is_array($trackingNumbers) || empty($trackingNumbers)) {
                    continue;
                }

                // Use the first tracking number to get the latest status
                $firstTracking = (string) $trackingNumbers[0];

                try {
                    $trackingData = $apiClient->getTracking($firstTracking);

                    if (empty($trackingData)) {
                        continue;
                    }

                    // Get the last (most recent) tracking entry
                    $lastEntry = end($trackingData);
                    $newStatus = strtolower(trim((string) ($lastEntry['status'] ?? '')));

                    if ($newStatus === '') {
                        continue;
                    }

                    // Only update if status has changed
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
                } catch (\Throwable $e) {
                    ++$errors;
                }
            }

            $this->ajaxResponse([
                'success' => true,
                'message' => sprintf('Processed %d orders: %d updated, %d errors.', count($orders), $updated, $errors),
                'total' => count($orders),
                'updated' => $updated,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            $this->ajaxResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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
