<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatableType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class ConfigurationFormType extends TranslatorAwareType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->buildGeneralGroup($builder);
        $this->buildApiGroup($builder);
        $this->buildShippingGroup($builder);
        $this->buildAdvancedGroup($builder);
    }

    private function buildGeneralGroup(FormBuilderInterface $builder): void
    {
        $builder
            ->add('enabled', SwitchType::class, [
                'label' => $this->trans('Enabled', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('courier_name', TranslatableType::class, [
                'label' => $this->trans('Courier carrier name', 'Modules.Ppvenipak.Admin'),
                'type' => TextType::class,
                'required' => false,
            ])
            ->add('pickup_name', TranslatableType::class, [
                'label' => $this->trans('Pickup point carrier name', 'Modules.Ppvenipak.Admin'),
                'type' => TextType::class,
                'required' => false,
            ])
            ->add('tracking_url', TextType::class, [
                'label' => $this->trans('Tracking URL', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'help' => $this->trans('Use {tracking_number} as placeholder.', 'Modules.Ppvenipak.Admin'),
                'empty_data' => 'https://www.venipak.lt/track/?trackingNumber={tracking_number}',
            ]);
    }

    private function buildApiGroup(FormBuilderInterface $builder): void
    {
        $builder
            ->add('api_user', TextType::class, [
                'label' => $this->trans('API Username', 'Modules.Ppvenipak.Admin'),
                'required' => true,
            ])
            ->add('api_pass', PasswordType::class, [
                'label' => $this->trans('API Password', 'Modules.Ppvenipak.Admin'),
                'required' => true,
                'always_empty' => false,
            ])
            ->add('api_id', TextType::class, [
                'label' => $this->trans('API ID', 'Modules.Ppvenipak.Admin'),
                'required' => true,
                'help' => $this->trans('Numeric API ID, e.g. 07789', 'Modules.Ppvenipak.Admin'),
            ])
            ->add('live_mode', SwitchType::class, [
                'label' => $this->trans('Live mode', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'help' => $this->trans('Disable for test/sandbox mode.', 'Modules.Ppvenipak.Admin'),
            ])
            ->add('sender_name', TextType::class, [
                'label' => $this->trans('Sender name', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_company_code', TextType::class, [
                'label' => $this->trans('Sender company code', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_contact', TextType::class, [
                'label' => $this->trans('Sender contact person', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_country', ChoiceType::class, [
                'label' => $this->trans('Sender country', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'choices' => [
                    'Lithuania' => 'LT',
                    'Latvia' => 'LV',
                    'Estonia' => 'EE',
                    'Poland' => 'PL',
                ],
            ])
            ->add('sender_city', TextType::class, [
                'label' => $this->trans('Sender city', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_address', TextType::class, [
                'label' => $this->trans('Sender address', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_postcode', TextType::class, [
                'label' => $this->trans('Sender postcode', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_phone', TextType::class, [
                'label' => $this->trans('Sender phone', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sender_email', TextType::class, [
                'label' => $this->trans('Sender email', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('include_sender', SwitchType::class, [
                'label' => $this->trans('Include sender in shipments', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'help' => $this->trans('Include sender info in API shipment requests.', 'Modules.Ppvenipak.Admin'),
            ]);
    }

    private function buildShippingGroup(FormBuilderInterface $builder): void
    {
        $builder
            ->add('show_door_code', SwitchType::class, [
                'label' => $this->trans('Show door code field', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('show_cabinet_no', SwitchType::class, [
                'label' => $this->trans('Show cabinet number field', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('show_warehouse_no', SwitchType::class, [
                'label' => $this->trans('Show warehouse number field', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('show_delivery_time', SwitchType::class, [
                'label' => $this->trans('Show delivery time field', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('show_call_before', SwitchType::class, [
                'label' => $this->trans('Show "call before delivery" option', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('enable_return_service', SwitchType::class, [
                'label' => $this->trans('Enable return service', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('return_days', IntegerType::class, [
                'label' => $this->trans('Return days', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'help' => $this->trans('Number of days for return label validity.', 'Modules.Ppvenipak.Admin'),
                'empty_data' => '14',
            ])
            ->add('nwd_enabled', SwitchType::class, [
                'label' => $this->trans('Enable next working day delivery', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('nwd10_enabled', SwitchType::class, [
                'label' => $this->trans('NWD delivery by 10:00', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('nwd12_enabled', SwitchType::class, [
                'label' => $this->trans('NWD delivery by 12:00', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('nwd8_14_enabled', SwitchType::class, [
                'label' => $this->trans('NWD delivery 08:00-14:00', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('nwd14_17_enabled', SwitchType::class, [
                'label' => $this->trans('NWD delivery 14:00-17:00', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('nwd18_22_enabled', SwitchType::class, [
                'label' => $this->trans('NWD delivery 18:00-22:00', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ]);
    }

    private function buildAdvancedGroup(FormBuilderInterface $builder): void
    {
        $builder
            ->add('label_format', ChoiceType::class, [
                'label' => $this->trans('Label format', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'choices' => [
                    'A4 (4 labels per page)' => 'a4',
                    '100x150mm (thermal)' => 'other',
                ],
            ])
            ->add('disable_passphrase', TextType::class, [
                'label' => $this->trans('Disable passphrase', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'help' => $this->trans('Optional passphrase to disable the module without uninstalling.', 'Modules.Ppvenipak.Admin'),
            ])
            ->add('cod_modules', TextType::class, [
                'label' => $this->trans('COD payment modules', 'Modules.Ppvenipak.Admin'),
                'required' => false,
                'help' => $this->trans('Comma-separated list of payment module names considered as COD.', 'Modules.Ppvenipak.Admin'),
                'empty_data' => 'ps_cashondelivery',
            ]);
    }
}
