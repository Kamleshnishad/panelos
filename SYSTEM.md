# PanelOS — Complete System Reference

**PanelOS** is a multi-tenant manufacturing ERP for PUF / PIR / sandwich-panel factories in India.
It takes a factory from *enquiry → BOQ → quotation → order → production → QC → dispatch → invoice → payment*,
with inventory, procurement, GST compliance and financial reporting attached to that spine.

This document describes **how the system actually works today**, read from the code rather than from the
plan documents. It is written so that another agent can drive the whole system end to end — create a BOQ,
price it, run production, dispatch, invoice and collect — exactly as a real client would, and verify each step.

> Other docs in this repo (`01_BUSINESS_LOGIC.md`, `04_BOQ_MODULE.md`, `backend/ARCHITECTURE.md`, …)
> are *design intent* and lag the code in places.
> **When they disagree with this file, trust this file. When this file disagrees with the code, trust the code.**

---

## 0. How to use this document

| If you want to… | Read |
|---|---|
| Understand the shape of the system | §1 Architecture, §2 API conventions |
| Log in and get a token | §3 Authentication |
| Know who can do what | §4 RBAC |
| Know what must exist before you can sell anything | §6 Master data prerequisites |
| Follow the business end to end | §5 The spine, then §7–§19 flow by flow |
| Know what a status means and what it unlocks | §20 State machines |
| Reproduce a number the UI shows | §21 Calculation reference |
| Debug a rejected request | §22 Guard rails & error catalogue |
| Run a full smoke test | §23 End-to-end script |
| Know where the system is thin | §24 Sharp edges & known gaps |
| Find the code for a feature | §25 File map |

---

## 1. Architecture

### 1.1 Stack

| Layer | Technology | Location |
|---|---|---|
| Backend | Laravel 11 (PHP 8.2+), Sanctum tokens, Eloquent, DomPDF | `backend/` |
| Frontend (web) | Vue 3 `<script setup>` + Vite + axios. **No router, no Pinia, no UI library** | `frontend/` |
| Mobile | React Native / Expo, React Navigation, TanStack Query, Zustand | `mobile/` |
| Database | MySQL 8 (uses `REGEXP`, generated columns, `FIELD()` ordering, `lockForUpdate`) | — |
| PDF | `barryvdh/laravel-dompdf` rendering Blade views | `backend/resources/views/` |
| Payments | Stripe (customer invoices), Razorpay (SaaS subscription) | `PaymentGatewayService`, `RazorpayClient` |
| Messaging | Twilio SMS + WhatsApp, sent from a queued job | `SmsService`, `SendNotificationJob` |
| Deploy | Docker Compose (`docker-compose.prod.yml`), nginx in front of php-fpm | repo root |

### 1.2 Layering rule

```
HTTP route (routes/api.php)
  → Middleware: auth:sanctum → throttle → tenant.active → perm.module
    → Controller (app/Http/Controllers/Api/*)   validate + authorize + shape response
      → Service  (app/Services/*)               ALL business logic + transactions
        → Model  (app/Models/*)                 persistence + relations + tenant scope
```

**Controllers contain no business rules.** Every state transition, calculation and invariant lives in a
service. To understand a rule, read the service.

### 1.3 Frontend shape

`App.vue` picks one of four screens: `Landing` → `LoginScreen` → `SubscriptionGate` → `AppShell`.
`AppShell.vue` *is* the authenticated app: a sidebar of ~25 keys plus a `v-if` chain that mounts one
manager component at a time. There is **no URL routing** — navigation is the `active` ref, and deep links
are props (`go('orders', 42)` → `:open-id="42"`).

Each manager component calls one `frontend/src/services/*.js` module, which wraps the shared `api.js`
axios instance (`baseURL: '/api'`, bearer token from `localStorage`).

Two global interceptors drive session behaviour:

* **401** with a token present → clear storage, fire `auth:expired` → drop to login.
* **402 `SUBSCRIPTION_INACTIVE`** → fire `subscription:inactive` → show `SubscriptionGate`.

**Progress feedback.** Two global pieces, both mounted once in `UiHost.vue` (`TransferProgress.vue`):

* **Top bar** (`services/activity.js`) — appears when any request is still running after 300 ms. Installed
  on both bare `axios` and the shared `api` instance in `main.js`. Background calls opt out with
  `{ silent: true }` (notification polling, token refresh).
* **File cards** (`services/transfers.js`) — every PDF/CSV/XML download shows a card ("Preparing… 40%" →
  "Downloading 90%" → "Ready"). Before the first byte arrives the % is an *estimate* learned from earlier
  runs of that file kind; once bytes flow it follows the real download. Use `openPdfFile()` /
  `downloadFile()` — never `window.open` after an `await`: the new tab is opened immediately (while the click
  still counts as a gesture, browsers block pop-ups after ~5 s) and navigated when the file is ready.

---

## 2. API conventions

Everything is prefixed `/api`. All JSON uses one envelope (`App\Traits\ApiResponse`):

```jsonc
// success
{ "success": true, "data": { }, "message": "Quotation created",
  "meta": { "timestamp": "…", "version": "1.0" } }

// paginated
{ "success": true, "data": [ ],
  "meta": { "current_page": 1, "last_page": 4, "total": 73, "per_page": 20 } }

// error
{ "success": false, "message": "Rates are pending…", "error_code": "SEND_ERROR",
  "errors": { "field": ["…"] } }
```

| Code | HTTP | Meaning |
|---|---|---|
| `VALIDATION_ERROR` | 422 | Laravel validation. `message` is the **first field error**, not a generic string. |
| `UNAUTHENTICATED` | 401 | Missing or expired token. |
| `FORBIDDEN` | 403 | RBAC failure; `errors.permission` names the key you lack. |
| `SUBSCRIPTION_INACTIVE` | 402 | Tenant trial expired or deactivated. |
| `CREDIT_LIMIT_EXCEEDED` | 422 | Order would exceed the customer's credit limit; `data` holds the breakdown. |
| `NOT_FOUND` | 404 | Record missing **or owned by another tenant** (deliberately indistinguishable). |
| `SERVER_ERROR` | 500 | Unhandled; carries a `reference` id that matches the log line. |

Common list params: `page`, `per_page` (default 20), `search`, `status`, `from_date`, `to_date`,
`sort_by`, `sort_order`.

**Rate limits** — `login`: 10/min per IP **and** 5/min per email+IP · `verify-otp`: same ·
`register`: 2/min, 5/hour, 15/day per IP · every authenticated route: 240/min per user.

**Uploaded files** are served by `GET /api/file?path=<relative/path>` — never as a static URL. Both nginx
layers 404 any URI ending in an image extension before PHP sees it, so the path travels in the query
string (`PublicFileController` validates and streams it).

**Health** — `GET /api/health` returns `{status: OK|DEGRADED, database: OK|DOWN}`, 503 when the DB is down.

---

## 3. Authentication & session

### 3.1 Sign-up provisions an entire tenant

`POST /api/auth/register` (public, rate-limited):

```json
{ "company_name": "Acme Panels", "name": "Ravi", "email": "ravi@acme.com",
  "password": "secret123", "password_confirmation": "secret123", "phone": "9876543210",
  "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "launch" }
```

`TenantProvisioningService::provision()` runs one transaction and creates:

1. **Company** — unique slug subdomain, `subscription_plan='starter'`, `subscription_status='trial'`,
   `trial_ends_at = now + 14 days`, `is_active = true`.
   Those four columns are **guarded** (not in `$fillable`) and may only be written via `forceFill()` by
   `TenantProvisioningService`, `SubscriptionService` or `SuperAdminController`. A tenant admin can never
   self-upgrade through a stray mass assignment.
2. **Five roles** — Company Admin (`['*']`), Sales, Production, Accounts, Viewer.
3. **First user** — `is_company_admin = true`, linked to Company Admin.

Returns a Sanctum token immediately. Password policy: min 8 characters, letters **and** numbers.

### 3.2 Login

`POST /api/auth/login` `{ email, password }`

* Normal → `{ user, token, token_type: "Bearer" }`.
* If `two_factor_enabled` → **no token**; returns `{ needs_2fa: true, email }` and emails a 6-digit OTP
  (stored hashed, 10-minute expiry, attempt counter). Then
  `POST /api/auth/verify-otp` `{ email, code }` returns the token.

Login requires `is_active = true` on the user; invalid credentials return 401 `INVALID_CREDENTIALS`
without revealing whether the email exists.

### 3.3 Session lifetime

Tokens live **12 hours**. `AppShell` calls `POST /api/auth/refresh-token` on mount and every 6 hours, so an
active user is never logged out mid-shift. **Use the new token after a refresh.**

Other auth routes: `GET /api/auth/me` (re-reads permissions — call it after any role change),
`POST /api/auth/logout`, `POST /api/auth/change-password`, `POST /api/auth/two-factor`.

### 3.4 Tenant gate — `EnsureActiveTenant`

Runs on every authenticated route. When `subscription_status === 'expired'` or `is_active === false`
it returns **402** for everything except the recovery allow-list:
`me`, `logout`, `change-password`, `company.show`, `billing.status|checkout|verify`,
`announcements.active`, `notif.settings.show`.
It also lazily flips `trial → expired` once `trial_ends_at` passes. Super admins bypass it entirely.

---

## 4. RBAC & permissions

### 4.1 How the check works

`EnforceModulePermissions` maps the **second URL segment** (`/api/<segment>/…`) to a `[read, write]` key
pair, requires the read key for `GET/HEAD/OPTIONS` and the write key for every other verb. Unmapped
segments pass through. `null` means "no permission needed for that verb".

Three routes are gated by **route name** instead, because their URL segment lies about their domain:

| Route name | URL | Required key |
|---|---|---|
| `batches.create` | `POST /api/orders/{id}/batches` | `production.manage` |
| `dispatches.create` | `POST /api/batches/{id}/dispatch` | `dispatch.manage` |
| `dispatches.by_batch` | `GET /api/batches/{id}/dispatches` | `dispatch.view` |

Admins (`is_company_admin`, `is_super_admin`) and roles holding `*` bypass all checks
(`User::hasPermission`).

### 4.2 Segment → permission map

| Segment | Read key | Write key |
|---|---|---|
| `leads` | `leads.view` | `leads.manage` |
| `customers` | `customers.view` | `customers.manage` |
| `quotations` | `quotations.view` | `quotations.manage` |
| `orders` | `orders.view` | `orders.manage` |
| `batches`, `production`, `production-stages`, `quality-control` | `production.view` | `production.manage` |
| `dispatches` | `dispatch.view` | `dispatch.manage` |
| `invoices` | `invoices.view` | `invoices.manage` |
| `payments` | `payments.view` | `payments.manage` |
| `stock` | `stock.view` | `stock.manage` |
| `procurement`, `purchase-orders`, `suppliers` | `procurement.view` | `procurement.manage` |
| `reports` | `reports.view` | `reports.view` |
| `export` | `reports.view` | — |
| `forecasts`, `analytics` | `reports.view` | `reports.manage` |
| `users` | `users.manage` | `users.manage` |
| `audit-logs` | `audit.view` | — |
| `company`, `settings`, `panel-types`, `accessories`, `material-settings`, `document-templates`, `gst`, `sms`, `roles`, `billing` | *(open)* | `settings.manage` |

Note the deliberate asymmetry: **master-data reads are open** because every form needs panel types,
accessories and company data; writes need `settings.manage`.

`costing.view` exists in the registry to hide cost/margin fields (`unit_cost`, `base_price`, valuation)
but is **not enforced by this middleware** — it is a UI-level flag only.

### 4.3 Default roles per tenant

| Role | Permissions |
|---|---|
| Company Admin | `*` |
| Sales | leads.*, quotations.*, orders.*, customers.* |
| Production | production.*, stock.*, dispatch.*, procurement.* |
| Accounts | invoices.*, payments.*, reports.*, costing.view |
| Viewer | every `.view` key, no writes |

Managed via `GET /api/roles`, `GET /api/permissions` (grouped registry for the UI),
`PUT /api/roles/{id}/permissions`.

### 4.4 Multi-tenancy

Isolation is **structural**. `BaseModel` uses `BelongsToTenant`, which:

