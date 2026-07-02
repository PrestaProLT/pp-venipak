<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaPro\Common\Carrier\AbstractPPCarrier;
use PrestaPro\Common\Traits\AdminAssetsTrait;
use PrestaPro\Common\Traits\OrderStateTrait;
use PrestaShop\Module\PPVenipak\Carrier\VenipakShippingCalculator;
use PrestaShop\Module\PPVenipak\Hooks\AdminHooks;
use PrestaShop\Module\PPVenipak\Hooks\CheckoutHooks;
use PrestaShop\Module\PPVenipak\Module\Installer;
use PrestaShop\Module\PPVenipak\Module\Uninstaller;

class PPVenipak extends AbstractPPCarrier
{
    use OrderStateTrait;
    use AdminAssetsTrait;
    use CheckoutHooks;
    use AdminHooks;

    public const MODULE_ADMIN_DOMAIN = 'Modules.Ppvenipak.Admin';
    public const MODULE_SHOP_DOMAIN = 'Modules.Ppvenipak.Shop';

    /** @var string[] */
    public array $hooks = [
        'actionCarrierUpdate',
        'actionFrontControllerSetMedia',
        'displayCarrierExtraContent',
        'displayAdminOrderMainBottom',
        'actionValidateOrder',
        'actionObjectOrderUpdateAfter',
        'actionPresentPaymentOptions',
        'displayBackOfficeHeader',
    ];

    public function __construct()
    {
        $this->name = 'ppvenipak';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.11';
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
        try {
            $router = $this->get('router');
            Tools::redirectAdmin(
                $router->generate('ps_ppvenipak_dashboard')
            );
        } catch (\Throwable $e) {
            // The module's Symfony routes are not yet compiled into the router
            // — this happens on a fresh install or upgrade before the Symfony
            // cache has been rebuilt, and clicking "Configure" would otherwise
            // throw RouteNotFoundException. Clear the cache so the routes are
            // registered and bounce back to the module list; opening the module
            // again lands on the dashboard.
            Tools::clearSf2Cache();
            Tools::redirectAdmin(
                $this->context->link->getAdminLink('AdminModules')
            );
        }
    }

    protected function getConfigPrefix(): string
    {
        return 'PPVENIPAK';
    }

    /**
     * Public wrapper so Installer can call the protected trait method.
     */
    public function addOrderState(string $configKey, array $names, string $color): int
    {
        return $this->createOrderState($configKey, $names, $color);
    }

    protected function getCarrierDefinitions(): array
    {
        // PrestaShop substitutes "@" in the URL with the customer's tracking
        // number when it renders the order detail page.
        $trackingUrl = 'https://www.venipak.lt/track/?trackingNumber=@';

        return [
            'courier' => [
                'name' => 'Venipak Courier',
                'url' => $trackingUrl,
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
                'url' => $trackingUrl,
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
        if (!(bool) Configuration::get('PPVENIPAK_ENABLED')) {
            return false;
        }

        $cart = $this->context->cart;

        if (!$cart || !$cart->id) {
            return false;
        }

        $calculator = new VenipakShippingCalculator();

        // Check if carriers should be disabled based on product description passphrase
        if ($calculator->shouldDisableCarriers((int) $cart->id, (int) $this->context->language->id)) {
            return false;
        }

        // For pickup carrier, check if terminals are available for the delivery country
        $carrierId = (int) ($this->id_carrier ?? 0);
        $carrierKey = $this->getCarrierKey($carrierId);

        if ($carrierKey === 'pickup' && !$calculator->hasAvailableTerminals((int) $cart->id)) {
            return false;
        }

        // Pass through PS zone-based pricing
        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }
}
