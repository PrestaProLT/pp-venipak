# PrestaPro Carrier Module — Unified Interface Requirements

## Purpose

This document defines the **unified UI/UX, architecture, and branding standards** for all PrestaPro carrier modules for PrestaShop 9. Every carrier module (Venipak, Omniva, DPD, etc.) must follow these requirements to ensure a consistent, professional PrestaPro brand experience.

---

## 1. Module Identity & Naming

### Naming Convention
- **Module technical name:** `pp{carrier}` (e.g., `ppvenipak`)
- **Display name:** `PrestaPro — {Carrier Name}` (e.g., `PrestaPro — Venipak`)
- **Namespace:** `PrestaShop\Module\PP{Carrier}\` (PS9 standard root namespace)
- **GitHub repo:** `pp-{carrier}` (e.g., `pp-venipak`)

### Module Metadata (config.xml / construct)
```php
declare(strict_types=1);

// Main module class
$this->name = 'ppvenipak';
$this->tab = 'shipping_logistics';
$this->version = '1.0.0';
$this->author = 'PrestaPro';
$this->author_uri = 'https://prestapro.lt/modules/{module_name}'; // e.g., https://prestapro.lt/modules/ppvenipak
$this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
$this->need_instance = 0;

// Required hooks array
$this->hooks = [
    'actionCarrierUpdate',
    'displayCarrierExtraContent',
    'displayOrderDetail',
    'displayAdminOrderSide',
    'actionOrderStatusUpdate',
    'actionValidateOrder',
    'displayBackOfficeHeader',
];

// Required tab registration for PS9 Module Manager
$this->tabs = [
    [
        'name' => 'PrestaPro — Venipak',
        'class_name' => 'AdminPPVenipak',
        'route_name' => 'ps_ppvenipak_configuration',
        'parent_class_name' => 'INVISIBLE',
    ],
];
```

### Required Module Methods (PS9)
```php
// MUST return true — enables PS9 translation system
public function isUsingNewTranslationSystem(): bool
{
    return true;
}

// MUST redirect to Symfony route — required for "Configure" button
public function getContent(): void
{
    Tools::redirectAdmin(
        $this->get('router')->generate('ps_ppvenipak_configuration')
    );
}
```

### Conditional Vendor Autoload
```php
// In main module file — required for Composer dependencies
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
```

### Logo
- All modules use the **PrestaPro logo** as the module icon (`logo.png`, 32x32).
- Carrier-specific icon used only inside the configuration UI where relevant.

---

## 2. Architecture (Shared Base)

### Abstract Base Class: `AbstractPPCarrier`

All carrier modules extend a shared abstract class providing:

```
AbstractPPCarrier extends CarrierModule
├── install() / uninstall()          — Shared lifecycle with hooks
├── getContent()                     — Redirects to Symfony config route
├── hookActionCarrierUpdate()        — Shared id_reference tracking
├── createCarriers()                 — Unified carrier creation logic
├── deleteCarriers()                 — Soft-delete on uninstall
├── getConfigFieldsPrefix()          — Per-module config key prefix
├── renderTracking()                 — Unified tracking URL builder
└── getOrderShippingCost()           — Abstract, implemented per carrier
    getOrderShippingCostExternal()   — Abstract, implemented per carrier
```

### Shared Composer Package

Extract common code into a shared library:
- **Package:** `prestapro/pp-common`
- Contains: abstract base class, shared form types, Twig partials, CSS/JS assets
- Each module requires it via `composer.json`

### Directory Structure (per module)

```
pp{carrier}/
├── pp{carrier}.php                     # Main module class (declare(strict_types=1))
├── composer.json
├── config.xml
├── logo.png                            # PrestaPro brand logo (32x32)
├── index.php                           # Security index
├── config/
│   ├── index.php
│   ├── admin/
│   │   ├── index.php
│   │   └── services.yml                # Back office services
│   ├── front/
│   │   ├── index.php
│   │   └── services.yml                # Front office services
│   ├── routes.yml                      # Route: ps_pp{carrier}_{action}
│   └── services.yml                    # Shared services
├── src/                                # No index.php (Symfony scans this)
│   ├── Carrier/
│   │   └── {Carrier}ShippingCalculator.php
│   ├── Controller/
│   │   └── Admin/
│   │       └── ConfigurationController.php  # Uses #[Autowire] for DI
│   ├── Form/
│   │   ├── ConfigurationFormType.php
│   │   ├── DataConfiguration.php
│   │   └── FormDataProvider.php
│   ├── Api/
│   │   └── {Carrier}ApiClient.php      # Carrier API integration
│   └── Module/
│       ├── Installer.php               # Delegated install logic
│       └── Uninstaller.php             # Delegated uninstall logic
├── views/
│   ├── index.php
│   ├── templates/
│   │   ├── admin/
│   │   │   ├── configuration.html.twig
│   │   │   └── partials/
│   │   │       ├── _header.html.twig
│   │   │       ├── _connection_status.html.twig
│   │   │       └── _footer.html.twig
│   │   └── hook/
│   │       ├── displayCarrierExtraContent.tpl
│   │       └── displayOrderDetail.tpl
│   ├── css/
│   │   ├── index.php
│   │   └── prestapro-carrier.css       # Shared brand CSS
│   └── js/
│       ├── index.php
│       └── prestapro-carrier.js        # Shared brand JS
├── translations/
│   ├── index.php
│   ├── en-US/
│   ├── lt-LT/
│   ├── lv-LV/
│   └── et-EE/
├── upgrade/
│   └── index.php                       # Version upgrade scripts
└── tests/
    └── ...
