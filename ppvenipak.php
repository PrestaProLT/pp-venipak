<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaPro\Common\Carrier\AbstractPPCarrier;
use PrestaPro\Common\Traits\AdminAssetsTrait;
use PrestaPro\Common\Traits\OrderStateTrait;
use PrestaShop\Module\PPVenipak\Module\Installer;
use PrestaShop\Module\PPVenipak\Module\Uninstaller;

class PPVenipak extends AbstractPPCarrier
{
    use OrderStateTrait;
    use AdminAssetsTrait;

    public const MODULE_ADMIN_DOMAIN = 'Modules.Ppvenipak.Admin';
    public const MODULE_SHOP_DOMAIN = 'Modules.Ppvenipak.Shop';

    /** @var string[] */
    public array $hooks = [
        'actionCarrierUpdate',
        'displayCarrierExtraContent',
        'displayOrderDetail',
        'displayAdminOrderSide',
        'actionOrderStatusUpdate',
        'actionValidateOrder',
        'displayBackOfficeHeader',
        'actionValidateStepComplete',
    ];

    public function __construct()
    {
        $this->name = 'ppvenipak';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'PrestaPro';
        $this->author_uri = 'https://prestapro.lt/modules/ppvenipak';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('PrestaPro — Venipak', [], self::MODULE_ADMIN_DOMAIN);
        $this->description = $this->trans('Venipak courier and pickup point shipping integration for PrestaShop.', [], self::MODULE_ADMIN_DOMAIN);
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall this module?', [], self::MODULE_ADMIN_DOMAIN);

        $this->tabs = [
            [
                'name' => 'PrestaPro — Venipak',
                'class_name' => 'AdminPPVenipak',
                'route_name' => 'ps_ppvenipak_configuration',
                'parent_class_name' => 'INVISIBLE',
            ],
        ];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent(): void
    {
        $router = $this->get('router');
        Tools::redirectAdmin(
            $router->generate('ps_ppvenipak_configuration')
        );
    }

    protected function getConfigPrefix(): string
    {
        return 'PPVENIPAK';
    }

    protected function getCarrierDefinitions(): array
    {
        return [
            'courier' => [
                'name' => 'Venipak Courier',
                'delay' => [
                    'en' => '1-3 business days',
                    'lt' => '1-3 darbo dienos',
                    'lv' => '1-3 darba dienas',
                    'et' => '1-3 tööpäeva',
                ],
                'is_free' => false,
                'shipping_handling' => true,
                'range_behavior' => 0,
            ],
            'pickup' => [
                'name' => 'Venipak Pickup Point',
                'delay' => [
                    'en' => '2-4 business days',
                    'lt' => '2-4 darbo dienos',
                    'lv' => '2-4 darba dienas',
                    'et' => '2-4 tööpäeva',
                ],
                'is_free' => false,
                'shipping_handling' => true,
                'range_behavior' => 0,
            ],
        ];
    }

    protected function getInstaller(): object
    {
        return new Installer($this);
    }

    protected function getUninstaller(): object
    {
        return new Uninstaller($this);
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return false;
    }
}
