# Changelog

All notable changes to **PrestaPro — Venipak** are documented here.
This project adheres to [Semantic Versioning](https://semver.org/) and the
[Keep a Changelog](https://keepachangelog.com/) format.

## [1.0.13] — 2026-07-03

### Fixed
- Pickup-point map: dragging/panning the map no longer snaps it back to the
  initial view. The map is now framed only when the picker is first shown (and
  on "Change"/"Find nearest"), not on every click inside the carrier row, so
  panning is preserved.

## [1.0.12] — 2026-07-02

### Added
- **Carrier logos** — newly created Venipak carriers now ship with a logo
  instead of PrestaShop's blank placeholder in the carrier list and at checkout.
  Existing installs get the logo back-filled on upgrade.

### Changed
- **Redesigned the legacy-migration UI** on the configuration page. The
  "Preview" action no longer opens a raw JSON page; what will be imported
  (orders, warehouses, manifests, settings, carriers) is shown as a clear
  summary, with dry-run / import actions and a readable result panel.

## [1.0.11] — 2026-07-02

### Fixed
- Opening the module (**Configure**) right after an install or upgrade could
  throw `RouteNotFoundException` for `ps_ppvenipak_dashboard` because the
  module's admin routes were not yet compiled into the Symfony router. The
  Symfony cache is now cleared on install and upgrade, and `getContent()` fails
  gracefully (clears the cache and returns to the module list) instead of
  showing an error page.

## [1.0.10] — 2026-07-02

### Added
- **Disable individual pickup points** from the Terminals admin screen. Each
  point has an Enable/Disable toggle and a Status column; disabled points are
  hidden from the checkout terminal list but stay visible and manageable in the
  back office. Adds an `enabled` column to the terminal cache.
- Bundled `vendor/` so the module installs without running `composer install`.

### Changed
- **Terminal sync is now incremental.** `syncCountry()` upserts terminals
  (`INSERT … ON DUPLICATE KEY UPDATE`) and only deletes points the API no longer
  returns, instead of wiping and re-inserting every row on each sync — faster,
  keeps row IDs stable, and preserves the merchant's enabled/disabled choices.
- **Checkout picker is theme-agnostic.** Works on the default Classic theme,
  Hummingbird and any theme derived from them:
  - Courier extra-field labels no longer inherit the theme's right-aligned
    checkout labels.
  - Pickup-selector visibility falls back to `.delivery-option` /
    `.carrier-extra-content` for themes without the `js-` prefixed wrappers.
  - The pickup map re-frames its markers once visible, fixing pins that could
    render off-screen when the map was built inside a collapsed carrier row.

## [1.0.9]

- Stronger pack-number self-heal (reacts to Venipak codes 42 and 45).

## [1.0.8]

- Self-heal Venipak code 45 ("pack number reserved").

## [1.0.7]

- Key the pack-number serial counter per API ID.

## [1.0.6]

- Add `attempt_count` + `previous_attempts` columns so a single order can be
  re-shipped with a fresh pack number.

## [1.0.5]

- Register `actionPresentPaymentOptions` — server-side removal of cash-on-delivery
  payment options when the selected pickup terminal does not accept COD.

## [1.0.4]

- Register `actionObjectOrderUpdateAfter` so the module keeps its order row in
  sync when a merchant changes the carrier on an existing order.

## [1.0.3]

- Move the admin order panel from `displayAdminOrderSide` (narrow column) to
  `displayAdminOrderMainBottom` (full width).

## [1.0.2]

- Register `actionFrontControllerSetMedia` so the pickup-point selector loads
  its JS/CSS on the checkout page.

## [1.0.1]

- Introduce the Warehouses CRUD page, replacing the legacy "Sender address"
  configuration block.

## [1.0.0]

- Initial release: Venipak courier and pickup-point carriers, checkout terminal
  selector with map, cash-on-delivery, shipment labels, manifests, terminal
  sync, order-state mapping and localized carrier names.
