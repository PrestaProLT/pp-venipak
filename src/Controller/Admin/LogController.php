<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Db;
use PrestaShop\Module\PPVenipak\Service\ApiLogger;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LogController extends FrameworkBundleAdminController
{
    private const PAGE_SIZE = 50;
    private const TABLE = 'ppvenipak_log';

    private const VALID_LEVELS = [
        ApiLogger::LEVEL_ERROR,
        ApiLogger::LEVEL_WARNING,
        ApiLogger::LEVEL_INFO,
    ];

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(Request $request): Response
    {
        [$where, $params] = $this->buildFilters($request);

        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . self::TABLE;

        $total = (int) $db->getValue('SELECT COUNT(*) FROM `' . $table . '` ' . $where);

        $rows = $db->executeS(
            'SELECT `id_ppvenipak_log`, `date_add`, `level`, `source`, `endpoint`,
                    `error_code`, `message`, `context`,
                    `request_body`, `response_body`, `id_order`
             FROM `' . $table . '` '
            . $where
            . ' ORDER BY `id_ppvenipak_log` DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (int) $offset
        ) ?: [];

        // Extract Venipak's verbatim error texts from the structured context
        // so the merchant can see what the API actually said without having
        // to expand the row. The humanized title still owns the main column;
        // the raw text appears as a small subtitle right below it.
        foreach ($rows as &$row) {
            $row['venipak_raw'] = $this->extractRawErrors((string) ($row['context'] ?? ''));
        }
        unset($row);

        $sources = $db->executeS(
            'SELECT DISTINCT `source` FROM `' . $table . '`
             WHERE `source` <> \'\' ORDER BY `source` ASC'
        ) ?: [];

        return $this->render('@Modules/ppvenipak/views/templates/admin/log.html.twig', [
            'logs' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'page_count' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'filters' => $params,
            'levels' => self::VALID_LEVELS,
            'sources' => array_column($sources, 'source'),
            'detail_url' => $this->generateUrl('ps_ppvenipak_log_detail'),
            'cleanup_url' => $this->generateUrl('ps_ppvenipak_log_cleanup'),
            'index_url' => $this->generateUrl('ps_ppvenipak_log'),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function detail(Request $request): JsonResponse
    {
        $id = (int) $request->query->get('id', 0);

        if ($id <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid log ID.'], 400);
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_ppvenipak_log` = ' . $id
        );

        if (!$row) {
            return new JsonResponse(['success' => false, 'message' => 'Log entry not found.'], 404);
        }

        $context = $row['context'] ? json_decode($row['context'], true) : null;
        if (!is_array($context)) {
            $context = $row['context'];
        }

        return new JsonResponse([
            'success' => true,
            'log' => [
                'id' => (int) $row['id_ppvenipak_log'],
                'date_add' => $row['date_add'],
                'level' => $row['level'],
                'source' => $row['source'],
                'endpoint' => $row['endpoint'],
                'error_code' => $row['error_code'] !== null ? (int) $row['error_code'] : null,
                'message' => $row['message'],
                'request_body' => $row['request_body'],
                'response_body' => $row['response_body'],
                'context' => $context,
                'id_order' => (int) $row['id_order'],
                'id_shop' => (int) $row['id_shop'],
            ],
        ]);
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))")]
    public function cleanup(Request $request): RedirectResponse
    {
        $retention = max(0, (int) $request->request->get('retention_days', 0));

        if ($retention > 0) {
            $deleted = ApiLogger::cleanup($retention);
            $this->addFlash(
                'success',
                $this->trans(
                    'Removed %count% log entries older than %days% days.',
                    'Modules.Ppvenipak.Admin',
                    ['%count%' => $deleted, '%days%' => $retention]
                )
            );
        } else {
            $deleted = (int) Db::getInstance()->execute(
                'TRUNCATE TABLE `' . _DB_PREFIX_ . self::TABLE . '`'
            );
            $this->addFlash(
                'success',
                $this->trans('Log table cleared.', 'Modules.Ppvenipak.Admin')
            );
        }

        return $this->redirectToRoute('ps_ppvenipak_log');
    }

    /**
     * Build the WHERE clause and the active filter map from query params.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildFilters(Request $request): array
    {
        $level = (string) $request->query->get('level', '');
        $source = (string) $request->query->get('source', '');
        $errorCode = trim((string) $request->query->get('error_code', ''));
        $idOrder = (int) $request->query->get('id_order', 0);
        $dateFrom = (string) $request->query->get('date_from', '');
        $dateTo = (string) $request->query->get('date_to', '');
        $search = trim((string) $request->query->get('q', ''));

        $clauses = [];

        if ($level !== '' && in_array($level, self::VALID_LEVELS, true)) {
            $clauses[] = '`level` = \'' . pSQL($level) . '\'';
        }

        if ($source !== '') {
            $clauses[] = '`source` = \'' . pSQL($source) . '\'';
        }

        if ($errorCode !== '' && ctype_digit($errorCode)) {
            $clauses[] = '`error_code` = ' . (int) $errorCode;
        }

        if ($idOrder > 0) {
            $clauses[] = '`id_order` = ' . $idOrder;
        }

        if ($this->isValidDate($dateFrom)) {
            $clauses[] = '`date_add` >= \'' . pSQL($dateFrom) . ' 00:00:00\'';
        }

        if ($this->isValidDate($dateTo)) {
            $clauses[] = '`date_add` <= \'' . pSQL($dateTo) . ' 23:59:59\'';
        }

        if ($search !== '') {
            $needle = '%' . pSQL($search, true) . '%';
            $clauses[] = '(`message` LIKE \'' . $needle . '\' OR `endpoint` LIKE \'' . $needle . '\')';
        }

        $where = empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [
            $where,
            [
                'level' => $level,
                'source' => $source,
                'error_code' => $errorCode,
                'id_order' => $idOrder > 0 ? (string) $idOrder : '',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'q' => $search,
            ],
        ];
    }

    private function isValidDate(string $value): bool
    {
        return $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Pluck the verbatim Venipak error text(s) out of the stored context
     * JSON. ApiLogger persists `errors` as an array of structured entries
     * (`code`, `message`, `pack`, `shipment`, ...) — the `message` field is
     * exactly what Venipak's response XML contained. Returns one string
     * per error, prefixed with `[code]` so the operator can correlate it
     * with the API code.
     *
     * Returns an empty array when no errors are stored (transport / parse
     * failures don't have a structured list).
     *
     * @return array<int, array{code: int|null, message: string}>
     */
    private function extractRawErrors(string $contextJson): array
    {
        if ($contextJson === '') {
            return [];
        }

        $context = json_decode($contextJson, true);
        if (!is_array($context) || empty($context['errors']) || !is_array($context['errors'])) {
            return [];
        }

        $out = [];
        foreach ($context['errors'] as $err) {
            if (!is_array($err) || empty($err['message'])) {
                continue;
            }
            $out[] = [
                'code' => isset($err['code']) ? (int) $err['code'] : null,
                'message' => (string) $err['message'],
            ];
        }

        return $out;
    }
}