```

### composer.json (per module)
```json
{
    "name": "prestapro/pp-{carrier}",
    "description": "PrestaPro — {Carrier Name} shipping module for PrestaShop 9",
    "type": "prestashop-module",
    "license": "AFL-3.0",
    "authors": [
        {
            "name": "PrestaPro",
            "homepage": "https://prestapro.lt"
        }
    ],
    "autoload": {
        "psr-4": {
            "PrestaShop\\Module\\PP{Carrier}\\": "src/"
        }
    },
    "require": {
        "php": ">=8.1",
        "prestapro/pp-common": "^1.0"
    },
    "config": {
        "prepend-autoloader": false
    }
}
```

### Route Naming Convention
```yaml
# config/routes.yml — routes MUST follow ps_{modulename}_{action} pattern
ps_pp{carrier}_configuration:
    path: /pp{carrier}/configuration
    methods: [GET, POST]
    defaults:
        _controller: 'PrestaShop\Module\PP{Carrier}\Controller\Admin\ConfigurationController::index'
        _legacy_controller: AdminPP{Carrier}
        _legacy_link: AdminPP{Carrier}
```

---

## 3. Migration from Legacy / Third-Party Carrier Modules

Every PrestaPro carrier module **MUST** detect and migrate data from older or third-party modules for the same carrier during installation. Historical order and shipping data must never be lost.

### Migration Flow

```
install()
  → detectLegacyModules()          # Scan for known older modules
  → if legacy found:
  │   → showMigrationPrompt()      # Inform merchant, ask to proceed
  │   → migrateConfiguration()     # API keys, settings, zone mappings
  │   → migrateCarrierReferences() # Map old carrier IDs → new carrier IDs
  │   → migrateOrderHistory()      # Re-link historical orders
  │   → migrateTrackingData()      # Preserve tracking numbers & statuses
  │   → migratePickupSelections()  # Preserve selected locker/pickup points
  │   → migrateLabels()            # Copy generated shipping labels
  │   → disableLegacyModule()      # Disable (NOT delete) old module
  │   → logMigration()             # Full migration log for audit
  → continue normal install
```

### Known Legacy Modules per Carrier

Each PrestaPro module must maintain a **versioned registry** of known legacy modules. Different versions of the same module may store data in different DB schemas, config keys, or table structures — each version case must be handled explicitly.

```php
// Example: ppvenipak
protected const LEGACY_MODULES = [
    'venipakshipping' => [
        'name' => 'Venipak Shipping (Official)',
        'versions' => [
            '1.x' => [
                'range' => '>=1.0.0 <2.0.0',
                'config_keys' => [
                    'VENIPAK_API_USER' => 'PPVENIPAK_API_KEY',
                    'VENIPAK_API_PASS' => 'PPVENIPAK_API_SECRET',
                ],
                'tables' => [
                    'venipak_cart' => ['schema' => 'v1', 'has_pickup' => false],
                ],
                'tracking_field' => 'tracking_number', // field name in their table
                'notes' => 'No parcel locker support in v1',
            ],
            '2.x' => [
                'range' => '>=2.0.0 <3.0.0',
                'config_keys' => [
                    'VENIPAK_API_KEY' => 'PPVENIPAK_API_KEY',
                    'VENIPAK_API_SECRET' => 'PPVENIPAK_API_SECRET',
                    'VENIPAK_SENDER_NAME' => 'PPVENIPAK_SENDER_NAME',
                    'VENIPAK_SENDER_CITY' => 'PPVENIPAK_SENDER_CITY',
                ],
                'tables' => [
                    'venipak_cart' => ['schema' => 'v2', 'has_pickup' => true],
                    'venipak_tracking' => ['schema' => 'v1'],
                    'venipak_labels' => ['schema' => 'v1'],
                ],
                'tracking_field' => 'pack_no',
                'notes' => 'Added parcel locker support, different tracking field name',
            ],
            '2.5+' => [
                'range' => '>=2.5.0 <3.0.0',
                'config_keys' => [
                    // Same as 2.x but with additional keys
                    'VENIPAK_API_KEY' => 'PPVENIPAK_API_KEY',
                    'VENIPAK_API_SECRET' => 'PPVENIPAK_API_SECRET',
                    'VENIPAK_PICKUP_TYPE' => 'PPVENIPAK_PICKUP_TYPE',
                    'VENIPAK_LABEL_FORMAT' => 'PPVENIPAK_LABEL_FORMAT',
                ],
                'tables' => [
                    'venipak_cart' => ['schema' => 'v2', 'has_pickup' => true],
                    'venipak_tracking' => ['schema' => 'v2'], // schema changed!
                    'venipak_labels' => ['schema' => 'v1'],
                    'venipak_pickup_points' => ['schema' => 'v1'], // new table
                ],
                'tracking_field' => 'pack_no',
                'notes' => 'New pickup_points table, tracking schema v2 with status history',
            ],
        ],
    ],
    'dh_venipak' => [
        'name' => 'DH Venipak',
        'versions' => [
            '*' => [
                'range' => '>=0.0.0',
                'config_keys' => [
                    'DH_VENIPAK_USER' => 'PPVENIPAK_API_KEY',
                    'DH_VENIPAK_PASS' => 'PPVENIPAK_API_SECRET',
                ],
                'tables' => [
                    'dh_venipak_orders' => ['schema' => 'v1'],
                ],
                'tracking_field' => 'barcode',
                'notes' => 'Simple module, single version schema',
            ],
        ],
    ],
];
```

### Version Detection & Resolution

```php
/**
 * Detects installed legacy module and resolves the correct version case.
 * Returns the matching version config for migration.
 */
