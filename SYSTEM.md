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
manager component at a time. Navigation is the `active` ref, mirrored into the address bar (UX-01, PB-060) with no
router dependency: `src/services/routes.js` maps a section key to `/<key>` (dashboard = `/`) and, for quotations,
orders, batches and dispatches, a record to `/<key>/<id>`. `go('orders', 42)` sets `:open-id="42"` *and* pushes
`/orders/42`; a manager that opens a record from its own list emits `detail` so the address follows; `popstate`
(Back/Forward) and first load go through `applyUrl()`, which remounts the screen so it reads the address afresh.
Unknown paths, bad ids and screens the user may not open fall back to the dashboard.
**Header search (UX-04, PB-061):** `GlobalSearch.vue` calls `GET /api/search?q=` (debounced 250 ms, stale answers dropped) and also matches screen names for "Go to"; the server side is `GlobalSearchService` (quotations, orders, invoices, customers, batches, dispatches; 5 per kind; each kind needs its own `*.view` permission; always filtered by `company_id`). The frontend nginx already answers
every unknown path with `index.html`. Invoices and customers have record addresses too; leads and BOQ records do not yet.

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

**Health & alerts** (EN-03/EN-04) — `GET /api/health` returns `{status OK|DEGRADED, database, scheduler OK|STALE|UNKNOWN, errors_last_hour, failed_jobs}`; 503 when the database is down, the scheduler has not beaten for 5 minutes (`health-heartbeat` task, every minute, in `routes/console.php`) or there were 25+ server errors this hour. `.github/workflows/uptime.yml` probes `/up` and `/api/health` every 10 minutes from GitHub and fails (GitHub e-mails the owner) after 3 tries. Every real server error (the exception `report` hook in `bootstrap/app.php`: never 401/403/404/422) is counted and `AlertService` e-mails the operator, at most once per 15 minutes, only when the platform switch is on and the mailer can deliver. `/api/health` also reports `backup` (`db:backup` records its last success; STALE after 36 h = 503). Anything run with `docker exec` must use `-u panelos`: root-owned files in the `storage` volume stop the scheduler and the web workers from writing (PB-042 logs, PB-062 cache locks); the entrypoint re-owns `storage` on every start. Recipient: `platform_settings.alert_email`, else every super admin's e-mail. Super Admin → Settings → *Server alerts* has the on/off switch, the address, live health and *Send test alert*. The alert path never throws.

**Backups** (EN-05) — `db:backup` (scheduler, 01:30) keeps 14 dumps in the `storage` volume on the server. `.github/workflows/offsite-backup.yml` (02:45 UTC) is the off-server copy: fresh dump over SSH → restore check in a throwaway MySQL 8 → AES-256 gpg with repo secret `BACKUP_PASSPHRASE` → artifact kept 30 days. Restore: `gpg --decrypt panelos-YYYYMMDD.sql.gz.gpg | gunzip | mysql …`. The passphrase must be stored outside GitHub.

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
| `leads.export` | `GET /api/leads/export` | `reports.view` (a whole contact list in one file, like `/export`) |
| `leads.views.store`, `leads.views.destroy` | `POST /api/leads/views`, `DELETE /api/leads/views/{id}` | `leads.view` (a saved view changes no lead) |

Admins (`is_company_admin`, `is_super_admin`) and roles holding `*` bypass all checks
(`User::hasPermission`).

**`leads.own_only` is a restriction, not a permission.** A role that carries it sees and works on only the leads assigned to the person (`User::ownLeadsOnly()`, which reads the role's own list and is false for admins and `*` roles; `hasPermission` cannot be used for it because it answers yes to everything for them). `LeadService::visible()` is the one place that applies it: the list and its status counts, the insights, the export, the header search, and every lead action look leads up through it, so another person's lead (or an unassigned one) is a plain 404. A restricted person's new lead is assigned to them unless they choose someone else; assigning a lead away hands it over. It is ticked per role in Users & Roles like any other key.

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
**Audit log** on 20 models (EN-06 added roles, users, company, stock and its movements, dispatches, accessories, tax rate).

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

**Statuses (LEADS_PLAN 1.2).** Open: `new`, `contacted`, `qualified`, `quoted`. Waiting: `on_hold`. Closed: `won`, `lost`, `invalid` (`Lead::OPEN`, `Lead::CLOSED`, `Lead::STATUSES`).
Every change goes through `LeadService::transition()` (`POST /api/leads/{id}/status`; `changeStatus()` is a wrapper). The allowed moves live in one table, `Lead::allowedNext()`,
and `GET /api/leads/options` returns it as `transitions` so the screen offers exactly those buttons:
an open lead can move to any other open stage, `on_hold`, `won`, `lost` or `invalid`; a lead on hold can go back to any open stage or be won, lost or invalid; a closed lead can only be
**reopened** to an open stage, and the request must carry a `reason`, which is written to the timeline and clears the lost reason and note. Won to lost goes through a reopen.
`on_hold` needs `hold_until` (today or later), which becomes `next_follow_up_date`, so the follow-up badge brings the lead back. `invalid` is junk (spam, wrong number): kept for the record,
left out of the default list (its own tab shows it), the dashboard totals, win rate, source chart and follow-up counts. Moving to the status a lead already has changes nothing and logs nothing.
`PUT /api/leads/{id}` refuses a different `status` (422). A new lead may start `new`, `contacted` or `qualified`. A quotation made for a lead moves an open or on-hold lead to `quoted` and links it;
for a won, lost or invalid lead it only adds a timeline note and leaves the status and the original quotation alone.

**Won (LEADS_PLAN 1.3).** A lead is **won when an order is created from its quotation**, not when the quotation is accepted. `OrderService::createFromQuotation` calls
`LeadService::markWonFromOrder()` after the order exists (inside its own try/catch: a lead problem is reported and never stops the order). The lead is found through `leads.quotation_id`, following
`parent_quotation_id` back from the ordered quotation, so an order from a revision (v2, v3) still wins the lead. An open or on-hold lead becomes `won` and the timeline reads
`Status: quoted → won (order ORD-... created)`; a lead that is already won is left alone; a lost or invalid lead is **not** reopened, it gets a note. Known limit: only the lead's latest quotation (plus its
revisions) is linked, so ordering an older, separate quotation does not win the lead. `php artisan leads:backfill-won [--company=ID] [--apply]` catches up leads whose order already exists: it lists
them and changes nothing unless `--apply` is given; closed leads are listed as left alone. Marking a lead lost on the screen is a dialog with the company's lost-reason list (a reason is required there)
and a note; the API still accepts a free-text `lost_reason`.

