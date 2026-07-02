<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use Configuration;
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

/**
 * Glue between OrderStatesFormType and the Configuration table. Each
 * Venipak pack_status mapping is stored as its own scalar — either
 * 0 (do not change) or a positive PS order-state ID.
 */
class OrderStatesFormDataProvider implements FormDataProviderInterface
{
    public function getData(): array
    {
        $data = [];
        foreach (OrderStatesFormType::FIELDS as $key => $meta) {
            $data[$key] = (int) Configuration::get($meta['config']);
        }

        return $data;
    }

    public function setData(array $data): array
    {
        foreach (OrderStatesFormType::FIELDS as $key => $meta) {
            $value = isset($data[$key]) ? (int) $data[$key] : 0;
            // Clamp to non-negative so a malformed POST can never write a
            // negative state id that would later confuse Order::setCurrentState.
            Configuration::updateValue($meta['config'], max(0, $value));
        }

        return [];
    }
}