protected function resolveLegacyVersion(string $moduleName): ?array
{
    // 1. Check if module is installed: Module::isInstalled($moduleName)
    // 2. Get module version: Module::getInstanceByName($moduleName)->version
    // 3. Match version against defined ranges (most specific first)
    //    e.g., v2.5.3 matches '2.5+' before '2.x'
    // 4. Detect actual DB schema by checking table structure
    //    (version number may lie — always verify tables exist and match)
    // 5. Return matched version config or null if no match
}
```

### Version-Aware Migration Flow

```
detectLegacyModules()
  → for each LEGACY_MODULES:
    → is module installed?
    → resolveLegacyVersion()
      → get declared version
      → match to version range (most specific first)
      → verify DB schema matches expected structure
      → if schema mismatch → log warning, attempt best-fit match
    → return: { module_name, version, matched_case, config_keys, tables }
```

### Admin UI — Version-Aware Migration Notice

```
┌─────────────────────────────────────────────────────────┐
│  ⚠ Legacy module detected: "venipakshipping" v2.5.3    │
│  Matched migration profile: 2.5+                       │
│                                                         │
│  We found:                                              │
│  • 1,247 historical orders                              │
│  • 3 configured shipping methods                        │
│  • API credentials configured                           │
│  • Parcel locker selections (423 records)               │
│  • Tracking data (1,102 records)                        │
│                                                         │
│  [Preview Migration]  [Migrate Now]  [Skip]             │
└─────────────────────────────────────────────────────────┘
```

### What MUST Be Migrated

| Data | Source | Target | Notes |
|------|--------|--------|-------|
| **API credentials** | Legacy config keys | PP config keys (`PP{CARRIER}_API_KEY`, etc.) | Ask merchant to confirm |
| **Tracking numbers** | Legacy tracking table or `ps_order_carrier.tracking_number` | `pp_{carrier}_tracking` lookup table | **Read-only copy** — never modify source |
| **Pickup point selections** | Legacy pickup/locker table | `pp_{carrier}_pickup_selections` | Copy, preserve address + locker ID |
| **Shipping labels** | Legacy label storage (files/DB) | `pp_{carrier}_labels` | Copy files, update references |
| **Zone/country mappings** | Legacy config | PP config | Carrier-specific zone logic |

### Core Principle: NEVER Modify Orders

**Old orders stay untouched.** We do NOT update `ps_orders`, `ps_order_carrier`, or any core order tables. Instead, the PP module **adopts** legacy carriers so it can seamlessly continue processing them.

### How Carrier Adoption Works

```
Legacy state:
  ps_carrier: id_carrier=5, id_reference=3, name="Venipak", external_module_name="venipakshipping"

After PP module install:
  ps_carrier: id_carrier=5 → UNTOUCHED (old orders keep working)
  ps_carrier: id_carrier=12 (NEW), id_reference=3, external_module_name="ppvenipak"
    └── New carrier inherits same id_reference as legacy
    └── PrestaShop routes new orders to ppvenipak module
    └── Old orders still reference id_carrier=5 — no breakage
```

```php
/**
 * Adopts legacy carrier by creating a new carrier linked to the same id_reference.
 * Old orders remain untouched. New orders use the PP module seamlessly.
 *
 * NEVER modify ps_orders or ps_order_carrier.
 * NEVER delete old carriers — only mark external_module_name to prevent conflicts.
 */
protected function adoptLegacyCarrier(int $legacyIdReference): int
{
    // 1. Read legacy carrier config (name, zones, ranges, logo)
    // 2. Create new carrier with same id_reference
    //    → PS will route new orders to our module via external_module_name
    // 3. Copy zone assignments, weight/price ranges from legacy carrier
    // 4. Disable legacy carrier (active=0, deleted=0) — keeps order history intact
    // 5. Build lookup table: legacy carrier IDs → PP carrier IDs
    //    → Used by our module to handle tracking/labels for old orders too
    // 6. Return new carrier ID
}
```

### Legacy Order Continuity

The PP module must be able to **service old orders** that belong to the legacy carrier:

```php
/**
 * Lookup table: maps legacy carrier IDs to PP module.
 * Allows our module to display tracking, generate labels,
 * and update statuses for orders created by the old module.
 */
// DB table: pp_{carrier}_legacy_map
// | id_legacy_carrier | id_legacy_module | id_pp_carrier | id_reference | migrated_at |

