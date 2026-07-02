<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Db;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseController extends FrameworkBundleAdminController
{
    private const TABLE = 'ppvenipak_warehouse';
    private const COUNTRIES = ['LT', 'LV', 'EE', 'PL'];

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        // Strict per-shop scoping: each shop sees only its own warehouses.
        // In CONTEXT_ALL we list everything and surface the shop column so
        // the merchant can audit the multistore setup at a glance.
        $shopContext = Shop::getContext();
        $idShop = (int) (Shop::getContextShopID() ?: 0);

        $where = '';
        if ($shopContext === Shop::CONTEXT_SHOP && $idShop > 0) {
            $where = ' WHERE `id_shop` = ' . $idShop;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . $where
            . ' ORDER BY `id_shop` ASC, `is_default` DESC, `name` ASC'
        ) ?: [];

        // Map id_shop -> shop name for the column shown in CONTEXT_ALL.
        $shopNames = [];
        foreach (Shop::getShops(false) as $shop) {
            $shopNames[(int) $shop['id_shop']] = (string) $shop['name'];
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/warehouses.html.twig', [
            'warehouses' => $rows,
            'index_url' => $this->generateUrl('ps_ppvenipak_warehouses'),
            'create_url' => $this->generateUrl('ps_ppvenipak_warehouse_create'),
            'multistore_blocked' => false,
            'show_shop_column' => $shopContext !== Shop::CONTEXT_SHOP,
            'shop_names' => $shopNames,
            'current_shop_id' => $idShop,
        ]);
    }

    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))")]
    public function create(Request $request): Response
    {
        return $this->renderWarehouseForm($request, null);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function edit(Request $request, int $id): Response
    {
        $warehouse = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_ppvenipak_warehouse` = ' . $id
        );

        if (!$warehouse) {
            $this->addFlash('error', $this->trans('Warehouse not found.', 'Modules.Ppvenipak.Admin'));

            return $this->redirectToRoute('ps_ppvenipak_warehouses');
        }

        return $this->renderWarehouseForm($request, $warehouse);
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))")]
    public function delete(int $id): RedirectResponse
    {
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `id_ppvenipak_warehouse`, `is_default` FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_ppvenipak_warehouse` = ' . $id
        );

        if (!$row) {
            $this->addFlash('error', $this->trans('Warehouse not found.', 'Modules.Ppvenipak.Admin'));

            return $this->redirectToRoute('ps_ppvenipak_warehouses');
        }

        if ((int) $row['is_default']) {
            $this->addFlash(
                'error',
                $this->trans('Cannot delete the default warehouse. Set another warehouse as default first.', 'Modules.Ppvenipak.Admin')
            );

            return $this->redirectToRoute('ps_ppvenipak_warehouses');
        }

        // Block deletion if any orders still reference this warehouse.
        $orderCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppvenipak_order`
             WHERE `id_warehouse` = ' . $id
        );

        if ($orderCount > 0) {
            $this->addFlash(
                'error',
                $this->trans(
                    'Cannot delete warehouse — %count% order(s) still reference it.',
                    'Modules.Ppvenipak.Admin',
                    ['%count%' => $orderCount]
                )
            );

            return $this->redirectToRoute('ps_ppvenipak_warehouses');
        }

        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_ppvenipak_warehouse` = ' . $id
        );

        $this->addFlash('success', $this->trans('Warehouse deleted.', 'Modules.Ppvenipak.Admin'));

        return $this->redirectToRoute('ps_ppvenipak_warehouses');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function setDefault(int $id): RedirectResponse
    {
        $db = Db::getInstance();
        $idShop = (int) (Shop::getContextShopID() ?: 1);

        $exists = $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_ppvenipak_warehouse` = ' . $id
        );

        if (!$exists) {
            $this->addFlash('error', $this->trans('Warehouse not found.', 'Modules.Ppvenipak.Admin'));

            return $this->redirectToRoute('ps_ppvenipak_warehouses');
        }

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `is_default` = (CASE WHEN `id_ppvenipak_warehouse` = ' . $id . ' THEN 1 ELSE 0 END)
             WHERE `id_shop` = ' . $idShop
        );

        $this->addFlash('success', $this->trans('Default warehouse updated.', 'Modules.Ppvenipak.Admin'));

        return $this->redirectToRoute('ps_ppvenipak_warehouses');
    }

    private function renderWarehouseForm(Request $request, ?array $warehouse): Response
    {
        $isEdit = $warehouse !== null;
        $errors = [];
        $data = $warehouse ?? [
            'name' => '',
            'company_code' => '',
            'contact' => '',
            'country_code' => 'LT',
            'city' => '',
            'address' => '',
            'zip_code' => '',
            'phone' => '',
            'is_default' => 0,
        ];

        if ($request->isMethod('POST')) {
            $data['name'] = trim((string) $request->request->get('name', ''));
            $data['company_code'] = trim((string) $request->request->get('company_code', ''));
            $data['contact'] = trim((string) $request->request->get('contact', ''));
            $data['country_code'] = strtoupper(trim((string) $request->request->get('country_code', 'LT')));
            $data['city'] = trim((string) $request->request->get('city', ''));
            $data['address'] = trim((string) $request->request->get('address', ''));
            $data['zip_code'] = trim((string) $request->request->get('zip_code', ''));
            $data['phone'] = trim((string) $request->request->get('phone', ''));
            $data['is_default'] = (int) (bool) $request->request->get('is_default');

            if ($data['name'] === '') {
                $errors[] = $this->trans('Name is required.', 'Modules.Ppvenipak.Admin');
            }
            if ($data['city'] === '') {
                $errors[] = $this->trans('City is required.', 'Modules.Ppvenipak.Admin');
            }
            if ($data['address'] === '') {
                $errors[] = $this->trans('Address is required.', 'Modules.Ppvenipak.Admin');
            }
            if ($data['zip_code'] === '') {
                $errors[] = $this->trans('Postal code is required.', 'Modules.Ppvenipak.Admin');
            }
            if (!in_array($data['country_code'], self::COUNTRIES, true)) {
                $errors[] = $this->trans('Country must be one of LT, LV, EE, PL.', 'Modules.Ppvenipak.Admin');
            }

            if (empty($errors)) {
                $idShop = (int) (Shop::getContextShopID() ?: 1);
                $payload = [
                    'name' => pSQL($data['name']),
                    'company_code' => pSQL($data['company_code']),
                    'contact' => pSQL($data['contact']),
                    'country_code' => pSQL($data['country_code']),
                    'city' => pSQL($data['city']),
                    'address' => pSQL($data['address']),
                    'zip_code' => pSQL($data['zip_code']),
                    'phone' => pSQL($data['phone']),
                    'id_shop' => $idShop,
                    'is_default' => $data['is_default'],
                ];

                $db = Db::getInstance();

                if ($data['is_default']) {
                    $db->execute(
                        'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
                         SET `is_default` = 0 WHERE `id_shop` = ' . $idShop
                    );
                }

                if ($isEdit) {
                    $db->update(self::TABLE, $payload, '`id_ppvenipak_warehouse` = ' . (int) $warehouse['id_ppvenipak_warehouse']);
                    $this->addFlash('success', $this->trans('Warehouse updated.', 'Modules.Ppvenipak.Admin'));
                } else {
                    $db->insert(self::TABLE, $payload);
                    $this->addFlash('success', $this->trans('Warehouse created.', 'Modules.Ppvenipak.Admin'));
                }

                return $this->redirectToRoute('ps_ppvenipak_warehouses');
            }
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/warehouse_form.html.twig', [
            'is_edit' => $isEdit,
            'data' => $data,
            'errors' => $errors,
            'countries' => self::COUNTRIES,
            'cancel_url' => $this->generateUrl('ps_ppvenipak_warehouses'),
            'multistore_blocked' => false,
        ]);
    }

}