* adds a global scope filtering every read by `company_id = auth()->user()->company_id`;
* auto-fills `company_id` on create;
* **no-ops when unauthenticated** (console commands, seeders, signup);
* is bypassed for `is_super_admin`.

Deliberate cross-tenant reads use `Model::withoutGlobalScope('tenant')` (platform invoice numbering does).
Services *also* pass `company_id` explicitly and use `where('company_id', …)->findOrFail()` as defence in
depth — which is why a cross-tenant id yields **404, not 403**. All models use `SoftDeletes`.

---

## 5. The spine (end-to-end flow)

```
            Lead ──convert──► Customer
                                 │
                          BOQ (no rates)                      ← technical team
                                 │ convert
                        Quotation draft ──enter rates──► sent ──► accepted
                                 │                                    │
                     (revise / duplicate / expire / reject)           │ create-order
                                                                      ▼
                                                              ORDER (pending)
                                                                      │
                 Production Planning  ── groups pending orders by production signature
                                                                      │ create run
                                  PRODUCTION RUN (draft) ── one child BATCH per order
                                                                      │ start
                              ┌───────────────────────────────────────┤
                              │  BOM computed → raw material ISSUED from stock (FIFO)
                              │  orders → in_production, batches → in_progress
                              ▼                                       │ complete (+ actuals)
                  Stage logs · cutting sheet · photos                 │
                                                                      ▼
                                                        batches → qc_pending
                                                                      │ QC pass + approve
                                                                      ▼
                                                        batches → qc_passed
                                                                      │ dispatch
                                                          DISPATCH (pending)
                                                                      │ complete
                                                 delivered · batch → dispatched
                                                                      │
                                                 INVOICE (draft → sent → paid)
                                                                      │
                                    Payments · Reminders · Receivables · GST · Reports
```

Parallel subsystems: **Inventory** (coils / chemicals / consumables) fed by **Procurement**
(suppliers → PO → GRN); **Notifications** on order confirmed, dispatch done, payment due, low stock;
**Audit log** on 10 high-value models.

---

## 6. Master data — what must exist first

A brand-new tenant cannot quote anything until these exist. This is the single most common cause of a
blocked end-to-end test.

| # | Thing | Endpoint | Why it is required |
|---|---|---|---|
| 1 | **Company profile** | `PUT /api/company` | `state_code` drives intra- vs inter-state GST. `gstin`'s first two digits are the state code used by quotations. Bank details and signatory appear on PDFs. |
| 2 | **Company logo** | `POST /api/company/logo` | Appears in the sidebar and on PDFs. Optional. |
| 3 | **Panel types** | `POST /api/panel-types` | *Mandatory.* Every quotation panel row references one. `base_price` seeds the pricing engine; `length`/`width` feed the cutting schedule. |
| 4 | **Accessories** | `POST /api/accessories` | Optional, needed only if you quote accessories from a master list. |
| 5 | **Customers** | `POST /api/customers` | *Mandatory.* `state_code` decides GST type; `type` (retail/wholesale/distributor/corporate) multiplies the suggested rate; `credit_limit` gates order creation. |
| 6 | **Production stages** | `POST /api/production-stages` | Needed for stage-by-stage tracking. Ordered by `sequence`; a stage cannot start until all lower-sequence active stages are complete. |
| 7 | **Stock items** | `POST /api/stock/coils` \| `/chemicals` \| `/consumables` | *Mandatory before starting production.* The BOM resolves coil by `panel_type_id`, chemicals by `category` (`Polyol`, `Isocyanate`), consumables by `category` (`film`, `tape`). Missing rows silently contribute zero availability → shortage. |
| 8 | **Material settings** | `PUT /api/material-settings` | Auto-created with defaults on first read. Controls every BOM number (see §21.3). |
| 9 | **Tax configuration** | `PUT /api/settings/tax` | Rate + inclusive/exclusive for invoices. Auto-provisioned at **18% exclusive** the first time an invoice is taxed, so it is optional — set it only to use a different rate or to switch tax off (`is_active: false`, which is honoured). `POST /api/gst/register` is a *different* thing: it records the GSTIN registration (`gstin`, `state_code`, `registration_type`) and does not set the rate. |
| 10 | **Suppliers** | `POST /api/suppliers` | Needed before raising a purchase order. |
| 11 | **Users & roles** | `POST /api/users`, `PUT /api/roles/{id}/permissions` | Needed to test role separation. |
| 12 | **Notification settings** | `PUT /api/settings/notifications` | Twilio SMS/WhatsApp credentials + per-event toggles. Without them, notification sends are silent no-ops. |
| 13 | **Document templates** | `PUT /api/document-templates` | Chooses the Blade template per document type; falls back to the default if missing. |

UI equivalent: **Settings → Company**, **Settings → Master Data** (`MasterDataManager.vue` hosts
`PanelTypeMaster`, `AccessoryMaster`, `ProductionStageMaster`, plus the **Tax / GST** and
**GST Compliance** tabs), then **Stock** and **Procurement**.

---

## 7. Flow — Leads (CRM)

**UI:** `LeadManager.vue` · **Service:** `LeadService` · **Model:** `Lead`, `LeadActivity`

Leads are the pre-customer stage. `lead_no` format `LEAD-YYYY-0001`.

| Action | Endpoint |
|---|---|
| List (filters: `status`, `source`, `assigned_to`, `follow_up=due`, `search`) | `GET /api/leads` |
| Funnel dashboard | `GET /api/leads/dashboard` |
| Create | `POST /api/leads` |
| Update | `PUT /api/leads/{id}` |
| Change status | `POST /api/leads/{id}/status` `{status, lost_reason?}` |
| Log an activity | `POST /api/leads/{id}/activities` `{type, description, activity_date}` |
| Convert to customer | `POST /api/leads/{id}/convert` |
| Delete | `DELETE /api/leads/{id}` |

**Status ladder:** `new → contacted → qualified → quoted → won | lost`.
Every status change auto-writes a `status_change` activity, so the lead timeline is complete.

**Convert** (`LeadService::ensureCustomer`) creates a `Customer` from the lead's details if one is not
already linked (auto-generates `code` as 4 letters + 3 digits, `type='retail'`, `payment_terms_days=30`),
links `lead.customer_id`, and logs an activity. It does **not** create the quotation — the UI then opens
`QuotationCreate` prefilled with `{customer_id, lead_id}`. When that quotation is saved with `lead_id`,
`QuotationService::create` calls `LeadService::linkQuotation`, which flips the lead to `quoted` and stores
`quotation_id`.

**Dashboard** returns: funnel counts, win rate, conversion % per source, top 5 lost reasons, and
follow-up buckets (overdue / today / this week). The sidebar badge on **Leads** is the count of leads whose
`next_follow_up_date <= today` and are not won/lost.

---

## 8. Flow — Customers

**UI:** `CustomerManager.vue`, `CustomerProfile.vue` · **Service:** `CustomerProfileService`, `CreditService`

| Action | Endpoint |
|---|---|
| List / create / show / update / delete | `GET|POST /api/customers`, `GET|PUT|DELETE /api/customers/{id}` |
| 360° profile | `GET /api/customers/{id}/profile` |
| Credit status | `GET /api/customers/{id}/credit-status` |

Fields that change behaviour downstream:

| Field | Effect |
|---|---|
| `state_code` | Compared against the company's state code → intra-state (CGST+SGST) vs inter-state (IGST). Stored canonically as the **2-digit GST code** (`24`); `GJ`, `Gujarat` and a GSTIN are all normalised to it on save by `App\Support\GstState`. |
| `type` | Pricing multiplier: retail 1.00, wholesale 0.95, distributor 0.90, corporate 1.00. |
| `credit_limit` | `0` or less = **no limit**. Otherwise order creation is blocked past the limit. |
| `whatsapp_no` / `phone` | Notification target (`whatsapp_no` preferred). |
| `payment_terms_days` | Reference for due dates (invoice default is still +30 days). |

**Credit exposure** (`CreditService`) is computed live — the `customers.outstanding_balance` column is
stale and unused:

```
outstanding          = Σ (invoice.total_amount − payments) for invoices of this customer's orders,
                       excluding paid & cancelled
pending_orders       = Σ order.total_amount for non-cancelled orders with NO active invoice
exposure             = outstanding + pending_orders
would_be             = exposure + new_order_total
within_limit         = credit_limit <= 0  OR  would_be <= credit_limit
```

Counting uninvoiced orders closes the hole where a customer could place unlimited orders that only hit the
limit once invoiced.

---

## 9. Flow — BOQ (Bill of Quantities)

**UI:** `BoqManager.vue` → `BoqRegister.vue` + `QuotationCreate.vue` (`isBoq` mode)
**Service:** `QuotationService` · **Table:** `quotations` with `status='boq'`

### 9.1 The concept

A BOQ and a quotation are **the same record** at different stages. The technical team captures the full
panel specification **without rates**; Sales prices it later. `status='boq'` is the rate-less stage.

`QuotationService::list()` **excludes `status='boq'`** unless the caller explicitly passes `status=boq`.
So the Quotations screen never shows raw BOQs, and the BOQ Register fetches them with `?status=boq`.

### 9.2 Create a BOQ

`POST /api/quotations` with `as_boq: true`.

```jsonc
{
  "as_boq": true,
  "customer_id": 7,
  "project_name": "Cold Storage Unit, Pune",
  "project_location": "Chakan MIDC",
  "quality_grade": "High",              // High | Medium | Standard
  "validity_days": 10,
  "quoted_on": "2026-10-23",
  "quotation_prefix": "SCP",
  "lead_id": 12,                        // optional — back-links the lead
  "panel_rows": [
    {
      "panel_type_id": 3,
      "application": "Cold Room",       // Wall|Roof|Cold Room|Partition|Clean Room|Ceiling|PEB Shade|Architectural
      "thickness": 100,                 // 10,25,30,40,50,60,75,80,100,120,150,200
      "density_type": "PUF",            // PUF|PIR|Rockwool|EPS|Glasswool
      "density_kgm3": 40,               // 10–200
      "top_skin_material": "PPGI",      // PPGI|PPGL|SS304|GI|Aluminium
      "top_skin_thickness": 0.50,       // 0.30|0.35|0.40|0.45|0.50|0.60
      "top_color": "Off White",
      "top_color_ral": "9002",
      "top_surface": "PLAIN",           // PLAIN|RIBBED
      "bottom_skin_material": "PPGI",
      "bottom_skin_thickness": 0.50,
      "bottom_color": "Off White",
      "bottom_color_ral": "9002",
      "bottom_surface": "PLAIN",
      "guard_film": true,
      "cello_tap": false,
      "fixing_system": "Cam-Lock",      // Cam-Lock|Secret-Fix|Standing-Seam|Lap-Joint|Visible-Fix
      "hsn_code": "39259010",
      "sizes": [
        { "length_mm": 3000, "nos": 40 },   // width is ALWAYS 1000 mm
        { "length_mm": 1800, "nos": 12 }    // < 2000 mm → "DL" (doubled length, cut after foaming)
      ]
    }
  ],
  "accessories": [
    { "type": "standard", "accessory_id": 5, "qty": 10, "rate": 450, "unit": "NOS",
      "description": "Cam-lock set" },
    { "type": "door", "door_type": "Sliding", "door_width": 1200, "door_height": 2100,
      "qty": 1, "rate": 45000 },
    { "type": "installation", "description": "Site installation", "qty": 1, "rate": 60000 }
  ]
}
```

Rules applied by `saveItems()`:

* **Panel width is hard-coded to 1000 mm.** `sqm = (length_mm / 1000) × 1 × nos`.
  (The `sqm` column on `quotation_item_sizes` is a **generated column** — never write it.)
* **Rates are stored exactly as entered. `0` means "rate pending".** The pricing engine is never applied
  silently; it is only a suggestion served by `/suggested-rate`.
* Item-level `rate_per_sqm` is the weighted average across its size rows; `amount` is their sum.
* Accessories with a numeric `accessory_id` attach to the pivot (tenant-checked).
  `installation` rows, `door` rows typed into the form and the form's "— Custom —" accessory have no master
  item: each is kept as its own row in `quotation_lines` (type, description, unit, quantity, unit price, amount,
  optional HSN, door size) and `installation_amount` is rewritten as the sum of those rows. The edit form loads
  and re-sends them, so saving never loses them (PB-045). Revise and duplicate copy them.