// When displaying order detail (displayAdminOrderSide hook):
// 1. Check if order's id_carrier belongs to us (new carrier)
// 2. If not, check pp_{carrier}_legacy_map for adopted legacy carriers
// 3. If found → show our tracking panel, allow label generation
// 4. If not found → skip (not our carrier)
```

### What This Enables

| Scenario | Behavior |
|----------|----------|
| **Old order, old carrier** | PP module recognizes it via legacy map, shows tracking panel, can generate new labels |
| **New order, new carrier** | Normal PP module flow |
| **Merchant views old order** | Sees PP tracking panel instead of broken legacy module panel |
| **Old module re-enabled** | No conflict — old orders still reference old carrier IDs |
| **PP module uninstalled** | Old carrier re-activated (active=1), merchant is back to previous state |

### Migration Safety Rules

1. **NEVER modify core order tables** — `ps_orders`, `ps_order_carrier`, `ps_order_history` are read-only
2. **Never delete legacy carriers** — disable only (active=0, deleted=0), preserving order history
3. **Never delete legacy module** — only disable it. Merchant may need to rollback
4. **Transaction-safe** — wrap all DB operations in a transaction; rollback on any failure
5. **Dry-run mode** — provide a "Preview migration" option that shows what will change without writing
6. **Migration log** — store a detailed log in `pp_{carrier}_migration_log` table:
   - Timestamp, legacy module name, data type, records migrated, errors
7. **Idempotent** — running migration twice must not duplicate data
8. **Reversible** — uninstalling PP module re-activates legacy carriers automatically

### Admin UI — Migration Notice

When a legacy module is detected, show a banner on the configuration page:

```
┌─────────────────────────────────────────────────────────┐
│  ⚠ Legacy module detected: "venipakshipping" v2.1.3    │
│                                                         │
│  We found an existing Venipak module with:              │
│  • 1,247 historical orders                              │
│  • 3 configured shipping methods                        │
│  • API credentials configured                           │
│                                                         │
│  [Preview Migration]  [Migrate Now]  [Skip]             │
└─────────────────────────────────────────────────────────┘
```

### Migration in AbstractPPCarrier

The base class provides shared migration infrastructure:

```
AbstractPPCarrier
├── detectLegacyModules(): array           — Scans LEGACY_MODULES list
├── resolveLegacyVersion(): ?array         — Matches installed version to migration profile
├── verifyLegacySchema(): bool             — Confirms DB tables match expected schema
├── getLegacyModuleData(): array           — Reads legacy config & tables (version-aware)
├── migrateConfiguration(): void           — Config key mapping (copy, never move)
├── adoptLegacyCarrier(): int              — Creates new carrier with same id_reference
├── buildLegacyMap(): void                 — Populates pp_{carrier}_legacy_map table
├── isLegacyOrder(int $idOrder): bool      — Checks if order belongs to adopted carrier
├── createMigrationLog(): void             — Audit trail
├── previewMigration(): array              — Dry-run summary (version-aware)
└── restoreLegacyCarriers(): void          — Re-activates legacy carriers on uninstall
```

Each carrier module defines:
- `LEGACY_MODULES` — versioned registry of known legacy modules with per-version config keys, table schemas, and field mappings
- Version-specific data transformations (e.g., different tracking field names, schema changes between versions)
- Schema verification queries to confirm actual DB structure matches expected version profile

---

## 4. Admin Configuration Page — Unified Layout

All carrier modules use the **same layout structure** with carrier-specific content injected into defined zones.

### Page Structure

```
┌─────────────────────────────────────────────────────────┐
│  PRESTAPRO BRANDED HEADER                               │
│  ┌─────────────────────────────────────────────────────┐│
│  │ PrestaPro logo  │  PrestaPro — {Carrier Name}      ││
│  │                 │  v1.0.0 · PS 9.x compatible      ││
│  │                 │  [Docs]  [Support]  [GitHub]      ││
│  └─────────────────────────────────────────────────────┘│
├─────────────────────────────────────────────────────────┤
│  CONNECTION STATUS BAR                                  │
│  ┌─────────────────────────────────────────────────────┐│
│  │ ● Connected to {Carrier} API  │  [Test Connection]  ││
│  └─────────────────────────────────────────────────────┘│
├─────────────────────────────────────────────────────────┤
│  TAB NAVIGATION (consistent across all modules)        │
│  ┌──────────┬──────────┬──────────┬──────────┐         │
│  │ General  │ API      │ Shipping │ Advanced │         │
│  └──────────┴──────────┴──────────┴──────────┘         │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  CARD: Tab Content Area                                │
│  ┌─────────────────────────────────────────────────────┐│
│  │  {Tab-specific form fields}                         ││
│  │                                                     ││
│  │  All forms use PrestaShop 9 Symfony form types      ││
│  │  with consistent spacing and labeling               ││
│  └─────────────────────────────────────────────────────┘│
│                                                         │
│  ┌─────────────────────────────────────────────────────┐│
│  │                              [Save Configuration]   ││
│  └─────────────────────────────────────────────────────┘│
├─────────────────────────────────────────────────────────┤
│  PRESTAPRO BRANDED FOOTER                               │
│  ┌─────────────────────────────────────────────────────┐│
│  │ PrestaPro · Dedicated to PrestaShop since 2019 ││
│  │ prestapro.lt · github.com/PrestaProLT              ││
│  └─────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────┘
```

### Tab Definitions (Standard Across All Modules)

#### Tab 1: General
- **Enable/Disable module** (SwitchType)
- **Carrier display name — Multilanguage** (TranslatableType — see below)
- **Carrier logo upload**
- **Tracking URL template** (TextType with `{tracking_number}` placeholder)
- **Delivery time estimate** (TranslatableType — e.g., "1-3 business days")

### Multilanguage Carrier Names (PrestaPro Feature)

**Problem:** PrestaShop stores carrier names as a single-language string in `ps_carrier.name`. Merchants with multilingual stores cannot display carrier names in the customer's language during checkout.

**Solution:** All PrestaPro carrier modules override the carrier display name per language using a custom translation layer.

#### How It Works

1. **Admin config** — merchant enters carrier name per language via `TranslatableType` field:
   ```
   ┌─────────────────────────────────────────────────┐
   │  Carrier display name                           │
   │  ┌──────┬──────┬──────┐                         │
   │  │  EN  │  LT  │  LV  │                         │
   │  └──────┴──────┴──────┘                         │
   │  ┌─────────────────────────────────────────────┐│
   │  │ Venipak — Courier delivery                  ││  (EN active)
   │  └─────────────────────────────────────────────┘│
   │                                                 │
   │  LT: Venipak — Kurjerinis pristatymas          │
   │  LV: Venipak — Kurjerpiegāde                   │
   └─────────────────────────────────────────────────┘
   ```

2. **Storage** — multilang names stored in module config per language ID:
   ```php
   // Config keys: PP{CARRIER}_{METHOD}_NAME_{id_lang}
   // e.g., PPVENIPAK_COURIER_NAME_1 = "Venipak — Courier delivery"
   // e.g., PPVENIPAK_COURIER_NAME_2 = "Venipak — Kurjerinis pristatymas"
   ```

3. **Front office override** — the module hooks into `displayCarrierExtraContent` and `actionGetExtraCarrier` to replace the single-language `ps_carrier.name` with the correct translation for the current language context.

4. **Applies per shipping method** — if a carrier module registers multiple methods (e.g., Courier, Parcel Locker, Pickup Point), each method gets its own multilang name.

#### Implementation in AbstractPPCarrier

```
AbstractPPCarrier
├── getCarrierName(int $idCarrier, int $idLang): string
│   → Returns multilang name, falls back to ps_carrier.name
├── saveCarrierNames(int $idCarrier, array $names): void
│   → Saves name per id_lang to Configuration
└── hookDisplayCarrierExtraContent(array $params): string
    → Injects correct language name into carrier display
