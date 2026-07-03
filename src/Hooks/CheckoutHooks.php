<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Hooks;

use Address;
use Cart;
use Carrier;
use Configuration;
use Country;
use Db;
use Validate;

trait CheckoutHooks
{
    /**
     * actionFrontControllerSetMedia hook
     * Registers the pickup-point selector JS + CSS on checkout pages so the
     * map, search and terminal list inside displayCarrierExtraContent_pickup
     * actually work. Loads on order / checkout / order-opc front controllers
     * only — no point shipping it to product pages.
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        $controller = $this->context->controller ?? null;
        if (!$controller) {
            return;
        }

        $page = $controller->php_self ?? '';
        if (!in_array($page, ['order', 'checkout', 'order-opc'], true)) {
            return;
        }

        $controller->registerStylesheet(
            'modules-ppvenipak-checkout',
            'modules/ppvenipak/views/css/front/checkout.css',
            ['priority' => 200]
        );

        $controller->registerJavascript(
            'modules-ppvenipak-checkout',
            'modules/ppvenipak/views/js/front/checkout.js',
            ['priority' => 200]
        );
    }

    /**
     * Server-side filter for the checkout payment-options list.
     *
     * Fires from PaymentOptionsFinder::present() before the storefront
     * controller hands options to any theme template, so filtering here
     * works identically across Classic, Hummingbird and any other
     * theme — no JS, no theme-specific selectors, can't be bypassed by
     * disabling JavaScript or editing the DOM.
     *
     * Removes any payment module listed in PPVENIPAK_COD_MODULES when:
     *   - the cart's carrier is the Venipak pickup carrier, AND
     *   - the selected terminal has cod_enabled=0 (Locker, or a Shop the
     *     merchant hasn't enabled for COD).
     *
     * The hook receives `paymentOptions` by reference; we mutate it
     * directly so the change propagates back to the finder.
     */
    public function hookActionPresentPaymentOptions(array &$params): void
    {
        if (!isset($params['paymentOptions']) || !is_array($params['paymentOptions'])) {
            return;
        }

        $cart = $this->context->cart ?? null;
        if (!$cart || !Validate::isLoadedObject($cart) || !$cart->id_carrier) {
            return;
        }

        $carrier = new Carrier((int) $cart->id_carrier);
        if (!$carrier->id) {
            return;
        }

        $pickupRef = (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF');
        if ($pickupRef <= 0 || (int) $carrier->id_reference !== $pickupRef) {
            return; // not a pickup-carrier order — nothing to filter
        }

        $row = Db::getInstance()->getRow(
            'SELECT `terminal_info` FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_cart` = ' . (int) $cart->id
        );

        if (!$row || empty($row['terminal_info'])) {
            return;
        }

        $terminal = json_decode((string) $row['terminal_info'], true);
        if (!is_array($terminal) || (int) ($terminal['cod_enabled'] ?? 0) === 1) {
            return; // terminal accepts COD — let everything through
        }

        $codModulesRaw = (string) Configuration::get('PPVENIPAK_COD_MODULES');
        $codModules = array_values(array_filter(array_map('trim', explode(',', $codModulesRaw))));
        if (empty($codModules)) {
            $codModules = ['ps_cashondelivery'];
        }

        // PaymentOptionsFinder::present() returns the options keyed by
        // module name, so removal is a direct unset.
        foreach ($codModules as $module) {
            if (isset($params['paymentOptions'][$module])) {
                unset($params['paymentOptions'][$module]);
            }
        }
    }

    /**
     * displayCarrierExtraContent hook
     * Renders pickup point selector OR courier extra fields depending on carrier type.
     */
    public function hookDisplayCarrierExtraContent(array $params): string
    {
        $carrierId = (int) ($params['carrier']['id'] ?? 0);

        if (!$this->isOwnCarrier($carrierId)) {
            return '';
        }

        $carrierKey = $this->getCarrierKey($carrierId);

        if ($carrierKey === 'pickup') {
            return $this->renderPickupSelector($params);
        }

        if ($carrierKey === 'courier') {
            return $this->renderCourierExtraFields($params);
        }

        return '';
    }

    /**
     * actionValidateOrder hook
     * Saves order data (weight, COD, warehouse) to ppvenipak_order.
     */
    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'];
        $cart = new Cart($order->id_cart);
        $carrier = new Carrier($order->id_carrier);

        if (!$this->isOwnCarrier((int) $carrier->id)) {
            return;
        }

        // Get weight in kg (convert from g if PS_WEIGHT_UNIT == 'g')
        $weight = (float) $order->getTotalWeight();
        if (strtolower((string) Configuration::get('PS_WEIGHT_UNIT')) === 'g') {
            $weight /= 1000;
        }

        // Detect COD
        $codModules = array_map('trim', explode(',', Configuration::get('PPVENIPAK_COD_MODULES') ?: 'ps_cashondelivery'));
        $isCod = in_array($order->module, $codModules, true);
        $codAmount = $isCod ? (float) $order->total_paid_tax_incl : 0.0;

        // Get delivery country
        $address = new Address($order->id_address_delivery);
        $country = new Country($address->id_country);

        // Default warehouse
        $warehouseId = $this->getDefaultWarehouseId();

        // Update ppvenipak_order (row should already exist from checkout AJAX saves)
        $db = Db::getInstance();
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE id_cart = ' . (int) $order->id_cart
        );

