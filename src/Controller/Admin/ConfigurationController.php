<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Module;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigurationController extends FrameworkBundleAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        Request $request,
        #[Autowire(service: 'prestashop.module.ppvenipak.form.configuration_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $configurationForm = $formHandler->getForm();
        $configurationForm->handleRequest($request);

        if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
            $errors = $formHandler->save($configurationForm->getData());

            if (empty($errors)) {
                $this->addFlash('success', $this->trans('Settings updated successfully.', [], 'Modules.Ppvenipak.Admin'));
            } else {
                $this->flashErrors($errors);
            }

            return $this->redirectToRoute('ps_ppvenipak_configuration');
        }

        $module = Module::getInstanceByName('ppvenipak');

        // Connection status check
        $connectionStatus = $this->getConnectionStatus();

        // Detect legacy module
        $carrierName = Configuration::get('PPVENIPAK_CARRIER_COURIER')
            ? 'Venipak'
            : null;

        return $this->render('@Modules/ppvenipak/views/templates/admin/configuration.html.twig', [
            'configurationForm' => $configurationForm->createView(),
            'module_logo' => $module->getPathUri() . 'logo.png',
            'module_display_name' => $module->displayName,
            'module_version' => $module->version,
            'docs_url' => 'https://prestapro.lt/modules/ppvenipak/docs',
            'support_url' => 'https://prestapro.lt/support',
            'github_url' => null,
            'connection_status' => $connectionStatus,
            'carrier_name' => $carrierName,
            'test_connection_url' => $this->generateUrl('ps_ppvenipak_test_connection'),
            'translation_domain' => 'Modules.Ppvenipak.Admin',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function testConnection(Request $request): JsonResponse
    {
        try {
            $apiUser = Configuration::get('PPVENIPAK_API_USER');
            $apiPass = Configuration::get('PPVENIPAK_API_PASS');

            if (empty($apiUser) || empty($apiPass)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->trans('API credentials are not configured.', [], 'Modules.Ppvenipak.Admin'),
                ]);
            }

            $liveMode = (bool) Configuration::get('PPVENIPAK_LIVE_MODE');
            $baseUrl = $liveMode
                ? 'https://go.venipak.lt/ws/api'
                : 'https://go.venipak.lt/ws/api';

            $url = $baseUrl . '/pickup-points?country=LT&limit=1';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_USERPWD => $apiUser . ':' . $apiPass,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->trans('Connection error: %error%', ['%error%' => $curlError], 'Modules.Ppvenipak.Admin'),
                ]);
            }

            if ($httpCode === 200) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $this->trans('Connection successful! API is responding.', [], 'Modules.Ppvenipak.Admin'),
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('API returned HTTP %code%. Check your credentials.', ['%code%' => $httpCode], 'Modules.Ppvenipak.Admin'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function getConnectionStatus(): array
    {
        $apiUser = Configuration::get('PPVENIPAK_API_USER');
        $apiPass = Configuration::get('PPVENIPAK_API_PASS');

        if (empty($apiUser) || empty($apiPass)) {
            return [
                'connected' => false,
                'message' => 'API credentials not configured',
            ];
        }

        return [
            'connected' => null,
            'message' => 'Click "Test Connection" to verify',
        ];
    }
}