```

#### Checkout Display Result

```
Customer language: Lithuanian (LT)

┌──────────────────────────────────────────────────┐
│  [Venipak logo]  Venipak — Kurjerinis pristatymas│  ← multilang name
│                  Pristatymas: 1-3 darbo dienos   │  ← multilang estimate
│                                          €4.99   │
└──────────────────────────────────────────────────┘

Customer language: English (EN)

┌──────────────────────────────────────────────────┐
│  [Venipak logo]  Venipak — Courier delivery      │  ← multilang name
│                  Estimated: 1-3 business days     │  ← multilang estimate
│                                          €4.99   │
└──────────────────────────────────────────────────┘
```

#### Tab 2: API Configuration
- **API Key / Username** (TextType, required)
- **API Secret / Password** (PasswordType, required)
- **API Environment** (ChoiceType: Sandbox / Production)
- **Sender/Warehouse address** (grouped fields: name, street, city, postcode, country)
- **[Test Connection]** button (AJAX call with visual feedback)

#### Tab 3: Shipping Methods & Rates
- **Available shipping methods** (carrier-specific, CheckboxType or SwitchType per method)
  - e.g., Venipak: "Courier", "Parcel Locker", "Pickup Point"
  - e.g., DPD: "Classic", "Pickup", "Express"
- **Free shipping threshold** (MoneyType per method)
- **Handling fee** (MoneyType — added on top of API rate)
- **Zone restrictions** (carrier-specific zone mapping)
- **Weight/Price ranges** (if not using API rates)

#### Tab 4: Advanced
- **Debug mode** (SwitchType — enables logging)
- **Log viewer** (last 50 log entries, read-only textarea)
- **Label format** (ChoiceType: PDF A4 / PDF A6 / ZPL)
- **Automatic status update** (SwitchType — webhook/cron for tracking updates)
- **Order state mapping** (map carrier statuses → PrestaShop order states)

---

## 5. Branding & Visual Design

### CSS Variables (prestapro-carrier.css)

```css
:root {
    --prestapro-primary: #2563eb;
    --prestapro-primary-hover: #1d4ed8;
    --prestapro-secondary: #64748b;
    --prestapro-success: #16a34a;
    --prestapro-warning: #d97706;
    --prestapro-danger: #dc2626;
    --prestapro-bg: #f8fafc;
    --prestapro-card-bg: #ffffff;
    --prestapro-border: #e2e8f0;
    --prestapro-text: #1e293b;
    --prestapro-text-muted: #94a3b8;
    --prestapro-radius: 8px;
    --prestapro-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    --prestapro-font: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
```

### Header Component

```css
.prestapro-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 24px;
    background: var(--prestapro-card-bg);
    border: 1px solid var(--prestapro-border);
    border-radius: var(--prestapro-radius);
    margin-bottom: 16px;
    box-shadow: var(--prestapro-shadow);
}