* `is_inter_state` is derived at save time by comparing the state code from the company's GSTIN prefix
  against `customer.state_code`.

### 9.3 Working with BOQs

| Action | Endpoint | Notes |
|---|---|---|
| List open BOQs | `GET /api/quotations?status=boq` | What the BOQ Register shows |
| Edit | `PUT /api/quotations/{id}` | Allowed while status is `boq`, `draft` or `sent`. Replaces all rows. |
| Worker cutting sheet (PDF) | `GET /api/quotations/{id}/boq-pdf` | **No rates, no customer name** — safe for the shop floor |
| Convert to quotation | `POST /api/quotations/{id}/convert` | `boq → draft`. Fails if the BOQ has no panel rows. |
| Delete | `DELETE /api/quotations/{id}` | Only `boq` or `draft` |

The BOQ sheet (`resources/views/quotations/_boq_sheet_body.blade.php`) prints, per panel row: the full
spec bar (panel type, application, thickness, core + density, fixing, top/bottom skin with RAL and surface,
guard film, cello tape) and a cutting table of Sr / Length / Width / NOS / SQM, flagging every
`length_mm < 2000` with a **⚠ DL** marker, then the row total NOS and SQM.

---

## 10. Flow — Quotations

**UI:** `QuotationManager.vue` → `QuotationList` / `QuotationCreate` / `QuotationDetail`
**Service:** `QuotationService`, `QuotationPdfService`

### 10.1 Numbering

`generateQuotationNumber()` → `PREFIX-FY-NNN`, e.g. **`SCP-2027-001`**.
Financial year starts **1 April**; the year in the number is `fyYear + 1`. The sequence is the max existing
root number inside the FY (a `REGEXP` keeps revision suffixes like `-v2` out of the scan), +1.
Concurrency: wrapped in `DocNumber::retry()`, which re-runs the whole transaction on SQLSTATE `23000`
(duplicate key) or `40001` (deadlock), up to 3 attempts.

### 10.2 Pricing

Rates can be entered three ways:

1. **Inline per size row** when creating/updating (`sizes[].rate_per_sqm`).
2. **Bulk on the detail page** — `POST /api/quotations/{id}/rates`
   `{ "rates": [ { "id": <quotation_item_size_id>, "rate_per_sqm": 1450 }, … ] }`.
   This recomputes each item's `amount`, its weighted-average `rate_per_sqm`, then all totals.
   Allowed on `boq`, `draft` and `sent`.
3. **Suggested rate** — `POST /api/quotations/suggested-rate`
   `{ panel_type_id, row_data, quality_grade, customer_id }` → a single advisory number
   (formula in §21.1). It is never auto-applied; the UI shows it as a clickable `≈ 1450` chip.

A quotation exposes a computed `rates_pending` boolean (true when any item still has
`rate_per_sqm <= 0`), but **only when `items` is eager-loaded** — otherwise it is `null` (unknown).

### 10.3 Lifecycle

| Action | Endpoint | Precondition | Result |
|---|---|---|---|
| Create | `POST /api/quotations` | — | `draft` (or `boq` with `as_boq`) |
| Update | `PUT /api/quotations/{id}` | status ∈ `boq, draft, sent` | rows replaced, totals recomputed |
| Convert BOQ | `POST /…/convert` | status `boq`, ≥1 row | `draft` |
| Save rates | `POST /…/rates` | status ∈ `boq, draft, sent` | totals recomputed |
| **Send** | `POST /…/send` | status `draft`, ≥1 row, **every item rate > 0** | `sent`, `sent_at` |
| Accept | `POST /…/accept` | status `sent` | `accepted`, `accepted_at` |
| Reject | `POST /…/reject` | status ∈ `draft, sent` | `rejected` |
| Expire | `POST /…/expire` | status ∈ `draft, sent` | `expired` |
| Revise | `POST /…/revise` | status ∈ `draft, sent, accepted` **and no order exists yet** | original → `revised`; new `draft` numbered `…-v2` |
| Duplicate | `POST /…/duplicate` | any | brand-new `draft` with a fresh number |
| Create order | `POST /…/create-order` | status `accepted` | new `Order`, see §11 |
| PDF | `GET /…/pdf` | any | customer-facing quotation |
| BOQ sheet | `GET /…/boq-pdf` | any | rate-less worker sheet |
| Delete | `DELETE /api/quotations/{id}` | status ∈ `boq, draft` | soft delete |

**The single most common 400 here:** `"Rates are pending. Enter rates for all panel rows before sending."`
— send is blocked while any item's `rate_per_sqm <= 0`.

### 10.4 Revisions

`revise()` locks the original to `revised`, replicates the header, items and size rows (dropping the
generated `sqm`), re-attaches all accessory pivot data, and numbers the copy
`<original>-v<n>` where `n = revisions().count() + 2`. `parent_quotation_id` links them, so
`parent` / `revisions` relations give you the full version chain.

### 10.5 Totals

See §21.2. The headline: quotation GST is **hard-coded at 18 %**, split CGST+SGST or IGST by
`is_inter_state`; transport is added **after** tax when `transport_fixed` is on; the grand total is
rounded to the nearest rupee with the delta stored in `round_off`; `advance_amount = total × advance_pct%`.

---

## 11. Flow — Orders

**UI:** `OrderManager.vue` → `OrderList` / `OrderDetail` · **Service:** `OrderService`

### 11.1 Creation

`POST /api/quotations/{id}/create-order` `{ "override_credit_limit": false }`

Sequence inside one transaction (`OrderService::createFromQuotation`):

1. Quotation must be `accepted` (checked in the controller *and* the service).
2. `lockForUpdate()` on the quotation, then reject if an order already exists → one order per quotation.
3. `lockForUpdate()` on the customer, then the **authoritative credit check**. A fast pre-check also runs in
   the controller for UX; the locked re-check is what actually decides. Only an admin may pass
   `override_credit_limit=true`; for non-admins the flag is ignored.
