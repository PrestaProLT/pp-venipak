<?php

declare(strict_types=1);

namespace PrestaPro\Common\Traits;

use Configuration;
use Db;
use Language;
use OrderState;

trait OrderStateTrait
{
    /**
     * Create a custom order state.
     *
     * @param string $configKey Config key to store the state ID
     * @param array<string, string> $names Map of ISO code => state name
     * @param string $color Hex color (e.g. '#FCEAA8')
     * @param bool $sendEmail Whether to send email on this state
     * @param string $template Email template name (empty = no template)
     */
    protected function createOrderState(
        string $configKey,
        array $names,
        string $color,
        bool $sendEmail = false,
        string $template = '',
    ): int {
        $existingId = (int) Configuration::get($configKey);

        if ($existingId > 0) {
            $existing = new OrderState($existingId);

            if (\Validate::isLoadedObject($existing)) {
                return $existingId;
            }
        }

        $orderState = new OrderState();
        $orderState->color = $color;
        $orderState->send_email = $sendEmail;
        $orderState->template = $template;
        $orderState->module_name = $this->name ?? '';
        $orderState->unremovable = false;
        $orderState->hidden = false;
        $orderState->logable = true;
        $orderState->delivery = false;
        $orderState->shipped = false;
        $orderState->paid = false;

        $languages = Language::getLanguages(false);

        foreach ($languages as $lang) {
            $iso = $lang['iso_code'];
            $orderState->name[$lang['id_lang']] = $names[$iso] ?? $names['en'] ?? 'Unknown state';
        }

        if ($orderState->add()) {
            Configuration::updateValue($configKey, (int) $orderState->id);

            return (int) $orderState->id;
        }

        return 0;
    }

    /**
     * Delete a custom order state by config key.
     */
    protected function deleteOrderState(string $configKey): void
    {
        $stateId = (int) Configuration::get($configKey);

        if ($stateId > 0) {
            $state = new OrderState($stateId);

            if (\Validate::isLoadedObject($state)) {
                $state->delete();
            }
        }

        Configuration::deleteByName($configKey);
    }
}
