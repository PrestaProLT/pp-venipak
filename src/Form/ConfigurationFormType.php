<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Form;

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigurationFormType extends TranslatorAwareType
{
    /**
     * Three presentation modes:
     *   - 'shop'  (default): all per-shop fields, no toggle. Used when
     *               per-store auth is ON and the merchant has picked a
     *               specific shop in the multistore selector.
     *   - 'global_toggle': only the per-store auth toggle. Used when
     *               per-store auth is ON and the merchant is at All Shops:
     *               credentials are scoped per shop, so they have no
     *               business being edited from CONTEXT_ALL — but the toggle
     *               itself must remain reachable.
     *   - 'global_full': all fields PLUS the toggle. Used when per-store
     *               auth is OFF (shared credentials), in which case the
     *               merchant edits one global set of values from CONTEXT_ALL.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mode' => 'shop',
        ]);
        $resolver->setAllowedValues('mode', ['shop', 'global_toggle', 'global_full']);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['mode'] === 'global_toggle') {
            $this->buildGlobalToggle($builder);

            return;
        }

        $this->buildApiGroup($builder);
        $this->buildShippingGroup($builder);
        $this->buildAdvancedGroup($builder);

        if ($options['mode'] === 'global_full') {
            $this->buildGlobalToggle($builder);
        }
    }

    private function buildGlobalToggle(FormBuilderInterface $builder): void
    {
        $builder->add('store_auth_mode', SwitchType::class, [
            'label' => $this->trans('Per-store authentication', 'Modules.Ppvenipak.Admin'),
            'required' => false,
            'help' => $this->trans(
                'When enabled, API credentials, checkout fields and the return-service window are stored per shop. Disable to share a single set of credentials across every shop.',
                'Modules.Ppvenipak.Admin'
            ),
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
                'required' => false,
                'help' => $this->trans('Leave blank to keep the saved password.', 'Modules.Ppvenipak.Admin'),
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
            ]);
    }

    private function buildAdvancedGroup(FormBuilderInterface $builder): void
    {
        // store_auth_mode lives in the separate 'global' form mode — it's
        // a multistore-wide toggle and we only render it in CONTEXT_ALL.
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
            ]);
        // Cron security token used to live here — moved to the Order states
        // tab where it is shown alongside the ready-to-paste cron URL and
        // crontab line, so merchants don't have to assemble it themselves.
    }
}