4. Create the order with `order_no` = `PREFIX-FY-NNN` (company's `order_prefix`, default `ORD`),
   status `pending`, `order_date = today`, `expected_delivery_date = today + 14 days`.
5. **Snapshot every panel row and size row** into `order_items` / `order_item_sizes`, copying the complete
   BOQ spec and pricing. The order is therefore independent of later quotation edits.
6. Fire a WhatsApp/SMS "order confirmed" notification (failures are swallowed).

### 11.2 Order lifecycle

| Status | Set by |
|---|---|
| `pending` | Creation |
| `in_production` | Set as soon as **any** batch is created for the order (`ProductionBatchService::createFromOrder`), whether standalone or inside a run. Deleting the order's last draft batch returns it to `pending`. |
| `completed` | `PUT /api/orders/{id}` with `status=completed` — **only when every batch of the order is `dispatched`** |
| `cancelled` | Manual |

`PUT /api/orders/{id}` accepts only `expected_delivery_date`, `notes`, `status`. Everything else is ignored.

Reads: `GET /api/orders` (filters `status`, `customer_id`, `search`), `GET /api/orders/{id}`
(items + sizes + panel type + customer + quotation + batches + company).

---

## 12. Flow — Production planning (advisory)

**UI:** `ProductionPlanner.vue` · **Service:** `ProductionPlanningService` · **Endpoint:** `GET /api/production/planning`

A continuous PUF/PIR line pays for every changeover in setup time, coil splice scrap and foam purge. This
module is **read-only advice** that tells the planner what to run together and in what order.

### 12.1 Production signature

Two panel rows can share a run if they share this signature — everything that forces a line changeover:

```
density_type | application | thickness | density_kgm3 |
top_skin_material | top_skin_thickness | top_color | top_color_ral | top_surface |
bottom_skin_material | bottom_skin_thickness | bottom_color | bottom_color_ral | bottom_surface
```

**Length and NOS are deliberately excluded** — changing cut length costs nothing.

### 12.2 What the endpoint returns

* `groups[]` — pending-order rows bucketed by signature, each with a human label
  (`COLD ROOM · 100mm · PUF 40kg · Top 0.5mm PPGI Off White (RAL 9002) (PLAIN) · Bottom: same`),
  `total_sqm`, `total_nos`, `dl_count` (rows under 2000 mm), contributing orders, `earliest_due`,
  `is_overdue`, `due_soon` (≤3 days), `matches_running`, `run_order`.
* `alerts[]` —
  * `running_match` (**high**): this spec is already on the line → add it to the current run now;
  * `merge_pending` (medium): several pending orders share one spec → run them together;
  * `overdue` (high): past its delivery date.
* `summary` — pending order count, group count, total sqm, alert count, `changeovers = groups − 1`.

**Sequence recommendation order:** matches-running → overdue → earliest due → biggest sqm.

The sidebar badge on **Production Plan** is `summary.alert_count`.

---

## 13. Flow — Production runs (the main production path)

**UI:** `ProductionRuns.vue` · **Services:** `ProductionRunService`, `ProductionMaterialService`, `MaterialBomService`

A **run** is a group of same-spec per-order batches that the line produces together. Dispatch, QC and
invoicing stay per-order/per-batch, so grouping changes nothing downstream.

### 13.1 Create a run

`POST /api/production/runs`

```json
{ "order_ids": [12, 15, 19], "signature": "<from the planner group>",
  "label": "COLD ROOM · 100mm · PUF 40kg", "notes": "Shift A" }
```

* Only orders in **`pending`** are eligible — already-started orders are skipped. If none qualify:
  `"No eligible pending orders to start. They may already be in production."`
* Creates `ProductionRun` (`RUN-YYYY-0001`, status `draft`), then **one child batch per order**
  via `ProductionBatchService::createFromOrder`, sets `batch.run_id`, moves each order to
  `in_production`, and sums `planned_sqm`.

### 13.2 Check material before starting

`GET /api/production/runs/{id}/material-requirement` → `MaterialBomService::requirementForRun()`.

Response shape:

```jsonc
{
  "total_sqm": 1480.5,
  "all_ok": false,
  "lines": [
    { "material_kind": "coil", "ref": 3, "label": "Coil — PUF Wall Panel",
      "standard": 11620.2, "required": 11968.8, "available": 9000.0,
      "unit": "kg", "short_by": 2968.8, "wastage_pct": 3.0, "ok": false },
    { "material_kind": "chemical", "ref": "Polyol", "label": "Polyol", … },
    { "material_kind": "chemical", "ref": "Isocyanate", "label": "Isocyanate (MDI)", … },
    { "material_kind": "consumable", "ref": "film", "label": "Protective Film", … },
    { "material_kind": "consumable", "ref": "tape", "label": "Sealant Tape", … }
  ],
  "notes": ["Quantities include wastage (3% coil, 5% chemical) and 5% foam overpack.", …]
}
```

Formulas in §21.3. Availability is matched by `panel_type_id` (coil) or `category` (chemical/consumable);
**if no stock row matches, availability reads 0 and the line is short.**

`GET /api/production/runs/{id}/po-suggestion` turns every short line into draft PO rows at the stock row's
last `unit_cost`. Lines with no matching stock row come back under `unresolved` with the reason
"create the stock item first".

### 13.3 Start the run — this is where stock actually moves

`POST /api/production/runs/{id}/start` `{ "force": false }`

Inside one transaction:

1. `lockForUpdate()` + recheck `status === 'draft'` — two concurrent starts cannot both issue material.
2. `ProductionMaterialService::issueForRun()`:
   * skips if usage rows already exist for the run (no double deduction);
   * recomputes the BOM; if `all_ok` is false and `force` is false it **throws**, rolling everything back:
     `"Insufficient stock: Polyol (short 120 kg). Add stock or start with override."`;
   * otherwise **FIFO-deducts** each line across matching stock rows (capped at each row's on-hand, so a
     forced start consumes what exists), writing a `stock_transactions` ledger row per deduction;
   * writes a `production_material_usages` row per line (`standard_qty`, `issued_qty`, `actual_qty = null`).
3. Every `draft` child batch → `in_progress` (`ProductionBatchService::startProduction`).
4. Run → `in_progress`, `started_at = now`.

> `force: true` is the override path: it issues whatever is on hand and starts anyway. Stock can reach 0
> but never goes negative.

### 13.4 Complete the run

`POST /api/production/runs/{id}/complete`

```json
{ "actuals": [ { "id": <production_material_usage_id>, "actual_qty": 11800 }, … ] }
```

* `recordActuals()` reconciles stock by the **difference** vs what was issued
  (`actual > issued` → deduct more, capped at on-hand; `actual < issued` → add the remainder back),
  and stores real `wastage_pct = (actual − standard) / standard × 100`.
* Every `in_progress` child batch → `qc_pending`, with `completed_quantity` defaulting to
  `planned_quantity`.
* Run → `completed`, `completed_at = now`.

`actuals` is optional — omit it and only the status changes.

### 13.5 Other run endpoints

| Endpoint | Purpose |
|---|---|
| `GET /api/production/runs` | List, ordered `in_progress → draft → qc_pending → completed → cancelled`, newest first |
| `GET /api/production/runs/{id}` | Run + child batches + orders + customers |
| `GET /api/production/runs/{id}/material-usage` | Issued vs actual rows — this is what the "complete" form renders |
| `GET /api/production/wastage-report?from=&to=` | Actual vs standard per material across runs |
| `DELETE /api/production/runs/{id}` | Cancel a **draft** run: deletes draft child batches, returns orders to `pending` |

---

## 14. Flow — Batches, stages, cutting, photos

**UI:** `BatchManager.vue` → `BatchList` / `BatchCreate` / `BatchDetail`
**Services:** `ProductionBatchService`, `BatchStageLogService`, `CuttingScheduleService`

### 14.1 Standalone batches

A batch can exist without a run: `POST /api/orders/{orderId}/batches` `{ planned_quantity?, notes? }`.
`batch_no` = `PREFIX-YYYY-000001` (company `batch_prefix`, default `BATCH`).

`POST /api/batches/{id}/start` — for a batch with **no `run_id`**, this issues its own material from the
order's BOM (`issueForBatch`), mirroring the run path. For a child of a run, material was already issued at
run start and nothing is deducted again.

`POST /api/batches/{id}/complete` `{ completed_quantity? }` → `qc_pending`.
`DELETE /api/batches/{id}` works only on `draft`.

### 14.2 Stage tracking

| Endpoint | Purpose |
|---|---|
| `GET /api/batches/{batchId}/timeline` | All stage logs with stage + user |
| `GET /api/batches/{batchId}/progress` | Every active stage with `pending/in_progress/completed`, timestamps, duration |
| `POST /api/batches/{batchId}/stages/{stageId}/start` | Start a stage |
| `POST /api/batches/{batchId}/stages/{stageId}/complete` | Complete it (computes `duration_minutes`) |
| `GET|POST /api/batches/{batchId}/stages/{stageId}/attachments` | Shop-floor photos (`stage_photo`, `qc_photo`) |

**Sequencing is enforced:** `validateStageStartable()` rejects a start while any active stage with a lower
`sequence` is incomplete, and rejects starting the same stage twice. The batch row is locked for the
duration so double-clicks serialise.

### 14.3 Cutting schedule

| Endpoint | Returns |
|---|---|
| `POST /api/batches/{id}/calculate-cutting-schedule` | Computes and returns the schedule |
| `GET /api/batches/{id}/cutting-schedule` | Plain-text shop instructions |
| `GET /api/batches/{id}/cutting-schedule/json` | Same data as JSON |

Logic: for each order item, if the panel type's `length < 2000 mm` and `length × 2 ≤ 3000 mm`, pair the
panels (`double` cut, 2 per roll); the remainder is cut `single`. Output carries material length per
operation, rolls needed, a double-cut percentage and an efficiency string.

Logic detail: the service walks the order's **size rows** (`length_mm`, `nos`), so `total_items` is a
panel count. `strips_needed` is how many strip runs the line makes; an odd panel left over from a paired
set is emitted as its own `single` row with a note. `optimization_efficiency` is panels produced per strip
slot (100% = every strip carried two panels). This matches the DL rule on the BOQ sheet (§9.3), so the
office and the floor describe the same job.

---

## 15. Flow — Quality Control

**UI:** `QcDashboard.vue` · **Service:** `QualityControlService`

| Endpoint | Purpose |
|---|---|
| `POST /api/batches/{id}/qc` | Create the QC record |
| `GET /api/batches/{id}/qc` | QC for a batch |
| `GET /api/quality-control` | List (filter `status`, `batch_id`) |
| `GET /api/quality-control/{id}` | Detail with inspector + approver |
| `POST /api/quality-control/{id}/approve` | Approve (the dispatch green light) |
| `GET /api/quality-control/statistics?date_from=&date_to=` | Totals, pass/fail, pass-rate |

Create payload:

```json
{ "status": "pass",
  "notes": "Edges clean, thickness within tolerance",
  "checklist": [ { "key": "thickness", "label": "Thickness within ±1mm", "passed": true },
                 { "key": "adhesion",  "label": "Skin adhesion",          "passed": true } ] }
```

Rules:

* The batch **must be `qc_pending`** to enter QC.
* `pass` → batch `qc_passed`; `fail` → batch `qc_failed`.
* **Approve only works on a `pass`.** `"Can only approve QC entries with pass status. Failed QC requires
  rework."` Approval stamps `approved_by_user_id` + `approved_at`, which is exactly what dispatch checks.
* Checklist is capped at 50 items; each needs `key`, `label`, `passed`.

---

## 16. Flow — Dispatch

**UI:** `DispatchManager.vue` → `DispatchList` / `DispatchCreate` / `DispatchDetail` · **Service:** `DispatchService`

### 16.1 Preconditions (strict)

`POST /api/batches/{batchId}/dispatch` requires **all** of:

1. A QC record exists for the batch, **and** `qc.status === 'pass'`, **and** `qc.approved_at` is set.
2. `batch.status` ∈ `qc_passed`, `completed`.
3. No dispatch already exists for the batch — **one dispatch per batch**.

Payload: `{ customer_address, expected_delivery_date, tracking_number, notes, auto_allocate }`.

`dispatch_no` is generated in the model's `boot()` (`creating` hook), prefix + 6-digit sequence.
Dispatch items are copied from **all** of the batch's order items
(`quantity = total_sqm` stored at 4 dp, `unit_price = rate_per_sqm`, `amount` carried across verbatim).
Dispatch carries **panel rows only** — accessories and installation are billed on the invoice (§17.1).

### 16.2 Lifecycle

| Action | Endpoint | Effect |
|---|---|---|
| Allocate stock | `POST /api/dispatches/{id}/allocate` | **No-op.** Raw material is consumed at production start; the route survives for compatibility. |
| Complete | `POST /api/dispatches/{id}/complete` `{actual_delivery_date?}` | `delivered`, marks legacy allocations used, sets **batch → `dispatched`**, fires the dispatch WhatsApp/SMS |
| Update | `PUT /api/dispatches/{id}` | Only `customer_address`, `expected_delivery_date`, `tracking_number`, `notes` |
| Cancel | `DELETE /api/dispatches/{id}` | `cancelled`, reverts batch to `qc_passed` |
| Challan (JSON) | `GET /api/dispatches/{id}/challan` | Delivery-challan data |
| Challan (PDF) | `GET /api/dispatches/{id}/challan/pdf` | Printable challan |
| Photos | `GET|POST /api/dispatches/{id}/attachments` | `delivery_proof`, `signature` |
| By batch | `GET /api/batches/{batchId}/dispatches` | Needs `dispatch.view` |

Once every batch of an order is `dispatched`, the order may be marked `completed`.

---

## 17. Flow — Invoicing

**UI:** `InvoiceManager.vue` → `InvoiceList` / `InvoiceCreate` / `InvoiceDetail` · **Services:** `InvoiceService`, `TaxService`, `EInvoiceService`

### 17.1 Creating an invoice

Two entry points, both guarded against duplicates (one active invoice per dispatch and per order):

* `POST /api/invoices/from-dispatch` `{ dispatch_id, invoice_date?, due_date?, notes?, terms? }` — the normal path.
* `POST /api/invoices/from-order` `{ order_id, … }` — advance/proforma style, before dispatch.

`invoice_no` comes from the model's `creating` hook; `due_date` defaults to **+30 days**. Items are copied
from the source using each line's **stored `amount`** (never a recomputed quantity × averaged rate, which
drifts by paise), `subtotal` is summed, then `TaxService::applyTaxToInvoice()` runs and `persistTotals()`
writes `tax_amount` and `total_amount` so PDFs and accessors can never drift apart.

**Non-panel lines.** The order's accessories, doors and installation charge are added as invoice lines with
`panel_type_id = null` and a `description` (installation / custom / door rows one line each, with their real
quantity and unit price; a quotation with an amount but no rows falls back to one lump line), read from the order's accepted quotation (accepted quotations
are immutable, so no second snapshot is needed). They are billed **once per order**: if another live
invoice for the same order already carries them, a later invoice skips them, so a multi-dispatch order
cannot double-bill. Fixed **transport** is deliberately excluded — see §24.

### 17.2 Tax

`TaxService` reads the company's **active** `TaxConfiguration`:

* `tax_type = exclusive` → `tax = subtotal × rate%`, total = subtotal + tax.
* `tax_type = inclusive` → `taxable = subtotal × 100 / (100 + rate)`, tax = subtotal − taxable.
* **Intra-state vs inter-state** compares `company.state_code` with the customer's `state_code`
  (via `order.customer` or `dispatch.batch.order.customer`): equal → CGST + SGST at half the rate each;
  otherwise → IGST at the full rate.
* Per-item `tax_rate`, `tax_amount` and `total_with_tax` are written back to `invoice_items`.
* **If the tenant has never configured tax, one is auto-provisioned at 18% exclusive** (`TaxService::DEFAULT_GST_RATE`), matching the rate the quotation engine uses. An existing configuration with `is_active: false` is honoured as a deliberate "no tax" choice and still produces a zero-tax invoice.

Results land in `tax_calculations` (one row per invoice).

### 17.3 Lifecycle

| Action | Endpoint | Precondition |
|---|---|---|
| Send | `POST /api/invoices/{id}/send` | status `draft` |
| Accept | `POST /api/invoices/{id}/accept` | status ∈ `draft, sent` |
| Mark paid | `POST /api/invoices/{id}/mark-paid` | status ∈ `accepted, sent` **and remaining due ≤ ₹0.01** |
| Cancel | `POST /api/invoices/{id}/cancel` | status ∈ `draft, sent` |
| Update | `PUT /api/invoices/{id}` | `draft` only; `due_date`, `notes`, `terms` |
| Add item | `POST /api/invoices/{id}/items` | `draft` only; recomputes totals |
| Duplicate | `POST /api/invoices/{id}/duplicate` | any |
| PDF | `GET /api/invoices/{id}/pdf` · preview `…/pdf-preview` | — |
| Email | `POST /api/invoices/{id}/send-email` · preview `…/email-preview/{type}` | — |

`mark-paid` will not fake a settlement: it throws
`"Cannot mark paid: ₹X is still outstanding. Record the payment instead."`
Invoice numbers are rendered in Indian words by `InvoiceService::amountInWords()` (Lakh/Crore).

### 17.4 e-Invoice (IRN / QR) and e-Way Bill

`EInvoiceService` runs in two modes. **Manual mode always works** — an admin pastes values from the GST
portal. **Live GSP mode** activates when `EINVOICE_GSP_URL`, `_CLIENT_ID`, `_CLIENT_SECRET`, `_GSTIN` are
configured; the generate methods are stubbed `TODO` blocks until then.

| Endpoint | Purpose |
|---|---|
| `GET /api/invoices/{id}/einvoice/status` | Current IRN + e-way state |
| `POST /…/einvoice/irn/generate` \| `/manual` \| `/cancel` | IRN via GSP, pasted, or cancelled |
| `POST /…/einvoice/eway/generate` \| `/manual` \| `/cancel` | e-Way bill, same three modes |

A cancelled IRN can never be updated.

