<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use PrestaShop\Module\PPVenipak\Service\MigrationService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class MigrationController extends FrameworkBundleAdminController
{
    /**
     * Preview migration — show what will be migrated.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function preview(Request $request): JsonResponse
    {
        try {
            $service = new MigrationService();
            $detection = $service->detect();

            if (!$detection) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No legacy module detected.',
                ]);
            }

            $preview = $service->preview();

            return new JsonResponse([
                'success' => true,
                'detection' => $detection,
                'preview' => $preview,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Preview failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute migration.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function migrate(Request $request): JsonResponse
    {
        $dryRun = (bool) $request->request->get('dry_run', false);

        try {
            $service = new MigrationService();
            $detection = $service->detect();

            if (!$detection) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No legacy module detected.',
                ]);
            }

            $result = $service->migrate($dryRun);

            return new JsonResponse([
                'success' => $result['success'],
                'log' => $result['log'],
                'errors' => $result['errors'],
                'dry_run' => $dryRun,
                'message' => $result['success']
                    ? ($dryRun ? 'Dry-run completed successfully.' : 'Migration completed successfully.')
                    : 'Migration completed with errors.',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Skip/dismiss migration banner.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function skip(Request $request): JsonResponse
    {
        \Configuration::updateValue('PPVENIPAK_MIGRATION_SKIPPED', '1');

        return new JsonResponse([
            'success' => true,
            'message' => 'Migration dismissed.',
        ]);
    }
}
