<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

/**
 * Maps numeric Venipak error codes to plain-English messages aimed at a
 * non-technical merchant. Each entry has a title (what's wrong) and a fix
 * (what to do about it). Error codes and the raw Venipak text are kept on
 * each structured error entry but no longer dominate the customer-facing
 * summary — code/raw text live in the Logs tab for forensic debugging.
 */
class VenipakErrorMapper
{
    public const CATEGORY_AUTH = 'auth';
    public const CATEGORY_REQUEST = 'request';
    public const CATEGORY_MANIFEST = 'manifest';
    public const CATEGORY_CONSIGNEE = 'consignee';
    public const CATEGORY_SENDER = 'sender';
    public const CATEGORY_CONSIGNOR = 'consignor';
    public const CATEGORY_RETURN_DOC = 'return_doc_consignee';
    public const CATEGORY_RECEIVER = 'receiver';
    public const CATEGORY_PACK = 'pack';
    public const CATEGORY_SHIPMENT = 'shipment';
    public const CATEGORY_COUNTRY = 'country';
    public const CATEGORY_SERVICE = 'service';
    public const CATEGORY_UNKNOWN = 'unknown';

    /**
     * Where postal codes are explained in one place we reuse this snippet.
     */
    private const POSTCODE_HINT = 'Lithuania, Estonia, Poland use 5 digits (e.g. 09131); Latvia uses 4 digits (e.g. 1010); no letters or spaces.';

