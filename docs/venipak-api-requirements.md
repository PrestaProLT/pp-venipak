# Venipak API Integration Requirements

> Purpose: API specification for building a PS9-native Venipak carrier module.

---

## Table of Contents

1. [API Overview](#1-api-overview)
2. [Authentication](#2-authentication)
3. [Endpoints Reference](#3-endpoints-reference)
4. [Data Structures](#4-data-structures)
5. [Complete Flows](#5-complete-flows)
6. [Number Generation](#6-number-generation)
7. [Validation Rules](#7-validation-rules)
8. [Required Configuration](#8-required-configuration)
9. [Required Data Storage](#9-required-data-storage)
10. [API Gotchas](#10-api-gotchas)

---

## 1. API Overview

### Base URLs

| Environment | URL |
|---|---|
| **Production** | `https://go.venipak.lt/` |
| **Sandbox/Test** | `https://venipak.uat.megodata.com/` |

### Coverage

Baltic countries: **Lithuania (LT)**, **Latvia (LV)**, **Estonia (EE)**, **Poland (PL)**

### Carrier Types

| Type | Description |
|---|---|
| **Courier** | Door-to-door delivery |
| **Pickup** | Delivery to pickup point or parcel locker |

### Custom HTTP Headers (sent with every request)

```
client-software-name: Prestashop
client-software-version: {PS_VERSION}
client-module-version: {MODULE_VERSION}
```

---

## 2. Authentication

All authenticated requests use **POST body parameters** (not headers, not Basic Auth):

| Parameter | Description |
|---|---|
| `user` | API Login (assigned upon contract signing) |
| `pass` | API Password (assigned upon contract signing) |

A third credential, **API ID** (numeric, e.g. `07789`), is used for generating tracking/manifest numbers but is NOT sent as authentication — it's embedded in the generated numbers.

**Public endpoints (no auth):** Pickup points, Route/services, Tracking GET.

---

## 3. Endpoints Reference

### 3.1 Submit Shipment/Manifest — `POST import/send.php`

The core endpoint for creating shipments and requesting courier pickup.

**POST Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `user` | string | API username |
| `pass` | string | API password |
| `xml_text` | string | XML document (see structures below) |

**Response:** XML text.

- **Success:** `<text>V07789E0000001</text>` (single) or multiple `<text>` elements
- **Error:** `<error pack="V07789E0000001">Pack number V07789E0000001 is in use already</error>`
- **Multiple errors:** Multiple `<error>` elements

**Error code 42** = duplicate pack number. `[source: Venipak PrestaShop PDF]`

The entire manifest is **atomic** — if one shipment fails, all fail.

---

### 3.2 Get Pickup Points — `GET ws/get_pickup_points`

**No authentication required.**

**Query Parameters:**

| Parameter | Required | Description |
|---|---|---|
| `country` | Yes | ISO country code: `LT`, `LV`, `EE`, `PL` |
| `postcode` | No | Digits only (non-numeric chars stripped) |
| `city` | No | City name |
| `pickup_enabled` | No | `1` = only show points accepting outgoing parcels |

**Response:** JSON array of terminal objects:

```json
{
  "id": 2630,
  "name": "Venipak locker, Express Market",
  "code": "300906055",
  "address": "Pilenu g. 1",
  "city": "Akademija",
  "zip": "53347",
  "country": "LT",
  "terminal": "02",
  "display_name": "Akademijos Express Market Venipak pasomatas",
  "description": null,
  "working_hours": [
    {
      "from_h": "0", "from_m": "0",
      "to_h": "23", "to_m": "59",
      "dayOfWeek": 1,
      "openTime": "00:00", "closeTime": "23:59"
    }
  ],
  "contact_t": "",
  "lat": "54.8960710",
  "lng": "23.8233773",
  "pick_up_enabled": 0,
  "cod_enabled": 0,
  "ldg_enabled": 0,
  "size_limit": 30,
  "max_height": 0.41,
  "max_width": 0.395,
  "max_length": 0.61,
  "type": 1
}
```

**Field notes:**
- `display_name` — use for checkout display `[source: venipak.com API Changes, April 2022]`
- `name` — used for labels and SMS notifications
- `type`: `1` = locker, `3` = manned pickup point
- `size_limit` — max weight in kg (10, 30, 45; 0 = no limit)
- `max_height/width/length` — in **meters**
- `cod_enabled` — `1` if cash-on-delivery supported at this point

**Recommended caching:** Fetch once every 24 hours per country.

---

### 3.3 Get Route/Services — `GET ws/get_route`

**No authentication required.**

**Query Parameters:**

| Parameter | Description |
|---|---|
| `country` | ISO country code |
| `code` | Postal code |
| `type` | `route`, `zone`, or `all` (default: `all`) |
| `view` | `csv` or `json` (default: `json`) |

**Response:**

```json
{
  "route": "00000",
  "zone": 99,
  "service1": 0,
  "service2": 0,
  "service3": 0,
  "service4": 0,
  "service5": 0,
  "service6": 0,
  "packOrderLimit": "15:00",
  "palletOrderLimit": "12:00",
  "orderPickupTime": []
}
```

`service1 === "1"` indicates "four hands" delivery is available.

---

### 3.4 Print Label — `POST ws/print_label`

**POST Parameters:**

| Parameter | Description |
|---|---|
| `user` | API username |
| `pass` | API password |
| `format` | `a4` (4 labels per A4) or `other` (100x150mm thermal) |
| `carrier` | `venipak`, `global`, or `all` |
| `pack_no[0]` | First tracking number |
| `pack_no[1]` | Second tracking number (etc.) |

**Response:** Raw binary PDF.

---

### 3.5 Get Label Link — `POST ws/print_link`

**POST Parameters:**

| Parameter | Description |
|---|---|
| `user` | API username |
| `pass` | API password |
| `pack_no[0]` | First tracking number |
| `pack_no[1]` | Second tracking number (etc.) |

**Response:** JSON with download URL.

---

### 3.6 Print Manifest — `POST ws/print_list`

**POST Parameters:**

| Parameter | Description |
|---|---|
| `user` | API username |
| `pass` | API password |
| `code` | Manifest number |

**Response:** Raw binary PDF (cargo declaration document).

---

### 3.7 Tracking — `GET ws/tracking`

**No authentication required.**

**IMPORTANT:** Always query the **production** endpoint (`go.venipak.lt`) for tracking — the sandbox has no tracking data.

**Query Parameters:**

| Parameter | Description |
|---|---|
| `code` | Tracking number or manifest number |
| `type` | `1` = single package, `2` = all packages in manifest, `5` = last status only, `7` = track shipment |
| `output` | `csv` |

**Response:** CSV with columns: `pack_no, shipment_no, date, status, terminal`. First row is header.

**Known statuses:** `new`, `registered`, `at terminal`, `out for delivery`, `delivered`

---

### 3.8 Register Tracking Code — `POST ws/tracking`

**POST Parameters:**

| Parameter | Description |
|---|---|
| `user` | API username |
| `pass` | API password |
| `code` | Package code |
| `type` | `3` = document code, `4` = bill, `13` = order |

This endpoint follows normal live/test mode (unlike GET tracking).

---

### Endpoint Summary Table

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `import/send.php` | POST | Yes | Submit shipment XML / courier invitation |
| `ws/get_pickup_points` | GET | No | List pickup points/lockers |
| `ws/get_route` | GET | No | Get delivery routes/zones/services |
| `ws/print_label` | POST | Yes | Download label PDF |
| `ws/print_link` | POST | Yes | Get label download URL |
| `ws/print_list` | POST | Yes | Download manifest PDF |
| `ws/tracking` | GET | No | Query tracking status |
| `ws/tracking` | POST | Yes | Register tracking code |

---

## 4. Data Structures

### 4.1 Shipment XML (description type="1")

```xml
<?xml version="1.0" encoding="UTF-8"?>
<description type="1">
  <manifest title="{manifest_number}" name="{shop_name}">

    <shipment>
      <!-- OPTIONAL: Override sender address (if different from contract default) -->
      <consignor>
        <name>Company Name</name>
        <company_code>123456789</company_code>
        <country>LT</country>
        <city>Vilnius</city>
        <address>Street 1</address>
        <post_code>01001</post_code>
        <contact_person>John</contact_person>
        <contact_tel>+37060000000</contact_tel>
        <contact_email>info@example.com</contact_email>
      </consignor>

      <!-- OPTIONAL: Return address (if different from consignor) -->
      <return_consignee>
        <!-- Same fields as consignor -->
      </return_consignee>

      <!-- REQUIRED: Recipient -->
      <consignee>
        <name>Recipient Name</name>
        <company_code></company_code>          <!-- optional -->
        <country>LT</country>
        <city>Kaunas</city>
        <address>Recipient St. 5</address>
        <post_code>44001</post_code>
        <contact_person>Jane</contact_person>
        <contact_tel>+37061111111</contact_tel>
        <contact_email>jane@example.com</contact_email>
      </consignee>

      <!-- Delivery attributes -->
      <attribute>
        <delivery_type>nwd</delivery_type>
        <return_doc>0</return_doc>              <!-- 0 or 1 -->
        <comment_door_code>1234</comment_door_code>    <!-- optional -->
        <comment_office_no>305</comment_office_no>     <!-- optional -->
        <comment_warehous_no>A1</comment_warehous_no>  <!-- optional -->
        <comment_call>1</comment_call>                  <!-- optional: call before delivery -->
        <cod>25.50</cod>                        <!-- COD amount, optional -->
        <cod_type>EUR</cod_type>                <!-- Currency ISO, optional -->
        <return_service>14</return_service>     <!-- Return days, optional -->
      </attribute>

      <!-- One or more packages -->
      <pack>
        <pack_no>V07789E0000001</pack_no>
        <doc_no>ORDER-123</doc_no>              <!-- optional document number -->
        <weight>1.5</weight>                    <!-- kg -->
        <volume>0.003</volume>                  <!-- m3 -->
      </pack>
      <!-- Additional <pack> elements for multi-parcel shipments -->

    </shipment>
    <!-- Additional <shipment> elements in same manifest -->

  </manifest>
</description>
```

### 4.2 Pickup Point Shipment (consignee differences)

For pickup/terminal delivery, the `<consignee>` uses terminal data instead of customer address:

```xml
<consignee>
  <name>{terminal_name}</name>
  <company_code>{terminal_code}</company_code>
  <country>{terminal_country}</country>
  <city>{terminal_city}</city>
  <address>{terminal_address}</address>
  <post_code>{terminal_zip}</post_code>
  <contact_person>{customer_firstname} {customer_lastname}</contact_person>
  <contact_tel>{customer_phone}</contact_tel>
  <contact_email>{customer_email}</contact_email>
</consignee>
```

### 4.3 Courier Invitation XML (description type="3")

```xml
<?xml version="1.0" encoding="UTF-8"?>
<description type="3">
  <sender>
    <name>Company Name</name>
    <company_code>123456</company_code>
    <country>LT</country>
    <city>Vilnius</city>
    <address>Street 1</address>
    <post_code>01001</post_code>
    <contact_person>John</contact_person>
    <contact_tel>+37060000000</contact_tel>
    <contact_email>info@example.com</contact_email>
  </sender>
  <weight>10</weight>           <!-- Total kg of all orders in manifest -->
  <volume>0.05</volume>         <!-- Total m3 -->
  <date_y>2026</date_y>
  <date_m>03</date_m>
  <date_d>17</date_d>
  <hour_from>08</hour_from>
  <min_from>00</min_from>
  <hour_to>14</hour_to>
  <min_to>00</min_to>
  <comment>Please call before arrival</comment>  <!-- max 50 chars -->
</description>
```

### 4.4 Delivery Type Values

| Value | Description |
|---|---|
| `nwd` | Next working day (anytime) — **default** |
| `nwd10` | Next working day until 10:00 |
| `nwd12` | Next working day until 12:00 |
| `nwd8_14` | Next working day 8:00–14:00 |
| `nwd14_17` | Next working day 14:00–17:00 |
| `nwd18_22` | Next working day 18:00–22:00 |

Each delivery time option should be individually configurable (enable/disable).

---

## 5. Complete Flows

### 5.1 Checkout: Pickup Point Selection

```
1. Terminal list cached locally (recommended: refresh every 24h per country)
   GET ws/get_pickup_points?country={CC}

2. Customer selects "Pickup" carrier at checkout
   → Load terminals for customer's delivery country
   → Filter by:
     a) Weight: terminal.size_limit >= cart total weight (0 = no limit)
     b) Dimensions: check all products fit in terminal.max_height/width/length
        - Test stacking combinations × rotation permutations
        - Dimensions converted from cm to meters (÷100)
   → Render map with filtered terminals

3. Customer selects terminal
   → Save terminal_id + terminal_info to DB for this cart

4. Checkout validation (delivery step)
   → Verify terminal_id is set for this cart
   → Block checkout if missing
```

### 5.2 Checkout: Courier Extra Fields

```
1. Customer selects "Courier" carrier at checkout
   → Display optional fields (each configurable on/off):
     - Door code (max 10 chars)
     - Cabinet number (max 10 chars)
     - Warehouse number (max 10 chars)
     - Delivery time dropdown (nwd, nwd10, nwd12, etc.)
     - Call before delivery checkbox

2. Validation on delivery step:
   → Field length checks (max 10 chars)
   → Delivery time must be in allowed values list
   → Save to DB
```

### 5.3 Order Creation

```
1. Order placed → hook fires (actionValidateOrder)
2. Check if carrier is Venipak (courier or pickup)
3. Get order weight (convert g→kg if PS_WEIGHT_UNIT == 'g')
4. Detect COD: check payment module name
5. Save: order_id, warehouse_id, order_weight, cod_amount, is_cod
```

### 5.4 Label Generation (Admin: Send to Venipak)

This is the most complex flow — triggered from admin for one or multiple orders.

```
1. GROUP orders by warehouse_id

2. For each warehouse group:
   a) Find or create manifest:
      - Query DB for today's open manifest for this warehouse
      - If found → reuse (add orders to existing manifest)
      - Otherwise → generate new manifest number

   b) For each order in group:
      - Load Order, Address, Carrier, Customer
      - Verify: carrier is Venipak, country is allowed (LT/LV/EE/PL)

      FOR COURIER:
        - consignee.name = address firstname+lastname (or company)
        - consignee.company_code = address.dni / vat_number / siret
        - consignee.address = address1 + " " + address2
        - consignee.phone = mobile (preferred) or phone
        - Include extra fields (door_code, cabinet_number, etc.)

      FOR PICKUP:
        - Refresh terminal data from API (avoid stale cache)
        - consignee = terminal data (name, code, country, city, address, zip)
        - contact_person = customer name from address

      COD (if is_cod):
        - <cod>{cod_amount}</cod>
        - <cod_type>{currency_iso_code}</cod_type>

      PACKAGES (loop 1..packages count):
        - Increment global pack counter
        - Generate pack_no: V{api_id}E{7-digit serial}
        - Weight per pack = order_weight / packages
        - Volume per pack = sum(product width×height×depth) / packages
          - Dimension unit conversion: cm→m3 (÷1000000), mm→m3 (÷1000000000)

   c) Build XML with all shipments under one <manifest>
   d) Validate XML before sending
   e) POST to import/send.php

3. Process response:
   SUCCESS:
     - API returns tracking numbers
     - Map tracking numbers to orders (returned in submission order)
     - For each order:
       → Save tracking numbers
       → Set manifest_id
       → Set status = 'registered'
       → Save first tracking number to order_carrier.tracking_number
       → Change order status to "Shipment ready"
     - Save/update manifest record
     - Update manifest counter

   ERROR:
     - Errors identify which shipment failed (pack attribute)
     - ALL orders in manifest fail (atomic operation)
     - Set status = 'error' with error message
     - Change order status to "Shipment error"
```

### 5.5 Label Printing

```
1. Get tracking numbers for order from DB
2. POST ws/print_label with pack_no[] array
   - format: 'a4' or 'other'
   - carrier: 'all'
3. Receive raw PDF binary
4. Cache and stream to browser
```

### 5.6 Manifest Close & Print

```
1. Admin clicks "Print and close Manifest"
2. Set manifest.closed = 1 in DB
3. POST ws/print_list with manifest number
4. Receive raw PDF binary (cargo declaration)
5. Stream to browser
```

### 5.7 Courier Call/Invitation

```
1. Admin selects manifest, enters pickup time window
   - Must be same day
   - Minimum 2-hour gap between from/to
   - Minutes in 15-minute increments

2. Build courier invitation data:
   - Sender: from warehouse
   - Weight: SUM of all order weights in manifest
   - Volume: calculated from all order products (cm→m3)
   - Date: year/month/day
   - Time: hour_from/min_from/hour_to/min_to
   - Comment: max 50 chars

3. Build XML with description type="3"
4. POST to import/send.php
5. On success: update manifest with arrival times, warehouse, weight, comment
```

### 5.8 Tracking Status Sync

```
SINGLE ORDER:
  1. Get tracking numbers for order
  2. For each number: GET ws/tracking?code={num}&type=1&output=csv
  3. Parse CSV rows: pack_no, shipment_no, date, status, terminal

BULK SYNC:
  1. Query all orders WHERE status != 'delivered' and has tracking numbers
  2. For each tracking number: GET tracking CSV
  3. Extract last row's status
  4. Update order status in DB
```

---

## 6. Number Generation

### 6.1 Tracking Number (Pack Number)

**Format:** `V{API_ID}E{7-DIGIT-SERIAL}`

**Example:** API ID = `07789`, serial = `42` → `V07789E0000042`

**Counter:** Global counter, incremented by 1 per package. If order has 3 packages, counter increments 3 times.

**Multishop:** Counter must be stored at global scope (not per-shop) to avoid serial number collisions.

### 6.2 Manifest Number

**Format:** `{API_ID}{YYMMDD}{3-DIGIT-SERIAL}`

**Example:** API ID = `07789`, date = 2026-03-16, serial = `5` → `07789260316005`

**Counter:** Stored as JSON: `{"counter": N, "date": "YYMMDD"}`
- If current date differs from stored date → counter resets to 1
- Otherwise → counter increments

**Manifest reuse:** Before creating new manifest, check DB for today's open manifest for same warehouse. If found and API ID prefix matches → reuse it.

---

## 7. Validation Rules

### Postcodes

| Country | Format | Processing |
|---|---|---|
| LT | 5 digits | Keep as-is |
| EE | 5 digits | Keep as-is |
| PL | 5 digits | Strip non-numeric (removes dash: `00-001` → `00001`) |
| LV | 4 digits | Strip non-numeric (`LV-1001` → `1001`) |

### Terminal Filtering

**Weight check:** `terminal.size_limit >= cart total weight` (size_limit 0 = no limit)

**Dimensions check:**
- Convert product dimensions from cm to meters (÷100)
- Test stacking combinations of all products
- For each combination, test rotation permutations against `max_height/max_width/max_length`
- Terminal excluded if no valid arrangement found

### Courier Call Validation

- Pickup window must be **same day**
- Minimum **2-hour gap** between from and to times
- Minutes must be in **15-minute increments** (0, 15, 30, 45)
- Comment max **50 characters**

### Extra Fields Validation

- Door code: max **10 characters**
- Cabinet number: max **10 characters**
- Warehouse number: max **10 characters**
- Delivery time: must match one of enabled `nwd*` values

### XML Validation

- Validate XML via `DOMDocument::loadXML()` before sending
- Pack numbers must be **globally unique** — API error 42 if duplicate

### COD Detection

Identify COD orders by checking the payment module used (e.g. `ps_cashondelivery`).

---

## 8. Required Configuration

### API Credentials

| Setting | Description |
|---|---|
| API username | Login for authenticated endpoints |
| API password | Password for authenticated endpoints |
| API ID | Numeric ID for number generation (e.g. `07789`) |
| Live/Test mode | Toggle between production and sandbox |

### Shop/Sender Info

| Setting | Description |
|---|---|
| Sender name | Company name for consignor |
| Contact person | Contact name |
| Company code | Business registration number |
| Country, City, Address, Postcode | Sender address |
| Phone, Email | Contact details |
| Include sender in XML | Whether to override contract default address |

### Courier Checkout Options (each toggleable)

| Setting | Description |
|---|---|
| Door code field | Show/hide at checkout |
| Cabinet number field | Show/hide at checkout |
| Warehouse number field | Show/hide at checkout |
| Delivery time selection | Enable time window choice |
| Call before delivery | Enable call-before checkbox |
| Return service | Enable return service |
| Return days | Number of days (default 14) |
| Delivery time options | Enable/disable each `nwd*` value individually |

### Label Settings

| Setting | Description |
|---|---|
| Label format | `a4` (4 per page) or `100x150` (thermal) |

### Carrier Disable (optional)

| Setting | Description |
|---|---|
| Disable passphrase | If set, hide Venipak carriers when any product description contains this string |

---

## 9. Required Data Storage

### Orders Table

Track Venipak-specific data per order:

| Data | Type | Description |
|---|---|---|
| cart_id | int | Cart reference |
| order_id | int | Order reference |
| manifest_id | string | Venipak manifest number |
| order_weight | float | Weight in kg |
| cod_amount | float | COD amount |
| packages | int | Number of packages (default 1) |
| is_cod | boolean | COD flag |
| carrier_ref | int | Carrier reference ID |
| country_code | string(5) | Delivery country ISO |
| terminal_id | int | Selected pickup terminal ID |
| warehouse_id | int | Shipping warehouse ID |
| terminal_info | JSON | Terminal details: name, code, country, city, address, zip, cod_enabled |
| status | string | `new` / `registered` / `error` / tracking statuses |
| extra_fields | JSON | door_code, cabinet_number, warehouse_number, delivery_time, carrier_call, return_service, return_doc |
| tracking_numbers | JSON | Array of tracking numbers |
| error | text | Error messages |

### Warehouses Table

| Data | Type | Description |
|---|---|---|
| id | int PK | Warehouse ID |
| name | string(60) | Name |
| company_code | string(16) | Company registration code |
| contact | string(40) | Contact person |
| country_code | string(5) | Country ISO |
| city | string(50) | City |
| address | string(255) | Address |
| zip_code | string(10) | Postal code |
| phone | string(15) | Phone |
| shop_id | int | Shop ID |
| is_default | boolean | Default warehouse flag |

### Manifests Table

| Data | Type | Description |
|---|---|---|
| id | int PK | Internal ID |
| manifest_id | string(40) | Venipak manifest number |
| shop_id | int | Shop ID |
| warehouse_id | int | Warehouse ID |
| total_weight | float | Total weight |
| call_comment | string(255) | Courier call comment |
| arrival_from | datetime | Courier window start |
| arrival_to | datetime | Courier window end |
| closed | boolean | Whether manifest is closed |
| created_at | datetime | Creation date |

### Counters (Configuration)

| Data | Description |
|---|---|
| Pack counter | Global integer, increments per package |
| Manifest counter | JSON `{"counter": N, "date": "YYMMDD"}`, resets daily |

---

## 10. API Gotchas

- **No webhooks/callbacks** — tracking must be polled
- **Tracking always uses production endpoint** — sandbox has no tracking data
- **Manifest submission is atomic** — if one shipment fails, ALL fail. Consider submitting individually or implementing retry
- **Pack numbers are globally unique** — resubmitting causes error 42
- **XML must be valid** — validated server-side, validate locally first
- **Courier call time window** — minimum 2 hours, same day only
- **Terminal data goes stale** — refresh before label generation, not just at checkout
- **Label format mapping** — API accepts `a4` or `other` (not `100x150`; map accordingly)

---

## Documentation Sources

- [Venipak PrestaShop Module PDF (EN)](https://www.venipak.com/wp-content/uploads/Venipak_Prestashop_dokumentacija_v01.0.10_EN.pdf) — confirms error code 42, general flow
- [Venipak Magento Module PDF (EN)](https://www.venipak.com/wp-content/uploads/Venipak_dokumentacija_Magento_EN_v2.pdf) — general flow reference
- [Venipak API Changes](https://venipak.com/lv/en/products-and-services/services-for-e-commerce/venipak-api-changes/) — confirms `display_name` parameter (April 2022)
- [Venipak GitHub](https://github.com/venipak/Venipak-Prestashop-1.6-1.7.7-8) — API integration reference (v1.1.11 source code analysis)
