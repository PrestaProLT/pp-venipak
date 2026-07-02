# PrestaPro — Venipak for PrestaShop 9

**Venipak courier and pickup‑point shipping module for PrestaShop 9.** A complete
carrier integration that adds Venipak home/office courier delivery and Venipak
pickup points (parcel lockers and parcel shops) to your PrestaShop 9 checkout,
with an interactive terminal map, cash‑on‑delivery support, shipment label
generation, manifests and full back‑office management.

Built for **PrestaShop 9.0+** on the shared `prestapro/pp-common` carrier base.

---

## Features

### Carriers
- **Venipak Courier** — door‑to‑door home and office delivery.
- **Venipak Pickup Point** — parcel lockers (*paštomatai*) and manned parcel
  shops.
- Automatic carrier creation, zone/group/price‑range assignment and tracking
  URL wiring on install.

### Checkout (front office)
- **Interactive pickup‑point selector** with an OpenStreetMap/Leaflet map,
  searchable terminal list and marker pop‑ups.
- **Find nearest** — geocodes the customer's postcode and lists the closest
  terminals; the nearest is pre‑selected automatically.
- **COD availability badges** and locker‑vs‑shop type badges per terminal, plus
  terminal working hours.
- **Courier extra fields** — door code, cabinet number, warehouse number,
  preferred delivery time window and "call before delivery" (each optional and
  configurable).
- **Theme‑agnostic** — verified on the default Classic theme, Hummingbird and
  any theme derived from them; no theme‑specific templates required.

### Cash on delivery (COD)
- Captures the COD amount on the order.
- Per‑terminal COD support: automatically hides COD payment methods when the
  chosen pickup terminal does not accept cash on delivery (server‑side, cannot
  be bypassed with JavaScript).

### Terminals
- Syncs the Venipak terminal catalogue via the API and caches it locally.
- Filters terminals by cart weight and product dimensions so customers only see
  terminals that can accept their parcel.

### Shipments & documents
- Shipment creation with Venipak XML builders and automatic pack‑number
  generation.
- **Label generation** in the configured format.
- **Manifests** with sequential manifest numbering.
- **Return service** / courier invitation support.

### Back office
- Dedicated admin area with:
  - **Dashboard** and connection status.
  - **Orders** management and a per‑order shipment panel on the PrestaShop order
    page.
  - **Manifests** generation and history.
  - **Warehouses** — multiple sender addresses, per‑shop default (multistore
    aware).
  - **Terminals** browser.
  - **Carriers** and **COD** configuration.
  - **Order‑state mapping** to custom PrestaShop statuses (at sender, in transit,
    out for delivery, at terminal/pickup point, delivered, picked up, ready,
    error).
  - **Configuration** — API credentials, live/test mode, label format, return
    service, log retention.
  - **API logs** with automatic retention.
- Built‑in **connection self‑test / diagnostics** and API error mapping.
- **Migration** helper for moving from a legacy Venipak setup.

### Operations
- **Cron endpoint** (token‑protected) for terminal sync and housekeeping.
- **Multilingual** — English, Lithuanian, Latvian and Estonian.
- **Multistore** aware.

---

## Requirements

- PrestaShop **9.0.0+**
- PHP **8.1+**
- A Venipak API account (username / password) for live shipping operations.

## Installation

```bash
# from the module directory
composer install
```

Then zip the folder (or copy it into `modules/`) and install it from the
PrestaShop back office under **Modules → Module Manager**, or via CLI:

```bash
php bin/console prestashop:module install ppvenipak
```

The module registers its carriers and admin tab automatically on install.

## Configuration

1. Open **PrestaPro — Venipak** in the back office.
2. Enter your Venipak **API credentials** and run **Test connection**.
3. Configure the **sender address / warehouse**.
4. Enable the courier extra fields you need and set delivery‑time windows.
5. Set the **cron token** and schedule the cron URL to keep terminals in sync.

## License

[AFL‑3.0](https://opensource.org/licenses/AFL-3.0) — PrestaPro, https://prestapro.lt

---

*Keywords: PrestaShop 9, PrestaShop carrier module, PrestaShop shipping module,
Venipak, pickup points, parcel lockers, parcel terminals, parcel machine,
cash on delivery, COD, courier delivery, Baltic shipping, Lithuania, Latvia,
Estonia.*
