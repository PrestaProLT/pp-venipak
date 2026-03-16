<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

use Configuration;

class VenipakApiClient
{
    private const LIVE_BASE_URL = 'https://go.venipak.lt/';
    private const SANDBOX_BASE_URL = 'https://venipak.uat.megodata.com/';

    private const MODULE_VERSION = '1.0.0';

    /**
     * Submit shipment XML to Venipak.
     *
     * @param string $xmlText The XML shipment data
     * @return array Parsed response with 'text' (tracking numbers) or 'error' keys
     */
    public function submitShipmentXml(string $xmlText): array
    {
        $credentials = $this->getCredentials();

        $params = [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'xml_text' => $xmlText,
        ];

        $response = $this->executePost('import/send.php', $params);

        return $this->parseXmlResponse($response);
    }

    /**
     * Get available pickup points for a country.
     *
     * @param string $country Country ISO code (e.g. 'LT', 'LV', 'EE')
     * @param string|null $postcode Optional postcode filter
     * @param string|null $city Optional city filter
     * @return array Array of terminal/pickup point objects
     */
    public function getPickupPoints(string $country, ?string $postcode = null, ?string $city = null): array
    {
        $params = ['country' => $country];

        if ($postcode !== null) {
            $params['postcode'] = $postcode;
        }

        if ($city !== null) {
            $params['city'] = $city;
        }

        $response = $this->executeGet('ws/get_pickup_points', $params);

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            $this->logError('getPickupPoints: Invalid JSON response');

            return [];
        }

