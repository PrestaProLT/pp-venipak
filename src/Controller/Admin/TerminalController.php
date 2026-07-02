<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Db;
use PrestaShop\Module\PPVenipak\Api\VenipakApiClient;
use PrestaShop\Module\PPVenipak\Carrier\TerminalSync;
use PrestaShop\Module\PPVenipak\Service\ApiLogger;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TerminalController extends FrameworkBundleAdminController
{
    private const COUNTRIES = ['LT', 'LV', 'EE', 'PL'];
    private const STALE_DAYS = 7;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(Request $request): Response
    {
        $country = strtoupper((string) $request->query->get('country', 'LT'));
        if (!in_array($country, self::COUNTRIES, true)) {
            $country = 'LT';
        }

        $search = trim((string) $request->query->get('q', ''));
        $typeFilter = (string) $request->query->get('type', '');
        $codFilter = (string) $request->query->get('cod', '');

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'ppvenipak_terminal';

        $where = ['`country_code` = \'' . pSQL($country) . '\''];

        if ($search !== '') {
            $needle = '%' . pSQL($search, true) . '%';
            $where[] = '(`name` LIKE \'' . $needle . '\' OR `code` LIKE \'' . $needle . '\' OR `city` LIKE \'' . $needle . '\' OR `address` LIKE \'' . $needle . '\' OR `zip` LIKE \'' . $needle . '\')';
        }

        if ($typeFilter === 'locker') {
            $where[] = '`terminal_type` = 3';
        } elseif ($typeFilter === 'shop') {
            $where[] = '`terminal_type` = 1';
        }

        if ($codFilter === '1') {
            $where[] = '`cod_enabled` = 1';
        } elseif ($codFilter === '0') {
            $where[] = '`cod_enabled` = 0';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $terminals = $db->executeS(
            'SELECT * FROM `' . $table . '` ' . $whereSql . ' ORDER BY `city` ASC, `name` ASC LIMIT 500'
        ) ?: [];

        // Per-country last sync + count for stat tiles.
        $stats = [];
        foreach (self::COUNTRIES as $cc) {
            $row = $db->getRow(
                'SELECT COUNT(*) AS total, MAX(`date_upd`) AS last_sync
                 FROM `' . $table . '`
                 WHERE `country_code` = \'' . pSQL($cc) . '\''
            );
            $lastSync = $row['last_sync'] ?? null;
            $isStale = false;
            if ($lastSync) {
                $daysSince = (new \DateTimeImmutable())->diff(new \DateTimeImmutable($lastSync))->days;
                $isStale = $daysSince > self::STALE_DAYS;
            }
            $stats[$cc] = [
                'country' => $cc,
                'total' => (int) ($row['total'] ?? 0),
                'last_sync' => $lastSync,
                'stale' => $isStale,
            ];
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/terminals.html.twig', [
            'active_country' => $country,
            'countries' => self::COUNTRIES,
            'stats' => $stats,
            'terminals' => $terminals,
            'filters' => [
                'q' => $search,
                'type' => $typeFilter,
                'cod' => $codFilter,
            ],
            'sync_url' => $this->generateUrl('ps_ppvenipak_terminals_sync'),
            'toggle_url' => $this->generateUrl('ps_ppvenipak_terminals_toggle'),
            'index_url' => $this->generateUrl('ps_ppvenipak_terminals'),
            'multistore_blocked' => false,
        ]);
    }

    /**
     * Enable/disable a single pickup point. Disabled points stay in the cache
     * and admin list but are hidden from the checkout terminal selector.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function toggle(Request $request): RedirectResponse
    {
        $id = (int) $request->request->get('id_terminal', 0);
        $country = strtoupper((string) $request->request->get('country', 'LT'));
        $redirectParams = in_array($country, self::COUNTRIES, true) ? ['country' => $country] : [];

        if ($id <= 0) {
            $this->addFlash('error', $this->trans('Invalid pickup point.', 'Modules.Ppvenipak.Admin'));

            return $this->redirectToRoute('ps_ppvenipak_terminals', $redirectParams);
        }

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'ppvenipak_terminal';
        $db->execute(
            'UPDATE `' . $table . '` SET `enabled` = 1 - `enabled` WHERE `id_ppvenipak_terminal` = ' . $id
        );

        $this->addFlash('success', $this->trans('Pickup point visibility updated.', 'Modules.Ppvenipak.Admin'));

        return $this->redirectToRoute('ps_ppvenipak_terminals', $redirectParams);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function sync(Request $request): RedirectResponse
    {
        $country = strtoupper((string) $request->request->get('country', ''));

        if ($country === 'ALL') {
            $countries = self::COUNTRIES;
        } elseif (in_array($country, self::COUNTRIES, true)) {
            $countries = [$country];
        } else {
            $this->addFlash('error', $this->trans('Invalid country code.', 'Modules.Ppvenipak.Admin'));

            return $this->redirectToRoute('ps_ppvenipak_terminals');
        }

        try {
            $apiClient = new VenipakApiClient();
            $sync = new TerminalSync($apiClient);

            $totals = [];
            foreach ($countries as $cc) {
                $totals[$cc] = $sync->syncCountry($cc);
            }

            $summary = implode(', ', array_map(
                fn ($cc) => $cc . ': ' . $totals[$cc],
                array_keys($totals)
            ));

            $this->addFlash(
                'success',
                $this->trans(
                    'Pickup points synced — %summary%.',
                    'Modules.Ppvenipak.Admin',
                    ['%summary%' => $summary]
                )
            );
        } catch (\Throwable $e) {
            ApiLogger::logException('TerminalController::sync', $e, 'ws/get_pickup_points');
            $this->addFlash(
                'error',
                $this->trans(
                    'Sync failed: %error%',
                    'Modules.Ppvenipak.Admin',
                    ['%error%' => $e->getMessage()]
                )
            );
        }

        return $this->redirectToRoute('ps_ppvenipak_terminals', $country !== 'ALL' ? ['country' => $country] : []);
    }

}