.prestapro-header__logo {
    width: 48px;
    height: 48px;
}

.prestapro-header__title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--prestapro-text);
    margin: 0;
}

.prestapro-header__meta {
    font-size: 0.8rem;
    color: var(--prestapro-text-muted);
}

.prestapro-header__links a {
    color: var(--prestapro-primary);
    text-decoration: none;
    font-size: 0.85rem;
    margin-right: 12px;
}
```

### Connection Status Bar

```css
.prestapro-status {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 24px;
    border-radius: var(--prestapro-radius);
    margin-bottom: 16px;
    font-size: 0.9rem;
}

.prestapro-status--connected {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: var(--prestapro-success);
}

.prestapro-status--disconnected {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: var(--prestapro-danger);
}

.prestapro-status--unknown {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: var(--prestapro-warning);
}
```

### Tab Navigation

```css
.prestapro-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--prestapro-border);
    margin-bottom: 16px;
}

.prestapro-tabs__tab {
    padding: 10px 20px;
    border: none;
    background: transparent;
    color: var(--prestapro-secondary);
    font-weight: 500;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color 0.2s, border-color 0.2s;
}

.prestapro-tabs__tab--active {
    color: var(--prestapro-primary);
    border-bottom-color: var(--prestapro-primary);
}
```

### Footer Component

```css
.prestapro-footer {
    text-align: center;
    padding: 16px 24px;
    margin-top: 24px;
    font-size: 0.8rem;
    color: var(--prestapro-text-muted);
    border-top: 1px solid var(--prestapro-border);
}

.prestapro-footer a {
    color: var(--prestapro-primary);
    text-decoration: none;
}
```

---

## 6. Front Office — Checkout Integration

### Carrier Selection (displayCarrierExtraContent hook)

All modules render a **consistent carrier option block** during checkout:

```
┌──────────────────────────────────────────────────┐
│  [Carrier Logo]  Carrier Name                    │
│                  Estimated: 1-3 business days    │
│                                                  │
│  ○ Courier delivery         €4.99               │
│  ○ Parcel locker            €2.49               │
│    └── [Select pickup point ▼]                   │
│  ○ Pickup point             €1.99               │
│    └── [Select location ▼]                       │
└──────────────────────────────────────────────────┘
```

### Pickup Point / Locker Selector (if applicable)

Standardized widget for all carriers that offer pickup/locker delivery:
- **Map view** with pins (Leaflet.js / OpenStreetMap — no API key needed)
- **List view** as fallback (sortable by distance)
- **Search by city/postcode**
- **Responsive** — works on mobile checkout
- **Same component** for Venipak lockers, Omniva lockers, DPD pickup, etc.
- Shared JS class: `PPPickupSelector`

### Order Confirmation & Tracking

```
┌──────────────────────────────────────────────────┐
│  Shipping: PrestaPro — Venipak                   │
│  Method: Parcel Locker                           │
│  Tracking: VP1234567890  [Track shipment →]      │
│  Status: In transit · Updated 2h ago             │
│                                                  │
│  Pickup: Venipak locker Žirmūnai, Vilnius        │
└──────────────────────────────────────────────────┘
```

---

## 7. Shared Hooks (Registered by All Modules)

| Hook | Purpose |
|------|---------|
| `actionCarrierUpdate` | Track carrier ID changes |
| `displayCarrierExtraContent` | Pickup point selector in checkout |
| `displayOrderDetail` | Tracking info on order detail page |
| `displayAdminOrderSide` | Shipping label + tracking in BO order |
| `actionOrderStatusUpdate` | Trigger label generation on status change |
| `actionValidateOrder` | Send shipment data to carrier API |
| `displayBackOfficeHeader` | Load admin CSS/JS on config page |

---

## 8. Back Office — Order Management

### Order Detail Sidebar (displayAdminOrderSide)

Consistent panel on every order that used a PrestaPro carrier:

```
┌──────────────────────────────────────┐
│  PrestaPro — {Carrier}               │
├──────────────────────────────────────┤
│  Tracking: {number}                  │
│  Status: {carrier_status}            │
│  Updated: {timestamp}                │
│                                      │
│  [Generate Label]  [Print Label]     │
│  [Track on {Carrier} →]             │
│                                      │
│  Pickup: {location_name}            │
│  {address}                           │
└──────────────────────────────────────┘
```

---

## 9. Translation & Localization

### Required Languages (minimum)
- `en` — English (default)
- `lt` — Lithuanian
- `lv` — Latvian (Baltic market)
- `et` — Estonian (Baltic market)

### Translation System (PS9 New System Only)
- Use `$this->trans()` — **NEVER** use `$this->l()`
- `isUsingNewTranslationSystem()` must return `true`
- All source strings **MUST be in English** (no other languages in code)
- Translation wordings **MUST be string literals** (never variables)
- Format: XLIFF (`.xlf`) in locale directories (`en-US/`, `lt-LT/`, etc.)

### Translation Domain Convention
- Domain format: `Modules.{ModuleNameCapitalizedFirstOnly}.{Context}`
- Admin: `Modules.Pp{carrier}.Admin` (e.g., `Modules.Ppvenipak.Admin`)
- Front: `Modules.Pp{carrier}.Shop` (e.g., `Modules.Ppvenipak.Shop`)

### Translation Usage Examples
```php
// In PHP (controllers, module class)
$this->trans('Enable module', [], 'Modules.Ppvenipak.Admin');
$this->trans('API Key', [], 'Modules.Ppvenipak.Admin');
$this->trans('Test Connection', [], 'Modules.Ppvenipak.Admin');
$this->trans('Successfully connected to Venipak API', [], 'Modules.Ppvenipak.Admin');
$this->trans('Connection failed: %error%', ['%error%' => $message], 'Modules.Ppvenipak.Admin');
$this->trans('Select pickup point', [], 'Modules.Ppvenipak.Shop');
$this->trans('Estimated delivery', [], 'Modules.Ppvenipak.Shop');
$this->trans('Track shipment', [], 'Modules.Ppvenipak.Shop');

