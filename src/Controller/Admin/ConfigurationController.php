<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Module;
use PrestaShop\Module\PPVenipak\Form\ConfigurationFormDataProvider;
use PrestaShop\Module\PPVenipak\Form\ConfigurationFormType;
use PrestaShop\Module\PPVenipak\Service\MigrationService;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
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
        $module = Module::getInstanceByName('ppvenipak');

        $storeAuthMode = (bool) Configuration::getGlobalValue('PPVENIPAK_STORE_AUTH_MODE');
        $shopContext = Shop::getContext();

        // Lockdowns — render a banner instead of the form when the current
        // context can't sensibly edit the credentials:
        //   - per-shop mode + CONTEXT_GROUP: a group covers multiple shops,
        //     saving a single credential here would silently overwrite each
        //     member's.
        //   - shared mode + CONTEXT_SHOP/GROUP: credentials live globally,
        //     editing them from a specific shop would suggest that shop has
        //     its own copy when it doesn't.
        $lockdownReason = null;
        if ($storeAuthMode && $shopContext === Shop::CONTEXT_GROUP) {
            $lockdownReason = 'group';
        } elseif (!$storeAuthMode && $shopContext !== Shop::CONTEXT_ALL) {
            $lockdownReason = 'shared';
        }

        if ($lockdownReason !== null) {
            return $this->render('@Modules/ppvenipak/views/templates/admin/configuration.html.twig', [
                'configurationForm' => null,
                'multistore_blocked' => true,
                'multistore_reason' => $lockdownReason,
                'connection_status' => ['connected' => null, 'message' => ''],
                'legacy_modules' => [],
                'test_connection_url' => '',
                'migration_preview_url' => '',
                'migration_migrate_url' => '',
                'migration_skip_url' => '',
                'module_version' => $module ? $module->version : '',
            ]);
        }

        // Pick the form variant for this (auth-mode, context) pair:
        //   - per-shop ON  + CONTEXT_ALL  → toggle only (creds belong per-shop)
        //   - per-shop ON  + CONTEXT_SHOP → full per-shop form, no toggle
        //   - per-shop OFF + CONTEXT_ALL  → full form + toggle, all global
        if ($storeAuthMode && $shopContext === Shop::CONTEXT_ALL) {
            $formMode = 'global_toggle';
        } elseif (!$storeAuthMode) {
            $formMode = 'global_full';
        } else {
            $formMode = 'shop';
        }

        if ($formMode === 'global_toggle') {
            $configurationForm = $this->createForm(
                ConfigurationFormType::class,
                ['store_auth_mode' => $storeAuthMode],
                ['mode' => 'global_toggle']
            );
            $configurationForm->handleRequest($request);

            if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
                $data = $configurationForm->getData();
                Configuration::updateGlobalValue('PPVENIPAK_STORE_AUTH_MODE', !empty($data['store_auth_mode']) ? '1' : '0');
                $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Ppvenipak.Admin'));

                return $this->redirectToRoute('ps_ppvenipak_configuration');
            }
        } elseif ($formMode === 'global_full') {
            // Shared-credential mode: build the full form manually so we
            // can inject the toggle. The PS FormHandler service is bound
            // to mode='shop' and isn't reusable here, so we drive the data
            // provider directly.
            $dataProvider = new ConfigurationFormDataProvider();
            $configurationForm = $this->createForm(
                ConfigurationFormType::class,
                $dataProvider->getData(),
                ['mode' => 'global_full']
            );
            $configurationForm->handleRequest($request);

            if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
                $errors = $dataProvider->setData($configurationForm->getData());

                if (empty($errors)) {
                    $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Ppvenipak.Admin'));
                } else {
                    $this->flashErrors($errors);
                }

                return $this->redirectToRoute('ps_ppvenipak_configuration');
            }
        } else {
            $configurationForm = $formHandler->getForm();
            $configurationForm->handleRequest($request);

            if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
                $errors = $formHandler->save($configurationForm->getData());

                if (empty($errors)) {
                    $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Ppvenipak.Admin'));
                } else {
                    $this->flashErrors($errors);
                }

                return $this->redirectToRoute('ps_ppvenipak_configuration');
            }
        }

        $connectionStatus = $this->getConnectionStatus();

        // Detect legacy mijoravenipak module for the migration banner.
        $legacyModules = [];
        $migrationSkipped = (bool) Configuration::get('PPVENIPAK_MIGRATION_SKIPPED');

        if (!$migrationSkipped) {
            $migrationService = new MigrationService();
            $detection = $migrationService->detect();

            if ($detection) {
                $detection['preview'] = $migrationService->preview();
                $legacyModules[] = $detection;
            }
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/configuration.html.twig', [
            'configurationForm' => $configurationForm->createView(),
            'multistore_blocked' => false,
            'multistore_reason' => null,
            'connection_status' => $connectionStatus,
            'legacy_modules' => $legacyModules,
            'test_connection_url' => $this->generateUrl('ps_ppvenipak_test_connection'),
            'migration_preview_url' => $this->generateUrl('ps_ppvenipak_migration_preview'),
            'migration_migrate_url' => $this->generateUrl('ps_ppvenipak_migration_migrate'),
            'migration_skip_url' => $this->generateUrl('ps_ppvenipak_migration_skip'),
            'module_version' => $module ? $module->version : '',
            // form_mode drives template branching:
            //   - 'shop'           → render per-shop fields only
            //   - 'global_toggle'  → render only the per-store auth toggle
            //   - 'global_full'    → render every field + the toggle
            'form_mode' => $formMode,
        ]);
    }


    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function testConnection(Request $request): JsonResponse
    {
        $apiUser = (string) Configuration::get('PPVENIPAK_API_USER');
        $apiPass = (string) Configuration::get('PPVENIPAK_API_PASS');
        $configuredApiId = trim((string) Configuration::get('PPVENIPAK_API_ID'));

        if ($apiUser === '' || $apiPass === '') {
            $msg = $this->trans('API credentials are not configured.', 'Modules.Ppvenipak.Admin');
            $this->saveConnectionTestResult(false, $msg);

            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        try {
            $client = new \PrestaShop\Module\PPVenipak\Api\VenipakApiClient();
            $returnedApiId = $client->getApiId();
        } catch (\Throwable $e) {
            $msg = $this->trans(
                'Connection error: %error%',
                'Modules.Ppvenipak.Admin',
                ['%error%' => $e->getMessage()]
            );
            $this->saveConnectionTestResult(false, $msg);

            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        if ($returnedApiId === '') {
            $msg = $this->trans(
                'Venipak rejected the credentials. Double-check the username and password (the password was just re-saved).',
                'Modules.Ppvenipak.Admin'
            );
            $this->saveConnectionTestResult(false, $msg);

            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        // Sandbox vs production hint based on the URL the client just used.
        $liveMode = (bool) Configuration::get('PPVENIPAK_LIVE_MODE');
        $envLabel = $liveMode ? 'production (go.venipak.lt)' : 'sandbox (gouat.venipak.lt)';

        if ($configuredApiId !== '' && $configuredApiId !== $returnedApiId) {
            $msg = $this->trans(
                'Connected to %env% as API ID %returned% — but the configured API ID is %configured%. The credentials work; consider updating the API ID field.',
                'Modules.Ppvenipak.Admin',
                [
                    '%env%' => $envLabel,
                    '%returned%' => $returnedApiId,
                    '%configured%' => $configuredApiId,
                ]
            );
            $this->saveConnectionTestResult(true, $msg);

            return new JsonResponse(['success' => true, 'message' => $msg]);
        }

        $msg = $this->trans(
            'Connected to %env% — Venipak API ID %id%.',
            'Modules.Ppvenipak.Admin',
            ['%env%' => $envLabel, '%id%' => $returnedApiId]
        );
        $this->saveConnectionTestResult(true, $msg);

        return new JsonResponse(['success' => true, 'message' => $msg]);
    }

    /**
     * Resolve the cached test result. Re-using a saved status keeps the page
     * from looking "untested" every reload — the only way the cache becomes
     * stale is when the merchant changes credentials, which the fingerprint
     * (api_user + api_pass + live_mode) detects automatically.
     */
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

        $savedSig = (string) Configuration::get('PPVENIPAK_API_LAST_TEST_SIG');
        $currentSig = $this->credentialsSignature();

        if ($savedSig === '' || $savedSig !== $currentSig) {
            return [
                'connected' => null,
                'message' => $savedSig === ''
                    ? 'Click "Test Connection" to verify'
                    : 'Credentials changed since last test — click Test Connection to verify',
            ];
        }

        $ok = (bool) Configuration::get('PPVENIPAK_API_LAST_TEST_OK');
        $msg = (string) Configuration::get('PPVENIPAK_API_LAST_TEST_MSG');
        $at = (int) Configuration::get('PPVENIPAK_API_LAST_TEST_AT');

        if ($at > 0) {
            $msg = trim($msg) . ' (last verified ' . $this->formatRelativeTime($at) . ')';
        }

        return [
            'connected' => $ok,
            'message' => $msg !== '' ? $msg : ($ok ? 'Connection verified' : 'Connection failed'),
        ];
    }

    private function saveConnectionTestResult(bool $ok, string $message): void
    {
        Configuration::updateValue('PPVENIPAK_API_LAST_TEST_OK', $ok ? 1 : 0);
        Configuration::updateValue('PPVENIPAK_API_LAST_TEST_MSG', $message);
        Configuration::updateValue('PPVENIPAK_API_LAST_TEST_AT', time());
        Configuration::updateValue('PPVENIPAK_API_LAST_TEST_SIG', $this->credentialsSignature());
    }

    private function credentialsSignature(): string
    {
        return sha1(
            (string) Configuration::get('PPVENIPAK_API_USER')
            . '|' . (string) Configuration::get('PPVENIPAK_API_PASS')
            . '|' . (Configuration::get('PPVENIPAK_LIVE_MODE') ? '1' : '0')
        );
    }

    private function formatRelativeTime(int $timestamp): string
    {
        $delta = max(0, time() - $timestamp);

        if ($delta < 60) {
            return 'just now';
        }
        if ($delta < 3600) {
            $m = (int) floor($delta / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($delta < 86400) {
            $h = (int) floor($delta / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        $d = (int) floor($delta / 86400);
        if ($d < 30) {
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }

        return 'on ' . date('Y-m-d', $timestamp);
    }
}