---

## 18. Flow — Payments & receivables

**UI:** `PaymentTracker.vue`, `AccountsReceivable.vue`, `PaymentReminderManager.vue`
**Services:** `PaymentService`, `PaymentGatewayService`, `PaymentReminderService`

### 18.1 Recording money

`POST /api/payments/record`

```json
{ "invoice_id": 42, "amount": 150000, "payment_method": "bank_transfer", "reference_no": "UTR12345" }
```

Rules (`recordPayment`, all inside a row-locked transaction):

* The invoice must not be `draft` or `cancelled` — **send the invoice first**.
* `amount` may not exceed the remaining due (`total − Σ payments`).
* When the remainder reaches 0, the invoice flips to `paid` with `paid_date`, and any scheduled reminder is
  marked paid.
* The row lock serialises concurrent payments on one invoice, so two half-payments can never over-apply.

Related reads/actions:

| Endpoint | Purpose |
|---|---|
| `GET /api/invoices/{id}/payments` | Payment history |
| `GET /api/invoices/{id}/payment-status` | total / paid / remaining / % / `is_overdue` / `days_overdue` |
| `GET /api/payments/unpaid` | Every unpaid invoice with its payment status |
| `POST /api/invoices/{id}/write-off` `{amount, reason}` | Records a `write_off` "payment" (excluded from collection figures) |
| `POST /api/payments/reconcile` | Marks a draft invoice sent, then records the payment |

### 18.2 Reminders

| Endpoint | Purpose |
|---|---|
| `POST /api/invoices/{id}/schedule-reminder` | Schedule the reminder ladder |
| `GET /api/invoices/{id}/reminder-status` | Where the ladder stands |
| `POST /api/invoices/{id}/send-reminder` · `/send-payment-reminder` | Send now |
| `POST /api/invoices/{id}/send-payment-confirmation` | Thank-you after payment |
| `POST /api/invoices/{id}/send-sms-reminder` | SMS channel specifically |
| `GET /api/reminders/stats` | Aggregate reminder stats |

### 18.3 Online payment (Stripe)

`POST /api/invoices/{id}/payment/checkout-session` → hosted checkout ·
`POST /api/invoices/{id}/payment/intent` + `POST /api/payments/intent/confirm` → embedded card form
(`StripePaymentForm.vue`) · `GET /api/invoices/{id}/payment-link` → shareable link.
Webhook: `POST /api/webhooks/stripe` (public, unauthenticated).

---

## 19. Supporting subsystems

### 19.1 Inventory

**UI:** `StockManager.vue` → `CoilStock` / `ChemicalStock` / `ConsumableStock` / `TransactionLog`
**Service:** `StockService`, `StockDashboardService`

Three stock families, each with identical verbs:

| Family | Keyed by | Unit | Endpoints |
|---|---|---|---|
| Coils | `panel_type_id` | kg | `/api/stock/coils`, `/{id}/add`, `/remove`, `/adjust`, `/reorder` |
| Chemicals | `category` (`Polyol`, `Isocyanate`) + `name` | kg/L | `/api/stock/chemicals/…` (also `batch_number`, `expiry_date`) |
| Consumables | `category` (`film`, `tape`, `oil`, `packaging`) | varies | `/api/stock/consumables/…` |

Every movement writes a `stock_transactions` row (`in` / `out` / `adjustment`) with user, note and
timestamp, under `lockForUpdate()`. Removals throw when stock is insufficient. Adding an expired chemical
is rejected.

Alerts (`low_stock_alerts`) are raised automatically on every movement and by `checkLowStock()`:
`low_stock` (`qty <= reorder_level`), `expiring_soon` (≤30 days), `expired` (past date with stock on hand).
Read `GET /api/stock/alerts`, clear `POST /api/stock/alerts/{id}/resolve`.

Dashboard & reports: `GET /api/stock/dashboard`, `GET /api/stock/report`, `GET /api/stock/transactions`.

### 19.2 Procurement

**UI:** `ProcurementManager.vue` · **Service:** `ProcurementService`

| Endpoint | Purpose |
|---|---|
| `GET|POST /api/suppliers`, `PUT /api/suppliers/{id}` | Supplier master |
| `GET /api/procurement/purchasable` | Every stock row as a PO-able line (coil + chemical + consumable) |
| `GET /api/procurement/reorder-suggestion` | Every item at/below reorder level, pre-shaped as PO rows (tops up to ~2× reorder level) |
| `POST /api/purchase-orders` | Create a PO (`PO-YYYY-0001`, status `ordered`) |
| `GET /api/purchase-orders` · `/{id}` | List / detail |
| `POST /api/purchase-orders/{id}/receive` | **Goods receipt (GRN)** |
| `POST /api/purchase-orders/{id}/cancel` | Cancel (blocked once fully received) |
| `GET /api/procurement/valuation` | Stock value by family: `Σ quantity × unit_cost` |

Create payload:

```json
{ "supplier_id": 2, "order_date": "2026-10-23", "expected_date": "2026-10-30", "tax_pct": 18,
  "items": [ { "material_kind": "chemical", "stock_id": 4, "item_name": "Polyol",
               "unit": "kg", "quantity": 2000, "rate": 142 } ] }
```

Receiving:

```json
{ "receipts": [ { "po_item_id": 9, "received_qty": 1500, "cost": 143,
                  "batch_no": "PL-2210", "expiry_date": "2027-04-30" } ] }
```

Each receipt adds to the stock row (full `StockService` ledger), refreshes that item's `unit_cost`,
stores chemical batch/expiry, and **refuses to over-receive** a line
(`"Cannot receive 500 — only 200 outstanding on this PO line."`).
PO status is then recomputed: all lines full → `received`, some → `partial`, none → `ordered`.

### 19.3 Reports & analytics

All under `reports.view`.

| Endpoint | Content |
|---|---|
| `/api/reports/profit-loss` | Revenue, COGS proxy, expenses, net |
| `/api/reports/balance-sheet` | As-of snapshot |
| `/api/reports/cash-flow` | Period cash movement |
| `/api/reports/accounts-receivable` | **AR ageing** buckets |
| `/api/reports/sales` | Sales by period |
| `/api/reports/tax` | GST summary from `tax_calculations` |
| `/api/reports/accounting-dashboard` | Combined finance view |
| `/api/reports/reconcile` · `/reconciliation` | Invoice reconciliation; per-order **ordered vs invoiced vs paid** (revenue-leakage flags) |
| `/api/reports/revenue-trend` | Invoiced vs collected, last N months |
| `/api/reports/top-customers` · `/panel-type-mix` | Customer and product mix |
| `/api/reports/mis` | Owner-level monthly MIS: revenue, collection, production sqm + runs, GST liability, outstanding |
| `/api/reports/tally/xml` · `/tally/csv` | Tally-compatible export |
| `/api/export/customers|quotations|orders|invoices` | Bulk CSV |

Forecasting (`reports.manage` to generate): `/api/forecasts/inventory`, `/demand`, `/reorders`, plus an ML
set `/forecasts/ml`, `/ml/compare-models`, `/ml/anomalies`, `/ml/record-actual`, `/ml/performance`.
Analytics: `/api/analytics/metrics/sales`, `/trends`, `/snapshot`.

### 19.4 Dashboard

`GET /api/dashboard` (`DashboardService`, **cached 60 s per company**) returns:

* `kpis` — open quotations, active orders, batches in production, pending dispatch, outstanding amount,
  collected this FY, plus sub-stats (quotations this week, overdue orders, batches on schedule,
  dispatch-ready count, overdue invoice count, YoY collection %).
* `pipeline` — counts by status across quotations → orders → batches → invoices.
* `receivables` — the AR ageing summary.
* `alerts` — low coil stock, low chemical stock, chemicals expiring in 30 days, expired chemicals with
  stock, overdue invoices, quotations past validity.
* `recent_activity` — last 12 events across quotations, orders, dispatches, invoices.

These KPI values are what the sidebar badges show.

### 19.5 Notifications

**Service:** `NotificationDispatchService` → queued `SendNotificationJob` → `SmsService` (Twilio).

| Trigger | Fired from | Toggle |
|---|---|---|
| Order confirmed | `OrderService::createFromQuotation` | `notify_order_confirmed` |
| Dispatch done | `DispatchService::completeDispatch` | `notify_dispatch_done` |
| Payment due | reminder flows | `notify_payment_due` |
| Low stock | stock alerts | `notify_low_stock` (to `admin_phone`) |

WhatsApp is tried first, SMS is the fallback; if neither is configured the send is a **silent no-op** (no
job is even queued). Phone numbers are normalised to E.164 (a bare 10-digit number becomes `+91…`).
Every attempt is written to `notification_logs`. All call sites wrap the send in `try/catch` — a messaging
failure can never break an order or a dispatch.

Settings: `GET|PUT /api/settings/notifications`, `POST /api/settings/notifications/test`,
`GET /api/settings/notifications/logs`.

#### In-app notifications (the header bell)

Separate from the above (that is outbound SMS/WhatsApp to *customers*; the settings page is labelled
**Customer Messaging**). Plan and status: [NOTIFICATIONS_PLAN.md](NOTIFICATIONS_PLAN.md).

**Service:** `AppNotificationService` → table `app_notifications` (one row per recipient). Recipients are
named by permission key (everyone in the company who holds it; admins hold all) or by user ids. Failures are
swallowed and logged.

| Event | Fired from | Recipients |
|---|---|---|
| Stage awaiting approval | `BatchStageLogService::completeStage` (stage has `requires_approval`) | `production.approve` |
| Stage approved / rejected | `approveStage` / `rejectStage` | the operator who ran it (skipped if they decided it themselves) |
| Low coil / chemical stock, invoices overdue | `notifications:scan`, hourly | `stock.manage`, `payments.view` |

Standing conditions carry a `dedupe_key` and are refreshed in place; when the condition clears the row is
deleted. Read rows are pruned after 30 days. API (own rows only): `GET /api/notifications`,
`GET /api/notifications/unread-count`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`.
UI: `NotificationBell.vue` in the AppShell header (polls every 60 s while the tab is visible).

### 19.6 Audit log

`Auditable` trait on **Customer, Invoice, Lead, Order, PanelType, PaymentTransaction, ProductionRun,
PurchaseOrder, Quotation, Supplier**. Records `created` / `updated` / `deleted` / `force_deleted` /
`restored` with before/after snapshots (stripping `password`, `remember_token`, timestamps) plus user, IP
and user-agent. Writes are wrapped in `try/catch` so auditing can never break the operation.
Read: `GET /api/audit-logs` (needs `audit.view`; UI = `AuditLog.vue`).

### 19.7 Document templates

`GET|PUT /api/document-templates`, `GET /api/document-templates/preview`.
`DocumentTemplateService::viewFor()` resolves the company's chosen template key through
`config/document_templates.php`, **verifies the Blade actually exists**, and otherwise falls back to the
default view — a removed template can never break PDF generation.

**Quotation templates available:** `classic` (the default) and `ledger`. Neither may contain a specific company's details: Classic takes its header address, the factory address in the Price Basis term and the optional certification line from Settings → Company (a test fails if a view contains another company's name, address, GSTIN or certification). A registry entry may set
`page_numbers => true`, which makes `QuotationPdfService` stamp "Page x of y" after rendering (the total is only
known once laid out); entries without it are untouched. Templates that use `QuotationDocumentService` receive
only layout work to do — it builds the numbered lines (panel, accessory, door, installation), GST summary by
HSN/SAC, amount in words, payment schedule and resolved terms (`config/quotation_terms.php`, placeholders
filled and HTML-escaped). DomPDF limits for any template: tables only, no flex/grid/`calc()`/CSS variables
(inject colours with Blade), `<thead>` repeats across pages, a `position: fixed` element repeats on every page.
The accent colour comes from `companies.primary_color` and is only used if it is a plain `#rrggbb`.
Shared layout for flowing templates lives in `resources/views/quotations/partials/` (`_pdf_base` — page margins,
base table rules, `.avoid`; `_pdf_footer` — running footer). `QuotationTemplateHarnessTest` loops over every
quotation template in the registry and enforces: valid PDF, under 400 KB, under 8 s, the facts every quotation must
show (number, buyer, totals, GST, installation, bank, seller GSTIN, terms), a bare quotation, a 45-line quotation
that flows across pages, IGST + discount, and markup injection. **A new template is covered by adding its registry
line — no new test needed.** Fixture: `Tests\Traits\BuildsUmaQuotation`.
Company settings that drive these templates (all optional, Settings → Company → *Quotation Document*):
`legal_name` ("pay to", "in favour of", signature block; blank = `name`), `quotation_title` (`PROFORMA INVOICE` or
`QUOTATION`; also changes "against {document}" and the footer note), `certification_line` (printed only when
set), and `quotation_terms` / `quotation_exclusions` (JSON lists; empty = the standard wording in
`config/quotation_terms.php`, which is also returned by `GET /api/company` so the screen can start from it).
Quotations also carry `delivery_address`, `site_contact_name`, `site_contact_phone` (optional; they may be
cleared on update). Bank details are `bank_name`, `bank_branch`, `bank_account_no`, `bank_ifsc`.
Plan for the further templates: [QUOTATION_TEMPLATES_PLAN.md](QUOTATION_TEMPLATES_PLAN.md).