**IndiaMART connection (LEADS_PLAN 3.1).** `lead_connectors` holds one row per company and outside channel (`type`, currently `indiamart`): `credentials` (the CRM key, `encrypted:array`, hidden from every API and from JSON), `key_hint` (last 4 characters),
`last_test_*`, `last_request_at`, and for the pull job (3.2) `is_enabled`, `cursor_at`, `last_success_at`, `last_error*`. `IndiaMartClient` is the only code that talks to IndiaMART, from IndiaMART's own help page (read 26 Oct 2026):
`GET https://mapi.indiamart.com/wservce/crm/crmListing/v2/?glusr_crm_key=&start_time=&end_time=`, times in **IST** as `DD-MM-YYYYHH:MM:SS` (the page also shows month-name examples; to be confirmed with a real key), **at most 7 days** between start and end, data for 365 days,
no times = last 24 hours, **at least 5 minutes between requests** (more than 5 in a minute blocks the key for 15 minutes; they recommend polling every 10 to 15 minutes with a 5-minute overlap and de-duplicating on `UNIQUE_QUERY_ID`), a key **expires after 7 days without use** and a new key cancels the old one;
answer `{CODE, STATUS, MESSAGE, TOTAL_RECORDS, RESPONSE:[...]}`, rows with `UNIQUE_QUERY_ID`, `QUERY_TYPE` (W direct, B buy-lead, P call, BIZ catalog, WA WhatsApp), `QUERY_TIME`, `SENDER_NAME` (default "IndiaMART Buyer"), `SENDER_MOBILE` and/or `SENDER_EMAIL` (one is always there),
`SENDER_COUNTRY_ISO`, `QUERY_MESSAGE`; the names for company, city, state and product are not in the readable part of the page. Failures become `IndiaMartException` with a fixed, readable sentence by kind (`bad_key`, `rate_limited`, `unreachable`, `rejected`, `unreadable`); the wording is matched
on the words IndiaMART is known to use, only on an answer that is not a success, and is confirmed against a real account in 3.4. The key travels in the query string (their design), so **no exception text, log line or message may carry it**: connection errors are replaced by fixed sentences and an unknown refusal has the key blanked.
`LeadConnectorService`: `saveIndiaMart` (blank keeps the saved key, `clear_key` removes it and turns the connector off, a different key forgets the old test result, shape check 8 to 200 characters of letters, digits and `._-=+/`), `testIndiaMart` (needs a key; refused with a wait message if the last request to IndiaMART was under 5 minutes ago, including a failed one; looks at the last 6 hours; counts; imports nothing; saves the outcome).
Screens: Master Data -> Lead intake -> *IndiaMART*.

