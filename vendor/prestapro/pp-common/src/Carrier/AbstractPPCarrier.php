<?php

declare(strict_types=1);

namespace PrestaPro\Common\Carrier;

use Carrier;
use CarrierModule;
use Configuration;
use Context;
use Db;
use Language;
use Module;
use Tools;
use Validate;

abstract class AbstractPPCarrier extends CarrierModule
{
    /**
     * Hooks that every PrestaPro carrier module registers.
     *
     * @var string[]
     */
    public array $hooks = [
        'actionCarrierUpdate',
        'displayCarrierExtraContent',
        'displayOrderDetail',
        'displayAdminOrderSide',
        'actionOrderStatusUpdate',
        'actionValidateOrder',
        'displayBackOfficeHeader',
    ];

    /**
     * Must return the config key prefix for this module.
     * Example: 'PPVENIPAK'
     */
    abstract protected function getConfigPrefix(): string;

    /**
     * Must return carrier definitions for this module.
     * Example:
     * [
     *     'courier' => [
     *         'name' => 'Venipak Courier',
     *         'delay' => ['en' => '1-3 business days', 'lt' => '1-3 darbo dienos'],
     *         'is_free' => false,
     *         'grade' => 0,
     *         'shipping_handling' => true,
     *         'range_behavior' => 0,
     *         'is_module' => true,
     *     ],
     *     'pickup' => [ ... ],
     * ]
     */
    abstract protected function getCarrierDefinitions(): array;

    /**
     * Must return the Installer class instance.
     */
    abstract protected function getInstaller(): object;

