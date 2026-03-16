# ppvenipak — Implementation Plan

## Prerequisites

### pp-common package does NOT exist yet
The `prestapro/pp-common` shared package (with `AbstractPPCarrier`, shared Twig partials, CSS/JS) must be built first or in parallel. **Decision needed:** build pp-common first as a separate package, or inline the shared code in ppvenipak and extract later?

**Recommendation:** Inline first, extract later. This avoids blocking development on a package that has no second consumer yet. Mark all "shared" code with `// @pp-common` comments for later extraction.

---

## Phase 1: Module Skeleton & Configuration (no API calls)

### 1.1 File scaffold
Create the full directory structure per carrier-module-interface-requirements.md:
```
ppvenipak/
├── ppvenipak.php
├── composer.json
├── config.xml
├── logo.png
├── index.php
├── config/
│   ├── admin/services.yml
│   ├── front/services.yml
│   ├── routes.yml
│   └── services.yml
├── src/
│   ├── Api/VenipakApiClient.php          (stub)
│   ├── Carrier/VenipakShippingCalculator.php (stub)
│   ├── Controller/Admin/ConfigurationController.php
│   ├── Form/ConfigurationFormType.php
│   ├── Form/DataConfiguration.php
│   ├── Form/FormDataProvider.php
│   ├── Module/Installer.php
│   └── Module/Uninstaller.php
├── views/
│   ├── templates/admin/configuration.html.twig
│   ├── templates/admin/partials/_header.html.twig
│   ├── templates/admin/partials/_connection_status.html.twig
│   ├── templates/admin/partials/_footer.html.twig
│   ├── templates/hook/displayCarrierExtraContent.tpl
│   ├── templates/hook/displayOrderDetail.tpl
│   ├── css/prestapro-carrier.css
│   └── js/prestapro-carrier.js
├── translations/ (en-US/, lt-LT/, lv-LV/, et-EE/)
├── upgrade/
├── sql/install.sql
├── sql/uninstall.sql
└── docs/
```

### 1.2 Main module class (`ppvenipak.php`)
- `declare(strict_types=1)`
- Extends `CarrierModule` (inline AbstractPPCarrier logic until pp-common exists)
- Constructor: name, tab, version, author, author_uri, ps_versions_compliancy, hooks array, tabs array
- `isUsingNewTranslationSystem()` returns true
- `getContent()` redirects to `ps_ppvenipak_configuration`
- Conditional vendor autoload
- `getOrderShippingCost()` / `getOrderShippingCostExternal()` — initial stubs returning carrier price from PS zones

### 1.3 Installer / Uninstaller
- **Installer:** Create 4 DB tables (ppvenipak_order, ppvenipak_warehouse, ppvenipak_manifest, ppvenipak_terminal), register 2 carriers (courier + pickup), create 2 custom order states ("Shipment ready", "Shipment error"), register hooks
- **Uninstaller:** Soft-delete carriers (active=0, deleted=0), remove DB tables, remove config keys, remove order states

### 1.4 Database tables
```
ppvenipak_order        — order shipment data (tracking, terminal, status, extra fields)
ppvenipak_warehouse    — sender warehouses
ppvenipak_manifest     — manifest records
ppvenipak_terminal     — cached terminal/pickup point list
```

### 1.5 Admin configuration page
- Symfony controller with `#[Autowire]`
- 4-tab form (General, API, Shipping, Advanced)
- PrestaPro branded header/footer Twig partials
- Connection status bar with "Test Connection" AJAX button

### 1.6 Config keys (all prefixed `PPVENIPAK_`)
```
API:       _API_USER, _API_PASS, _API_ID, _LIVE_MODE
Sender:    _SENDER_NAME, _SENDER_COMPANY_CODE, _SENDER_CONTACT, _SENDER_COUNTRY,
           _SENDER_CITY, _SENDER_ADDRESS, _SENDER_POSTCODE, _SENDER_PHONE,
           _SENDER_EMAIL, _INCLUDE_SENDER
Checkout:  _SHOW_DOOR_CODE, _SHOW_CABINET_NO, _SHOW_WAREHOUSE_NO,
           _SHOW_DELIVERY_TIME, _SHOW_CALL_BEFORE,
           _ENABLE_RETURN_SERVICE, _RETURN_DAYS,
           _NWD_ENABLED, _NWD10_ENABLED, _NWD12_ENABLED,
           _NWD8_14_ENABLED, _NWD14_17_ENABLED, _NWD18_22_ENABLED
Label:     _LABEL_FORMAT
Counters:  _PACK_COUNTER (global int), _MANIFEST_COUNTER (JSON)
States:    _STATE_READY, _STATE_ERROR
Carriers:  _COURIER_ID, _COURIER_ID_REF, _PICKUP_ID, _PICKUP_ID_REF
Misc:      _DISABLE_PASSPHRASE, _COD_MODULES, _CRON_TOKEN
Multilang: _COURIER_NAME_{id_lang}, _PICKUP_NAME_{id_lang}
```

