<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Service;

use Configuration;
use Db;

/**
 * Persists Venipak-related errors to the dedicated `ppvenipak_log` table and
 * mirrors a one-line summary into PrestaShop's central log so they show up in
 * Advanced Parameters → Logs.
 *
 * All entry points are static so the API client and other low-level services
 * can call this without container wiring.
 */
class ApiLogger
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';

    /**
     * Cap on persisted body length. Anything bigger is truncated with a marker
     * so a single misbehaving response can't blow up the log table.
     */
    private const BODY_TRUNCATE_BYTES = 65535;

    /**
     * Log a Venipak API error response. `errors` is the structured list emitted
     * by VenipakErrorMapper / parseXmlResponse; the first entry's code is
     * persisted in `error_code` for filtering, and the full list is stored in
     * `context.errors`.
     *
     * @param array<int, array{code?: int, message?: string, pack?: string, shipment?: string, title?: string, fix?: string, category?: string}> $errors
     */
    public static function logApiError(
        string $source,
        string $endpoint,
        string $summary,
        array $errors = [],
        string $requestBody = '',
        string $responseBody = '',
        int $idOrder = 0,
        array $extraContext = []
    ): void {
        $firstCode = null;
        foreach ($errors as $error) {
            if (!empty($error['code'])) {
                $firstCode = (int) $error['code'];
                break;
            }
        }

        self::insert([
            'level' => self::LEVEL_ERROR,
            'source' => $source,
            'endpoint' => $endpoint,
            'error_code' => $firstCode,
            'message' => $summary,
            'request_body' => $requestBody,
            'response_body' => $responseBody,
            'context' => array_merge(['errors' => $errors], $extraContext),
            'id_order' => $idOrder,
        ]);
    }

    /**
     * Log a transport-level failure (cURL error, timeout, DNS, …).
     */
    public static function logTransportFailure(
        string $source,
        string $endpoint,
        string $message,
        string $requestBody = '',
        int $idOrder = 0,
        array $extraContext = []
    ): void {
        self::insert([
            'level' => self::LEVEL_ERROR,
            'source' => $source,
            'endpoint' => $endpoint,
            'error_code' => null,
            'message' => $message,
            'request_body' => $requestBody,
            'response_body' => '',
            'context' => $extraContext,
            'id_order' => $idOrder,
        ]);
    }

    /**
     * Log an exception caught while interacting with the API.
     */
    public static function logException(
        string $source,
        \Throwable $exception,
        string $endpoint = '',
        int $idOrder = 0,
        array $extraContext = []
    ): void {
        $context = array_merge(
            [
                'class' => get_class($exception),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
            ],
            $extraContext
        );

        self::insert([
            'level' => self::LEVEL_ERROR,
            'source' => $source,
            'endpoint' => $endpoint,
            'error_code' => null,
            'message' => $exception->getMessage(),
            'request_body' => '',
            'response_body' => '',
            'context' => $context,
            'id_order' => $idOrder,
        ]);
    }

    /**
     * Log a parse / validation failure (invalid JSON, malformed XML, etc).
     */
    public static function logParseFailure(
        string $source,
        string $endpoint,
        string $message,
        string $responseBody = '',
        int $idOrder = 0,
        array $extraContext = []
    ): void {
        self::insert([
            'level' => self::LEVEL_ERROR,
            'source' => $source,
            'endpoint' => $endpoint,
            'error_code' => null,
            'message' => $message,
            'request_body' => '',
            'response_body' => $responseBody,
            'context' => $extraContext,
            'id_order' => $idOrder,
        ]);
    }

    /**
     * Generic write — useful for warnings/info that don't fit the above shapes.
     */
    public static function log(
        string $level,
        string $source,
        string $message,
        array $context = [],
        int $idOrder = 0
    ): void {
        self::insert([
            'level' => $level,
            'source' => $source,
            'endpoint' => '',
            'error_code' => null,
            'message' => $message,
            'request_body' => '',
            'response_body' => '',
            'context' => $context,
            'id_order' => $idOrder,
        ]);
    }

    /**
     * Delete every log row tied to a given order — called after a shipment
     * is successfully registered with Venipak so we don't keep large XML
     * request/response bodies indefinitely. The error rows are only useful
     * while the merchant is debugging a failure; once the order earns
     * tracking numbers, the technical payload becomes noise.
     *
     * Two ways a row can be "tied" to an order:
     *   1. `id_order = $idOrder` — the normal path (set by submitShipmentXml
     *      and LabelGenerationService when the order ID is known).
     *   2. The order's PrestaShop reference appears as `<doc_no>` inside the
     *      stored request_body. This catches legacy rows logged before the
     *      per-order id_order plumbing existed — without this, they would
     *      stay in the table forever.
     *
     * Returns the number of rows deleted.
     */
    public static function clearForOrder(int $idOrder, ?string $orderReference = null): int
    {
        if ($idOrder <= 0) {
            return 0;
        }

        $db = Db::getInstance();

        $clauses = ['`id_order` = ' . $idOrder];

        if ($orderReference !== null && $orderReference !== '') {
            // Match the literal `<doc_no>REFERENCE</doc_no>` token that the
            // ShipmentXmlBuilder emits per pack — keeps the LIKE bounded so
            // we don't accidentally swallow unrelated rows that mention the
            // reference in free-form context.
            $needle = '%<doc_no>' . pSQL($orderReference, true) . '</doc_no>%';
            $clauses[] = '`request_body` LIKE \'' . $needle . '\'';
        }

        $deleted = $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'ppvenipak_log`
             WHERE ' . implode(' OR ', $clauses)
        );

        if ($deleted === false) {
            return 0;
        }

        return (int) $db->Affected_Rows();
    }

    /**
     * Delete log rows older than $retentionDays. Returns the number deleted.
     */
    public static function cleanup(int $retentionDays): int
    {
        $retentionDays = max(1, $retentionDays);
        $cutoff = (new \DateTimeImmutable('-' . $retentionDays . ' days'))->format('Y-m-d H:i:s');

        $db = Db::getInstance();
        $deleted = $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'ppvenipak_log` WHERE `date_add` < \'' . pSQL($cutoff) . '\''
        );

        if ($deleted === false) {
            return 0;
        }

        return (int) $db->Affected_Rows();
    }

    /**
     * @param array{
     *     level: string,
     *     source: string,
     *     endpoint: string,
     *     error_code: ?int,
     *     message: string,
     *     request_body: string,
     *     response_body: string,
     *     context: array,
     *     id_order: int,
     * } $row
     */
    private static function insert(array $row): void
    {
        $idShop = 0;
        if (class_exists('Context') && \Context::getContext() && \Context::getContext()->shop) {
            $idShop = (int) \Context::getContext()->shop->id;
        }

        $context = $row['context'];
        $contextJson = empty($context) ? null : json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $insert = [
            'date_add' => date('Y-m-d H:i:s'),
            'level' => pSQL(self::clip($row['level'], 16)),
            'source' => pSQL(self::clip($row['source'], 96)),
            'endpoint' => pSQL(self::clip($row['endpoint'], 255)),
            'error_code' => $row['error_code'] !== null ? (int) $row['error_code'] : null,
            'message' => pSQL(self::clip($row['message'], 8000), true),
            'request_body' => $row['request_body'] !== ''
                ? pSQL(self::clip($row['request_body'], self::BODY_TRUNCATE_BYTES), true)
                : null,
            'response_body' => $row['response_body'] !== ''
                ? pSQL(self::clip($row['response_body'], self::BODY_TRUNCATE_BYTES), true)
                : null,
            'context' => $contextJson !== null ? pSQL(self::clip($contextJson, self::BODY_TRUNCATE_BYTES), true) : null,
            'id_order' => (int) $row['id_order'],
            'id_shop' => $idShop,
        ];

        try {
            Db::getInstance()->insert('ppvenipak_log', $insert);
        } catch (\Throwable $e) {
            // Logging must never throw; fall back to PrestaShop's core logger only.
        }

        self::mirrorToCoreLogger($row);
    }

    /**
     * Echo a one-liner to PrestaShopLogger so the entry shows up in the
     * standard Advanced Parameters → Logs view.
     */
    private static function mirrorToCoreLogger(array $row): void
    {
        if (!class_exists('PrestaShopLogger')) {
            return;
        }

        $severity = $row['level'] === self::LEVEL_INFO ? 1 : ($row['level'] === self::LEVEL_WARNING ? 2 : 3);
        $prefix = $row['source'] !== '' ? $row['source'] . ': ' : 'PPVenipak: ';
        $line = $prefix . self::clip($row['message'], 500);

        try {
            \PrestaShopLogger::addLog(
                $line,
                $severity,
                $row['error_code'],
                'PPVenipak',
                $row['id_order'] > 0 ? $row['id_order'] : null
            );
        } catch (\Throwable $e) {
            // Swallow — logging must never fail the calling flow.
        }
    }

    private static function clip(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        $marker = "\n…[truncated]";
        $room = max(0, $maxBytes - strlen($marker));

        return substr($value, 0, $room) . $marker;
    }
}