**IndiaMART pull (LEADS_PLAN 3.2).** `leads:pull-indiamart` runs every 10 minutes (`routes/console.php`, `withoutOverlapping(9)`) for every active company whose connector `is_enabled` and has a key; each company is isolated (an exception is reported and the loop carries on).
`IndiaMartPullService::pullSinceLast`: window = `cursor_at` minus **5 minutes** (the overlap) to now, or the last 24 hours the first time, never longer than 7 days (cut to the most recent 7); skipped (nothing is called) when `last_request_at` is under 5 minutes old, or the per-company cache lock `lead-pull:indiamart:{company}` is held;
`last_request_at` is set before the call (IndiaMART counts a failed one too). Each row goes through `IndiaMartMapper::map` and `LeadIntakeService::ingest(company, 'IndiaMART', mapped, rawRow)`, so a repeat enquiry (the overlap) is `already_received`, a known buyer gets an "Enquired again" note, a new buyer a lead (assignment and the bell follow the 2.4 rules).
On success `cursor_at` = the end of the window, `last_success_at` is set and `last_error` cleared; on failure `last_error(_at)` is set, the cursor stays, and a `pull_error` row goes in `lead_import_logs`. Log rows are written for created, merged, rejected and error enquiries only (repeats would drown the log). The mapper reads `UNIQUE_QUERY_ID` (external id),
`SENDER_NAME` (their placeholder "IndiaMART Buyer" is dropped), `SENDER_MOBILE`/`_ALT`, `SENDER_EMAIL`/`_ALT`, company / city / product under several likely names, `QUERY_MESSAGE`, `QUERY_TYPE` (W, B, P, BIZ, WA -> the lead's *campaign*), `QUERY_TIME` (Indian time; ISO, `10-Nov-2026 13:50:00`, `10-11-202613:50:00` and epoch accepted, unreadable -> now).
`PUT /api/settings/lead-connectors/indiamart {is_enabled}` switches it (needs a key; `clear_key` switches it off). `POST .../indiamart/pull {from, to}` (dates; 6 a minute per person by the `lead-pull` limiter) pulls whole Indian days by hand: at most 7 days, not in the future, within 365 days, refused if IndiaMART was asked under 5 minutes ago, and it does **not** move the automatic cursor.
Not yet seen with a real key: the accepted time format, the company / city / product field names, the exact error wording (LEADS_PLAN 3.4).

**IndiaMART link health (LEADS_PLAN 3.3).** `LeadConnectorHealth::assess($connector)` returns `{state, severity, title, body}`; worst first:
`key_rejected` (switched on and the last failed pull was `last_error_kind = bad_key`; danger) -> `stale` (switched on, and no `last_success_at` for 60 minutes, counted from `enabled_at` if it never succeeded; danger, names the last good pull and the last problem) ->
`idle_soon` (a saved key that is switched off, or never used, and whose last real use is 5 or more days old; warning with the date IndiaMART stops it, 7 days after last use; a stale one adds the same sentence) -> `waiting` / `ok` / `off` / `not_set_up` (no alert).
"Use" = the last successful pull or a successful test; a key never used counts from `key_saved_at`. One unreachable blip is not an alert: only a refused key, or an hour without a success, is.
`LeadConnectorHealth::sync($companyId)` runs inside `notifications:scan` (hourly, its own try/catch) and keeps a single standing bell entry (`lead_connector_health`, dedupe key `indiamart`, link to Master Data) for the company's active admins; it carries no "N hours ago", so it turns unread only when the situation changes, and it is removed when the link recovers.
`LeadConnector::summary()` now includes `health`; the IndiaMART card shows it as a banner. `/api/health` gets `lead_pull`: `NONE` (no switched-on connector), `OK`, or `STALE` (a switched-on connector of an active company has not been asked in 60 minutes, which means the job itself is not running; `status` becomes `DEGRADED`/503).
A refused key alone never degrades `/api/health` (the job is running; the fix belongs to that company). Columns added to `lead_connectors`: `last_error_kind` (bad_key, rate_limited, unreachable, rejected, unreadable), `enabled_at` (set when switched on, cleared when off or the key is removed), `key_saved_at` (set when a different key is saved).

**Assignment and the instant alert (LEADS_PLAN 2.4).** `lead_assignment_rules` (`LeadAssignmentRule`): `name`, `priority` (lowest first), `is_active`, optional `source` / `city` / `application` (case-insensitive equality; empty matches anything, so a rule with no
conditions is the catch-all), `user_ids` (JSON list, the pool, in the order they take turns) and `last_user_id` (whose turn was last; reset when the list is edited). **A company has no rules by default and its leads stay unassigned.**
`LeadService::create` decides: an `assigned_to_user_id` given -> that person (timeline "Assigned to X (by hand)", rules and the rotation untouched); none given -> `LeadAssignmentService::assignByRules` inside the creating transaction, which locks the rules so two leads
arriving together take different turns: first active matching rule, the next *able* person after `last_user_id` (active, same company) wrapping round; if nobody in the rule is able, the next rule is tried; no match leaves it unassigned. Timeline text: "Assigned to X (rule: <name>)",
"Assigned to X (by hand)", "Reassigned: A -> B (by <user>)", "Unassigned (was A, by <user>)"; nothing is written when the person did not change. `LeadService::update` records reassignments the same way. The **bell** (`lead_assigned` notifications, link to Leads) goes to the **new
assignee only**: not the previous one, not anyone else, not the person who made the change themselves; it names the lead number, buyer, source, city, application, a piece of the requirement and the phone. A repeat enquiry that merges into an assigned lead (`LeadIntakeService`)
rings its assignee ("Enquired again"); an unassigned lead has nobody to ring. Rules are managed at `GET|POST /api/settings/lead-assignment-rules`, `PUT|DELETE .../{id}` (reads open, changes `settings.manage`; people must be active users of the company, a source must be one of the company's sources; new rules go last).
WhatsApp alerts are not built. The screen is Master Data -> Lead intake -> "Who gets a new lead" (`LeadAssignmentSettings.vue`): rules in priority order with up/down buttons (they rewrite the priorities 1..n), conditions, the people in turn, on/off, edit, delete.

**Inbound lead address (LEADS_PLAN 2.3).** `POST /api/inbound/leads/{token}` lets a website form, a partner or Zapier create leads with no login. Public route, no tenant middleware: the **token is the only credential and the only thing
that picks the company**. `LeadInboundService`: a token is `lt_` + 48 random characters; `lead_inbound_tokens` stores only `sha256(token)` (unique), the last four characters as a hint, the `source` its leads are recorded under (one of the company's own sources),
`is_active`, `last_used_at`; the plain token is returned once, by create and regenerate, and never again (regenerate replaces the hash, so the old address dies at once; a deleted, switched-off or inactive-company token is the same 401). Order of checks:
token (malformed or unknown -> 401 `INVALID_TOKEN`; wrong guesses are counted per address and after 30 a minute answer 429, valid tokens never count) -> size over 64 KB -> 413 `PAYLOAD_TOO_LARGE` -> the hidden field `_hp` filled -> 200 as if it worked, nothing created
(logged `spam`) -> `LeadIntakeService::ingest` (the body is mapped: `name`->contact_name, `mobile`->phone, `message`->requirement, `company`->company_name, `id`->external_id; the intake names always win) -> 201 created / 200 merged / 200 already_received
(so a retry with the same id is safe) / 422 `REJECTED` with the reason; an exception is a plain 500 `SERVER_ERROR` with nothing internal in it (logged `error`). 60 requests a minute per token (429 `RATE_LIMITED` with Retry-After). There is **no per-address
limit** on valid traffic on purpose: behind the proxies the address may be the proxy's (see PB-065), so such a limit could let one noisy sender block everyone. `lead_import_logs` gets one row per request that reached a valid token
(`created | merged | already_received | rejected | spam | too_large | error`, lead id, reason, external id, ip); the hourly `notifications:scan` deletes rows older than 90 days. Management: `GET/POST /api/settings/lead-inbound-tokens`,
`PUT|DELETE /api/settings/lead-inbound-tokens/{id}`, `POST .../{id}/regenerate`, `GET /api/settings/lead-import-logs` (7-day summary + newest first; reads open, changes need `settings.manage`), screen Master Data -> Lead intake.
**Website form (LEADS_PLAN 4.1).** The inbound route is open to browsers on any website (the framework's default CORS: every origin, no credentials, `api/*`; there is no cookie to steal, the token in the address is the credential). `frontend/public/lead-form.js` (built into the web root, served at `/lead-form.js`, no libraries) is the embed: it reads `data-token` from its own `<script>` tag, takes the API origin from the script's own address, draws the form in `#panelos-enquiry` (or just above the script; `data-target`, `data-button`, `data-thanks`, `data-style="none"` adjust it) and posts JSON:
`name, phone, email, city, message` (aliases of `contact_name, phone, email, city, requirement`), `_hp` (hidden trap, must be empty), `_t` (milliseconds the page had been open), `external_id` (`web-` + random, one per drawn form, so a double click is `already_received`), `landing_page` (origin + path, never the query string), `referrer`, and `utm_*` plus `campaign` (= `utm_campaign`) taken from the address of the landing page and kept in `sessionStorage` for the visit. `landing_page` is stored on the lead (`leads.landing_page`, 500 characters); the referrer stays in the original message (`lead_payloads`).
`LeadInboundService::receive` adds two protections on top of the 2.3 ones. **Allowed websites:** `lead_inbound_tokens.allowed_origins` (JSON list of `scheme://host[:port]`, at most 10, set in Master Data -> Lead intake -> Website form, `settings.manage`; entries are tidied with `LeadInboundService::origin()`, default ports dropped, host lower-cased, `www` and plain are different sites). Empty/null = any website. When set and the request carries an `Origin` header that is not listed (including `null`), the answer is 403 `ORIGIN_NOT_ALLOWED`, nothing is created and the import log gets a `blocked` row. A request with **no** Origin header (curl, Zapier, a partner's server) is not a browser and is not stopped; anyone outside a browser can fake the header, so this only stops the address being reused on someone else's web page. A new address (`regenerate`) keeps the list.
**Too fast:** `_t` below `MIN_FILL_MS` (1000) is treated like the trap: `200 received`, nothing created, a `spam` log row. A post with no `_t` is not affected. A real visitor on a phone with autofill still needs over a second.
Not built (not asked yet): a captcha, a form builder, attaching to a form the client already has (they can post the JSON themselves, the Lead intake screen shows a curl example), and a per-address rate limit by website.

**Spreadsheet import and export (LEADS_PLAN 4.2).** `LeadSheetController` + `LeadImportService`; table `lead_imports` (`LeadImport`: `file_name`, `source`, `status` previewed | running | done | failed | undone, `total_rows`, `counts`, `rows` (the checked rows as JSON, hidden from every API), `created_lead_ids`, `undo_result`, times).
Routes (all under the leads module, before `/leads/{id}`): `GET /leads/import/template`, `GET /leads/import/last`, `POST /leads/import/preview` (multipart `file`, optional `source`; 10 a minute per person, limiter `lead-import`), `POST /leads/import/{id}/commit` (same limiter), `POST /leads/import/{id}/undo`, `GET /leads/export`. Importing needs `leads.manage` (method rule); **the export is overridden to `reports.view`** (`ROUTE_OVERRIDE['leads.export']`), like the bulk exports under `/export`.
*Reading:* CSV only; UTF-8 (a byte-order mark is dropped; a file that is not valid UTF-8 is read as Windows-1252, which is what Excel's plain "CSV" save writes); the delimiter (comma, semicolon or tab) is guessed from the first line; quoted commas, quotes and line breaks inside a cell work; blank lines are skipped. Columns are found by heading with everything but letters and digits ignored (Name / Contact name / Full name; Phone / Mobile / Mobile No; Email / E-mail ID; Company; City; Source; Requirement / Message / Notes / Remarks; Application; Area (sqm) / Qty; Value (Rs) / Budget; Reference / Ref / Id; Date / Enquiry date; Campaign), any order; unknown headings are ignored and listed. At least one of name, company, phone or e-mail must be present. Limits 1,000 rows and 2 MB.
Dates are read **day first** (2026-10-20, 20-10-2026, 20/10/2026, 20.10.2026, 20 Oct 2026, optional time), as Indian time, never month first; a date that does not parse is ignored with a note. A phone like `9.87654E+11` (Excel's shortened number) is not stored; the row says so and is rejected unless it has an e-mail. A row's own Source is used when it is one of the company's sources, otherwise the one chosen in the dialog (default Exhibition/Other) with a note.
*Check (`LeadIntakeService::preview`):* a read-only twin of `ingest` (same helpers: reference already received -> already had; no name / no usable phone or e-mail -> cannot import; the buyer has an open lead -> added to it; otherwise new, naming closed leads of the same buyer). On top of that the import service predicts within the file: the same phone or e-mail again is "added to the lead made by row N", the same reference again is "already had". `LeadImportTest::test_what_the_check_predicts_is_what_the_import_does` keeps the two honest.
*Import:* the claim is one atomic `previewed -> running` update, so a double click or two tabs cannot run a file twice (422 the second time); every row goes through `ingest(company, row source, data, original row)` (the original row is kept in `lead_payloads`); one failing row is counted and the rest go on; assignment rules apply, and `LeadAssignmentService::holdAlerts` holds the per-lead bell and returns how many leads each person got, which the import sends as one line ("N new leads assigned to you from an import"). A row that is only a repeat adds an "Enquired again" note to the buyer's lead and brings its follow-up to today (as for every channel).
*Undo:* only the most recent import that ran (done / failed / undone) can be undone, and only once. It removes (soft delete, through `LeadService::delete`) the leads that import created, **except** any lead whose status is no longer new, that has a quotation or customer, that has an activity by a person or any activity after the import finished (for example a second enquiry), or that was edited after the import. Those stay, and the answer lists how many and why. Enquiries that were added to existing leads are not undone. Checks that were never imported are deleted after two days by the hourly scan.
*Export:* `GET /leads/export?status=&source=&assigned_to=&follow_up=&search=` uses `LeadService::list` (so "invalid" stays out unless asked for, exactly like the list), streams newest first in chunks, UTF-8 with a byte-order mark, formulas defused (`ExportController::csvSafe`). The screen sends the status tab and the search box. Columns: lead no, date (IST), name, company, phone, email, city, source, campaign, status, assigned to, next follow-up, application, area, value, requirement, lost reason, page, UTM source / medium / campaign. An exported file can be imported again.
Not built: `.xlsx` files (save as CSV), a downloadable list of rejected rows, importing as another user's leads, scheduling.

**The lead list, views and addresses (LEADS_PLAN 5.1).** `GET /leads` filters: `status`, `source`, `assigned_to` (a user id, `me`, `none`), `follow_up` (`due` = today or earlier, `overdue` = before today, `today`, `none` = no date; open leads only), `search` (name, company, lead no, e-mail, phone as typed, or any 4+ digits matched against the phone key; `%` and `_` are plain characters), `sort` (`created`, `follow_up`, `name`, `updated`) and `dir`; the default is newest first, `follow_up` and `name` default to ascending, and leads with no follow-up date always come last. `GET /leads/options` now also says `own_only` so the screen hides filters that cannot apply.
Views: the ready-made ones are defined by the screen as plain filters (`My leads` = `assigned_to=me`, `My overdue`, `Due today`, `New from IndiaMART` = `status=new&source=IndiaMART` when the company has that source, `Unassigned`); saved ones live in `lead_views` (`LeadView`: `user_id`, `name`, `filters` JSON), private to the person, `GET|POST /leads/views` and `DELETE /leads/views/{id}` (only your own; same name replaces; at most 20; only `status, source, assigned_to, follow_up, search, sort, dir` are kept, each checked against its allowed values). The screen remembers the last filters per person in `localStorage` (never the search text); a first visit by someone who is not an admin opens `My leads`.
Addresses: `/leads/12` (`DETAIL_SECTIONS` now includes `leads`); `LeadManager` takes `openId`, emits `detail` whenever the open lead changes, and answers an unknown or not-yours id with a message and the list. The header search (`GlobalSearchService`) has a Leads group (needs `leads.view`, junk leads left out, restricted people find only their own).

**Next actions (LEADS_PLAN 5.2).** `lead_tasks` (`LeadTask`): `lead_id`, `assigned_to_user_id`, `created_by_user_id`, `type` (`call`, `whatsapp`, `email`, `visit`, `quote`, `other`; labels in `LeadTask::TYPES`), `title` (optional), `due_date`, `due_time` (optional), `status` **open | done | cancelled**, `done_at`, `done_by_user_id`, `done_note`. `LeadTaskService` is the one place that changes them:
`create` (the lead must be open; the day is today or later in **Indian time**, the time `HH:MM`; the person must be an active user of the company, default the lead's owner, else the actor), `update` (open only; an action already overdue may keep its day while its note or person changes), `complete` (open only; marks done, writes a timeline line of the type call / whatsapp / email / meeting / note, and with `next` plans the following action in the same transaction, so a refused next step leaves the first one open), `delete` (soft), `cancelOpen` (called from `LeadService::transition` when a lead goes to won, lost or invalid).
**The follow-up date rule:** after every change, if the lead is not on hold and has open actions, `leads.next_follow_up_date` = the earliest open `due_date`; if the change removed or finished the last open action the date is cleared; creating never clears; a lead that never had an action is never touched. (Merging a repeat enquiry still sets the date to today for a lead without actions; for a lead with actions it is overwritten at the next action change.)
Routes (leads module; reading `leads.view`, changing `leads.manage`; a person with `leads.own_only` only meets the actions of their own leads, the rest are 404): `GET /leads/tasks?assigned_to=me|none|all|<id>` ("my day": `overdue` before today, `today`, `upcoming` the next 7 days, `later` a count), `GET|POST /leads/{id}/tasks`, `PUT|DELETE /leads/tasks/{id}`, `POST /leads/tasks/{id}/complete {note?, next?{type, due_date, due_time?, title?, assigned_to_user_id?}}`. `GET /leads/options` includes `task_types`.
Bell: `LeadTaskService::tell` rings the person an action is given or handed to (`lead_task_assigned`, never the person who made the change). `LeadTaskReminderService::sync` (hourly `notifications:scan`) keeps one standing entry (`lead_task`, key `task:<id>`) per open action due today or earlier, for its assignee: "Due today [at 4:00 pm]: Call LD-1 Ravi" (warning), "Overdue: ..." from the next day (danger, unread again, no "N days" in the text so it changes only once); it clears when the action is done, moved or removed, and follows `notify_lead_followups`. `LeadReminderService` leaves out leads that have open actions, so the per-lead reminder and the per-action one never both fire (the overdue summary for admins still counts them through the follow-up date).
Screen: `LeadNextActions.vue` in the lead drawer, `LeadMyDay.vue` (the My day tab, with the due count on the tab), and `LeadTaskDoneDialog.vue` shared by both (the note and the "what is next" choice).

**Contacting a lead (LEADS_PLAN 5.3).** `LeadMessageService` (+ `LeadMessageController`, `LeadMessageTemplateController`, `LeadContactBar.vue`, `LeadMessageSettings.vue`).
*Templates:* `lead_message_templates` (`LeadMessageTemplate`: `channel` whatsapp | email, `name`, `subject` (e-mail only), `body`, `is_active`, `sort_order`), seeded once per company by `ensureDefaults` (4 WhatsApp texts, 2 e-mails; a company that has deleted all of them is not re-seeded because soft-deleted rows count). Placeholders are `LeadMessageService::PLACEHOLDERS` (`{name}` falls back to "there", `{requirement}` is shortened to 80 characters on a word, or "your enquiry"); an unknown `{word}` stays as typed; the text is plain, the e-mail escapes it. Settings API `GET|POST /settings/lead-message-templates`, `PUT|DELETE .../{id}` (reads open, changes `settings.manage`).
*On a lead (leads module; changing needs `leads.manage`; `leads.own_only` people reach only their own leads, others 404):* `GET /leads/{id}/message-templates?channel=` (the active templates already filled in for this lead and the signed-in person, plus `whatsapp_ready` and `email_ready`), `POST /leads/{id}/whatsapp {message}` (returns `https://wa.me/<number>?text=...` and writes "WhatsApp opened with: ..." (activity type `whatsapp`); 422 when the lead has no number WhatsApp can use), `POST /leads/{id}/email {subject, body}`; both POSTs are limited to 30 a minute per person (`lead-contact`).
*Numbers:* `waNumber`: 10 digits -> `91` + digits; a leading 0 and 11 digits -> `91` + the rest; 12 digits starting 91 as is; 11 to 15 other digits are taken as another country's number; anything else is refused.
*E-mail:* goes through `CompanyMail::deliver(..., 'lead_message')`, **sent now** via the mailbox that type is routed to (default main); `lead_message` is listed in the mailbox routing screen. It is written to the lead's own saved address only (no `to`, `cc` or `bcc` from the request), subject line breaks are removed (no header injection), reply-to is the sender, body is HTML-escaped with line breaks kept. If the company has **no complete mailbox** nothing is sent and the answer says to set one up in Customer Messaging; if the mailbox refuses, the answer is a fixed sentence and the log gets only the exception class, never the host, login or message. Each success writes "E-mail sent to x: subject" plus the first 600 characters (activity type `email`).
*Call:* a `tel:` link; after tapping it the screen asks how the call went and posts an ordinary `call` activity ("Call: No answer. note"). Nothing is dialled or recorded by the server.

**Requirement lines and "Quote this lead" (LEADS_PLAN 5.4).** `LeadRequirementService` (+ `LeadRequirementController`, `LeadRequirement.vue`). `lead_requirement_lines` (`LeadRequirementLine`): `kind` panel | accessory; panel lines have `panel_type_id` (an active panel type of the company), `application` (one of the eight in the list, optional), `thickness` (10, 25, 30, 40, 50, 60, 75, 80, 100, 120, 150, 200 mm) and `area_sqm` (> 0); accessory lines have `accessory_id` (an active accessory of the company) or a typed `description`, and `qty` (> 0). Up to 30 lines. `PUT /leads/{id}/requirement {lines}` replaces them all (and sets `leads.est_qty_sqm` to the panel area, only when there is some), `GET` reads them with names, both in the leads module.
`POST /leads/{id}/quote {as_boq?, + the Convert fields customer_id / create_new / state_code / gstin / address_line1 / pincode}` needs `leads.manage` **and** `quotations.manage` (403 otherwise). Steps: the lead must be open and have a panel line; the customer is made or found by `LeadService::ensureCustomer` exactly as Convert does (409 `CUSTOMER_EXISTS`, 422 for a missing state); `payload()` builds the data; `QuotationService::create($payload)` makes the quotation (`status` draft, or `boq` with `as_boq`), which also back-links the lead (`LeadService::linkQuotation`: status quoted, `quotation_id`). The answer is `{quotation_id, quotation_no, as_boq, customer_id}` and the lead's timeline gets one note saying what was assumed.
`payload()` = the quotation form's own save shape and defaults: `density_type` PUF, `density_kgm3` 40, both skins PPGI 0.40 mm, colour Off White, `top_surface` from `PanelType::default_top_surface` (roof ribbed), bottom PLAIN, `hsn_code` from the panel type else 39259010, `quotation_prefix` left to the service default; one size row of **3000 mm x ceil(area / 3)**, 1000 mm wide, so the area is covered and never under-quoted; **`rate_per_sqm` 0 (rate pending)**: the send is blocked by the existing rule until the quotation is priced. Accessories: a catalogue one as `type standard` with `accessory_id`, its `unit_price` (else `rate`) and unit; a typed one as `type custom`, rate 0. `project_name` = company (else contact) + city; nothing from the lead is written into the quotation `notes` (they print on the customer's PDF). Quotation, BOQ and order code is only called; `LeadRequirementTest::test_the_data_it_builds_is_accepted_by_the_real_quotation_endpoint` posts the same data to `POST /api/quotations` so a change to those rules fails here first.

**Quotation chasing (LEADS_PLAN 5.5).** `QuotationChaseService::sync($companyId)`, called by the hourly `notifications:scan` before the action reminders. It **reads** quotations and never writes to them or hooks into them.
For every quotation with `status = 'sent'` and a `sent_at`, whose lead (`LeadService::leadsForQuotation`: the lead's quotation is this one or an earlier version, so a revision is chased for the same lead) is open, it plans next actions (`LeadTaskService::create` with `meta = {quotation_id, chase_step, quiet}`): **d3** (a call, 3 days after the day it was sent), **d7** (a WhatsApp action, 7 days after), **expiry** (a call, 2 days before `valid_until`). Days are Indian dates. Not planned: a step whose day is before today, a step after `valid_until`, and an expiry step on or before the latest other step.
`lead_tasks.quotation_id` + `chase_step` are unique together (`lead_tasks_chase_unique`), including soft-deleted and done rows, so each step is planned once for ever; a concurrent second scan loses to the key and is ignored. `quiet` skips the "New action for you" bell (the due-day reminder of 5.2 still rings on the day). Owner: the lead's owner, else the first active `is_company_admin` of the company.
`cancelStale`: open chase actions whose quotation is no longer `sent` (accepted, rejected, expired, revised, ...) are cancelled through `LeadTaskService::cancelForQuotation` (one timeline line "Quotation follow-ups cancelled: the quotation is now <status>.", then the lead's date is recomputed; actions planned by hand are never touched). Closing the lead cancels all its open actions as before.
Switch: `notification_settings.chase_quotations` (default true, also for a company with no settings row; `NotificationSetting::forCompany` sets it). Off = no new steps are planned; cancelling stale ones continues. The task API marks these actions `auto: true`.

**Response time and quiet leads (LEADS_PLAN 5.6).** `LeadEngagement` (+ `LeadStaleService`). Nothing is stored; two subqueries over `lead_activities` give every lead `first_response_at` and `last_touch_at` (`LeadEngagement::select` for lists, `attach` for one lead; `Lead` appends `quiet_days`, `response_minutes`, `response_waiting_minutes`, which are null unless those columns were loaded).
*Response* = the earliest activity with a `user_id` (a person, not the system) of type `call`, `email`, `whatsapp` or `meeting`, or a `status_change` whose text starts `Status: new →` (the person moved it out of new). *Touch* = the latest activity with a `user_id` of any type (notes count). The wait is measured from `first_seen_at` when that is within 6 hours before `created_at`, else from `created_at`.
*Quiet* (`onlyQuiet`): status in new / contacted / qualified / quoted (not on hold, not closed), `coalesce(last touch, created_at)` older than N days, and **no open next action due today or later** (Indian date). N = `notification_settings.lead_stale_days` (default 7; **0 switches the bell off but the view and filter use 7**).
List filters `quiet=1` and `unanswered=1` (open leads with no response), both allowed in saved views and the export. `GET /leads/options` returns `quiet_days`. `GET /leads/dashboard` has `response`: `responded`, `avg_minutes`, `within_hour_pct` (first response within 60 minutes) for leads that arrived in the last 30 days, and right now `waiting` (open, unanswered), `quiet`, `quiet_days`; all respect `leads.own_only`.
Bell: `LeadStaleService::sync` (hourly `notifications:scan`) keeps one standing entry per person (`lead_quiet`, key `quiet:<user>`: "3 leads have had no activity for 7 days or more", the oldest named with its last-touched date) and, for quiet leads with no owner, one per active company admin (`quiet:unassigned:<admin>`). The text has no "N days" of its own, so an entry turns unread only when the picture changes; it clears when the leads are touched or the setting is 0.

**Files on a lead (LEADS_PLAN 5.7).** `LeadAttachmentService` (+ `LeadAttachmentController`, `LeadAttachments.vue`); `lead_attachments` (`LeadAttachment`: `kind` drawing | photo | boq | document, `original_name`, `path` (hidden from every API), `extension`, `size_bytes`, `uploaded_by_user_id`).
Storage: the **`local` (private) disk**, `storage/app/private/lead-attachments/<company>/<lead>/<uuid>.<ext>`; never the `public` disk, so there is no address that serves a file. Routes (leads module; reading `leads.view`, changing `leads.manage`): `GET|POST /leads/{id}/attachments` (the POST is limited to 20 a minute per person, `lead-upload`), `GET /leads/attachments/{id}` (the file; `?inline=1` shows a PDF or picture in place, everything else is always an attachment; headers `Content-Type` from the extension, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`), `DELETE /leads/attachments/{id}`. A file is always found through its lead (`LeadService::visible`), so another company's file, or another person's lead under `leads.own_only`, is 404; a file missing from the disk answers 404 `FILE_MISSING`.
Accepted: `TYPES` in the service (pdf, jpg/jpeg, png, webp, dwg, dxf, xls, xlsx, csv, doc, docx). Both the extension (from the uploaded name) and the content must fit: `looksLike` checks the first bytes (`%PDF-`, JPEG `FF D8 FF`, PNG signature, `RIFF....WEBP`, `PK\x03\x04` for xlsx/docx, `D0 CF 11 E0` for xls/doc, `AC<4 digits>` for dwg; dxf and csv only need to be text with no NUL). 15 MB a file, 30 files a lead (the app's PHP limit is 20 MB, nginx 22 MB). The displayed name has folders, quotes and control characters removed; the stored name is never the user's.
Each add and removal writes a timeline line. `LeadService::delete` calls `LeadAttachmentService::purge`, so a deleted lead (and the spreadsheet import's undo, which deletes through the same method) removes its files. **Backups:** the nightly backup is the database only; the files live in the backend's `storage` volume, so back that volume up if the files matter. **Not done:** virus scanning; the files are not copied onto the quotation (that would change the quotation module).

**Merging two leads (LEADS_PLAN 5.8).** `LeadMergeService` (+ `LeadMergeController`): `GET /leads/{id}/merge-candidates?q=` and `POST /leads/{id}/merge {from_lead_id}`; the lead in the address is KEPT, `from_lead_id` is folded into it. Leads module, `leads.manage` for the POST; both leads are looked up through `LeadService::visible`, so another company's, or another person's under `leads.own_only`, is 404.
`problem()` refuses: the same lead; different companies; either lead **closed** (won, lost, invalid; open and on-hold leads may merge); **both** with a `quotation_id`; both with a `customer_id` and the ids differ. `candidates()` lists open and on-hold leads of the same buyer (`LeadMatcher`: same phone key or e-mail key, with `why`) first, then those found by `q` (lead no, name, company, phone), each with `blocked` = the reason it cannot be merged or null.
`merge()` runs in one transaction with both rows locked in id order. Blanks on the kept lead (`company_name, phone, email, city, application, est_qty_sqm, est_value, assigned_to_user_id, customer_id, quotation_id, campaign, landing_page, utm_*`) are filled from the other, nothing present is overwritten; `requirement` becomes `<kept> (From <other no>) <other>` unless equal; `first_seen_at` the earlier; `status` the more advanced of new < contacted < qualified < quoted (an on-hold lead keeps its status and date); `next_follow_up_date` the earlier, then `LeadTaskService::refreshDate` when open actions exist. Moved by changing `lead_id`: `lead_activities`, `lead_tasks`, `lead_attachments` (the files stay where they are on the disk), `lead_payloads`, `lead_import_logs`; `lead_requirement_lines` up to 30 (the rest are dropped with the other lead). The other lead gets `merged_into_lead_id` and is **soft-deleted** (not via `LeadService::delete`, so nothing is purged), so it still holds its `lead_no` and its unique `(company, source, external_id)`: the same enquiry cannot create it again (`LeadIntakeService` checks with trashed). One note on the kept lead's timeline says what moved. Not undoable.

**The board (LEADS_PLAN 5.8).** `GET /leads/board` (`LeadService::board`, `LeadController::board`; `leads.view`): columns `new, contacted, qualified, quoted, on_hold` in that order (`BOARD_COLUMNS`), each `{status, count, est_qty_sqm, est_value, more, leads[]}` with the first 25 leads (`BOARD_PER_COLUMN`) ordered by the nearest follow-up with undated leads last. It runs the list's own query (`LeadService::list`) once per column, so the filters are the list's: `source`, `assigned_to` (id, `me`, `none`), `follow_up`, `search`, `quiet`, `unanswered`; a `status`, `sort` or `dir` is ignored (the columns are the statuses). Closed leads are never on it. `LeadService::visible` applies, so a person under `leads.own_only` sees only their own. Cards use the list's row (so `quiet_days`, `response_waiting_minutes` and `assigned_user` are there).
`LeadBoard.vue` moves a card through the ordinary `POST /leads/{id}/status` (drag and drop, or the card's Move menu); `LeadManager` sends hold, lost and junk through its existing status dialog (date, reason) and does not open the drawer after a board move. 

**Bulk actions on the list (LEADS_PLAN 5.8).** `LeadBulkService` (+ `LeadBulkController`): `POST /leads/bulk {ids[], action, ...}` (leads module, `leads.manage`; 10 a minute per person, `lead-bulk`; at most 200 ids, repeats collapsed). Actions: `assign` (`user_id` an active user of the company, or `none`), `status` (`contacted`, `qualified`, `on_hold` + `hold_until`, `lost` + `lost_reason_id` (an active reason of the company) + optional `lost_note`, `invalid` + optional `reason`; **won, quoted and new are not offered**), `plan` (`type`, `due_date`, optional `due_time`, `title`, `assigned_to_user_id`). Anything else, including delete, is 422.
Each lead is handled alone by the ordinary service (`LeadService::update` for assign, `transition` for status, `LeadTaskService::create` for plan), so every rule and timeline line is the single-lead one; a `ValidationException` for one lead turns into a **skipped** entry `{id, lead_no, reason}` (not found or not visible: "Not found."; closed leads are skipped for all three actions with their own words; already with that person / already that status). The answer is `{action, requested, done, skipped[≤50], skipped_count}`. The per-lead bell is held (`LeadAssignmentService::holdAlerts`, and `quiet` for planned actions) and each person gets **one** summary line ("N leads assigned to you by X", "N actions planned for you by X"); the person who made the change is never rung.

**Lead intake (LEADS_PLAN 2.2).** `LeadIntakeService::ingest(int $companyId, string $source, array $data, ?array $raw = null): LeadIntakeResult` is the one door for every channel; a connector maps its own message to the
common keys (`contact_name, company_name, phone, email, city, requirement, application, est_qty_sqm, est_value, external_id, campaign, utm_*, first_seen_at`) and calls it. It runs from jobs and webhooks: no signed-in
user, every query scoped to the company given. Order of decisions: (1) the same `(company, source, external_id)` already became a lead (deleted ones count) or was added to one -> `already_received`, nothing changes; a *rejected* message
does not count, so a corrected resend with the same id gets through; (2) no name/company/phone/e-mail, or neither a 10-digit phone nor a valid e-mail -> `rejected`, the raw message is stored with `lead_id` null; (3) `LeadMatcher` finds the buyer
and one of the matches is open or on hold -> `merged`: note "Enquired again on <source> (ref ...): <requirement>", blanks on the lead filled (never overwritten), `next_follow_up_date` set to today unless already due, raw message kept against the lead;
(4) otherwise `created` (status new, `first_seen_at` = the channel's date, clamped to now if in the future); if only closed leads matched, a new lead is made and its first timeline note names them ("Same buyer as LEAD-... (lost)"); old leads are never reopened.
Values are trimmed, capped to column sizes, numbers parsed (commas, rupee sign), unreadable dates become now, non-scalar values dropped; an unusable phone is not stored (the raw message keeps it). The source is the company's own spelling when it is
in the list, otherwise the channel's name as given. Two deliveries at the same instant: the unique key stops the second and it is reported as `already_received`. Assignment (2.4) and the inbound API (2.3) are not part of it. The seen-before lookup uses
`lead_payloads (company_id, source, external_id)`.

**Source detail and raw messages (LEADS_PLAN 2.1).** A lead that came through a channel records where from: `external_id` (the channel's own id for the enquiry; **unique per company and source**
`leads_company_source_external_unique`, so the same enquiry can never become two leads; empty for manual leads, of which there can be any number), `campaign`, `utm_source/medium/campaign/term/content`,
`first_seen_at` (when it first reached the channel; `created_at` is when we stored it). These are set by the intake service (2.2) and importers only: the lead form and `PUT /api/leads/{id}` cannot write them.
`lead_payloads` (`LeadPayload`, tenant-scoped, soft-deleted) keeps the raw message exactly as received (`payload` JSON, `source`, `external_id`, `received_at`) with an optional `lead_id` (null when a message was
rejected before a lead existed; set null, not cascaded, if a lead is ever hard-deleted; a soft-deleted lead keeps its payloads). `GET /api/leads/{id}/payloads` returns them for support; the lead detail and list never
carry them. Intake must check for an existing `(company, source, external_id)` before inserting: a clash is a database error, and `LeadService::create` would retry it pointlessly.

**Follow-up reminders (LEADS_PLAN 1.7).** `notifications:scan` (hourly) calls `LeadReminderService::sync($companyId)`, which keeps two kinds of *standing* bell notifications in step with the leads
(re-running changes nothing; an entry disappears when its condition clears): `lead_follow_up`, one per lead for its **assignee only** (open or on-hold lead, `next_follow_up_date` today or earlier; "Follow up today: LEAD-..."
becomes "Overdue follow-up" in red and unread again the day after, but does not turn unread again every morning because the text carries the due date, not a day count; unassigned leads get no personal entry), and
`lead_follow_up_digest`, one summary for every active company admin when any open lead is overdue by **more than `lead_overdue_alert_days`** (default 3, unassigned leads included). Won, lost and invalid leads are never
reminded; a lead on hold is reminded on its return date. `notification_settings.notify_lead_followups` (default on) switches it off and clears what is showing. WhatsApp and e-mail reminders are **not built** (pending the client).
The bell links to the Leads section (it does not open the single lead yet; `/leads/12` addresses are LEADS_PLAN 5.1).
`AppNotificationService::syncStanding` items may carry their own `to` recipients.

**Lead numbers (LEADS_PLAN 1.6).** `LEAD-<year>-<n>`: the year is the year the lead was made, `<n>` is the company's running count and **keeps counting across years** (LEAD-2027-0002 follows LEAD-2026-0001), a deleted
lead's number is never reused, each company counts on its own. `LeadService::create` takes the next number inside `DocNumber::retry(DB::transaction(...), 5)`: if two requests read the same last number, the unique
`(company_id, lead_no)` key rejects the loser, which re-reads and takes the next one.

**Duplicates (LEADS_PLAN 1.4).** `App\Services\LeadMatcher` is the one definition of "the same buyer": same phone (compared on the last 10 digits) or same e-mail (lower case) within the company.
The keys live on the lead (`phone_key`, `email_key`, set by the `Lead` model on every save, indexed), so the check is a lookup. `POST /api/leads` answers **409 `DUPLICATE_LEAD`** with
`errors.duplicates` (up to 10 leads: id, lead_no, contact_name, company_name, status, assigned_to, created_at, matched_on) unless the request carries `allow_duplicate: true`; `PUT /api/leads/{id}`
does the same only when the phone or e-mail changes to something that belongs to another lead (editing other fields, or the format of its own number, never warns). Won, lost and invalid leads
count as matches; deleted leads and other companies never match; a phone shorter than 10 digits or an invalid e-mail has no key, so blank leads never match each other. Importers must call
`LeadService::findDuplicates()` (same matcher) and decide for themselves; the service `create()` does not block.

**Lead lists (LEADS_PLAN 1.1).** `leads.source` and `leads.status` are plain strings, no longer database enums. The allowed *sources* and the *lost reasons* are lists each company
edits in **Master Data → Lead lists** (`lead_sources`, `lead_lost_reasons`; `LeadListService`). Starter lists are seeded for every company the first time they are read (15 sources,
7 reasons). Leads store the source and the reason by **name**, so reports and old rows keep working; renaming an entry rewrites it on that company's leads, an entry that leads use is
switched off rather than deleted, and *Other* is built in (the fallback). `GET /api/leads/options` gives the lead form its drop-downs; `GET /api/settings/lead-lists` and
`POST|PUT|DELETE /api/settings/lead-sources[/{id}]`, `/api/settings/lead-lost-reasons[/{id}]` manage the lists (reads open, writes `settings.manage`). Changing status to lost accepts
`lost_reason_id` (a reason from the list; stored as `lost_reason` = its name with `lost_reason_id` beside it) and an optional `lost_note`; free-text `lost_reason` still works.
Every status change auto-writes a `status_change` activity, so the lead timeline is complete.

**Convert** (`POST /api/leads/{id}/convert`, `LeadService::ensureCustomer`, LEADS_PLAN 1.5) links the lead to a customer and returns `{lead_id, customer_id}`; it does **not** create the quotation.
The UI then opens `QuotationCreate` prefilled with `{customer_id, lead_id}`, and when that quotation is saved with `lead_id`, `QuotationService::create` calls `LeadService::linkQuotation`, which moves the lead
to `quoted` and stores `quotation_id`. Rules, in order:
1. A lead that already has a customer returns it, asking nothing.
2. `customer_id` in the body links that existing customer of the same company (timeline: "Customer linked: ...").
3. Otherwise customers of the company that look like the buyer are looked for: same phone (last 10 digits, any formatting), e-mail, GSTIN (if given) or name. If there are any, the answer is **409 `CUSTOMER_EXISTS`**
   with `errors.customers` (id, code, name, phone, email, city, gstin, matched_on) unless the body carries `create_new: true`. Name alone is only ever offered, never used automatically.
4. A new customer needs a **state**: `state_code` (2-digit code, alpha code or name) or a `gstin` whose first two digits give it. A state that disagrees with the GSTIN is refused; a GSTIN that already belongs to a
   customer is refused with `create_new` too. The customer gets `state_code` as the 2-digit GST code and `state` as its name, so `GstState` splits CGST/SGST vs IGST correctly. Optional `address_line1`, `pincode`; city comes from the lead.
5. The code is `Customer::nextCode()`: first four letters of the name (or CUST) plus a number that counts up from the highest existing one in the company (RAVI101, RAVI102...), also used by the customer screen. Creation retries once on a unique-key clash.
Defaults on the new customer: `type='retail'`, `payment_terms_days=30`, no credit limit.

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

**E-mail (admin side).** Settings → Customer Messaging → *E-mail*: a switch for the automatic payment-reminder
e-mails (`notification_settings.email_payment_reminders`, **off by default**; the 09:00 `reminders:send` run skips any
company that has not turned it on; sending a reminder by hand from an invoice always works), a one-line statement of
whether the server can deliver mail at all (`App\Support\MailStatus`: the `log` mailer, the `.env.example` default,
delivers nothing), and *Send me a test e-mail* (`POST /api/settings/notifications/test-email`, sent synchronously to the
signed-in admin so a bad SMTP setting is shown as a readable error).
**Company mailbox (FR-007).** A company can send *customer* e-mail from its own SMTP mailbox (`notification_settings.smtp_*`, password `encrypted`, never returned). `App\Support\CompanyMail::deliver()` is the single door: with the mailbox on and complete it builds an on-demand mailer (`Mail::build`, 10 s timeout, 465 = `smtps`, 587 = STARTTLS) and sends **now** with the company's From; otherwise it queues on the server mailer as before. `SmtpHostGuard` refuses non-public hosts and ports other than 25/465/587/2525 (at save time and again after DNS before connecting). A company has **two** mailboxes (FR-008): `main` (`smtp_*`) and `notifications` (`notif_smtp_*`). Which one a kind of e-mail uses is the admin's routing table `notification_settings.mail_routing` over the defaults in `CompanyMail::TYPES` (`invoice` → main, `payment_reminder` / `payment_receipt` → notifications); `CompanyMail::activeFor($company, $kind)` falls back from notifications to main to the server mailer, `exactFor` is the strict version used by the test buttons. A test e-mail may name another recipient (`to`) but only through the company's own mailbox, and the endpoint is throttled to 10 a minute. `EmailService` (invoice, reminder, receipt) uses it; login codes (`AuthController`) and server alerts (`AlertService`) never do.

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
PurchaseOrder, Quotation, Supplier**, and since EN-06 (PB-059) on **Role, User, Company, StockTransaction, CoilStock,
ChemicalStock, ConsumableStock, Dispatch, Accessory, TaxConfiguration**. A model can list extra fields to skip in
`$auditAlsoExclude` (User skips `last_login_at` and the 2FA code, so logins do not flood the log). A Company row is logged
against its own tenant, so a super admin editing a tenant shows up in that tenant's log. Records `created` / `updated` / `deleted` / `force_deleted` /
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

**What never reaches the browser (PB-043).** The `RedactErrorLeaks` API middleware replaces any error text that looks like SQL, a database host, a file path or an internal class name with a reference id (the original is in the server log), so the messages below are the only wording a user sees. Domain messages and 422 validation errors are never rewritten. List screens take `sort_by`/`sort_order` through `App\Support\SafeSort`: an unknown column or direction falls back to the default order.

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

10. *(Fixed, PB-021: freight is settled at dispatch — amount, transporter and vehicle live on the dispatch and are billed as an invoice line with the same GST as the goods; the quoted figure is the fallback. Worth confirming the composite-supply treatment with the client's accountant.)*
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
18. *(Fixed 2026-10-23, PB-030: `routes/console.php` schedules reminders, SMS reminders, backups, token pruning, notification scans and the health heartbeat.)*
19. **The dashboard is cached for 60 s per company** — a freshly created record may not appear in KPIs
    immediately. This is expected, not a bug.
20. **`StockService::getStockLevel/getStockHistory` query legacy columns** (`coil_id` / `chemical_id`) that
    the current tables do not use. The live code paths go through the controller endpoints instead.
21. *(Fixed, PB-022: font subsetting is on and the logo is shrunk before embedding; PDFs are ~97% smaller.)*
22. **The test suite is fully green** (572 of 572 on 2026-10-24, up from 191 failing). The CI gate
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