### 1.7 Services & Routes
- `config/routes.yml`: `ps_ppvenipak_configuration` (GET/POST), `ps_ppvenipak_ajax` (POST)
- `config/admin/services.yml`: ConfigurationController, FormType, FormDataProvider, FormHandler
- `config/front/services.yml`: CronController, CheckoutAjaxController
- `config/services.yml`: VenipakApiClient, VenipakShippingCalculator, Installer, Uninstaller

**Milestone:** Module installs, config page loads, carriers appear in PS carrier list.

---

## Phase 2: API Client & Terminal Management

### 2.1 VenipakApiClient
Full implementation with all methods:
- `submitShipment(string $xml): VenipakResponse`
- `getPickupPoints(string $country, ?string $postcode, ?string $city): array`
- `getRoute(string $country, string $postcode): array`
- `printLabel(array $packNos, string $format): string` (binary PDF)
- `printLink(array $packNos): string` (URL)
- `printManifest(string $manifestId): string` (binary PDF)
- `getTracking(string $code, int $type): array`
- `registerTracking(string $code, int $type): bool`
- Custom headers, live/test endpoint switching, error handling

### 2.2 XML Builders
- `ShipmentXmlBuilder` — builds `<description type="1">` (manifest with shipments)
- `CourierInvitationXmlBuilder` — builds `<description type="3">` (courier pickup request)
- Both use `DOMDocument` for valid XML construction

### 2.3 Number generators
- `PackNumberGenerator` — `V{API_ID}E{7-digit serial}`, global counter
- `ManifestNumberGenerator` — `{API_ID}{YYMMDD}{3-digit serial}`, daily reset

### 2.4 Terminal sync (cron)
- Front controller: `/module/ppvenipak/cron?action=terminals&token={token}`
- Fetches terminals for LT, LV, EE, PL from `ws/get_pickup_points`
- Truncates + inserts into `ppvenipak_terminal` table
- Runs on install + every 24h via cron

### 2.5 Test Connection
- AJAX endpoint that calls `getPickupPoints('LT')` with current credentials
- Returns success/failure to admin UI

**Milestone:** API client works, terminals sync, Test Connection passes.

---

## Phase 3: Checkout Integration

### 3.1 Pickup point selector (displayCarrierExtraContent)
- When pickup carrier selected → show map + terminal list
- Leaflet.js + OpenStreetMap (no API key)
- Load terminals from DB via AJAX (filtered by delivery country)
- Client-side weight/dimension filtering
- On selection → AJAX POST saves terminal_id + terminal_info to ppvenipak_order
- Validation: block delivery step if no terminal selected

### 3.2 Courier extra fields (displayCarrierExtraContent)
- When courier carrier selected → show configurable fields
- Door code, cabinet number, warehouse number (max 10 chars each)
- Delivery time dropdown (enabled nwd* options)
- Call before delivery checkbox
- Save via AJAX to ppvenipak_order.extra_fields JSON

### 3.3 Order creation hook (actionValidateOrder)
- Detect Venipak carrier
- Get weight (g→kg conversion)
- Detect COD (check payment module against PPVENIPAK_COD_MODULES)
- Save to ppvenipak_order: id_order, warehouse_id, weight, cod_amount, is_cod

### 3.4 Checkout JS
- `views/js/front/checkout.js` — carrier change listener, map, AJAX saves
- `views/css/front/checkout.css` — map container, terminal list, extra fields
- Uses shared `PPPickupSelector` class (inline until pp-common exists)

**Milestone:** Checkout works — customer can select courier/pickup, choose terminal, place order.

---

## Phase 4: Admin Order Management

