<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Module;
use PaymentModule;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CodController extends FrameworkBundleAdminController
{
    private const CONFIG_KEY = 'PPVENIPAK_COD_MODULES';

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        $selected = $this->loadSelectedModules();
        $available = $this->loadAvailablePaymentModules();
        $orphans = $this->detectOrphanModules($selected, $available);

        return $this->render('@Modules/ppvenipak/views/templates/admin/cod.html.twig', [
            'available_modules' => $available,
            'selected' => $selected,
            'orphans' => $orphans,
            'save_url' => $this->generateUrl('ps_ppvenipak_cod_save'),
            'multistore_blocked' => false,
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function save(Request $request): RedirectResponse
    {
        $picked = (array) $request->request->all('modules');
        $clean = [];

        foreach ($picked as $name) {
            $name = trim((string) $name);
            if ($name === '' || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
                continue;
            }
            $clean[$name] = true;
        }

        Configuration::updateValue(self::CONFIG_KEY, implode(',', array_keys($clean)));

        $this->addFlash(
            'success',
            $this->trans(
                '%count% payment method(s) flagged as Cash on Delivery.',
                'Modules.Ppvenipak.Admin',
                ['%count%' => count($clean)]
            )
        );

        return $this->redirectToRoute('ps_ppvenipak_cod');
    }

    /**
     * @return string[]
     */
    private function loadSelectedModules(): array
    {
        $raw = (string) Configuration::get(self::CONFIG_KEY);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @return array<int, array{name: string, display_name: string, version: string, installed: bool}>
     */
    private function loadAvailablePaymentModules(): array
    {
        if (!class_exists('PaymentModule')) {
            return [];
        }

        $rows = PaymentModule::getInstalledPaymentModules() ?: [];
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $instance = Module::getInstanceByName($name);
            $result[] = [
                'name' => $name,
                'display_name' => $instance && $instance->displayName ? (string) $instance->displayName : $name,
                'version' => $instance ? (string) $instance->version : '',
                'installed' => (bool) $instance,
            ];
        }

        usort($result, static fn ($a, $b) => strcasecmp($a['display_name'], $b['display_name']));

        return $result;
    }

    /**
     * Selected entries that no longer match any installed payment module.
     * Surfacing them lets the admin clean up after uninstalling a payment
     * module without losing track of what was previously flagged.
     *
     * @param string[] $selected
     * @param array<int, array{name: string}> $available
     * @return string[]
     */
    private function detectOrphanModules(array $selected, array $available): array
    {
        $availableNames = array_column($available, 'name');

        return array_values(array_diff($selected, $availableNames));
    }

}
