<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

use Configuration;
use PrestaShop\Module\PPVenipak\Service\ApiLogger;

class VenipakApiClient
{
    private const LIVE_BASE_URL = 'https://go.venipak.lt/';
    private const SANDBOX_BASE_URL = 'https://gouat.venipak.lt/';
    private const TRACKING_BASE_URL = 'https://tracking.venipak.com/api/v1/';

    private const MODULE_VERSION = '1.0.0';

    /**
     * Pack status codes documented in the Postman tracking spec.
     * The list intentionally omits 4 and 8 — those are reserved by Venipak.
     */
    public const PACK_STATUS_AT_SENDER = 0;
    public const PACK_STATUS_PICKED_UP = 1;
    public const PACK_STATUS_AT_TERMINAL = 2;
    public const PACK_STATUS_OUT_FOR_DELIVERY = 3;
    public const PACK_STATUS_AT_PICKUP_POINT_5 = 5;
    public const PACK_STATUS_AT_PICKUP_POINT_6 = 6;
    public const PACK_STATUS_IN_TRANSIT = 7;
    public const PACK_STATUS_DELIVERED = 9;

    /**
     * Shop id whose per-shop Venipak account (credentials + live/sandbox mode)
     * this client authenticates with. Null = use the current PrestaShop
     * context shop, preserving the original single-shop behaviour.
     */
    private ?int $shopId = null;

    /**
     * Bind this client to a specific shop's Venipak account so a request is
     * always sent under the correct credentials regardless of the admin's
     * current shop context (e.g. label generation from "All shops").
     */
    public function setShopId(?int $shopId): self
    {
        $this->shopId = ($shopId !== null && $shopId > 0) ? $shopId : null;

        return $this;
    }

    /**
     * Submit shipment XML to Venipak.
     *
     * @param string $xmlText  The shipment-import XML payload.
     * @param int[]  $orderIds Optional list of PrestaShop order IDs that the
     *                         payload covers. When provided, error logging
     *                         writes one row per order so that cleaning up
     *                         logs after a successful re-send (via
     *                         ApiLogger::clearForOrder) actually finds
     *                         them. Without this, batch-level errors would
     *                         persist with `id_order = 0` indefinitely.
     *
     * @return array Parsed response (XML decoded as associative array).
     */
    public function submitShipmentXml(string $xmlText, array $orderIds = []): array
    {
        $credentials = $this->getCredentials();
        $endpoint = 'import/send.php';

        $response = $this->executePost($endpoint, [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'xml_text' => $xmlText,
        ], $xmlText, 'VenipakApiClient::submitShipmentXml');

        $parsed = $this->parseXmlResponse($response);

        if (isset($parsed['error'])) {
            $errors = $parsed['errors'] ?? [];
            $summary = (string) $parsed['error'];

            if (empty($orderIds)) {
                ApiLogger::logApiError(
                    'VenipakApiClient::submitShipmentXml',
                    $endpoint,
                    $summary,
                    $errors,
                    $xmlText,
                    $response
                );
            } else {
                // One row per order so the per-order cleanup on later
                // success can remove these. Identical request/response
                // payloads are repeated — that's fine, the rows are tiny
                // bookkeeping and they'll be wiped by clearForOrder().
                foreach ($orderIds as $idOrder) {
                    ApiLogger::logApiError(
                        'VenipakApiClient::submitShipmentXml',
                        $endpoint,
                        $summary,
                        $errors,
                        $xmlText,
                        $response,
                        (int) $idOrder
                    );
                }
            }
        }

        return $parsed;
    }

    /**
     * Get available pickup points for a country.
     *
     * @param string $country Country ISO code (LT, LV, EE, PL).
     */
    public function getPickupPoints(string $country, ?string $postcode = null, ?string $city = null): array
    {
        $params = ['country' => $country, 'view' => 'json'];

        if ($postcode !== null) {
            // Postman parameter name is `zip`, not `postcode`.
            $params['zip'] = $postcode;
        }

        if ($city !== null) {
            $params['city'] = $city;
        }

        $endpoint = 'ws/get_pickup_points';
        $response = $this->executeGet($endpoint, $params, 'VenipakApiClient::getPickupPoints');
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getPickupPoints',
                $endpoint,
                'Invalid JSON response from get_pickup_points.',
                $response,
                0,
                ['country' => $country]
            );