### 4.1 Label generation ("Send to Venipak")
- Groups orders by warehouse
- Finds/creates manifest (reuse today's open manifest or create new)
- Builds shipment XML per order (courier vs pickup consignee)
- Generates pack numbers, increments counter
- POSTs to `import/send.php`
- Processes response: saves tracking numbers, manifest_id, updates order status
- Error handling: atomic failure → all orders in manifest marked as error

### 4.2 Label printing
- Fetches PDF via `ws/print_label`
- Caches locally
- Streams to browser with PDF headers

### 4.3 Manifest management
- List view: open/closed manifests with order counts
- Close manifest: sets closed=1, downloads cargo declaration PDF via `ws/print_list`
- Courier call: time window form, builds type="3" XML, POSTs to API

### 4.4 Tracking sync
- Single order: modal with tracking history (all CSV rows)
- Bulk sync: poll all non-delivered orders, update statuses
- Cron endpoint: `/module/ppvenipak/cron?action=tracking&token={token}`
- Always hits production endpoint for tracking

### 4.5 Order sidebar panel (displayAdminOrderSide)
- Shows tracking number, status, last update
- Buttons: Generate Label, Print Label, Track on Venipak
- Pickup location info if applicable
- Works for both new and legacy-adopted orders

### 4.6 Warehouse CRUD
- Admin page for managing sender warehouses
- Default warehouse per shop
- Used in label generation for consignor data

**Milestone:** Full admin workflow — generate labels, print, manage manifests, track shipments.

---

## Phase 5: Migration from Legacy Module

### 5.1 Legacy module registry
```php
protected const LEGACY_MODULES = [
    'mijoravenipak' => [
        'name' => 'Venipak (Mijora)',
        'versions' => [
            '1.1.x' => [
                'range' => '>=1.0.0',
                'config_keys' => [
                    'MJVP_API_USER' => 'PPVENIPAK_API_USER',
                    'MJVP_API_PASS' => 'PPVENIPAK_API_PASS',
                    'MJVP_API_ID' => 'PPVENIPAK_API_ID',
                    'MJVP_API_LIVE_MODE' => 'PPVENIPAK_LIVE_MODE',
                    'MJVP_SENDER_NAME' => 'PPVENIPAK_SENDER_NAME',
                    // ... full mapping
                ],
                'tables' => [
                    'mjvp_orders' => 'v1',
                    'mjvp_warehouse' => 'v1',
                    'mjvp_manifest' => 'v1',
                ],
                'carrier_keys' => [
                    'courier_ref' => 'MJVP_COURIER_ID_REFERENCE',
                    'pickup_ref' => 'MJVP_PICKUP_ID_REFERENCE',
                ],
                'tracking_field' => 'labels_numbers', // JSON array in mjvp_orders
                'terminal_field' => 'terminal_id',    // in mjvp_orders
                'label_path' => 'modules/mijoravenipak/pdf/labels/',
                'order_states' => ['MJVP_ORDER_STATE_READY', 'MJVP_ORDER_STATE_ERROR'],
            ],
        ],
    ],
];
```

### 5.2 Migration flow
- Detect `mijoravenipak` installed → show migration banner
- Preview: count orders, warehouses, manifests, tracking numbers
- Migrate: copy config, adopt carriers (same id_reference), copy order data to ppvenipak_order, copy warehouses, build legacy map
- Disable old module
- Log everything to ppvenipak_migration_log

### 5.3 Carrier adoption
- Read `MJVP_COURIER_ID_REFERENCE` and `MJVP_PICKUP_ID_REFERENCE`
- Create new carriers with same `id_reference`, `external_module_name='ppvenipak'`
- Disable old carriers (active=0)
- Build `ppvenipak_legacy_map` for old order lookups

### 5.4 Safety
- Never modify ps_orders, ps_order_carrier
- Transaction-safe, rollback on failure
- Dry-run preview mode
- Idempotent (skip already-migrated records)
- Reversible (uninstall re-activates legacy carriers)

**Milestone:** Seamless migration from mijoravenipak — no data loss, old orders accessible.

---

## Phase 6: Polish & Release

### 6.1 Translations
- Extract all strings to XLIFF files
- EN (default), LT, LV, ET

### 6.2 Branding
- PrestaPro CSS variables applied to all admin pages
- Logo, header, footer, connection status bar

### 6.3 Testing
- Unit tests: shipping calculator, number generators, XML builders, postcode validation
- Integration tests: API client with mocked responses

### 6.4 Documentation
- README.md per template
- Screenshots (admin config, checkout, pickup map, order panel)
- CHANGELOG.md

### 6.5 Quality
- PHPStan level 6
- PSR-12
- `declare(strict_types=1)` everywhere
- GitHub CI pipeline

**Milestone:** Production-ready release v1.0.0.

---

## Implementation Order Summary

| Phase | What | Depends on | Deliverable |
|-------|------|------------|-------------|
| **1** | Skeleton, config, DB, carriers | Nothing | Module installs, config page works |
| **2** | API client, terminals, XML builders | Phase 1 | API calls work, terminals sync |
| **3** | Checkout (pickup map, courier fields) | Phase 2 | Customer can order with Venipak |
| **4** | Admin (labels, manifests, tracking) | Phase 2-3 | Full admin workflow |
| **5** | Migration from mijoravenipak | Phase 4 | Seamless upgrade path |
| **6** | Polish, translations, tests, release | Phase 1-5 | v1.0.0 |

---

## Key Reference Files

- **API spec:** `docs/venipak-api-requirements.md`
- **Module standards:** `docs/carrier-module-interface-requirements.md`
- **PS9 module pattern:** `/var/www/html/9onelife/modules/ppcloudinary/` (services.yml, routes.yml, controllers, forms)
- **CarrierModule base:** `/var/www/html/9onelife/classes/module/CarrierModule.php`
- **Checkout hook rendering:** `/var/www/html/9onelife/themes/onelife/templates/checkout/_partials/steps/delivery.tpl`
