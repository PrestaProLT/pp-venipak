<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Db;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\HttpFoundation\Response;

class ManifestListController extends FrameworkBundleAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        $db = Db::getInstance();
        $orderTable = _DB_PREFIX_ . 'ppvenipak_order';
        $manifestTable = _DB_PREFIX_ . 'ppvenipak_manifest';
        $whTable = _DB_PREFIX_ . 'ppvenipak_warehouse';
        $psOrderTable = _DB_PREFIX_ . 'orders';

        // 1) Orders that have labels (status=registered, tracking present) but
        //    are not yet attached to a manifest. These need a manifest cycle.
        $awaitingManifest = $db->executeS(
            'SELECT v.*, o.reference AS ps_reference, o.date_add AS ps_date_add
             FROM `' . $orderTable . '` v
             LEFT JOIN `' . $psOrderTable . '` o ON o.id_order = v.id_order
             WHERE v.`status` = \'registered\'
             AND v.`tracking_numbers` IS NOT NULL
             AND v.`tracking_numbers` <> \'\'
             AND v.`tracking_numbers` <> \'[]\'
             AND (v.`manifest_id` IS NULL OR v.`manifest_id` = \'\')
             ORDER BY v.`date_add` ASC
             LIMIT 200'
        ) ?: [];

        foreach ($awaitingManifest as &$row) {
            $row['_tracking'] = json_decode((string) ($row['tracking_numbers'] ?? '[]'), true) ?: [];
        }
        unset($row);

        // 2) Open manifests (closed = 0) with order count and warehouse name.
        $openManifests = $this->loadManifests('m.`closed` = 0', 100);

        // 3) Recently closed manifests for context.
        $closedManifests = $this->loadManifests('m.`closed` = 1', 30);

        return $this->render('@Modules/ppvenipak/views/templates/admin/manifests.html.twig', [
            'awaiting_manifest' => $awaitingManifest,
            'open_manifests' => $openManifests,
            'closed_manifests' => $closedManifests,
            'multistore_blocked' => false,
        ]);
    }

    private function loadManifests(string $whereClause, int $limit): array
    {
        $db = Db::getInstance();
        $orderTable = _DB_PREFIX_ . 'ppvenipak_order';
        $manifestTable = _DB_PREFIX_ . 'ppvenipak_manifest';
        $whTable = _DB_PREFIX_ . 'ppvenipak_warehouse';

        // Order count includes orders that PREVIOUSLY referenced the
        // manifest too, so regenerating a label on one of them doesn't
        // shrink the historical count. The previous_attempts JSON column
        // stores objects like {"manifest_id":"<MID>",...}; matching on
        // the verbatim `"manifest_id":"<MID>"` substring is reliable
        // because the manifest_id values are alphanumeric (no escape
        // concerns) and json_encode always quotes string values.
        $matchActiveOrArchived =
            '`manifest_id` = m.`manifest_id`
             OR `previous_attempts` LIKE CONCAT(\'%"manifest_id":"\', m.`manifest_id`, \'"%\')';

        return $db->executeS(
            'SELECT m.*,
                    w.`name` AS warehouse_name,
                    (SELECT COUNT(*) FROM `' . $orderTable . '`
                     WHERE ' . $matchActiveOrArchived . ') AS order_count,
                    (SELECT COUNT(*) FROM `' . $orderTable . '`
                     WHERE (' . $matchActiveOrArchived . ') AND `is_cod` = 1) AS cod_count
             FROM `' . $manifestTable . '` m
             LEFT JOIN `' . $whTable . '` w ON w.`id_ppvenipak_warehouse` = m.`id_warehouse`
             WHERE ' . $whereClause . '
             ORDER BY m.`id_ppvenipak_manifest` DESC
             LIMIT ' . (int) $limit
        ) ?: [];
    }

}