// In Twig templates
{{ 'Save'|trans({}, 'Admin.Actions') }}
{{ 'Free shipping above'|trans({}, 'Modules.Ppvenipak.Admin') }}

// In Smarty templates (front hooks)
{l s='Estimated delivery' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}
```

---

## 10. Quality & Compliance Standards

### Code Quality
- `declare(strict_types=1)` in **ALL** PHP files
- PHPStan level 6 minimum
- PSR-12 coding standard
- All services defined in `config/services.yml` (split: `config/admin/services.yml`, `config/front/services.yml`)
- Controllers use `#[Autowire]` PHP attributes for dependency injection
- Controller extends `PrestaShopAdminController` (NOT deprecated `FrameworkBundleAdminController`)
- Route names follow `ps_{modulename}_{action}` convention
- Install/uninstall logic delegated to `src/Module/Installer.php` and `src/Module/Uninstaller.php`
- `index.php` security files in every directory (except `src/` — Symfony scans it)
- Database safety: use `pSQL()`, `bqSQL()`, escape all input
- Conditional vendor autoload: `if (file_exists(__DIR__ . '/vendor/autoload.php'))`

### Testing
- Unit tests for shipping cost calculation
- Integration tests for API client (with mocked HTTP responses)
- PHPUnit 10+

### GitHub Repository Standards
- `README.md` — installation, configuration, screenshots
- `LICENSE` — Academic Free License 3.0 (AFL-3.0)
- `CHANGELOG.md` — keep a changelog format
- `.github/workflows/` — CI pipeline (PHPStan, tests, PS9 compatibility check)
- GitHub topics: `prestashop`, `prestashop-9`, `prestashop-module`, `shipping`, `carrier`, `{carrier-name}`

### Git Commit Rules
- **NO references to AI tools** in commit messages — no "Claude", "Claude Code", "Anthropic", "Co-Authored-By: Claude", "AI-generated", or similar
- **NO references to AI tools** in code comments, docblocks, or README files
- Commits must read as if written by the PrestaPro team — clean, professional, human

### Compatibility
- **PrestaShop:** 9.0.0 — 9.99.99
- **PHP:** 8.1, 8.2, 8.3, 8.4
- **Symfony:** 6.4 (bundled with PS9)
- **PS 9.1 Shipments API:** Ready (behind feature flag support)

---

## 11. README.md Template — Standard Across All Modules

Every module repository must include a `README.md` following this structure:

```markdown
# PrestaPro — {Carrier Name}

> Free PrestaShop 9 shipping module for {Carrier Name} integration.
> Built by [PrestaPro](https://prestapro.lt)

![PrestaShop 9](https://img.shields.io/badge/PrestaShop-9.x-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)
![License](https://img.shields.io/badge/License-AFL--3.0-green)

## Screenshots

<!-- Admin config page, checkout carrier selection, pickup map, order detail panel -->
![Admin Configuration](docs/screenshots/admin-config.png)
![Checkout](docs/screenshots/checkout.png)
![Pickup Selector](docs/screenshots/pickup-selector.png)
![Order Panel](docs/screenshots/order-panel.png)

## Features

- {Carrier}-specific feature list
- Real-time shipping rates via {Carrier} API
- Parcel locker / pickup point selector with map (if applicable)
- Automatic label generation (PDF A4 / A6 / ZPL)
- Shipment tracking with automatic order status updates
- Multi-language support (EN, LT, LV, ET)
- PrestaShop 9.1 Shipments architecture ready

## Requirements

| Requirement   | Version          |
|---------------|------------------|
| PrestaShop    | 9.0.0+           |
| PHP           | 8.1, 8.2, 8.3, 8.4 |
| {Carrier} API | Account required |

## Installation

### Via Composer (recommended)
\```bash
composer require prestapro/pp-{carrier}
\```

### Manual
1. Download the latest release from [Releases](../../releases)
2. Upload `pp{carrier}/` folder to `/modules/` directory
3. Go to **Back Office → Modules → Module Manager**
4. Search for "PrestaPro" and click **Install**

## Configuration

1. Navigate to **Modules → PrestaPro — {Carrier Name} → Configure**
2. Enter your {Carrier} API credentials in the **API** tab
3. Click **Test Connection** to verify
4. Enable desired shipping methods in the **Shipping** tab
5. Save configuration

## API Credentials

<!-- Carrier-specific instructions on where to get API keys -->
To obtain API credentials:
1. Register at [{Carrier} developer portal]({url})
2. Create an API key for your account
3. Copy the API Key and Secret into the module configuration

## Shipping Methods

| Method | Description |
|--------|-------------|
| {method_1} | {description} |
| {method_2} | {description} |

## Support

- Documentation: [prestapro.lt/modules/pp{carrier}](https://prestapro.lt/modules/pp{carrier})
- Issues: [GitHub Issues](../../issues)
- Email: modules@prestapro.lt

## Other PrestaPro Modules

| Module | Carrier | Link |
|--------|---------|------|
| ppvenipak | Venipak | [GitHub](https://github.com/PrestaProLT/pp-venipak) |
| ppomniva | Omniva | [GitHub](https://github.com/PrestaProLT/pp-omniva) |
| ppdpd | DPD | [GitHub](https://github.com/PrestaProLT/pp-dpd) |

## Contributing

Pull requests are welcome. For major changes, please open an issue first.

## License

[Academic Free License 3.0 (AFL-3.0)](LICENSE)

---

**PrestaPro** · [prestapro.lt](https://prestapro.lt) · [GitHub](https://github.com/PrestaProLT)
```