    /**
     * @var array<int, array{category: string, title: string, fix: string}>
     */
    private const CODES = [
        // ----- Account / auth -------------------------------------------------
        1 => [
            'category' => self::CATEGORY_AUTH,
            'title' => 'Your Venipak account has unpaid invoices.',
            'fix' => 'Contact your Venipak account manager to restore service.',
        ],
        9 => [
            'category' => self::CATEGORY_AUTH,
            'title' => 'Venipak does not recognise your username or password.',
            'fix' => 'Open the Configuration tab and double-check the API credentials. The password field clears on save for security — re-type the password and click Save.',
        ],

        // ----- Request format (technical, rare) -------------------------------
        2 => [
            'category' => self::CATEGORY_REQUEST,
            'title' => 'Venipak did not receive any data.',
            'fix' => 'This is usually a temporary network issue. Try again in a moment. If it keeps happening, check the Logs tab and contact support.',
        ],
        3 => [
            'category' => self::CATEGORY_REQUEST,
            'title' => 'Venipak could not read the request.',
            'fix' => 'Likely a module bug — please report this with the request body from the Logs tab.',
        ],
        4 => [
            'category' => self::CATEGORY_REQUEST,
            'title' => 'Too many shipments in one batch.',
            'fix' => 'Generate labels in smaller batches (under 1024 KB of data, roughly 100 orders).',
        ],
        5 => [
            'category' => self::CATEGORY_REQUEST,
            'title' => 'The shipment data does not match what Venipak expects.',
            'fix' => 'Likely a module bug — please report this with the request body from the Logs tab.',
        ],
        8 => [
            'category' => self::CATEGORY_REQUEST,
            'title' => 'Venipak got an unexpected request type.',
            'fix' => 'Likely a module bug — please report this.',
        ],

        // ----- Manifest -------------------------------------------------------
        10 => [
            'category' => self::CATEGORY_MANIFEST,
            'title' => 'The shipment manifest number is malformed.',
            'fix' => 'Likely a module bug. As a workaround you can clear pending labels and retry tomorrow (manifest numbers reset daily).',
        ],

        // ----- Consignee (recipient — the customer's address) -----------------
        20 => [
            'category' => self::CATEGORY_CONSIGNEE,
            'title' => 'The customer\'s name is missing on this order.',
            'fix' => 'Open the order and edit the delivery address — fill in the recipient\'s first and last name.',
        ],
        21 => [
            'category' => self::CATEGORY_CONSIGNEE,
            'title' => 'The customer\'s country is missing or invalid.',
            'fix' => 'Open the order, edit the delivery address, and pick a valid country.',
        ],
        22 => [
            'category' => self::CATEGORY_CONSIGNEE,
            'title' => 'The customer\'s city is missing.',
            'fix' => 'Open the order, edit the delivery address, and fill in the city.',
        ],
        23 => [
            'category' => self::CATEGORY_CONSIGNEE,
            'title' => 'The customer\'s street address is missing.',
            'fix' => 'Open the order, edit the delivery address, and fill in the street and house number.',
        ],
        24 => [
            'category' => self::CATEGORY_CONSIGNEE,
            'title' => 'The customer\'s postal code is missing or in the wrong format.',
            'fix' => 'Open the order, edit the delivery address, and fix the postal code. ' . self::POSTCODE_HINT,
        ],

        // ----- Pack ----------------------------------------------------------
        40 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'A parcel is missing its tracking number.',
            'fix' => 'Likely a module bug. Try regenerating the label.',
        ],
        41 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'A parcel\'s tracking number has the wrong format.',
            'fix' => 'Likely a module bug. Verify the API ID is correct in Configuration → API credentials and try again.',
        ],
        42 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'A parcel\'s tracking number is already in use at Venipak.',
            'fix' => 'Each tracking number can only ever be used once. The module will pick a fresh number on the next attempt — just retry generating the label.',
        ],
        43 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'The same tracking number was used twice in this batch.',
            'fix' => 'Retry — the module assigns fresh numbers on each attempt.',
        ],
        44 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'A parcel has no weight.',
            'fix' => 'Open the order and check the products have weights set. Total order weight must be greater than 0.',
        ],
        45 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'A tracking number is reserved by Venipak.',
            'fix' => 'Retry — the module will pick a different one.',
        ],
        46 => [
            'category' => self::CATEGORY_PACK,
            'title' => 'A parcel exceeds the 10 m³ size limit.',
            'fix' => 'Split the order into more parcels. Edit the order in the Venipak side panel — increase "Packages" so the volume is divided.',
        ],

        // ----- Sender (load place) -------------------------------------------
        50 => [
            'category' => self::CATEGORY_SENDER,
            'title' => 'Sender name is missing.',
            'fix' => 'Open the Warehouses tab, edit the warehouse for this order, and fill in the Name field.',
        ],
        51 => [
            'category' => self::CATEGORY_SENDER,
            'title' => 'Sender country is missing or invalid.',
            'fix' => 'Open the Warehouses tab, edit the warehouse, and pick a valid country (LT, LV, EE, or PL).',
        ],
        52 => [
            'category' => self::CATEGORY_SENDER,
            'title' => 'Sender city is missing.',
            'fix' => 'Open the Warehouses tab, edit the warehouse, and fill in the City field.',
        ],
        53 => [
            'category' => self::CATEGORY_SENDER,
            'title' => 'Sender street address is missing.',
            'fix' => 'Open the Warehouses tab, edit the warehouse, and fill in the Address field.',
        ],
        54 => [
            'category' => self::CATEGORY_SENDER,
            'title' => 'Sender postal code is missing or in the wrong format.',
            'fix' => 'Open the Warehouses tab, edit the warehouse, and fix the postal code. ' . self::POSTCODE_HINT,
        ],

        // ----- Return-doc consignee -------------------------------------------
        60 => [
            'category' => self::CATEGORY_RETURN_DOC,
            'title' => 'Return-document recipient name is missing.',
            'fix' => 'Either disable "Document return" on this shipment, or set a valid return-documents address.',
        ],
        61 => [
            'category' => self::CATEGORY_RETURN_DOC,
            'title' => 'Return-document recipient country is missing or invalid.',
            'fix' => 'Either disable "Document return" or pick a valid country for the return-documents address.',
        ],
        62 => [
            'category' => self::CATEGORY_RETURN_DOC,
            'title' => 'Return-document recipient city is missing.',
            'fix' => 'Either disable "Document return" or fill in the return-documents city.',
        ],
        63 => [
            'category' => self::CATEGORY_RETURN_DOC,
            'title' => 'Return-document recipient street address is missing.',
            'fix' => 'Either disable "Document return" or fill in the return-documents address.',
        ],
        64 => [
            'category' => self::CATEGORY_RETURN_DOC,
            'title' => 'Return-document recipient postal code is missing or invalid.',
            'fix' => 'Either disable "Document return" or fix the postal code. ' . self::POSTCODE_HINT,
        ],

        // ----- Consignor (your warehouse — what shows on the label) -----------
        70 => [
            'category' => self::CATEGORY_CONSIGNOR,
            'title' => 'Your warehouse name is missing.',
            'fix' => 'Open the Warehouses tab → edit the warehouse for this order → fill in the Name field.',
        ],
        71 => [
            'category' => self::CATEGORY_CONSIGNOR,
            'title' => 'Your warehouse country is missing or invalid.',
            'fix' => 'Open the Warehouses tab → edit the warehouse → pick a valid country (LT, LV, EE, or PL).',
        ],
        72 => [
            'category' => self::CATEGORY_CONSIGNOR,
            'title' => 'Your warehouse city is missing.',
            'fix' => 'Open the Warehouses tab → edit the warehouse → fill in the City field.',
        ],
        73 => [
            'category' => self::CATEGORY_CONSIGNOR,
            'title' => 'Your warehouse street address is missing.',
            'fix' => 'Open the Warehouses tab → edit the warehouse → fill in the Address field.',
        ],
        74 => [
            'category' => self::CATEGORY_CONSIGNOR,
            'title' => 'Your warehouse postal code is missing or in the wrong format.',
            'fix' => 'Open the Warehouses tab → edit the warehouse → fix the postal code. ' . self::POSTCODE_HINT,
        ],

        // ----- Receiver (further forwarding receiver) -------------------------
        80 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'Forwarding receiver name is missing.',
            'fix' => 'Either disable forwarding for this shipment or set a valid receiver address.',
        ],
        81 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'Forwarding receiver country is missing or invalid.',
            'fix' => 'Either disable forwarding or pick a valid country.',
        ],
        82 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'Forwarding receiver city is missing.',
            'fix' => 'Either disable forwarding or fill in the forwarding city.',
        ],
        83 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'Forwarding receiver street address is missing.',
            'fix' => 'Either disable forwarding or fill in the address.',
        ],
        84 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'Forwarding receiver postal code is missing or invalid.',
            'fix' => 'Either disable forwarding or fix the postal code. ' . self::POSTCODE_HINT,
        ],
        85 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'Receiver phone number is missing or in the wrong format.',
            'fix' => 'Open the order, edit the delivery address, and use international format starting with the country code (e.g. +37060012345).',
        ],

        // ----- Shipment / batch ---------------------------------------------
        90 => [
            'category' => self::CATEGORY_SHIPMENT,
            'title' => 'A shipment has no parcels.',
            'fix' => 'Likely a module bug. Open the order and confirm Packages is at least 1.',
        ],
        91 => [
            'category' => self::CATEGORY_SHIPMENT,
            'title' => 'A shipment has more than 300 parcels.',
            'fix' => 'Lower the Packages count on the order, or split the order in PrestaShop.',
        ],
        92 => [
            'category' => self::CATEGORY_MANIFEST,
            'title' => 'The manifest is empty.',
            'fix' => 'Likely a transient issue — retry. If it persists, please report it.',
        ],
        93 => [
            'category' => self::CATEGORY_SHIPMENT,
            'title' => 'A shipment is empty.',
            'fix' => 'Open the order and verify a delivery address, weight, and at least one parcel are set.',
        ],
        94 => [
            'category' => self::CATEGORY_COUNTRY,
            'title' => 'This delivery country combination is not supported by your Venipak contract.',
            'fix' => 'Most Venipak accounts cover Lithuania, Latvia, Estonia, Poland and Finland only. To ship beyond those, ask your Venipak manager to enable Global service for your account.',
        ],
        95 => [
            'category' => self::CATEGORY_COUNTRY,
            'title' => 'Venipak does not deliver to this country with your current service.',
            'fix' => 'For PL/FI you need the International service; for the rest of the world, the Global service. Contact your Venipak manager to enable them.',
        ],
        96 => [
            'category' => self::CATEGORY_SERVICE,
            'title' => 'Insurance amount is too high.',
            'fix' => 'Maximum insurance per shipment is 10000 EUR. Lower the declared value, or split the order.',
        ],
        97 => [
            'category' => self::CATEGORY_SERVICE,
            'title' => 'Insurance is only available for shipments to Poland or Finland.',
            'fix' => 'Disable insurance for this shipment, or contact your Venipak manager.',
        ],
        98 => [
            'category' => self::CATEGORY_SERVICE,
            'title' => 'Return service is not available for this shipment.',
            'fix' => 'Disable return service in Configuration → Return service, or contact your Venipak manager to enable it on your contract.',
        ],
        115 => [
            'category' => self::CATEGORY_SERVICE,
            'title' => 'Return service needs a return address.',
            'fix' => 'Open the Warehouses tab and make sure your default warehouse has a complete address — the module re-uses it as the return-consignee whenever Return service is enabled.',
        ],

        // ----- Pickup point / locker -----------------------------------------
        134 => [
            'category' => self::CATEGORY_RECEIVER,
            'title' => 'The selected pickup point / locker is no longer valid at Venipak.',
            'fix' => 'Click "Change" in the Venipak panel and re-select a pickup point, then send again. If the list looks outdated, re-sync it from the Pickup points tab first.',
        ],
    ];

    /**
     * @return array{code: int, category: string, title: string, fix: string}
     */
    public static function describe(int $code): array
    {
        if (isset(self::CODES[$code])) {
            return [
                'code' => $code,
                'category' => self::CODES[$code]['category'],
                'title' => self::CODES[$code]['title'],
                'fix' => self::CODES[$code]['fix'],
            ];
        }

        return [
            'code' => $code,
            'category' => self::CATEGORY_UNKNOWN,
            'title' => 'Venipak rejected the shipment.',
            'fix' => 'See the exact Venipak reason below. If it is not clear how to fix it, contact support with this order reference.',
        ];
    }

    /**
     * Build a plain-English summary that a non-technical merchant can act on.
     * The structured `errors` list (codes + raw Venipak text) is kept in the
     * Logs tab — this output drops the technical bits in favour of a clear
     * "what's wrong" + "what to do" pair.
     *
     * @param array<int, array{
     *     code?: int, message?: string, pack?: string, shipment?: string,
     *     title?: string, fix?: string, hint?: string
     * }> $errors
     */
    public static function format(array $errors): string
    {
        if (empty($errors)) {
            return 'Venipak rejected the shipment without a specific reason. Please retry; if it keeps failing, contact support with this order reference.';
        }

        // Deduplicate: when multiple parcels in the same shipment hit the same
        // problem (e.g. all of them have the same postcode issue), Venipak
        // reports it once per parcel. We collapse those into a single bullet.
        $byCode = [];
        foreach ($errors as $error) {
            $code = (int) ($error['code'] ?? 0);
            if (!isset($byCode[$code])) {
                $byCode[$code] = [
                    'code' => $code,
                    'title' => trim((string) ($error['title'] ?? $error['hint'] ?? '')),
                    'fix' => trim((string) ($error['fix'] ?? '')),
                    'packs' => [],
                    'raw' => trim((string) ($error['message'] ?? '')),
                ];
            }
            $pack = trim((string) ($error['pack'] ?? ''));
            if ($pack !== '' && $pack !== '0' && !in_array($pack, $byCode[$code]['packs'], true)) {
                $byCode[$code]['packs'][] = $pack;
            }
        }

        $lines = [];
        foreach ($byCode as $error) {
            $title = $error['title'] ?: 'Venipak rejected the shipment.';
            $fix = $error['fix'];

            $packNote = '';
            if (!empty($error['packs'])) {
                $packNote = ' (parcel ' . implode(', ', $error['packs']) . ')';
            }

            $line = $title . $packNote;
            if ($fix !== '') {
                $line .= "\n→ " . $fix;
            }

            // Surface the exact Venipak reason inline so the merchant doesn't
            // have to open the Logs tab to see what Venipak actually said.
            // Skip it only when the raw text just echoes the mapped title.
            if ($error['raw'] !== '' && strcasecmp($error['raw'], $title) !== 0) {
                $codeLabel = $error['code'] ? '[' . $error['code'] . '] ' : '';
                $line .= "\nVenipak: " . $codeLabel . $error['raw'];
            }

            $lines[] = $line;
        }

        return implode("\n\n", $lines);
    }
}
