<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Db;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderListController extends FrameworkBundleAdminController
{
    private const PAGE_SIZE = 50;
    private const VALID_STATUSES = [
        'new',
        'registered',
        'error',
        'delivered',
        'in_transit',
        'at terminal',
        'out for delivery',
    ];

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(Request $request): Response
    {
        [$where, $params] = $this->buildFilters($request);

        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $db = Db::getInstance();
        $orderTable = _DB_PREFIX_ . 'ppvenipak_order';
        $psOrderTable = _DB_PREFIX_ . 'orders';
        $carrierTable = _DB_PREFIX_ . 'carrier';

        $sqlBase =
            'FROM `' . $orderTable . '` v
             LEFT JOIN `' . $psOrderTable . '` o ON o.id_order = v.id_order
             LEFT JOIN `' . $carrierTable . '` c ON c.id_carrier = o.id_carrier
             ' . $where;

        $total = (int) $db->getValue('SELECT COUNT(*) ' . $sqlBase);

        $rows = $db->executeS(
            'SELECT v.*, o.reference AS ps_reference, o.total_paid_tax_incl AS ps_total,
                    o.id_currency AS ps_id_currency, o.date_add AS ps_date_add,
                    c.id_reference AS current_carrier_ref, c.name AS current_carrier_name
             ' . $sqlBase . '
             ORDER BY v.id_ppvenipak_order DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (int) $offset
        ) ?: [];

        // Decode JSON fields for display.
        foreach ($rows as &$row) {
            $row['_tracking'] = json_decode((string) ($row['tracking_numbers'] ?? '[]'), true) ?: [];
            $row['_terminal'] = json_decode((string) ($row['terminal_info'] ?? '{}'), true) ?: null;

            // Flatten previous attempts into a tracking-number list so the
            // dashboard can surface "old labels" alongside the current one
            // — without it, regenerating a label visually wipes the order's
            // shipping history from the operations view.
            $row['_previous_tracking'] = [];
            if (!empty($row['previous_attempts'])) {
                $prev = json_decode((string) $row['previous_attempts'], true);
                if (is_array($prev)) {
                    foreach ($prev as $p) {
                        if (!empty($p['tracking_numbers']) && is_array($p['tracking_numbers'])) {
                            foreach ($p['tracking_numbers'] as $tn) {
                                $row['_previous_tracking'][] = (string) $tn;
                            }
                        }
                    }
                }
            }
        }
        unset($row);

        // Status counts (ignoring active filters so the user always sees the totals).
        $statusBuckets = $db->executeS(
            'SELECT `status`, COUNT(*) AS c FROM `' . $orderTable . '`
             GROUP BY `status` ORDER BY c DESC'
        ) ?: [];

        $statusCounts = [];
        foreach ($statusBuckets as $b) {
            $statusCounts[$b['status']] = (int) $b['c'];
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/orders.html.twig', [
            'orders' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'page_count' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'filters' => $params,
            'status_counts' => $statusCounts,
            'index_url' => $this->generateUrl('ps_ppvenipak_orders'),
            'multistore_blocked' => false,
            'pickup_ref' => (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF'),
            'courier_ref' => (int) Configuration::get('PPVENIPAK_COURIER_ID_REF'),
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildFilters(Request $request): array
    {
        $status = (string) $request->query->get('status', '');
        $country = strtoupper((string) $request->query->get('country', ''));
        $hasManifest = (string) $request->query->get('manifest', ''); // '', 'yes', 'no'
        $hasTracking = (string) $request->query->get('tracking', ''); // '', 'yes', 'no'
        $codFilter = (string) $request->query->get('cod', '');         // '', '1', '0'
        $search = trim((string) $request->query->get('q', ''));
        $dateFrom = (string) $request->query->get('date_from', '');
        $dateTo = (string) $request->query->get('date_to', '');

        $clauses = [];

        if ($status !== '' && in_array($status, self::VALID_STATUSES, true)) {
            $clauses[] = 'v.`status` = \'' . pSQL($status) . '\'';
        }

        if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country)) {
            $clauses[] = 'v.`country_code` = \'' . pSQL($country) . '\'';
        }

        if ($hasManifest === 'yes') {
            $clauses[] = '(v.`manifest_id` IS NOT NULL AND v.`manifest_id` <> \'\')';
        } elseif ($hasManifest === 'no') {
            $clauses[] = '(v.`manifest_id` IS NULL OR v.`manifest_id` = \'\')';
        }

        if ($hasTracking === 'yes') {
            $clauses[] = '(v.`tracking_numbers` IS NOT NULL AND v.`tracking_numbers` <> \'\' AND v.`tracking_numbers` <> \'[]\')';
        } elseif ($hasTracking === 'no') {
            $clauses[] = '(v.`tracking_numbers` IS NULL OR v.`tracking_numbers` = \'\' OR v.`tracking_numbers` = \'[]\')';
        }

        if ($codFilter === '1') {
            $clauses[] = 'v.`is_cod` = 1';
        } elseif ($codFilter === '0') {
            $clauses[] = 'v.`is_cod` = 0';
        }

        if ($this->isValidDate($dateFrom)) {
            $clauses[] = 'v.`date_add` >= \'' . pSQL($dateFrom) . ' 00:00:00\'';
        }

        if ($this->isValidDate($dateTo)) {
            $clauses[] = 'v.`date_add` <= \'' . pSQL($dateTo) . ' 23:59:59\'';
        }

        if ($search !== '') {
            $needle = '%' . pSQL($search, true) . '%';
            $clauses[] = '(v.`manifest_id` LIKE \'' . $needle . '\''
                . ' OR v.`tracking_numbers` LIKE \'' . $needle . '\''
                . ' OR o.`reference` LIKE \'' . $needle . '\')';
        }

        $where = empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [
            $where,
            [
                'status' => $status,
                'country' => $country,
                'manifest' => $hasManifest,
                'tracking' => $hasTracking,
                'cod' => $codFilter,
                'q' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }

    private function isValidDate(string $value): bool
    {
        return $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

}