### README Rules

- **Screenshots are mandatory** — no module ships without at least 3 screenshots (admin config, checkout, order panel)
- **Badges** — always include PS version, PHP version, and license badges at the top
- **Cross-promotion** — always list other PrestaPro modules in the "Other PrestaPro Modules" table
- **Store screenshots** in `docs/screenshots/` directory within each repo
- **Keep updated** — version numbers and feature lists must match the actual release

---

## 12. Checklist — New Carrier Module

Before releasing any new PrestaPro carrier module, verify:

### Architecture & Naming
- [ ] Extends `AbstractPPCarrier` base class
- [ ] Uses shared `prestapro/pp-common` package
- [ ] Module name follows `pp{carrier}` convention
- [ ] Display name follows `PrestaPro — {Carrier Name}` format
- [ ] Namespace: `PrestaShop\Module\PP{Carrier}\`
- [ ] composer.json with `type: prestashop-module` and `prepend-autoloader: false`

### Migration & Legacy Adoption
- [ ] `LEGACY_MODULES` list defined with all known legacy/third-party modules
- [ ] Legacy config keys copied (never moved) to PP config keys
- [ ] New carrier created with same `id_reference` as legacy (carrier adoption)
- [ ] `pp_{carrier}_legacy_map` table built for old order lookups
- [ ] Core order tables (`ps_orders`, `ps_order_carrier`) are NEVER modified
- [ ] Legacy carriers disabled (active=0) but NOT deleted
- [ ] Module can service old orders via legacy map (tracking, labels, status)
- [ ] Uninstall re-activates legacy carriers automatically
- [ ] Tracking numbers and labels copied (not moved)
- [ ] Pickup point selections copied
- [ ] Migration is transaction-safe with rollback on failure
- [ ] Dry-run / preview mode available
- [ ] Migration log stored in `pp_{carrier}_migration_log`
- [ ] Migration banner shown in admin UI when legacy module detected

### PS9 Compliance
- [ ] `declare(strict_types=1)` in ALL PHP files
- [ ] `isUsingNewTranslationSystem()` returns `true`
- [ ] `$this->hooks = [...]` array in constructor
- [ ] `$this->tabs = [...]` with `route_name` for tab registration
- [ ] `getContent()` redirects to Symfony route
- [ ] Controllers use `#[Autowire]` for dependency injection
- [ ] Route names follow `ps_{modulename}_{action}` pattern
- [ ] Install/uninstall delegated to `src/Module/Installer.php` / `Uninstaller.php`
- [ ] Conditional vendor autoload in main module file
- [ ] `index.php` security files in all directories (except `src/`)
- [ ] Services split: `config/admin/services.yml` + `config/front/services.yml`
- [ ] Translations use `$this->trans()` with `Modules.Pp{carrier}.Admin|Shop` domains
- [ ] All translation strings are English literals (never variables)

### Multilanguage Carrier Names
- [ ] Each shipping method has TranslatableType name field in General tab
- [ ] Multilang names stored per `id_lang` in module Configuration
- [ ] Front office displays correct language name during checkout
- [ ] Falls back to `ps_carrier.name` if no translation set

### UI & Branding
- [ ] Admin config page has all 4 standard tabs
- [ ] Branded header with PrestaPro logo, version, links
- [ ] Connection status bar with Test Connection button
- [ ] Branded footer with PrestaPro identity
- [ ] Shared CSS variables used (no hardcoded colors)
- [ ] Pickup point selector uses shared `PPPickupSelector` component
- [ ] Order detail panel follows standard layout

### Quality & Release
- [ ] Translations for EN + LT minimum
- [ ] PHPStan level 6 passes
- [ ] PSR-12 coding standard
- [ ] Unit tests for shipping calculation
- [ ] Database queries use `pSQL()` / `bqSQL()`
- [ ] README with screenshots and install guide
- [ ] AFL-3.0 license
- [ ] GitHub CI pipeline configured
- [ ] Tested on PS 9.0.x
- [ ] PS 9.1 Shipments feature flag tested