        $data = [
            'id_order' => (int) $order->id,
            'order_weight' => $weight,
            'cod_amount' => $codAmount,
            'is_cod' => $isCod ? 1 : 0,
            'id_warehouse' => $warehouseId,
            'country_code' => pSQL($country->iso_code),
            'id_carrier' => (int) $carrier->id_reference,
        ];

        if ($exists) {
            $db->update('ppvenipak_order', $data, 'id_cart = ' . (int) $order->id_cart);
        } else {
            $data['id_cart'] = (int) $order->id_cart;
            $data['status'] = 'new';
            $db->insert('ppvenipak_order', $data);
        }
    }

    private function renderPickupSelector(array $params): string
    {
        // Get cart delivery address country
        $cart = $this->context->cart;
        $address = new Address($cart->id_address_delivery);
        $country = new Country($address->id_country);
        $countryCode = $country->iso_code;

        // Get currently selected terminal (if any)
        $selectedTerminal = $this->getSelectedTerminal((int) $cart->id);

        // Build AJAX URL
        $ajaxUrl = $this->context->link->getModuleLink('ppvenipak', 'ajax', [], true);

        // Assign Smarty vars and render
        $this->context->smarty->assign([
            'ppvenipak_ajax_url' => $ajaxUrl,
            'ppvenipak_country_code' => $countryCode,
            'ppvenipak_postcode' => trim((string) $address->postcode),
            'ppvenipak_selected_terminal' => $selectedTerminal,
            'ppvenipak_carrier_id' => (int) ($params['carrier']['id'] ?? 0),
            'ppvenipak_i18n' => json_encode($this->getFrontI18n()),
        ]);

        return $this->context->smarty->fetch(
            'module:ppvenipak/views/templates/hook/displayCarrierExtraContent_pickup.tpl'
        );
    }

    /**
     * Translations for the strings that checkout.js renders on the client. The
     * English source doubles as the lookup key AND the fallback, so the JS keeps
     * working if a key is missing from a locale. Passed to the template as a
     * JSON data-i18n attribute on the pickup container.
     *
     * @return array<string, string>
     */
    private function getFrontI18n(): array
    {
        $d = self::MODULE_SHOP_DOMAIN;
        $keys = [
            'No terminals found.',
            'Loading terminals…',
            'Failed to load terminals.',
            'Failed to save selection.',
            'Enter a postcode.',
            'Terminals are still loading…',
            'Locating…',
            'Could not locate postcode.',
            'Locker',
            'Shop',
            'Cash on Delivery available',
            'No Cash on Delivery',
            'Select',
            'Working hours:',
            'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
            'Showing %count% closest to %postcode%',
            'top match: %name%',
        ];

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->trans($key, [], $d);
        }

        return $out;
    }

    private function renderCourierExtraFields(array $params): string
    {
        // Read config for which fields to show
        $showDoorCode = (bool) Configuration::get('PPVENIPAK_SHOW_DOOR_CODE');
        $showCabinetNo = (bool) Configuration::get('PPVENIPAK_SHOW_CABINET_NO');
        $showWarehouseNo = (bool) Configuration::get('PPVENIPAK_SHOW_WAREHOUSE_NO');
        $showDeliveryTime = (bool) Configuration::get('PPVENIPAK_SHOW_DELIVERY_TIME');
        $showCallBefore = (bool) Configuration::get('PPVENIPAK_SHOW_CALL_BEFORE');

        // If the merchant has disabled every courier extra field, the
        // template would render an empty <div class="ppvenipak-courier">
        // wrapper. Skip the hook output entirely so the carrier-extra slot
        // collapses cleanly instead of leaving an empty container.
        if (!$showDoorCode && !$showCabinetNo && !$showWarehouseNo
            && !$showDeliveryTime && !$showCallBefore) {
            return '';
        }

        // Build delivery time options from enabled config flags
        $timeOptions = [];
        $deliveryTypeMap = [
            'nwd' => 'Anytime',
            'nwd10' => 'Until 10:00',
            'nwd12' => 'Until 12:00',
            'nwd8_14' => '8:00 – 14:00',
            'nwd14_17' => '14:00 – 17:00',
            'nwd18_22' => '18:00 – 22:00',
            'tswd' => 'Same day',
            'tswd17' => 'Same day 17:00 – 22:00',
            'sat' => 'Saturday',
        ];
        foreach ($deliveryTypeMap as $key => $label) {
            $configKey = 'PPVENIPAK_' . strtoupper($key) . '_ENABLED';
            if (Configuration::get($configKey)) {
                $timeOptions[$key] = $this->trans($label, [], self::MODULE_SHOP_DOMAIN);
            }
        }

        // Get saved values (if any)
        $savedFields = $this->getSavedExtraFields((int) $this->context->cart->id);
        $ajaxUrl = $this->context->link->getModuleLink('ppvenipak', 'ajax', [], true);

        $this->context->smarty->assign([
            'ppvenipak_ajax_url' => $ajaxUrl,
            'ppvenipak_carrier_id' => (int) ($params['carrier']['id'] ?? 0),
            'ppvenipak_show_door_code' => $showDoorCode,
            'ppvenipak_show_cabinet_no' => $showCabinetNo,
            'ppvenipak_show_warehouse_no' => $showWarehouseNo,
            'ppvenipak_show_delivery_time' => $showDeliveryTime,
            'ppvenipak_show_call_before' => $showCallBefore,
            'ppvenipak_time_options' => $timeOptions,
            'ppvenipak_saved_fields' => $savedFields,
        ]);

        return $this->context->smarty->fetch(
            'module:ppvenipak/views/templates/hook/displayCarrierExtraContent_courier.tpl'
        );
    }

    private function getSelectedTerminal(int $idCart): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT terminal_id, terminal_info FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE id_cart = ' . (int) $idCart
        );

        if (!$row || empty($row['terminal_id'])) {
            return null;
        }

        return [
            'id' => (int) $row['terminal_id'],
            'info' => json_decode($row['terminal_info'] ?? '{}', true),
        ];
    }

    private function getSavedExtraFields(int $idCart): array
    {
        $row = Db::getInstance()->getValue(
            'SELECT extra_fields FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE id_cart = ' . (int) $idCart
        );

        if (!$row) {
            return [];
        }

        return json_decode($row, true) ?: [];
    }

    /**
     * Resolve the default warehouse for the current cart's shop. Per-shop
     * scoping means each shop in a multistore setup keeps its own default.
     * Falls back to the legacy global row (id_shop = 0) so installs that
     * predate the per-shop UI keep working.
     */
    private function getDefaultWarehouseId(): int
    {
        $idShop = (int) ($this->context->shop->id ?? 0);

        // Try the current shop first; fall back to a legacy global default
        // (id_shop=0) so older installs without per-shop warehouses still
        // resolve a sender address.
        // getValue() (via getRow) appends its own "LIMIT 1", so the query must
        // not include one — a second LIMIT triggers a SQL syntax error.
        return (int) Db::getInstance()->getValue(
            'SELECT `id_ppvenipak_warehouse` FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
             WHERE `is_default` = 1
               AND (`id_shop` = ' . $idShop . ' OR `id_shop` = 0)
             ORDER BY (`id_shop` = ' . $idShop . ') DESC'
        );
    }
}
