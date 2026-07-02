<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Hooks;

use Carrier;
use Configuration;
use Db;
use Order;

trait AdminHooks
{
    /**
     * displayAdminOrderMainBottom hook — renders Venipak panel beneath the
     * order's main column. Picked over displayAdminOrderSide because the side
     * column is too narrow for the action buttons + tracking list.
     */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $orderId = (int) ($params['id_order'] ?? 0);

        if ($orderId <= 0) {
            return '';
        }

        // Check if this is a Venipak order
        $vo = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` = ' . $orderId
        );

        if (!$vo) {
            return '';
        }

        $trackingNumbers = json_decode((string) ($vo['tracking_numbers'] ?? '[]'), true) ?: [];
        $terminalInfo = json_decode((string) ($vo['terminal_info'] ?? '{}'), true) ?: null;
        $extraFields = json_decode((string) ($vo['extra_fields'] ?? '{}'), true) ?: null;

        // Load manifest info
        $manifest = null;
        if (!empty($vo['manifest_id'])) {
            $manifest = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_manifest`
                 WHERE `manifest_id` = \'' . pSQL($vo['manifest_id']) . '\''
            );
        }

        // Determine carrier type
        $pickupRef = (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF');
        $isPickup = (int) $vo['id_carrier'] === $pickupRef;

        // Resolve the warehouse this order will ship from and pre-validate it
        // — surfaces missing required fields here before the merchant clicks
        // "Generate label" and gets a Venipak code 70-74 rejection.
        // Look up the order's shop so the per-shop default warehouse is
        // resolved correctly in multistore setups.
        $orderShopId = (int) Db::getInstance()->getValue(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_order` = ' . $orderId
        );
        $warehouseInfo = $this->resolveWarehouseForOrder((int) ($vo['id_warehouse'] ?? 0), $orderShopId);

        // Build route URLs for AJAX
        $router = $this->get('router');

        $twig = $this->get('twig');

        $previousAttempts = [];
        if (!empty($vo['previous_attempts'])) {
            $decoded = json_decode((string) $vo['previous_attempts'], true);
            if (is_array($decoded)) {
                $previousAttempts = $decoded;
            }
        }

        return $twig->render('@Modules/ppvenipak/views/templates/admin/order_panel.html.twig', [
            'venipak_order' => [
                'status' => $vo['status'],
                'tracking_numbers' => $trackingNumbers,
                'manifest_id' => $vo['manifest_id'],
                'order_weight' => (float) $vo['order_weight'],
                'packages' => (int) $vo['packages'],
                'is_cod' => (bool) $vo['is_cod'],
                'cod_amount' => (float) $vo['cod_amount'],
                'error' => $vo['error'] ?? '',
                'is_pickup' => $isPickup,
                'terminal_info' => $terminalInfo,
                'country_code' => (string) ($vo['country_code'] ?? ($terminalInfo['country_code'] ?? '')),
                'extra_fields' => $extraFields,
                'attempt_count' => (int) ($vo['attempt_count'] ?? 1),
                'previous_attempts' => $previousAttempts,
            ],
            'manifest' => $manifest ? [
                'manifest_id' => $manifest['manifest_id'],
                'closed' => (bool) $manifest['closed'],
                'total_weight' => (float) $manifest['total_weight'],
                'arrival_from' => $manifest['arrival_from'],
                'arrival_to' => $manifest['arrival_to'],
            ] : null,
            'warehouse' => $warehouseInfo,
            'id_order' => $orderId,
            'urls' => [
                'generate_label' => $router->generate('ps_ppvenipak_generate_label'),
                'print_label' => $router->generate('ps_ppvenipak_print_label'),
                'print_manifest' => $router->generate('ps_ppvenipak_print_manifest'),
                'close_manifest' => $router->generate('ps_ppvenipak_close_manifest'),
                'call_courier' => $router->generate('ps_ppvenipak_call_courier'),
                'tracking' => $router->generate('ps_ppvenipak_tracking'),
                'warehouses' => $router->generate('ps_ppvenipak_warehouses'),
                'manifests' => $router->generate('ps_ppvenipak_manifests'),
                'update_shipment' => $router->generate('ps_ppvenipak_update_shipment'),
                'regenerate_label' => $router->generate('ps_ppvenipak_regenerate_label'),
                'order_terminals' => $router->generate('ps_ppvenipak_order_terminals'),
            ],
            'tracking_base_url' => 'https://go.venipak.lt/ws/tracking?type=1&output=csv&code=',
        ]);
    }

    /**
     * Resolve the warehouse for an order (specific id_warehouse → default →
     * none) and run the same field validation the label generator uses, so
     * the order side panel can warn before the API call.
     *
     * @return array{
     *     id: int, name: string, exists: bool,
     *     missing_fields: string[], edit_url: string,
     * }|null
     */
    private function resolveWarehouseForOrder(int $idWarehouse, int $idShop = 0): ?array
    {
        $db = Db::getInstance();
        $row = null;

        if ($idWarehouse > 0) {
            $row = $db->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
                 WHERE `id_ppvenipak_warehouse` = ' . $idWarehouse
            );
        }

        // Fall back to the order's shop default first, then to a global
        // (id_shop=0) legacy default — never to another shop's default,
        // which would silently leak addresses across shops in multistore.
        if (!$row) {
            $row = $db->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
                 WHERE `is_default` = 1
                   AND (`id_shop` = ' . $idShop . ' OR `id_shop` = 0)
                 ORDER BY (`id_shop` = ' . $idShop . ') DESC'
            );
        }

        $editUrl = '';
        try {
            $router = $this->get('router');
            if ($row && !empty($row['id_ppvenipak_warehouse'])) {
                $editUrl = $router->generate('ps_ppvenipak_warehouse_edit', [
                    'id' => (int) $row['id_ppvenipak_warehouse'],
                ]);
            } else {
                $editUrl = $router->generate('ps_ppvenipak_warehouses');
            }
        } catch (\Throwable $e) {
            // If routing isn't available in this context, omit the link.
            $editUrl = '';
        }

        if (!$row) {
            return [
                'id' => 0,
                'name' => '',
                'exists' => false,
                'missing_fields' => ['(no warehouse configured)'],
                'edit_url' => $editUrl,
            ];
        }

        return [
            'id' => (int) $row['id_ppvenipak_warehouse'],
            'name' => (string) ($row['name'] ?? ''),
            'exists' => true,
            'missing_fields' => \PrestaShop\Module\PPVenipak\Service\LabelGenerationService::validateWarehouseFields($row),
            'edit_url' => $editUrl,
        ];
    }

    /**
     * displayBackOfficeHeader hook — load admin CSS/JS on order pages and on
     * every PPVenipak admin route.
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        $controller = $this->context->controller;

        if (!$controller) {
            return '';
        }

        $controllerName = $controller->php_self ?? '';
        $routeName = '';

        if (method_exists($controller, 'getRequest')) {
            $request = $controller->getRequest();
            if ($request) {
                $routeName = (string) $request->attributes->get('_route', '');
            }
        }

        $isOrderPage = $controllerName === 'AdminOrders'
            || $routeName === 'admin_orders_view'
            || str_contains($routeName, 'admin_order');

        $isPpvenipakPage = str_starts_with($routeName, 'ps_ppvenipak_');

        if (!$isOrderPage && !$isPpvenipakPage) {
            return '';
        }

        $moduleUri = $this->getPathUri();
        $moduleDir = _PS_MODULE_DIR_ . 'ppvenipak/';

        // Cache-bust on file mtime so updated admin JS/CSS reloads without a
        // hard refresh (these tags carry no version otherwise and browsers
        // cache them indefinitely).
        $asset = static function (string $relPath) use ($moduleUri, $moduleDir): string {
            $full = $moduleDir . $relPath;
            $version = @filemtime($full) ?: 0;

            return $moduleUri . $relPath . '?v=' . $version;
        };

        $output = '';

        if ($isPpvenipakPage) {
            $output .= '<link rel="stylesheet" href="' . $asset('views/css/admin/ppvenipak-admin.css') . '">';
            $output .= '<script src="' . $asset('views/js/admin/ppvenipak-admin.js') . '" defer></script>';
        }

        if ($isOrderPage) {
            $output .= '<link rel="stylesheet" href="' . $asset('views/css/admin/order.css') . '">';
            $output .= '<script src="' . $asset('views/js/admin/order.js') . '" defer></script>';
        }

        return $output;
    }

    /**
     * actionObjectOrderUpdateAfter — keep ppvenipak_order in sync when the
     * merchant changes the carrier on an existing order in admin. Without
     * this, ppvenipak_order.id_carrier and terminal_info stay frozen at
     * checkout-time values, which means:
     *   - the orders dashboard shows the old service (Pickup vs Courier)
     *   - label generation builds an XML for the wrong shipment type
     *
     * No-ops when the new carrier isn't one of ours or when nothing changed.
     */
    public function hookActionObjectOrderUpdateAfter(array $params): void
    {
        $order = $params['object'] ?? null;
        if (!$order instanceof Order || !$order->id) {
            return;
        }

        $db = Db::getInstance();
        $vo = $db->getRow(
            'SELECT `id_carrier`, `terminal_id` FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_order` = ' . (int) $order->id
        );

        if (!$vo) {
            return; // not a Venipak order
        }

        $carrier = new Carrier((int) $order->id_carrier);
        $newRef = (int) $carrier->id_reference;
        $oldRef = (int) $vo['id_carrier'];

        if ($newRef === $oldRef) {
            return; // carrier unchanged — likely a status update, ignore
        }

        $pickupRef = (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF');
        $courierRef = (int) Configuration::get('PPVENIPAK_COURIER_ID_REF');

        $update = ['id_carrier' => $newRef];

        if ($newRef === $courierRef) {
            // Switching to courier: clear the pickup terminal so labels
            // don't ship a stale terminal_id and the dashboard doesn't show
            // a leftover terminal name under the "Courier" line.
            $update['terminal_id'] = 0;
            $update['terminal_info'] = null;
        }
        // For pickup→pickup or other→pickup we keep terminal_info as-is —
        // the merchant may need to re-pick a terminal manually if there's
        // none, but we don't have anywhere good to surface that prompt
        // server-side.

        $db->update('ppvenipak_order', $update, '`id_order` = ' . (int) $order->id);
    }
}
