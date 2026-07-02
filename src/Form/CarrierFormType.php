<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatableType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class CarrierFormType extends TranslatorAwareType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->buildGeneralGroup($builder);
        $this->buildDeliveryTypesGroup($builder);
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
            ]);
    }

    private function buildDeliveryTypesGroup(FormBuilderInterface $builder): void
    {
        $builder
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
            ])
            ->add('tswd_enabled', SwitchType::class, [
                'label' => $this->trans('Same-day delivery (tswd)', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('tswd17_enabled', SwitchType::class, [
                'label' => $this->trans('Same-day delivery 17:00-22:00 (tswd17)', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ])
            ->add('sat_enabled', SwitchType::class, [
                'label' => $this->trans('Saturday delivery (sat)', 'Modules.Ppvenipak.Admin'),
                'required' => false,
            ]);
    }
}
