<?php

declare(strict_types=1);

use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;
use PrestaShop\Module\PPVenipak\Carrier\TerminalSync;

class ppvenipakAjaxModuleFrontController extends ModuleFrontController
{
    /** @var array Valid delivery time values */
    private const VALID_DELIVERY_TIMES = [
        'nwd',
        'nwd10',
        'nwd12',
        'nwd8_14',
        'nwd14_17',
        'nwd18_22',
    ];

    public function initContent(): void
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'getTerminals':
                $this->getTerminals();
                break;
            case 'saveTerminal':
                $this->saveTerminalSelection();
                break;
            case 'saveExtraFields':
                $this->saveExtraFields();
                break;
            default:
                $this->ajaxResponse(['error' => 'Unknown action'], 400);
        }
    }

    /**
     * Get filtered terminals for a country.
     * Filters by cart weight and product dimensions.
     */
    private function getTerminals(): void
    {
        $countryCode = strtoupper(trim((string) Tools::getValue('country', '')));

        if ($countryCode === '') {
            $this->ajaxResponse(['error' => 'Country code is required.'], 400);
        }

        try {
            $apiClient = new VenipakApiClient();
            $terminalSync = new TerminalSync($apiClient);

            $city = Tools::getValue('city', null);
            $postcode = Tools::getValue('postcode', null);

            $terminals = $terminalSync->getTerminals(
                $countryCode,
                !empty($city) ? (string) $city : null,
                !empty($postcode) ? (string) $postcode : null
            );

            // Get cart weight and product dimensions for filtering
            $cart = $this->context->cart;

            if ($cart !== null && Validate::isLoadedObject($cart)) {
                $totalWeight = (float) $cart->getTotalWeight();
                $products = $this->getCartProductDimensions($cart);

                $terminals = $terminalSync->filterByConstraints($terminals, $totalWeight, $products);
            }

            $this->ajaxResponse([
                'success' => true,
                'terminals' => $terminals,
                'count' => count($terminals),
            ]);
        } catch (\Throwable $e) {
            $this->ajaxResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save terminal selection for the current cart.
     * Creates or updates the ppvenipak_order row.
     */
    private function saveTerminalSelection(): void
    {
        $cart = $this->context->cart;

        if ($cart === null || !Validate::isLoadedObject($cart)) {
            $this->ajaxResponse(['error' => 'No active cart.'], 400);
        }

        $terminalId = (int) Tools::getValue('terminal_id', 0);

        if ($terminalId <= 0) {
            $this->ajaxResponse(['error' => 'Invalid terminal ID.'], 400);
        }

        // Load terminal info from DB
        $apiClient = new VenipakApiClient();
        $terminalSync = new TerminalSync($apiClient);
        $terminal = $terminalSync->getTerminalById($terminalId);

        if ($terminal === null) {
            $this->ajaxResponse(['error' => 'Terminal not found.'], 404);
        }

        // Build terminal info JSON
        $terminalInfo = json_encode([
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

        $db = Db::getInstance();
        $table = bqSQL('ppvenipak_order');
        $idCart = (int) $cart->id;
        $now = date('Y-m-d H:i:s');

        // Check if row already exists for this cart
        $existing = $db->getValue(
            'SELECT `id_ppvenipak_order` FROM `' . _DB_PREFIX_ . $table . '`
             WHERE `id_cart` = ' . $idCart
        );

        if ($existing) {
            $db->update($table, [
                'terminal_id' => $terminalId,
                'terminal_info' => pSQL((string) $terminalInfo),
                'country_code' => pSQL($terminal['country_code']),
                'date_upd' => pSQL($now),
            ], '`id_ppvenipak_order` = ' . (int) $existing);
        } else {
            $db->insert($table, [
                'id_cart' => $idCart,
                'terminal_id' => $terminalId,
                'terminal_info' => pSQL((string) $terminalInfo),
                'country_code' => pSQL($terminal['country_code']),
                'status' => 'new',
                'date_add' => pSQL($now),
                'date_upd' => pSQL($now),
            ]);
        }

        $this->ajaxResponse([
            'success' => true,
            'terminal_id' => $terminalId,
            'display_name' => $terminal['display_name'],
        ]);
    }

    /**
     * Save extra courier fields (door code, cabinet number, etc.) for the current cart.
     */
    private function saveExtraFields(): void
    {
        $cart = $this->context->cart;

        if ($cart === null || !Validate::isLoadedObject($cart)) {
            $this->ajaxResponse(['error' => 'No active cart.'], 400);
        }

        // Collect and validate extra fields
        $doorCode = trim((string) Tools::getValue('door_code', ''));
        $cabinetNumber = trim((string) Tools::getValue('cabinet_number', ''));
        $warehouseNumber = trim((string) Tools::getValue('warehouse_number', ''));
        $deliveryTime = trim((string) Tools::getValue('delivery_time', ''));
        $carrierCall = (int) Tools::getValue('carrier_call', 0);

        // Validate max length for text fields
        $errors = [];

        if (mb_strlen($doorCode) > 10) {
            $errors[] = 'Door code must not exceed 10 characters.';
        }

        if (mb_strlen($cabinetNumber) > 10) {
            $errors[] = 'Cabinet number must not exceed 10 characters.';
        }

        if (mb_strlen($warehouseNumber) > 10) {
            $errors[] = 'Warehouse number must not exceed 10 characters.';
        }

        // Validate delivery time if provided
        if ($deliveryTime !== '' && !in_array($deliveryTime, self::VALID_DELIVERY_TIMES, true)) {
            $errors[] = 'Invalid delivery time value.';
        }

        if (!empty($errors)) {
            $this->ajaxResponse(['error' => implode(' ', $errors)], 400);
        }

        // Build extra fields JSON
        $extraFields = json_encode([
            'door_code' => $doorCode,
            'cabinet_number' => $cabinetNumber,
            'warehouse_number' => $warehouseNumber,
            'delivery_time' => $deliveryTime,
            'carrier_call' => $carrierCall ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);

        $db = Db::getInstance();
        $table = bqSQL('ppvenipak_order');
        $idCart = (int) $cart->id;
        $now = date('Y-m-d H:i:s');

        // Check if row already exists for this cart
        $existing = $db->getValue(
            'SELECT `id_ppvenipak_order` FROM `' . _DB_PREFIX_ . $table . '`
             WHERE `id_cart` = ' . $idCart
        );

        if ($existing) {
            $db->update($table, [
                'extra_fields' => pSQL((string) $extraFields),
                'date_upd' => pSQL($now),
            ], '`id_ppvenipak_order` = ' . (int) $existing);
        } else {
            $db->insert($table, [
                'id_cart' => $idCart,
                'extra_fields' => pSQL((string) $extraFields),
                'status' => 'new',
                'date_add' => pSQL($now),
                'date_upd' => pSQL($now),
            ]);
        }

        $this->ajaxResponse([
            'success' => true,
            'extra_fields' => json_decode((string) $extraFields, true),
        ]);
    }

    /**
     * Get product dimensions from the current cart.
     *
     * @param Cart $cart
     * @return array Array of products with width, height, depth (cm) and quantity
     */
    private function getCartProductDimensions(Cart $cart): array
    {
        $products = $cart->getProducts();
        $dimensions = [];

        if (!is_array($products)) {
            return $dimensions;
        }

        foreach ($products as $product) {
            $dimensions[] = [
                'width' => (float) ($product['width'] ?? 0),
                'height' => (float) ($product['height'] ?? 0),
                'depth' => (float) ($product['depth'] ?? 0),
                'quantity' => (int) ($product['cart_quantity'] ?? 1),
            ];
        }

        return $dimensions;
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