    /**
     * Must return the Uninstaller class instance.
     */
    abstract protected function getUninstaller(): object;

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install() && ($this->getInstaller())();
    }

    public function uninstall(): bool
    {
        return ($this->getUninstaller())() && parent::uninstall();
    }

    /**
     * Redirect to Symfony configuration route.
     */
    public function getContent(): void
    {
        $route = 'ps_' . $this->name . '_configuration';
        Tools::redirectAdmin(
            $this->get('router')->generate($route)
        );
    }

    // -------------------------------------------------------------------------
    // Carrier Management
    // -------------------------------------------------------------------------

    /**
     * Create carriers defined by the module.
     *
     * @return array<string, int> Map of carrier key => carrier ID
     */
    public function createCarriers(): array
    {
        $prefix = $this->getConfigPrefix();
        $carriers = [];
        $context = Context::getContext();
        $languages = Language::getLanguages(false);

        foreach ($this->getCarrierDefinitions() as $key => $definition) {
            $carrier = new Carrier();
            $carrier->name = $definition['name'];
            $carrier->active = true;
            $carrier->is_free = $definition['is_free'] ?? false;
            $carrier->shipping_handling = $definition['shipping_handling'] ?? true;
            $carrier->range_behavior = $definition['range_behavior'] ?? 0;
            $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
            $carrier->is_module = true;
            $carrier->external_module_name = $this->name;
            $carrier->need_range = true;

            foreach ($languages as $lang) {
                $isoCode = $lang['iso_code'];
                $carrier->delay[$lang['id_lang']] = $definition['delay'][$isoCode]
                    ?? $definition['delay']['en']
                    ?? '';
            }

            if (!$carrier->add()) {
                continue;
            }

            $this->assignCarrierToAllGroups($carrier);
            $this->assignCarrierToAllZones($carrier);
            $this->createDefaultRange($carrier);

            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';
            $configKeyRef = $prefix . '_' . strtoupper($key) . '_ID_REF';

            Configuration::updateValue($configKeyId, (int) $carrier->id);
            Configuration::updateValue($configKeyRef, (int) $carrier->id);

            $carriers[$key] = (int) $carrier->id;
        }

        $this->installCarrierLogos($carriers);

        return $carriers;
    }

    /**
     * Give each newly created carrier the module's logo so it isn't rendered
     * with PrestaShop's blank placeholder in the carrier list and at checkout.
     *
     * The module just needs to ship views/img/carrier_logo.png. The file is
     * copied to img/s/{id_carrier}.jpg — PrestaShop serves that directly and
     * copies it forward when a merchant edits the carrier (which clones it to a
     * new id). A PNG served under a .jpg name renders fine in browsers, so any
     * transparency in the source logo is preserved.
     *
     * @param array<string, int> $carrierIds
     */
    protected function installCarrierLogos(array $carrierIds): void
    {
        $logo = $this->getLocalPath() . 'views/img/carrier_logo.png';

        if (!file_exists($logo) || !defined('_PS_SHIP_IMG_DIR_')) {
            return;
        }

        foreach ($carrierIds as $idCarrier) {
            $idCarrier = (int) $idCarrier;
            if ($idCarrier > 0) {
                @copy($logo, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
            }
        }
    }

    /**
     * Soft-delete carriers on uninstall (active=0, deleted=0).
     */
    public function deleteCarriers(): bool
    {
        $prefix = $this->getConfigPrefix();

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyRef = $prefix . '_' . strtoupper($key) . '_ID_REF';
            $idReference = (int) Configuration::get($configKeyRef);

            if ($idReference <= 0) {
                continue;
            }

            $carriers = Carrier::getCarrierByReference($idReference);

            if (!$carriers) {
                continue;
            }

            if (!is_array($carriers)) {
                $carriers = [$carriers];
            }

            foreach ($carriers as $carrier) {
                $carrier->active = false;
                $carrier->update();
            }
        }

        return true;
    }

    /**
     * Track carrier ID changes when PS clones a carrier.
     */
    public function hookActionCarrierUpdate(array $params): void
    {
        $prefix = $this->getConfigPrefix();
        $newCarrierId = (int) $params['id_carrier'];
        $oldCarrierId = (int) ($params['carrier']->id ?? 0);

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';
            $storedId = (int) Configuration::get($configKeyId);

            if ($storedId === $oldCarrierId) {
                Configuration::updateValue($configKeyId, $newCarrierId);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Multilanguage Carrier Names
    // -------------------------------------------------------------------------

    /**
     * Get carrier display name for a specific language.
     * Falls back to ps_carrier.name if no custom name is set.
     */
    public function getCarrierName(int $idCarrier, int $idLang): string
    {
        $prefix = $this->getConfigPrefix();

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';

            if ((int) Configuration::get($configKeyId) !== $idCarrier) {
                continue;
            }

            $nameKey = $prefix . '_' . strtoupper($key) . '_NAME_' . $idLang;
            $name = Configuration::get($nameKey);

            if (!empty($name)) {
                return $name;
            }

            break;
        }

        $carrier = new Carrier($idCarrier);

        return $carrier->name ?: '';
    }

    /**
     * Save multilanguage carrier names.
     *
     * @param string $carrierKey The carrier key (e.g. 'courier', 'pickup')
     * @param array<int, string> $names Map of id_lang => name
     */
    public function saveCarrierNames(string $carrierKey, array $names): void
    {
        $prefix = $this->getConfigPrefix();

        foreach ($names as $idLang => $name) {
            $configKey = $prefix . '_' . strtoupper($carrierKey) . '_NAME_' . $idLang;
            Configuration::updateValue($configKey, $name);
        }
    }

    // -------------------------------------------------------------------------
    // Carrier Helper Methods
    // -------------------------------------------------------------------------

    /**
     * Check if a given carrier ID belongs to this module.
     */
    public function isOwnCarrier(int $idCarrier): bool
    {
        $prefix = $this->getConfigPrefix();

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';

            if ((int) Configuration::get($configKeyId) === $idCarrier) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the carrier key (e.g. 'courier', 'pickup') for a given carrier ID.
     */
    public function getCarrierKey(int $idCarrier): ?string
    {
        $prefix = $this->getConfigPrefix();

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';

            if ((int) Configuration::get($configKeyId) === $idCarrier) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Get the carrier reference for a given carrier key.
     */
    public function getCarrierReference(string $carrierKey): int
    {
        $prefix = $this->getConfigPrefix();
        $configKeyRef = $prefix . '_' . strtoupper($carrierKey) . '_ID_REF';

        return (int) Configuration::get($configKeyRef);
    }

    // -------------------------------------------------------------------------
    // Back Office Header Hook
    // -------------------------------------------------------------------------

    /**
     * Load PrestaPro admin CSS/JS on configuration pages.
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        $controller = Tools::getValue('controller');
        $moduleName = Tools::getValue('configure');

        if ($moduleName !== $this->name && strpos($controller, 'AdminPP') !== 0) {
            return '';
        }

        $this->context->controller->addCSS(
            $this->getPathUri() . 'views/css/prestapro-carrier.css'
        );
        $this->context->controller->addJS(
            $this->getPathUri() . 'views/js/prestapro-carrier.js'
        );

        return '';
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    private function assignCarrierToAllGroups(Carrier $carrier): void
    {
        $groups = \Group::getGroups(Context::getContext()->language->id);

        foreach ($groups as $group) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int) $carrier->id,
                'id_group' => (int) $group['id_group'],
            ]);
        }
    }

    private function assignCarrierToAllZones(Carrier $carrier): void
    {
        $zones = \Zone::getZones();

        foreach ($zones as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }
    }

    private function createDefaultRange(Carrier $carrier): void
    {
        $rangeWeight = new \RangeWeight();
        $rangeWeight->id_carrier = (int) $carrier->id;
        $rangeWeight->delimiter1 = 0;
        $rangeWeight->delimiter2 = 100;
        $rangeWeight->add();
    }
}