### 19.8 Billing & the platform (super admin)

**Tenant side** (reachable even when expired): `GET /api/billing/status`,
`POST /api/billing/checkout` (Razorpay order), `POST /api/billing/verify` (signature verification).
Webhook `POST /api/webhooks/razorpay` (public).

`SubscriptionService::activate()` is idempotent on the payment `reference` (checkout verify and webhook can
both fire for one payment), stacks new months on remaining time, increments any coupon's `used_count`
exactly once, `forceFill`s the guarded subscription columns, and writes a `SubscriptionPayment` with a
GST-split (18 % inclusive) and a platform invoice number `PINV-YYYY-0001`.

**Platform side** — `/api/admin/*`, guarded by `super.admin` middleware **and** a `requireSuperAdmin()`
check in each method: `overview`, `companies`, `companies/{id}`, `activate`, `extend-trial`, `set-active`,
`expiring`, `revenue`, `funnel`, `impersonate`, `settings` (+ `test-razorpay`), `payments/{id}/invoice`,
`announcements` CRUD + toggle, `coupons` CRUD + toggle, `platform-admins`.

**Impersonation** stashes the super-admin token in `localStorage.token_superadmin` and shows a persistent
amber banner with "Exit to Platform Admin". Super admins see **only** the Platform Admin nav item — they
belong to no tenant factory.

Every authenticated user polls `GET /api/announcements` for the broadcast banner (dismissals are stored
per browser).

### 19.9 Mobile app

`mobile/` is an Expo React Native app against the same API, structured by feature:
`dashboard`, `production` (batch list/detail, QC), `dispatch` (list/detail, challan), `sales`
(sales home, quotation/order/invoice detail, receivables). Auth tokens go in `expo-secure-store`,
server state in TanStack Query, local state in Zustand. It is a **second client, not a second system** —
every rule in this document applies to it unchanged.

---

## 20. State machines (quick reference)

### Quotation / BOQ
```
boq ──convert──► draft ──send──► sent ──accept──► accepted ──create-order──► (Order)
                   │               │                  │
                   ├─reject────────┤                  └─revise──► revised (+ new draft -v2)
                   ├─expire────────┤
                   └─revise──────► revised
```
`draft` and `boq` are the only deletable states. Edits are allowed in `boq`, `draft`, `sent`.

### Order
```
pending ──run created──► in_production ──all batches dispatched──► completed
```

### Production run
```
draft ──start (issues material)──► in_progress ──complete (+actuals)──► completed
  └── cancel (returns orders to pending, deletes draft batches)
```

### Production batch
```
draft ──start──► in_progress ──complete──► qc_pending ──QC pass──► qc_passed ──dispatch complete──► dispatched
                                                   └──QC fail──► qc_failed (rework)
```

### Dispatch
```
pending ──complete──► delivered          (sets batch → dispatched)
   └────cancel─────► cancelled           (reverts batch → qc_passed)
```

### Invoice
```
draft ──send──► sent ──accept──► accepted ──full payment / mark-paid──► paid
  └──cancel──► cancelled        (sent can also be cancelled)
```

### Lead
```
new → contacted → qualified → quoted → won | lost
```

### Purchase order
```
ordered ──partial receipt──► partial ──full receipt──► received
   └── cancel (blocked once fully received)
```

---

## 21. Calculation reference

### 21.1 Suggested rate (advisory only)

```
base = panel_type.base_price  (falls back to 850 when it is null OR zero)
     + thicknessPremium[thickness]              // 30:0, 40:80, 50:160, 60:240, 75:320,
                                                // 80:360, 100:480, 120:600, 150:750, 200:1000
     + skinPremium[top_skin_thickness]          // 0.30:-50, 0.35:-25, 0.40:0, 0.45:+30, 0.50:+60, 0.60:+100
     + skinPremium[bottom_skin_thickness] × 0.5 // bottom contributes half
     + densityPremium[density_type]             // PUF 0, PIR +150
     + (top_surface == RIBBED ? 30 : 0)

rate = base × customerMultiplier[customer.type]   // retail 1.00, wholesale 0.95,
                                                  // distributor 0.90, corporate 1.00
            × qualityMultiplier[quality_grade]    // High 1.00, Medium 0.97, Standard 0.93

result = round(rate / 5) × 5                      // rounded to the nearest ₹5
```

Served by `POST /api/quotations/suggested-rate`, which accepts the spec fields either flat
(`thickness`, `top_skin_thickness`, `bottom_skin_thickness`, `density_type`, `top_surface`) or nested under
`row_data`. **Never applied automatically.**

### 21.2 Quotation totals

```
sqm(size)          = (length_mm / 1000) × 1.0 × nos     // width fixed at 1000 mm; generated column
item.amount        = Σ (size.sqm × size.rate_per_sqm)
item.rate_per_sqm  = item.amount / Σ size.sqm           // weighted average

panel_subtotal     = Σ item.amount
accessory_subtotal = Σ pivot.amount
installation_amount= Σ quotation_lines.amount   (installation, custom, typed-in doors)

subtotal        = panel_subtotal + accessory_subtotal + installation_amount
discount_amount = subtotal × discount_pct / 100
taxable_amount  = subtotal − discount_amount
gst_total       = taxable_amount × 0.18                 // ⚠ hard-coded 18%
  intra-state → cgst = sgst = gst_total / 2,  igst = 0
  inter-state → igst = gst_total,             cgst = sgst = 0

transport  = transport_fixed ? transport_amount : 0     // added AFTER tax, untaxed
raw_total  = taxable_amount + gst_total + transport
round_off  = round(raw_total) − raw_total
total_amount   = raw_total + round_off
advance_amount = total_amount × advance_pct / 100
balance_amount = total_amount − advance_amount
```

Inter-state detection runs through `App\Support\GstState::isInterState()`, shared with `TaxService` so a
quotation and its invoice can never disagree. Each side resolves to a 2-digit GST code from
`state_code` → `gstin` prefix → `state` name, accepting `24`, `GJ` or `Gujarat`. If either side is
unknown, the supply is treated as **intra-state**.

### 21.3 BOM / material requirement

Per `MaterialSetting` row (defaults in brackets):
`steel_density` [7.850] · `iso_polyol_ratio` [1.100] · `foam_overpack_pct` [5] ·
`wastage_coil_pct` [3] · `wastage_chemical_pct` [5] · `wastage_consumable_pct` [2] ·
`film_per_sqm` [1.000] · `tape_per_panel_m` [0].

```
area        = item.total_sqm  (fallback Σ (length/1000 × width/1000 × nos))

coil_kg     = area × top_skin_thickness × steel_density
            + area × bottom_skin_thickness × steel_density          → grouped per panel_type_id

foam_kg     = area × (thickness / 1000) × density_kgm3
polyol      = foam_kg / (1 + iso_polyol_ratio)
isocyanate  = foam_kg × iso_polyol_ratio / (1 + iso_polyol_ratio)

film_sqm    = area × film_per_sqm            (only when guard_film is true)
tape_m      = Σ nos × tape_per_panel_m

required(coil)       = coil_kg    × (1 + wastage_coil_pct/100)
required(chemical)   = chem       × (1 + foam_overpack_pct/100) × (1 + wastage_chemical_pct/100)
required(consumable) = cons       × (1 + wastage_consumable_pct/100)

short_by = max(0, required − available)      ok = available >= required
```

Mould oil / release agent is consumed per shift, not per panel, and is tracked separately.

### 21.4 Credit exposure

See §8 — `exposure = unpaid invoice balances + accepted-but-uninvoiced orders`.

### 21.5 Invoice tax

See §17.2 — driven by `TaxConfiguration.default_tax_rate` and `tax_type`, which defaults to the same
18 % the quotation uses. Change the rate and the two will diverge; that is the tenant's call.

### 21.6 Document numbering

| Document | Format | Example | Scope |
|---|---|---|---|
| Quotation / BOQ | `PREFIX-FY-NNN` | `SCP-2027-001` | Per company, per financial year (Apr–Mar) |
| Quotation revision | `<original>-vN` | `SCP-2027-001-v2` | Chained via `parent_quotation_id` |
| Order | `PREFIX-FY-NNN` | `ORD-2027-001` | Per company, per FY |
| Production run | `RUN-YYYY-NNNN` | `RUN-2026-0001` | Per company, calendar year |
| Batch | `PREFIX-YYYY-NNNNNN` | `BATCH-2026-000001` | Per company |
| Dispatch | `PREFIX…NNNNNN` | model `boot()` hook | Per company |
| Invoice | `PREFIX…NNNNNN` | model `boot()` hook | Per company |
| Purchase order | `PO-YYYY-NNNN` | `PO-2026-0001` | Per company |
| Lead | `LEAD-YYYY-NNNN` | `LEAD-2026-0001` | Per company |
| Platform invoice | `PINV-YYYY-NNNN` | `PINV-2026-0001` | Global (cross-tenant) |

Quotation, order and invoice creation are wrapped in `DocNumber::retry()` — up to 3 attempts on duplicate
key or deadlock, so two simultaneous creations never collide on a number. The sequence scan matches the
number's shape in PHP (a `LIKE` prefix query plus `preg_match`), not with SQL `REGEXP`, which exists only
on MySQL.

---

## 22. Guard rails & error catalogue

Hit one of these and the message tells you exactly which invariant you tripped.

| Message | Where | Why |
|---|---|---|
| `Rates are pending. Enter rates for all panel rows before sending.` | quotation send | An item still has `rate_per_sqm <= 0` |
| `Only draft quotations can be sent.` | quotation send | Wrong status |
| `Only a BOQ can be converted to a quotation.` | convert | Status ≠ `boq` |
| `Only BOQs, draft or sent quotations can be edited.` | quotation update | Locked after accept |
| `Quotation must be accepted to create an order.` | order create | Status ≠ `accepted` |
| `This quotation already has an order and can no longer be revised.` | quotation revise | Revising it would allow a second order for one job |
| `An order already exists for this quotation.` | order create | One order per quotation (row-locked) |
| `CREDIT_LIMIT_EXCEEDED` (422) | order create | Exposure + new order > `credit_limit`; admins can pass `override_credit_limit` |
| `No eligible pending orders to start.` | run create | Orders already `in_production` |
| `Insufficient stock: X (short N unit). Add stock or start with override.` | run/batch start | BOM shortage; retry with `force: true` |
| `Only a draft run can be started.` / `…be cancelled.` | run | Wrong status (lock-checked) |
| `Only a run in progress can be completed.` | run complete | Wrong status |
| `Cannot start production for batch not in draft status` | batch start | Wrong status |
| `Cannot start stage 'X' until 'Y' is completed` | stage start | Sequence enforcement |
| `Stage 'X' has already been started for this batch` | stage start | Duplicate stage log |
| `Batch must be in qc_pending status to enter QC` | QC create | Complete the batch first |
| `Can only approve QC entries with pass status. Failed QC requires rework.` | QC approve | Failed QC cannot be approved |
| `Batch must have an approved-pass QC record before dispatch.` | dispatch create | QC missing, failed, or unapproved |
| `Batch has already been dispatched` | dispatch create | One dispatch per batch |
| `An invoice already exists for this dispatch/order.` | invoice create | One active invoice per source |
| `Cannot record payment for draft invoice` | payment | Send the invoice first |
| `Payment amount exceeds remaining due amount of N` | payment | Over-payment blocked |
| `Cannot mark paid: ₹X is still outstanding.` | mark-paid | Record real payments instead |
| `Cannot receive N — only M outstanding on this PO line.` | GRN | Over-receipt blocked |
| `Cannot cancel a fully received PO.` | PO cancel | — |
| `Insufficient stock available. Available: N` | stock remove | Manual removal below zero |
| `Cannot add expired chemical` | chemical add | Expiry in the past |
| `An order can only be marked completed once all its production batches are dispatched.` | order update | — |
| `SUBSCRIPTION_INACTIVE` (402) | any route | Trial expired / tenant deactivated |