            return [];
        }

        return $decoded;
    }

    /**
     * Get route/zone/services info for a postcode.
     *
     * @param string $type One of: all, route, zone (default 'all' to include all service flags).
     */
    public function getRoute(string $country, string $postcode, string $type = 'all'): array
    {
        $endpoint = 'ws/get_route';
        $response = $this->executeGet($endpoint, [
            'country' => $country,
            'code' => $postcode,
            'type' => $type,
            'view' => 'json',
        ], 'VenipakApiClient::getRoute');

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getRoute',
                $endpoint,
                'Invalid JSON response from get_route.',
                $response,
                0,
                ['country' => $country, 'postcode' => $postcode]
            );

            return [];
        }

        return $decoded;
    }

    /**
     * Print shipping labels as PDF.
     *
     * @param array $packNumbers Pack numbers to print (mutually exclusive with $manifestCode).
     * @param string $format     'a4' or 'other' (100x150mm thermal).
     * @param string $carrier    'venipak', 'global', or 'all'.
     * @param ?bool  $printReturns true=print return labels, false=skip; null falls back to user setting.
     * @param ?string $manifestCode Print labels by manifest code instead of by pack list.
     */
    public function printLabel(
        array $packNumbers,
        string $format = 'other',
        string $carrier = 'all',
        ?bool $printReturns = null,
        ?string $manifestCode = null
    ): string {
        $credentials = $this->getCredentials();

        $params = [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'format' => $format,
            'carrier' => $carrier,
        ];

        if ($manifestCode !== null) {
            $params['code'] = $manifestCode;
        } else {
            foreach (array_values($packNumbers) as $i => $packNo) {
                $params['pack_no[' . $i . ']'] = $packNo;
            }
        }

        if ($printReturns !== null) {
            $params['printReturns'] = $printReturns ? '1' : '0';
        }

        $endpoint = 'ws/print_label';
        $response = $this->executePost($endpoint, $params, '', 'VenipakApiClient::printLabel');

        if ($response === '') {
            ApiLogger::logParseFailure(
                'VenipakApiClient::printLabel',
                $endpoint,
                'Empty response from print_label (no PDF returned).',
                '',
                0,
                ['pack_count' => count($packNumbers), 'manifest' => $manifestCode]
            );
        }

        return $response;
    }

    /**
     * Print manifest (sender's shipment bill) as PDF.
     */
    public function printManifest(string $manifestId): string
    {
        $credentials = $this->getCredentials();
        $endpoint = 'ws/print_list';

        $response = $this->executePost($endpoint, [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'code' => $manifestId,
        ], '', 'VenipakApiClient::printManifest');

        if ($response === '') {
            ApiLogger::logParseFailure(
                'VenipakApiClient::printManifest',
                $endpoint,
                'Empty response from print_list (no manifest PDF).',
                '',
                0,
                ['manifest_id' => $manifestId]
            );
        }

        return $response;
    }

    /**
     * Get a Proof-of-Delivery PDF for a delivered pack. Returns raw PDF bytes,
     * or an empty string when none is available.
     */
    public function getProofOfDelivery(string $packNo): string
    {
        $credentials = $this->getCredentials();
        $endpoint = 'ws/get_pod.php';

        $response = $this->executePost($endpoint, [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'pack_no' => $packNo,
        ], '', 'VenipakApiClient::getProofOfDelivery');

        $xml = trim($response);
        if ($xml === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $simpleXml = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($simpleXml === false || !isset($simpleXml->pod)) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getProofOfDelivery',
                $endpoint,
                'Invalid XML response from get_pod (no <pod> element).',
                $response,
                0,
                ['pack_no' => $packNo]
            );

            return '';
        }

        $base64 = (string) $simpleXml->pod;
        $decoded = base64_decode($base64, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Get pickup PIN for a pack as JSON. Returns the PIN string or empty on failure.
     */
    public function getLabelFreePin(string $packNo): string
    {
        $credentials = $this->getCredentials();
        $endpoint = 'ws/get_label_free_pin';

        $response = $this->executePost($endpoint, [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'pack_no' => $packNo,
            'format' => 'pin',
        ], '', 'VenipakApiClient::getLabelFreePin');

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || empty($decoded['pin'])) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getLabelFreePin',
                $endpoint,
                'Invalid response from get_label_free_pin (missing pin).',
                $response,
                0,
                ['pack_no' => $packNo]
            );

            return '';
        }

        return (string) $decoded['pin'];
    }

    /**
     * Get pickup PIN as a QR code image (PNG/GIF binary).
     *
     * @param string $imageFormat 'png' or 'gif'.
     */
    public function getLabelFreeQr(string $packNo, int $width = 300, string $imageFormat = 'png'): string
    {
        $credentials = $this->getCredentials();
        $endpoint = 'ws/get_label_free_pin';

        $response = $this->executePost($endpoint, [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'pack_no' => $packNo,
            'format' => 'qr',
            'width' => (string) max(1, min(1000, $width)),
            'image_format' => $imageFormat,
        ], '', 'VenipakApiClient::getLabelFreeQr');

        if ($response === '') {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getLabelFreeQr',
                $endpoint,
                'Empty response from get_label_free_pin (qr).',
                '',
                0,
                ['pack_no' => $packNo, 'image_format' => $imageFormat]
            );
        }

        return $response;
    }

    /**
     * Look up the numeric API ID for the configured credentials.
     */
    public function getApiId(): string
    {
        $credentials = $this->getCredentials();
        $endpoint = 'ws/get_api_id';

        $response = $this->executePost($endpoint, [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
        ], '', 'VenipakApiClient::getApiId');

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || empty($decoded['data']['api_id'])) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getApiId',
                $endpoint,
                'Invalid response from get_api_id (missing data.api_id).',
                $response
            );

            return '';
        }

        return (string) $decoded['data']['api_id'];
    }

    /**
     * Get tracking events for a single shipment from the modern tracking API.
     *
     * Returns the raw event list as documented in the Postman tracking spec.
     * Use `derivePackState()` on the returned array to apply the Postman rule
     * "do not stop polling on pack_status=9 unless the event is `Delivered`".
     *
     * @param string $code shipment_id or pack_no.
     * @param string $lang Language for translated text (LT, LV, EE, RU, EN).
     */
    public function getTracking(string $code, string $lang = 'EN'): array
    {
        $params = ctype_digit($code)
            ? ['shipment_id' => $code]
            : ['pack_no' => $code];

        if ($lang !== '') {
            $params['lang'] = $lang;
        }

        $endpoint = 'events';
        $response = $this->executeTrackingGet($endpoint, $params, 'VenipakApiClient::getTracking');
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getTracking',
                'tracking.venipak.com/api/v1/events',
                'Invalid JSON response from tracking events endpoint.',
                $response,
                0,
                ['code' => $code]
            );

            return [];
        }

        return $decoded;
    }

    /**
     * Get tracking events for multiple shipments in one call.
     *
     * @param string[] $codes Shipment IDs (numeric) or pack numbers.
     * @return array<string, array> Map of code => event list.
     */
    public function getTrackingMultiple(array $codes, string $lang = 'EN'): array
    {
        if (empty($codes)) {
            return [];
        }

        $shipmentIds = [];
        $packNumbers = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if (ctype_digit($code)) {
                $shipmentIds[] = $code;
            } else {
                $packNumbers[] = $code;
            }
        }

        $params = [];
        if (!empty($shipmentIds)) {
            foreach ($shipmentIds as $i => $id) {
                $params['shipment_ids[' . $i . ']'] = $id;
            }
        }
        if (!empty($packNumbers)) {
            foreach ($packNumbers as $i => $packNo) {
                $params['pack_numbers[' . $i . ']'] = $packNo;
            }
        }
        if ($lang !== '') {
            $params['lang'] = $lang;
        }

        $response = $this->executeTrackingGet('multiple-events', $params, 'VenipakApiClient::getTrackingMultiple');
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            ApiLogger::logParseFailure(
                'VenipakApiClient::getTrackingMultiple',
                'tracking.venipak.com/api/v1/multiple-events',
                'Invalid JSON response from tracking multiple-events endpoint.',
                $response,
                0,
                ['code_count' => count($codes)]
            );

            return [];
        }

        return $decoded;
    }

    /**
     * Apply the Postman "wait for Delivered event" rule to an event list.
     *
     * Returns the latest pack_status, the event text, and a `is_final` flag
     * that is true only when the shipment is genuinely complete (pack_status=9
     * AND event=Delivered). Forwarding/Returning/LDG-created events also reach
     * pack_status=9, but they spawn a new shipment and tracking should continue.
     *
     * @return array{
     *     pack_status: ?int,
     *     pack_status_text: string,
     *     event: string,
     *     date: string,
     *     is_final: bool,
     * }
     */
    public function derivePackState(array $events): array
    {
        if (empty($events)) {
            return [
                'pack_status' => null,
                'pack_status_text' => '',
                'event' => '',
                'date' => '',
                'is_final' => false,
            ];
        }

        $latest = end($events);
        $packStatus = isset($latest['pack_status']) ? (int) $latest['pack_status'] : null;
        $event = (string) ($latest['event'] ?? '');

        $isFinal = $packStatus === self::PACK_STATUS_DELIVERED && $event === 'Delivered';

        return [
            'pack_status' => $packStatus,
            'pack_status_text' => (string) ($latest['pack_status_text'] ?? ''),
            'event' => $event,
            'date' => (string) ($latest['date'] ?? ''),
            'is_final' => $isFinal,
        ];
    }

    private function getBaseUrl(): string
    {
        return (bool) Configuration::get('PPVENIPAK_LIVE_MODE', null, null, $this->shopId)
            ? self::LIVE_BASE_URL
            : self::SANDBOX_BASE_URL;
    }

    /**
     * @return array{user: string, pass: string}
     */
    private function getCredentials(): array
    {
        // Credentials and live/sandbox mode are configured per shop (each shop
        // can have its own Venipak account). Read them for $this->shopId so a
        // request triggered from the "All shops" context still authenticates
        // with the right account — otherwise Configuration::get falls back to
        // the admin's current context shop (usually shop 1) and the shipment
        // is sent under the wrong account. Null shopId preserves the legacy
        // context-shop behaviour.
        return [
            'user' => (string) Configuration::get('PPVENIPAK_API_USER', null, null, $this->shopId),
            'pass' => (string) Configuration::get('PPVENIPAK_API_PASS', null, null, $this->shopId),
        ];
    }

    /**
     * @return string[]
     */
    private function getHeaders(): array
    {
        $psVersion = defined('_PS_VERSION_') ? _PS_VERSION_ : '9.0.0';

        return [
            'client-software-name: Prestashop',
            'client-software-version: ' . $psVersion,
            'client-module-version: ' . self::MODULE_VERSION,
        ];
    }

    private function executeGet(string $endpoint, array $params = [], string $source = 'VenipakApiClient'): string
    {
        return $this->executeHttp('GET', $this->getBaseUrl() . $endpoint, $params, '', $source);
    }

    /**
     * @param string $logRequestBody Body to log on transport failure (XML for shipment XML calls,
     *                               otherwise the form-encoded params are used as a fallback).
     */
    private function executePost(
        string $endpoint,
        array $params = [],
        string $logRequestBody = '',
        string $source = 'VenipakApiClient'
    ): string {
        return $this->executeHttp('POST', $this->getBaseUrl() . $endpoint, $params, $logRequestBody, $source);
    }

    private function executeTrackingGet(string $endpoint, array $params = [], string $source = 'VenipakApiClient'): string
    {
        return $this->executeHttp(
            'GET',
            self::TRACKING_BASE_URL . $endpoint,
            $params,
            '',
            $source,
            ['Accept: application/json', 'Content-Type: application/json']
        );
    }

    /**
     * @param array         $params         Query string for GET, POST fields for POST.
     * @param string        $logRequestBody Body to persist when logging a transport failure.
     * @param string        $source         Source label propagated to ApiLogger.
     * @param string[]|null $extraHeaders   Replaces the default module-version headers when set.
     */
    private function executeHttp(
        string $method,
        string $url,
        array $params,
        string $logRequestBody = '',
        string $source = 'VenipakApiClient',
        ?array $extraHeaders = null
    ): string {
        $ch = curl_init();

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $extraHeaders ?? $this->getHeaders(),
        ];

        $postBodyForLog = '';
        if ($method === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $postBodyForLog = http_build_query($params);
            $options[CURLOPT_POSTFIELDS] = $postBodyForLog;
        } else {
            if (!empty($params)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($errno !== 0) {
            $message = sprintf('cURL %s error #%d: %s', $method, $errno, $error);
            $bodyForLog = $logRequestBody !== '' ? $logRequestBody : $postBodyForLog;

            ApiLogger::logTransportFailure(
                $source,
                $url,
                $message,
                $bodyForLog,
                0,
                ['method' => $method, 'curl_errno' => $errno]
            );

            throw new \RuntimeException($message . ' (url: ' . $url . ')');
        }

        return (string) $response;
    }

    /**
     * Parse a Venipak XML response. On <answer type="error"> the array carries
     * a structured `errors` list (each entry has code, message, pack, shipment,
     * category, hint) and a human-readable `error` summary string.
     */
    private function parseXmlResponse(string $xml): array
    {
        $xml = trim($xml);

        if ($xml === '') {
            return ['error' => 'Empty response from Venipak API.'];
        }

        libxml_use_internal_errors(true);
        $simpleXml = simplexml_load_string($xml);

        if ($simpleXml === false) {
            $libxmlErrors = libxml_get_errors();
            libxml_clear_errors();
            $errorMessages = array_map(fn ($e) => trim($e->message), $libxmlErrors);

            return ['error' => 'Invalid XML response: ' . implode('; ', $errorMessages)];
        }

        $type = (string) ($simpleXml['type'] ?? '');

        if ($type === 'error' || isset($simpleXml->error)) {
            $errors = [];
            foreach ($simpleXml->error as $errorEl) {
                $errors[] = $this->parseErrorElement($errorEl);
            }

            $summary = VenipakErrorMapper::format($errors);

            return ['error' => $summary, 'errors' => $errors];
        }

        $json = json_encode($simpleXml);

        if ($json === false) {
            return ['error' => 'Failed to encode XML as JSON'];
        }

        $result = json_decode($json, true);

        if (!is_array($result)) {
            return ['error' => 'Failed to decode XML JSON'];
        }

        return $result;
    }

    /**
     * Extract a single <error> element. The spec emits two layouts:
     *   <error code="24"><text shipment="0" pack="..." code="5">...</text></error>
     *   <error shipment="0" pack="..." code="42"><text>...</text></error>
     * We accept both, preferring the outer attribute when present.
     *
     * @return array{code: int, message: string, pack: string, shipment: string, category: string, title: string, fix: string}
     */
    private function parseErrorElement(\SimpleXMLElement $errorEl): array
    {
        $codeAttr = isset($errorEl['code']) ? (string) $errorEl['code'] : '';
        $shipmentAttr = isset($errorEl['shipment']) ? (string) $errorEl['shipment'] : '';
        $packAttr = isset($errorEl['pack']) ? (string) $errorEl['pack'] : '';

        $textNode = $errorEl->text ?? null;
        $message = $textNode !== null ? trim((string) $textNode) : trim((string) $errorEl);

        if ($textNode !== null) {
            if ($codeAttr === '' && isset($textNode['code'])) {
                $codeAttr = (string) $textNode['code'];
            }
            if ($shipmentAttr === '' && isset($textNode['shipment'])) {
                $shipmentAttr = (string) $textNode['shipment'];
            }
            if ($packAttr === '' && isset($textNode['pack'])) {
                $packAttr = (string) $textNode['pack'];
            }
        }

        $code = $codeAttr !== '' && ctype_digit($codeAttr) ? (int) $codeAttr : 0;
        $description = VenipakErrorMapper::describe($code);

        return [
            'code' => $code,
            'message' => $message,
            'pack' => $packAttr,
            'shipment' => $shipmentAttr,
            'category' => $description['category'],
            'title' => $description['title'],
            'fix' => $description['fix'],
        ];
    }

}
