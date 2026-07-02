<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use Configuration;
use OrderState;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Mapping form: each Venipak pack_status code → a PrestaShop OrderState id.
 *
 * Each <select> defaults to "Do not change" (value `0`) so merchants can
 * opt in per status — the status sync only calls Order::setCurrentState()
 * for codes that have a non-zero mapping. This avoids surprise state
 * jumps for codes the merchant doesn't care about.
 */
class OrderStatesFormType extends TranslatorAwareType
{
    /**
     * Each entry is a tuple of {config_key, friendly label, hint shown
     * under the field}. The order matches the lifecycle of a parcel.
     */
    public const FIELDS = [
        // --- Label generation (set by the module, not by tracking) ----------
        'ready' => [
            'config' => 'PPVENIPAK_STATE_READY',
            'label' => 'Label generated — ready to ship',
            'help' => 'Applied right after a label is successfully generated. Use the module\'s "Venipak siunta paruošta" state — do NOT map this to a payment state.',
        ],
        'error' => [
            'config' => 'PPVENIPAK_STATE_ERROR',
            'label' => 'Label generation failed',
            'help' => 'Applied when Venipak rejects the shipment so failed orders are easy to spot. Use the module\'s "Klaida Venipak siuntoje" state — do NOT map this to a payment state.',
        ],

        // --- Tracking pack_status sync --------------------------------------
        'at_sender' => [
            'config' => 'PPVENIPAK_STATE_AT_SENDER',
            'label' => 'At sender (pack_status 0)',
            'help' => 'Label printed but parcel still with the merchant — typically right after label generation.',
        ],
        'picked_up' => [
            'config' => 'PPVENIPAK_STATE_PICKED_UP',
            'label' => 'Picked up by courier (pack_status 1)',
            'help' => 'Venipak\'s courier has collected the parcel from your warehouse.',
        ],
        'at_terminal' => [
            'config' => 'PPVENIPAK_STATE_AT_TERMINAL',
            'label' => 'At Venipak terminal (pack_status 2)',
            'help' => 'Parcel arrived at a Venipak hub and is being sorted.',
        ],
        'out_for_delivery' => [
            'config' => 'PPVENIPAK_STATE_OUT_FOR_DELIVERY',
            'label' => 'Out for delivery (pack_status 3)',
            'help' => 'Last-mile courier is heading to the recipient.',
        ],
        'at_pickup_point' => [
            'config' => 'PPVENIPAK_STATE_AT_PICKUP_POINT',
            'label' => 'At pickup point / locker (pack_status 5 or 6)',
            'help' => 'Parcel has reached the locker or shop and is awaiting customer collection.',
        ],
        'in_transit' => [
            'config' => 'PPVENIPAK_STATE_IN_TRANSIT',
            'label' => 'In transit (pack_status 7)',
            'help' => 'Generic in-transit signal Venipak emits between hub events.',
        ],
        'delivered' => [
            'config' => 'PPVENIPAK_STATE_DELIVERED',
            'label' => 'Delivered (pack_status 9, event "Delivered")',
            'help' => 'Customer has received the parcel — only triggered when the tracking event is literally "Delivered" (forwarding/return events also reach 9).',
        ],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = $this->buildOrderStateChoices();

        foreach (self::FIELDS as $key => $meta) {
            $builder->add($key, ChoiceType::class, [
                'label' => $this->trans($meta['label'], 'Modules.Ppvenipak.Admin'),
                'help' => $this->trans($meta['help'], 'Modules.Ppvenipak.Admin'),
                'choices' => $choices,
                'required' => false,
                'placeholder' => false,
                'choice_translation_domain' => false,
            ]);
        }
    }

    /**
     * Build the [label => id] choices array Symfony's ChoiceType expects.
     * "Do not change" is value 0 so an unset / 0 / null stored value all
     * mean the same thing: skip the order-state update for this code.
     */
    private function buildOrderStateChoices(): array
    {
        $idLang = (int) (Configuration::get('PS_LANG_DEFAULT') ?: 1);
        $states = OrderState::getOrderStates($idLang) ?: [];

        $choices = [
            $this->trans('— Do not change order state —', 'Modules.Ppvenipak.Admin') => 0,
        ];

        foreach ($states as $state) {
            $name = (string) $state['name'];
            $id = (int) $state['id_order_state'];
            $choices[$name . ' (#' . $id . ')'] = $id;
        }

        return $choices;
    }
}