**Concurrency protections in place:** `lockForUpdate()` on quotation + customer (order creation), run,
batch, invoice (payments), PO and PO lines (receipt), and every stock row; `DocNumber::retry()` on
numbered-document creation; idempotency guards on material issue (`alreadyIssued`) and subscription
activation (payment `reference`).

---

## 23. End-to-end script (full happy path)

Every call needs `Authorization: Bearer <token>` and `Accept: application/json`.
Numbers in `{}` are ids returned by the previous step.

```bash
# ── 0. Sign up / log in ────────────────────────────────────────────────
POST /api/auth/register  { company_name, name, email, password, password_confirmation }
#   or: POST /api/auth/login { email, password }   → token
GET  /api/auth/me                                   # confirm permissions

# ── 1. Master data ─────────────────────────────────────────────────────
PUT  /api/company            { name, gstin:"27…", state_code:"MH", address_line1, city, state,
                               pincode, phone, email, bank_name, bank_account_no, bank_ifsc,
                               authorized_signatory }
POST /api/panel-types        { name:"PUF Wall Panel", code:"PUF-W", base_price:850,
                               width:1000, length:3000, hsn_code:"39259010" }          → {PT}
POST /api/accessories        { name:"Cam-lock set", unit:"NOS", unit_price:450 }       → {ACC}
POST /api/production-stages  { name:"Foaming",  sequence:1 }
POST /api/production-stages  { name:"Cutting",  sequence:2 }
POST /api/production-stages  { name:"Packing",  sequence:3 }
PUT  /api/settings/tax       { tax_type:"exclusive", default_tax_rate:18, is_active:true,
                               gst_number:"24AAFFU9050M1ZS" }   # optional: 18% exclusive is the default
POST /api/gst/register       { gstin:"24AAFFU9050M1ZS", state_code:"24",
                               registration_type:"regular" }    # GSTIN registration record, not the rate
PUT  /api/material-settings  { steel_density:7.85, iso_polyol_ratio:1.1,
                               foam_overpack_pct:5, wastage_coil_pct:3,
                               wastage_chemical_pct:5, film_per_sqm:1, tape_per_panel_m:0.5 }

# ── 2. Stock (must cover the BOM or production will not start) ─────────
POST /api/stock/coils        { panel_type_id:{PT}, quantity_in_stock:20000,
                               reorder_level:5000, unit_cost:62 }
POST /api/stock/chemicals    { name:"Polyol",           category:"Polyol",
                               quantity_in_stock:5000, unit:"kg", reorder_level:1000, unit_cost:142 }
POST /api/stock/chemicals    { name:"Isocyanate (MDI)", category:"Isocyanate",
                               quantity_in_stock:5000, unit:"kg", reorder_level:1000, unit_cost:155 }
POST /api/stock/consumables  { name:"Protective Film",  category:"film",
                               quantity_in_stock:3000, unit:"sqm", reorder_level:500, unit_cost:12 }
POST /api/stock/consumables  { name:"Sealant Tape",     category:"tape",
                               quantity_in_stock:2000, unit:"m",   reorder_level:300, unit_cost:8 }

# ── 3. CRM ─────────────────────────────────────────────────────────────
POST /api/leads              { contact_name, company_name, phone, city, source:"website" }  → {L}
#   source is matched case-insensitively against
#   Website|Phone|WhatsApp|Referral|IndiaMART|Justdial|Exhibition|Walk-in|Other
POST /api/leads/{L}/activities { type:"call", description:"Discussed cold room spec" }
POST /api/leads/{L}/convert                                               → {CUST}
#   or directly:
POST /api/customers          { name, code, type:"retail", phone, city, state:"Maharashtra",
                               state_code:"27", credit_limit:5000000, payment_terms_days:30 }
#   state_code accepts "27", "MH" or "Maharashtra" — all stored as "27"
#   credit_limit / payment_terms_days are persisted (they used to be ignored)

# ── 4. BOQ ─────────────────────────────────────────────────────────────
POST /api/quotations         { as_boq:true, customer_id:{CUST}, lead_id:{L}, project_name,
                               panel_rows:[ … see §9.2 … ] }             → {BOQ}
GET  /api/quotations?status=boq                       # BOQ register
GET  /api/quotations/{BOQ}/boq-pdf                    # rate-less worker cutting sheet
POST /api/quotations/{BOQ}/convert                    # boq → draft

# ── 5. Price and send ──────────────────────────────────────────────────
POST /api/quotations/suggested-rate  { panel_type_id:{PT}, row_data:{…},
                                       quality_grade:"High", customer_id:{CUST} }
GET  /api/quotations/{BOQ}                            # read quotation_item_size ids
POST /api/quotations/{BOQ}/rates     { rates:[ {id:<sizeId>, rate_per_sqm:1450}, … ] }
POST /api/quotations/{BOQ}/send                       # draft → sent   (fails if any rate = 0)
GET  /api/quotations/{BOQ}/pdf                        # customer quotation
POST /api/quotations/{BOQ}/accept                     # sent → accepted

# ── 6. Order ───────────────────────────────────────────────────────────
GET  /api/customers/{CUST}/credit-status
POST /api/quotations/{BOQ}/create-order  { override_credit_limit:false }  → {ORD}
GET  /api/orders/{ORD}

# ── 7. Plan and run production ─────────────────────────────────────────
GET  /api/production/planning                         # groups + "take this first" alerts
POST /api/production/runs    { order_ids:[{ORD}], label:"COLD ROOM · 100mm · PUF 40kg" }  → {RUN}
GET  /api/production/runs/{RUN}/material-requirement   # check all_ok BEFORE starting
GET  /api/production/runs/{RUN}/po-suggestion          # only if something is short
POST /api/production/runs/{RUN}/start  { force:false }  # ISSUES STOCK, orders→in_production
GET  /api/production/runs/{RUN}                        # child batch ids               → {BATCH}

# ── 8. Shop floor ──────────────────────────────────────────────────────
GET  /api/batches/{BATCH}/cutting-schedule
GET  /api/production-stages                                                  → {S1},{S2},{S3}
POST /api/batches/{BATCH}/stages/{S1}/start
POST /api/batches/{BATCH}/stages/{S1}/complete  { notes:"Foaming done" }
POST /api/batches/{BATCH}/stages/{S1}/attachments   (multipart: photo, type=stage_photo)
#   …repeat for S2, S3 in sequence…
GET  /api/batches/{BATCH}/progress

# ── 9. Complete the run with real consumption ──────────────────────────
GET  /api/production/runs/{RUN}/material-usage                               → usage ids
POST /api/production/runs/{RUN}/complete  { actuals:[ {id:<usageId>, actual_qty:11800} ] }
GET  /api/production/wastage-report?from=2026-10-01&to=2026-10-31

# ── 10. QC ─────────────────────────────────────────────────────────────
POST /api/batches/{BATCH}/qc  { status:"pass", notes:"OK",
                                checklist:[ {key:"thickness", label:"±1mm", passed:true} ] }  → {QC}
POST /api/quality-control/{QC}/approve  { notes:"Approved for dispatch" }
GET  /api/quality-control/statistics

# ── 11. Dispatch ───────────────────────────────────────────────────────
POST /api/batches/{BATCH}/dispatch  { customer_address:"…", expected_delivery_date:"2026-11-02",
                                      tracking_number:"MH12AB1234" }        → {DISP}
GET  /api/dispatches/{DISP}/challan/pdf
POST /api/dispatches/{DISP}/attachments   (multipart: photo, type=delivery_proof)
POST /api/dispatches/{DISP}/complete  { actual_delivery_date:"2026-11-02" }  # batch → dispatched

# ── 12. Invoice ────────────────────────────────────────────────────────
POST /api/invoices/from-dispatch  { dispatch_id:{DISP}, due_date:"2026-12-02" }  → {INV}
GET  /api/invoices/{INV}/gst-breakdown
POST /api/invoices/{INV}/send
GET  /api/invoices/{INV}/pdf
POST /api/invoices/{INV}/einvoice/irn/manual  { irn:"…", irn_ack_no:"…", irn_qr:"…" }   # optional
POST /api/invoices/{INV}/send-email

# ── 13. Collect ────────────────────────────────────────────────────────
GET  /api/invoices/{INV}/payment-status
POST /api/payments/record  { invoice_id:{INV}, amount:<part>, payment_method:"bank_transfer",
                             reference_no:"UTR123" }
POST /api/invoices/{INV}/send-payment-reminder
POST /api/payments/record  { invoice_id:{INV}, amount:<balance> }    # → invoice auto-flips to paid
PUT  /api/orders/{ORD}  { status:"completed" }                       # all batches dispatched

# ── 14. Replenish ──────────────────────────────────────────────────────
GET  /api/stock/alerts
GET  /api/procurement/reorder-suggestion
POST /api/suppliers        { name:"Chem Supplies Pvt Ltd", gstin, phone }          → {SUP}
POST /api/purchase-orders  { supplier_id:{SUP}, tax_pct:18,
                             items:[ {material_kind:"chemical", stock_id:…, item_name:"Polyol",
                                      unit:"kg", quantity:2000, rate:142} ] }      → {PO}
POST /api/purchase-orders/{PO}/receive
                           { receipts:[ {po_item_id:…, received_qty:2000, cost:143,
                                         batch_no:"PL-2210", expiry_date:"2027-04-30"} ] }
GET  /api/procurement/valuation

# ── 15. Verify the books ───────────────────────────────────────────────
GET  /api/dashboard
GET  /api/reports/accounts-receivable
GET  /api/reports/reconciliation          # ordered vs invoiced vs paid, per order
GET  /api/reports/mis?from=2026-10-01&to=2026-10-31
GET  /api/reports/tax
GET  /api/audit-logs
```

**Invariants to assert along the way**

1. `BOQ total_sqm` = Σ `(length_mm / 1000) × nos` across all size rows.
2. After `/rates`, each item's `rate_per_sqm` equals its weighted average, and `total_amount` matches §21.2.
3. Order items are a byte-for-byte spec snapshot of the quotation items.
4. After run start: every coil/chemical/consumable on-hand drops by exactly the BOM `required` quantity
   (or to 0 under `force`), and one `stock_transactions` row exists per deduction.
5. Dispatch is impossible until an **approved pass** QC exists.
6. Invoice `total_amount = subtotal + tax_amount`, `tax` is 18% by default, and it splits CGST+SGST
   (same state) or IGST. The invoice subtotal includes the order's accessories and installation, and each
   panel line's amount equals the order item's amount to the paisa.
7. Full payment auto-flips the invoice to `paid`; `remaining_due` reaches 0.
8. `GET /api/reports/reconciliation` shows ordered = invoiced = paid for the completed order.

---

## 24. Sharp edges & known gaps

Read this before concluding something is broken — these are current, deliberate or known characteristics.
A full end-to-end audit on 2026-10-23 (`PANELOS_E2E_BUG_REPORT.md`) closed the items that used to sit here
as live defects; what is listed below is what is still true of the code today.

### Design decisions that surprise people

1. **Panel width is always 1000 mm.** Hard-coded in `QuotationService::saveItems` and in the UI.
   Non-standard widths are not modelled.
2. **`quotation_item_sizes.sqm` is a generated column.** Never write it; replication code explicitly
   excludes it.
