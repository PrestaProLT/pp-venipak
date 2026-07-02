<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Db;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CarriersController extends FrameworkBundleAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        Request $request,
        #[Autowire(service: 'prestashop.module.ppvenipak.form.carrier_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $form = $formHandler->getForm();
        $form->handleRequest($request);

        if ($request->isMethod('POST') && $form->isSubmitted() && $form->isValid()) {
            $errors = $formHandler->save($form->getData());

            if (empty($errors)) {
                $this->addFlash(
                    'success',
                    $this->trans('Carrier settings updated.', 'Modules.Ppvenipak.Admin')
                );
            } else {
                $this->flashErrors($errors);
            }

            return $this->redirectToRoute('ps_ppvenipak_carriers');
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/carriers.html.twig', [
            'carriersForm' => $form->createView(),
            'multistore_blocked' => false,
            'carrier_status' => $this->loadCarrierStatus(),
        ]);
    }

    /**
     * Live state of the two carriers this module manages, joined to ps_carrier
     * so the admin can see at a glance whether they exist and are active in
     * PrestaShop's own carrier table.
     *
     * @return array{
     *     globally_enabled: bool,
     *     carriers: array<string, array{
     *         exists: bool, active: bool, id_carrier: int, name: string,
     *         reference: int, edit_url: string, label: string,
     *     }>
     * }
     */
    private function loadCarrierStatus(): array
    {
        return [
            'globally_enabled' => (bool) Configuration::get('PPVENIPAK_ENABLED'),
            'carriers' => [
                'courier' => $this->loadCarrierStatusFor(
                    'Venipak Courier',
                    (int) Configuration::get('PPVENIPAK_COURIER_ID_REF')
                ),
                'pickup' => $this->loadCarrierStatusFor(
                    'Venipak Pickup Point',
                    (int) Configuration::get('PPVENIPAK_PICKUP_ID_REF')
                ),
            ],
        ];
    }

    /**
     * @return array{exists: bool, active: bool, id_carrier: int, name: string,
     *     reference: int, edit_url: string, label: string}
     */
    private function loadCarrierStatusFor(string $defaultLabel, int $reference): array
    {
        $base = [
            'exists' => false,
            'active' => false,
            'id_carrier' => 0,
            'name' => $defaultLabel,
            'reference' => $reference,
            'edit_url' => '',
            'label' => $defaultLabel,
        ];

        if ($reference <= 0) {
            return $base;
        }

        // id_reference points to the original carrier; PS clones the row when
        // a merchant edits one — we want the latest non-deleted clone.
        $row = Db::getInstance()->getRow(
            'SELECT `id_carrier`, `name`, `active`
             FROM `' . _DB_PREFIX_ . 'carrier`
             WHERE `id_reference` = ' . $reference . '
             AND `deleted` = 0
             ORDER BY `id_carrier` DESC'
        );

        if (!$row) {
            return $base;
        }

        $idCarrier = (int) $row['id_carrier'];
        $editUrl = '';
        try {
            $editUrl = $this->generateUrl('admin_carriers_edit', ['carrierId' => $idCarrier]);
        } catch (\Throwable $e) {
            // Older PS9 builds may use a different route name; fall back silently.
            $editUrl = '';
        }

        return [
            'exists' => true,
            'active' => (bool) $row['active'],
            'id_carrier' => $idCarrier,
            'name' => (string) $row['name'],
            'reference' => $reference,
            'edit_url' => $editUrl,
            'label' => $defaultLabel,
        ];
    }

}