        return $decoded;
    }

    /**
     * Get route/delivery information for a postcode.
     *
     * @param string $country Country ISO code
     * @param string $postcode Postcode
     * @param string $type Route type filter ('all', 'courier', 'pickup')
     * @return array Route data
     */
    public function getRoute(string $country, string $postcode, string $type = 'all'): array
    {
        $params = [
            'country' => $country,
            'code' => $postcode,
            'type' => $type,
            'view' => 'json',
        ];

        $response = $this->executeGet('ws/get_route', $params);

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            $this->logError('getRoute: Invalid JSON response');

            return [];
        }

        return $decoded;
    }

    /**
     * Print shipping label(s) as PDF.
     *
     * @param array $packNumbers Array of pack/tracking numbers
     * @param string $format Label format: 'a4' for A4 sheet, 'other' for thermal/sticker
     * @return string Raw PDF binary content
     */
    public function printLabel(array $packNumbers, string $format = 'other'): string
    {
        $credentials = $this->getCredentials();

        $params = [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'format' => $format,
            'carrier' => 'all',
        ];

        foreach (array_values($packNumbers) as $i => $packNo) {
            $params['pack_no[' . $i . ']'] = $packNo;
        }

        return $this->executePost('ws/print_label', $params);
    }

    /**
     * Get a URL link to a printable label.
     *
     * @param array $packNumbers Array of pack/tracking numbers
     * @return string URL to the label PDF
     */
    public function printLabelLink(array $packNumbers): string
    {
        $credentials = $this->getCredentials();

        $params = [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
        ];

        foreach (array_values($packNumbers) as $i => $packNo) {
            $params['pack_no[' . $i . ']'] = $packNo;
        }

        $response = $this->executePost('ws/print_link', $params);

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || empty($decoded['url'])) {
            $this->logError('printLabelLink: Invalid response — ' . substr($response, 0, 500));

            return '';
        }

        return (string) $decoded['url'];
    }

    /**
     * Print manifest/dispatch list as PDF.
     *
     * @param string $manifestId Manifest/dispatch code
     * @return string Raw PDF binary content
     */
    public function printManifest(string $manifestId): string
    {
        $credentials = $this->getCredentials();

        $params = [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'code' => $manifestId,
        ];

        return $this->executePost('ws/print_list', $params);
    }

    /**
     * Get tracking information for a shipment.
     * Always uses the LIVE endpoint regardless of sandbox mode.
     *
     * @param string $code Tracking/pack number
     * @param int $type Tracking type (1 = by pack number)
     * @return array Array of tracking rows: [pack_no, shipment_no, date, status, terminal]
     */
    public function getTracking(string $code, int $type = 1): array
    {
        $params = [
            'code' => $code,
            'type' => $type,
            'output' => 'csv',
        ];

        $response = $this->executeGet('ws/tracking', $params, true);

        return $this->parseCsvResponse($response);
    }

    /**
     * Register a shipment for tracking callbacks.
     * Uses normal live/test mode.
     *
     * @param string $code Tracking/pack number
     * @param int $type Registration type (3 = register for callbacks)
     * @return bool True on success
     */
    public function registerTracking(string $code, int $type = 3): bool
    {
        $credentials = $this->getCredentials();

        $params = [
            'user' => $credentials['user'],
            'pass' => $credentials['pass'],
            'code' => $code,
            'type' => $type,
        ];

        $response = $this->executePost('ws/tracking', $params);

        $decoded = json_decode($response, true);

        if (is_array($decoded) && !empty($decoded['error'])) {
            $this->logError('registerTracking: ' . $decoded['error']);

            return false;
        }

        return true;
    }

    /**
     * Get the base URL for API requests.
     *
     * @param bool $forceLive Force use of the live/production endpoint
     * @return string Base URL with trailing slash
     */
    private function getBaseUrl(bool $forceLive = false): string
    {
        if ($forceLive) {
            return self::LIVE_BASE_URL;
        }

        return (bool) Configuration::get('PPVENIPAK_LIVE_MODE')
            ? self::LIVE_BASE_URL
            : self::SANDBOX_BASE_URL;
    }

    /**
     * Get API credentials from PrestaShop configuration.
     *
     * @return array{user: string, pass: string}
     */
    private function getCredentials(): array
    {
        return [
            'user' => (string) Configuration::get('PPVENIPAK_API_USER'),
            'pass' => (string) Configuration::get('PPVENIPAK_API_PASS'),
        ];
    }

    /**
     * Build custom HTTP headers required by Venipak API.
     *
     * @return string[] Array of "Header: Value" strings for cURL
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

    /**
     * Execute a GET request via cURL.
     *
     * @param string $endpoint API endpoint path (relative to base URL)
     * @param array $params Query string parameters
     * @param bool $forceLive Force use of the live endpoint
     * @return string Raw response body
     *
     * @throws \RuntimeException On cURL error
     */
    private function executeGet(string $endpoint, array $params = [], bool $forceLive = false): string
    {
        $url = $this->getBaseUrl($forceLive) . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $this->getHeaders(),
            CURLOPT_HTTPGET => true,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($errno !== 0) {
            $message = sprintf('cURL GET error #%d: %s (endpoint: %s)', $errno, $error, $endpoint);
            $this->logError($message);

            throw new \RuntimeException($message);
        }

        return (string) $response;
    }

    /**
     * Execute a POST request via cURL.
     *
     * @param string $endpoint API endpoint path (relative to base URL)
     * @param array $params POST fields
     * @param bool $forceLive Force use of the live endpoint
     * @return string Raw response body
     *
     * @throws \RuntimeException On cURL error
     */
    private function executePost(string $endpoint, array $params = [], bool $forceLive = false): string
    {
        $url = $this->getBaseUrl($forceLive) . $endpoint;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $this->getHeaders(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($errno !== 0) {
            $message = sprintf('cURL POST error #%d: %s (endpoint: %s)', $errno, $error, $endpoint);
            $this->logError($message);

            throw new \RuntimeException($message);
        }

        return (string) $response;
    }

    /**
     * Parse an XML response into an associative array.
     *
     * @param string $xml Raw XML string
     * @return array Parsed data
     */
    private function parseXmlResponse(string $xml): array
    {
        $xml = trim($xml);

        if (empty($xml)) {
            $this->logError('parseXmlResponse: Empty response');

            return ['error' => 'Empty XML response'];
        }

        libxml_use_internal_errors(true);
        $simpleXml = simplexml_load_string($xml);

        if ($simpleXml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMessages = array_map(fn ($e) => trim($e->message), $errors);
            $this->logError('parseXmlResponse: ' . implode('; ', $errorMessages));

            return ['error' => 'Invalid XML: ' . implode('; ', $errorMessages)];
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
     * Parse a CSV response into an array of rows.
     * First row is treated as a header and skipped.
     * Each row is returned as: [pack_no, shipment_no, date, status, terminal]
     *
     * @param string $csv Raw CSV string
     * @return array Array of parsed rows
     */
    private function parseCsvResponse(string $csv): array
    {
        $csv = trim($csv);

        if (empty($csv)) {
            return [];
        }

        $rows = [];
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            return [];
        }

        fwrite($stream, $csv);
        rewind($stream);

        // Skip header row
        fgetcsv($stream);

        while (($row = fgetcsv($stream)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }

            $rows[] = [
                'pack_no' => $row[0] ?? '',
                'shipment_no' => $row[1] ?? '',
                'date' => $row[2] ?? '',
                'status' => $row[3] ?? '',
                'terminal' => $row[4] ?? '',
            ];
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Log an error message if debug mode is enabled.
     *
     * @param string $message Error message to log
     */
    private function logError(string $message): void
    {
        if (!(bool) Configuration::get('PPVENIPAK_DEBUG')) {
            return;
        }

        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog(
                'PPVenipak API: ' . $message,
                3,
                null,
                'VenipakApiClient'
            );
        }
    }
}