3. **"Rate pending" is `0`, not null.** Any size row left at 0 blocks sending.
4. **BOQ and quotation share one table.** The list endpoint hides `status='boq'` unless asked for it.
5. **One dispatch per batch, one active invoice per dispatch/order.** Partial dispatch of a batch is not
   modelled; a dispatch copies **all** of the batch's order items.
6. **Dispatch no longer touches stock.** Raw material is consumed at production start; the
   `/dispatches/{id}/allocate` endpoint is a kept-for-compatibility no-op.
7. **Super admins see no tenant data.** The platform owner belongs to no factory; use impersonation.
8. **Deleted records are soft-deleted everywhere**, and numbering scans include trashed rows (runs, POs,
   leads) so sequences never reuse a number.
9. **`batch.planned_quantity` is square metres**, not a panel count — consistently so across the API, the
   web UI ("SQM planned") and the mobile app. The cutting schedule's `total_items` is the panel count.

### Real inconsistencies that remain

10. **Fixed transport is never invoiced.** The quotation adds `transport_amount` *after* tax and leaves it
    untaxed (§21.2); a per-line invoice cannot express an untaxed line today, because `TaxService` applies
    one rate across the whole subtotal. So `InvoiceService::addNonPanelLines()` deliberately skips it and
    an order with fixed transport invoices short by that amount. Needs a business decision on whether
    transport should be taxed; once decided, the fix is one line plus a quotation-side change to match.
11. **Quotation GST is hard-coded at 18%** in `QuotationService::recalculate()`, while invoices read
    `TaxConfiguration.default_tax_rate`. They agree out of the box (both 18%) but will diverge the moment
    a tenant changes the invoice rate.
12. **`customers.outstanding_balance` is a stale column.** Everything real is computed live by
    `CreditService`. Do not read or trust that column.
13. **`costing.view` is defined but not enforced** by the permission middleware.
14. **Module-level RBAC only.** The middleware cannot distinguish sub-resources inside one module — any
    write under `/invoices` needs `invoices.manage`, with no finer grain.
15. **Mould oil / release agent is not in the BOM** (consumed per shift, tracked manually).
16. **`GstTaxBreakdown` is only populated by an explicit `/calculate-gst` call.** `/gst-breakdown` falls
    back to the invoice's `TaxCalculation`, so the two can describe the same invoice by different routes.

### Not finished yet

17. **Live GSP e-invoicing is stubbed.** IRN/e-way generation returns "not enabled" until the GSP env vars
    are set; manual entry is the shipped path.
18. *(Fixed 2026-10-23: `routes/console.php` now schedules reminders, SMS reminders, backups, token pruning and notification scans.)* **No scheduled jobs are registered.** `routes/console.php` contains only the stock `inspire` command, so
    reminder ladders, low-stock sweeps (`checkLowStock`) and forecast refreshes must currently be triggered
    by API calls, not cron.
19. **The dashboard is cached for 60 s per company** — a freshly created record may not appear in KPIs
    immediately. This is expected, not a bug.
20. **`StockService::getStockLevel/getStockHistory` query legacy columns** (`coil_id` / `chemical_id`) that
    the current tables do not use. The live code paths go through the controller endpoints instead.
21. **Generated PDFs are ~1 MB each** — mostly fonts, not the logo. DomPDF defaults font subsetting to
    on, but the Laravel wrapper's bundled config turns it off and the app never published its own, so two
    whole DejaVu files (717 KB) ride in every PDF; the logo adds a further 173 KB because it is stored at
    3381×1085 px and shown at 200×46. Correct output, just heavy. See PB-022 in `BUGS.md` for the measured
    breakdown and the two-line fix (97% smaller).
22. **The test suite is fully green** (546 of 546 on 2026-10-24, up from 191 failing). The CI gate
    (`backend/tests/ci/known-failures.txt`, now empty) blocks any new failure. The suite runs against
    **MySQL/MariaDB** (`phpunit.xml` forces `DB_DATABASE=panelos_test`), matching production — see `CLAUDE.md`.

### Fixed on 2026-10-23 (do not re-report)

| Was | Now |
|---|---|
| Every invoice carried ₹0 GST — nothing created the `TaxConfiguration` that `TaxService` reads | Auto-provisioned at 18% exclusive; settable via `PUT /api/settings/tax` and the Master Data → Tax / GST tab |
| Accessories, doors and installation never reached an invoice | Billed as non-panel invoice lines, once per order |
| `dispatch_items.quantity` / `invoice_items.quantity` were INTEGER, so 312.93 m² billed as 313 | `decimal(12,4)`; line amounts copied from the order item rather than recomputed |
| A standalone batch left its order `pending`, so the planner re-offered produced work | Any batch claims the order (`in_production`); planner and run creation also skip already-batched orders |
| Customer/company/supplier/user writes returned an empty 500 | `app/Rules/*` implemented a contract they didn't satisfy — a PHP fatal. Rewritten against `ValidationRule` |
| `credit_limit` / `payment_terms_days` were hard-coded on customer create | Persisted, and editable in the UI |
| `state_code` held `24`, `GJ` and `Gujarat` interchangeably, so GST head depended on typing | `App\Support\GstState` normalises to the 2-digit GST code; one shared inter-state helper; UI is a dropdown |
| Cutting schedule read `quantity` (m²) as a piece count and overstated material ~6× | Walks the order's size rows with the DL pairing rule |
| Suggested rate returned ₹160 (zero `base_price` skipped the 850 fallback) | Falls back on zero too; accepts flat or `row_data` payloads; bottom skin included |
| Duplicate `create-order` returned a generic 500 | 400 with the real domain message |
| An accepted quotation with an order could still be revised | Blocked |
| Removing every installation row left the old amount in the total | Always rewritten, including to 0 |
| Editing a quotation in the form reset installation to 0; typed-in doors and custom accessories were dropped | Stored as rows and round-tripped by the form (PB-045) |
| `remaining_due` leaked floats like `64000.119999999995` | Rounded to 2 dp |
| Lead `source` was case-sensitive; lead validation returned a generic message | Case-insensitive; first field error surfaced |
| `/gst-breakdown` 404'd; GST config stored `state_name: "Unknown"` | Falls back to `TaxCalculation`; state names resolve from either format |
| Document numbering used MySQL-only `REGEXP` | Shape matched in PHP, so the path runs on any driver |


## 25. File map

### Backend

| Area | Service | Controller | Key models |
|---|---|---|---|
| Auth / tenants | `TenantProvisioningService` | `AuthController` | `User`, `Company`, `Role` |
| Leads | `LeadService` | `LeadController` | `Lead`, `LeadActivity` |
| Customers | `CustomerProfileService`, `CreditService` | `CustomerController` | `Customer` |
| BOQ / Quotations | `QuotationService`, `QuotationPdfService`, `AccessoryService` | `QuotationController`, `AccessoryController` | `Quotation`, `QuotationItem`, `QuotationItemSize`, `Accessory` |
| Orders | `OrderService` | `OrderController` | `Order`, `OrderItem`, `OrderItemSize` |
| Planning | `ProductionPlanningService` | `ProductionPlanningController` | `Order` |
| Runs & material | `ProductionRunService`, `ProductionMaterialService`, `MaterialBomService` | `ProductionRunController` | `ProductionRun`, `ProductionMaterialUsage`, `MaterialSetting` |
| Batches & stages | `ProductionBatchService`, `BatchStageLogService`, `ProductionStageService`, `CuttingScheduleService` | `ProductionBatchController`, `BatchStageLogController`, `ProductionStageController`, `CuttingScheduleController` | `ProductionBatch`, `BatchStageLog`, `ProductionStage` |
| QC | `QualityControlService` | `QualityControlController` | `QualityControl`, `Attachment` |
| Dispatch | `DispatchService`, `DispatchPdfService` | `DispatchController` | `Dispatch`, `DispatchItem` |
| Invoicing | `InvoiceService`, `TaxService`, `GstService`, `EInvoiceService`, `EmailService` | `InvoiceController`, `GstController`, `EInvoiceController` | `Invoice`, `InvoiceItem`, `TaxCalculation`, `TaxConfiguration`, `GstConfiguration`, `HsnCode` |
| Payments | `PaymentService`, `PaymentGatewayService`, `PaymentReminderService` | `PaymentController` | `PaymentTransaction`, `PaymentReminder` |
| Inventory | `StockService`, `StockDashboardService` | `StockController` | `CoilStock`, `ChemicalStock`, `ConsumableStock`, `StockTransaction`, `LowStockAlert` |
| Procurement | `ProcurementService` | `ProcurementController` | `Supplier`, `PurchaseOrder`, `PurchaseOrderItem` |
| Reports | `ReportingService`, `AnalyticsService`, `ForecastingService`, `MlForecastingService` | `ReportingController`, `AnalyticsController`, `ForecastingController`, `ExportController` | `SalesMetric`, `DemandForecast`, `TrendAnalysis`, `AnalyticsSnapshot` |
| Dashboard | `DashboardService` | `DashboardController` | — |
| Notifications | `NotificationDispatchService`, `SmsService`, `TwilioStreamClient` | `SmsController`, `NotificationSettingsController` | `NotificationSetting`, `NotificationLog`, `SmsLog` |
| Templates & audit | `DocumentTemplateService` | `DocumentTemplateController`, `AuditLogController` | `CompanyDocumentTemplate`, `AuditLog` |
| Billing & platform | `SubscriptionService`, `RazorpayClient` | `BillingController`, `SuperAdminController` | `SubscriptionPayment`, `PlatformSetting`, `PlatformAnnouncement`, `Coupon` |

Cross-cutting: `app/Http/Middleware/{EnsureActiveTenant, EnforceModulePermissions, EnsureSuperAdmin}.php` ·
`app/Models/Concerns/BelongsToTenant.php` · `app/Traits/{ApiResponse, Auditable}.php` ·
`app/Support/DocNumber.php` · `app/Support/GstState.php` · `config/permissions.php` · `config/document_templates.php` ·
`routes/api.php` (the authoritative route list) · `resources/views/{quotations,invoices,dispatches,emails,billing}`.

### Frontend (`frontend/src/components/`)

| Module | Components |
|---|---|
| Shell | `App.vue`, `AppShell.vue`, `Landing.vue`, `LoginScreen.vue`, `SubscriptionGate.vue`, `UiHost.vue` |
| CRM | `LeadManager`, `CustomerManager`, `CustomerProfile`, `CustomerEditModal` |
| Sales | `QuotationManager` → `QuotationList` / `QuotationCreate` / `QuotationDetail`; `BoqManager` → `BoqRegister`; `OrderManager` → `OrderList` / `OrderDetail` |
| Production | `BatchManager` → `BatchList` / `BatchCreate` / `BatchDetail`; `ProductionPlanner`, `ProductionRuns`, `QcDashboard` |
| Inventory | `StockManager` → `CoilStock` / `ChemicalStock` / `ConsumableStock` / `TransactionLog`; `ProcurementManager` |
| Finance | `DispatchManager` → `DispatchList` / `DispatchCreate` / `DispatchDetail`; `InvoiceManager` → `InvoiceList` / `InvoiceCreate` / `InvoiceDetail`; `AccountsReceivable`, `PaymentTracker`, `PaymentReminderManager`, `StripePaymentForm`, `BusinessReports` |
| Settings | `CompanySettings`, `MasterDataManager` (`PanelTypeMaster`, `AccessoryMaster`, `ProductionStageMaster`, `GstConfigManager`, `TaxConfiguration`), `NotificationSettings`, `BillingSettings`, `DocumentTemplates`, `UserManagement`, `AuditLog`, `SuperAdmin`, `UserGuide` |

### Mobile (`mobile/src/`)

`api/` · `auth/` · `design-system/` · `navigation/` ·
`features/{dashboard, production, dispatch, sales}/` (each with screens, `hooks.ts`, `statusMeta.ts`).

---

*Generated from the codebase on 2026-10-23, and revised the same day after the end-to-end audit in
`PANELOS_E2E_BUG_REPORT.md` and the fixes it produced. Regenerate after any change to a service's state
transitions, the permission map, or the BOM formulas — those three are what this document is load-bearing
for.*
