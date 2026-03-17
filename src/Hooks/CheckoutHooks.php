<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Hooks;

use Address;
use Cart;
use Carrier;
use Configuration;
use Country;
use Db;

trait CheckoutHooks
{
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
            'warehouse_id' => $warehouseId,
            'country_code' => pSQL($country->iso_code),
            'carrier_ref' => (int) $carrier->id_reference,
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
            'ppvenipak_selected_terminal' => $selectedTerminal,
            'ppvenipak_carrier_id' => (int) ($params['carrier']['id'] ?? 0),
        ]);

        return $this->context->smarty->fetch(
            'module:ppvenipak/views/templates/hook/displayCarrierExtraContent_pickup.tpl'
        );
    }

    private function renderCourierExtraFields(array $params): string
    {
        // Read config for which fields to show
        $showDoorCode = (bool) Configuration::get('PPVENIPAK_SHOW_DOOR_CODE');
        $showCabinetNo = (bool) Configuration::get('PPVENIPAK_SHOW_CABINET_NO');
        $showWarehouseNo = (bool) Configuration::get('PPVENIPAK_SHOW_WAREHOUSE_NO');
        $showDeliveryTime = (bool) Configuration::get('PPVENIPAK_SHOW_DELIVERY_TIME');
        $showCallBefore = (bool) Configuration::get('PPVENIPAK_SHOW_CALL_BEFORE');

        // Build delivery time options from enabled nwd* configs
        $timeOptions = [];
        $nwdMap = [
            'nwd' => 'Anytime',
            'nwd10' => 'Until 10:00',
            'nwd12' => 'Until 12:00',
            'nwd8_14' => '8:00 – 14:00',
            'nwd14_17' => '14:00 – 17:00',
            'nwd18_22' => '18:00 – 22:00',
        ];
        foreach ($nwdMap as $key => $label) {
            $configKey = 'PPVENIPAK_' . strtoupper($key) . '_ENABLED';
            if (Configuration::get($configKey)) {
                $timeOptions[$key] = $label;
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

    private function getDefaultWarehouseId(): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id FROM `' . _DB_PREFIX_ . 'ppvenipak_warehouse`
             WHERE is_default = 1
             LIMIT 1'
        );
    }
}
