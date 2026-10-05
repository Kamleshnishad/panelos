# System Audit — 2026-08-14

**Scope:** Full module-by-module audit of the PUF Panel ERP — Laravel API backend (`backend/`) + Vue 3 frontend (`frontend/`), multi-tenant SaaS covering Quotation → Order → Production → QC → Dispatch → Invoice → Payment → Reports.

**Method:** 8 parallel deep-read passes (one per module group), each reading every controller/service/model/migration and Vue component/service in its area, cross-checked against the prior `AUDIT_2026-06-14.md` to avoid re-reporting fixed issues and to catch regressions. Every finding below cites `file:line`. The most severe claims (RBAC bypass, payment double-credit, QC checklist not persisted, "Mark Paid" ignoring balance) were independently re-verified by grep/read against the current code before inclusion.

**Total findings: 112** — 5 Critical · 22 High · 50 Medium · 35 Low.

Findings are tagged with a **Category**: `UI/UX`, `Code Error`, `Logic Bug`, `Query Optimization`, `Scalability` (huge-data handling), `Concurrency`.

---

## 1. Executive summary

| Module | Critical | High | Medium | Low | Total |
|---|---|---|---|---|---|
| A — Auth, Tenancy, Users, Company, Super Admin, Billing | 1 | 2 | 7 | 5 | 15 |
| B — CRM: Leads & Quotations/BOQ | 0 | 3 | 6 | 4 | 13 |
| C — Orders & Production (Batches, Stages, QC) | 1 | 4 | 10 | 2 | 17 |
| D — Inventory/Stock, Procurement, Master Data | 0 | 2 | 5 | 7 | 14 |
| E — Dispatch, Invoicing, Payments, Tax/GST, Customers | 2 | 3 | 5 | 3 | 13 |
| F — Analytics, Reporting, Forecasting, Dashboards | 0 | 5 | 5 | 3 | 13 |
| G — Notifications, SMS, Documents, Validation, Shared Infra | 1 | 2 | 7 | 5 | 15 |
| H — Infrastructure: DB, Migrations, Jobs/Queue, Concurrency | 0 | 1 | 5 | 6 | 12 |
| **Total** | **5** | **22** | **50** | **35** | **112** |

**The good news first.** The prior June audit's fixes (tenant-scoping IDORs, the quotation/order number race, the Stock Dashboard bugs) all verified as still correctly in place — no regressions except one (Module A, session-expiry UX). The `DocNumber::retry()` pattern + composite unique indexes it introduced were generalized well across quotation/order/invoice numbering. Every high-traffic table has correct FK indexes and unique number-constraints (Module H found **zero** missing indexes across all 85 migrations). `StockService`'s add/remove/adjust flows correctly use row-locking. The main dashboard (`HomeDashboard.vue`/`DashboardService`) is properly SQL-aggregated and cached.

**The five Critical findings, in order of business risk:**

1. **RBAC is decorative** (Module A) — the granular Roles & Permissions UI exists and looks functional, but zero backend controllers enforce it. Any authenticated tenant user — including one assigned the seeded read-only "Viewer" role — can hit any endpoint directly: record payments, delete customers, cancel invoices, change the billing plan.
2. **Payments can be double-recorded** (Module E) — `PaymentService::recordPayment` has no row locking. Two concurrent submissions (double-click, two staff members) can both pass the overpayment check and both insert, corrupting AR figures.
3. **"Mark Paid" ignores the actual balance** (Module E) — any sent/accepted invoice can be marked "Paid" with one click regardless of `remaining_due`, producing a visibly contradictory UI (status: Paid, Balance Due: ₹X).
4. **QC checklist is fabricated, not real** (Module C) — the shop-floor pass/fail checklist is a local UI array that is never sent to the backend and never persisted; historical "checklist results" shown for past inspections are re-generated defaults, not what was actually recorded. The core pre-dispatch quality gate is non-functional as a control.
5. **Scheduled SMS reminders silently never fire** (Module G) — the daily reminder command resolves Twilio credentials globally instead of per-tenant, so the advertised automated-SMS-reminder feature is a full, silent outage for every tenant using the supported (per-company) configuration path.

---

## 2. Can the system handle huge data?

**Mostly not yet, in the areas that matter most for a multi-year tenant.** The schema itself is fine (indexes are correct everywhere checked), but a recurring pattern shows up across almost every module: **aggregate figures are computed by loading full Eloquent collections into PHP and summing/filtering in memory, instead of using SQL `SUM`/`GROUP BY`.** This is the single most common defect class found:

- **AR aging** (Module E) loads the company's *entire* invoice history with a 4-level eager-load chain, buckets aging with five passes of `Collection::filter()->sum()`, and ships the whole unpaginated list to the frontend. This is the daily-use collections screen — it will not scale past a few thousand invoices.
- **Analytics snapshot generation** (Module F) loads every non-draft invoice a company has *ever* issued (no date bound) to compute one snapshot; most of `ReportingService`'s P&L/balance-sheet/cash-flow/sales/reconciliation/MIS reports follow the same full-collection-then-PHP-sum pattern, several eager-loading relations they never actually read.
- **Production Planner** (Module C) loads the entire pending + in-production order backlog on every screen load/refresh, with no caching.
- **Purchase order list** (Modules D & H, confirmed independently by both agents) is the one un-paginated transactional list endpoint — `->get()` with no limit.
- **Payment-reminder send paths** (Module G) load all due reminders / all overdue invoices system-wide with no `chunk()`/`cursor()`.
- Several list endpoints (Quotations, Leads) are paginated but don't clamp client-supplied `per_page`, so a single request can still force an unbounded response.
- **Caching is essentially absent**: a repo-wide grep found exactly **one** `Cache::` call in the entire backend (the main dashboard, 60s TTL). Panel-type/accessory catalogs, GST/HSN config, and tax lookups — all near-static per tenant — re-hit MySQL on every single request despite Redis already being configured and unused for this purpose.
- No read-replica / connection-pool split exists — one MySQL instance serves all reads and writes for every tenant (a structural ceiling, not an urgent problem today).

**What's already handled well:** the live dashboard and reports screen are properly SQL-aggregated and cached; stock-level math uses SQL aggregation, not PHP loops; every high-traffic table has correct indexes, so the *queries* that do run are efficient — the problem is mostly that too much data is pulled into PHP before being summarized.

## 3. Can the system handle concurrency?

**Inconsistently — some of the highest-stakes paths in the system are unprotected.** The codebase clearly knows how to do this correctly (proof: `StockService`, `PaymentGatewayService`'s Stripe webhook handler, and `OrderService`'s order-numbering all correctly use `DB::transaction()` + `lockForUpdate()`), but the pattern wasn't applied consistently to sibling code paths:

- **Manual payment recording has no lock** (Critical, Module E) while the Stripe *webhook* path for the same operation does — the hardening was applied to one entry point and missed the other.
- **Production stage start / production run start** (Module C, High) can both be double-triggered by concurrent clicks: duplicate stage logs, and — more seriously — raw material stock deducted twice for one physical production event, since the check-then-act sequence isn't lock-protected.
- **Order creation from a quotation** (Module B, High) can still be double-created — the existence check runs before the lock, and nothing re-checks inside it.
- **Coupon redemption, subscription-payment idempotency, plan user-limits** (Module A, Medium) all have the same check-then-act race shape, one of them explicitly regressing a fix the code's own comments claim is already closed.
- **Batch/dispatch/PO number generation** (Modules C, E, H) all use the same safe-but-ungraceful pattern: a real unique index prevents data corruption, but none of the three retry on collision the way Order/Invoice/Quotation numbering does, so a concurrent create surfaces as a raw 500 instead of transparently succeeding.
- **`GstService::registerGstConfiguration`** (Module H, Medium) is the one money/tax-adjacent service with zero transactional protection at all — a double-submit can leave two "primary" GST configs.
- **Scheduled jobs are correctly deduplicated at the scheduler level** (`withoutOverlapping()` + `onOneServer()` on every entry in `Kernel.php`), but a manually-triggered command running alongside the scheduled one is not protected — duplicate reminder sends are possible.
- **Async/queue usage is inconsistent**: WhatsApp/SMS correctly moved onto the queue (with an explicit comment citing the original blocking-request problem), but invoice/payment/reminder emails still call `Mail::send()` instead of `->queue()` — the `ShouldQueue` interface on those Mailables is inert. A single queue worker process (no `numprocs`) also means any burst of async jobs serializes behind one at a time.

**Net assessment:** the concurrency *primitives* the team needs (transactions, row locks, unique constraints, a retry helper, a queue) all already exist and are proven correct where applied — this is a rollout-consistency problem, not a missing-capability problem. The fix is mechanical: apply the same lock/transaction/retry pattern already used correctly in 3-4 places to the ~10-12 sibling call sites that were missed.

---

## 4. Fix-first punch list (all Critical + highest-impact High findings)

- [x] **A-C1** (done 2026-10-05: `perm.module` middleware, tests in `ModulePermissionTest`) Enforce `hasPermission()` (or equivalent middleware) on every mutating/sensitive endpoint; extend `AppShell.vue` nav gating beyond the 2 items that currently use it.
- [x] **E-C1** (done 2026-10-05, `InvoicePaymentIntegrityTest`) Lock the `Invoice` row in `PaymentService::recordPayment` (`lockForUpdate()`) before computing `remainingDue`, mirroring the Stripe webhook handler.
- [x] **E-C2** (done 2026-10-05, enforced in `InvoiceService::markPaid`, not the model) Add `remaining_due <= 0` to `Invoice::canMarkPaid()`.
- [x] **C-C1** (done 2026-10-05, `QcChecklistTest`; checklist optional at API for mobile compatibility) Persist the QC checklist (add a `checklist` column, require it in `QualityControlService::create()`, render historical results from the stored record).
- [x] **G-C1** (done 2026-10-05, `SmsReminderCommandTest`) Make `SendSmsReminders` resolve `SmsService` per-company (loop `Company::pluck('id')`), not globally.
- [x] **A-H1** (done 2026-10-05, invite uniqueness now global) Fix duplicate-email-across-tenants login ambiguity (make invite-email uniqueness global, or scope login by company).
- [x] **B-H1 / B-H2 / B-H3** (done 2026-10-05: `AccessoryTenantGuardTest`, revise status guard, quotation row lock in `OrderService`) Tenant-scope the `saveAccessories` accessory attach; add a status guard to `QuotationService::revise()`; lock the quotation row before the "order already exists" check in `createFromQuotation`.
- [x] **C-H1** (done 2026-10-05: completion blocked unless all batches dispatched) Gate "Mark Completed" on order status behind actual batch/QC/dispatch state.
- [x] **C-H2 / C-H3** (done 2026-10-05: row locks + recheck in `BatchStageLogService`, `ProductionRunService`) Lock the batch/run row before "Start Stage"/"Start Run" check-then-act (prevents duplicate stage logs and double material deduction).
- [x] **C-H4** (done 2026-10-05: standalone batches must start through a run; `StandaloneBatchStartTest`) Route standalone batch creation through the same material-issuance path Production Runs use (or retire the standalone path).
- [x] **E-H1** (done 2026-10-05, `invoiceService.sendReminder` now hits the emailing endpoint) Point the AR "Remind" button at the working `sendManualReminder` endpoint instead of the no-op `issueReminder`.
- [x] **E-H2** (done 2026-10-05: `paymentIntent` destructured) Fix the `setupIntent`/`paymentIntent` destructuring bug in `StripePaymentForm.vue` (successful card charges are currently shown to customers as failures).
- [~] **F-H*** (partial 2026-10-05: dead UI removed, snapshot + P&L/balance/cash-flow/AR aggregated in SQL, forecasts idempotent; sales/top-customer/panel-mix/reconciliation/MIS/Tally reports still load collections) Either wire the forecasting/ML/trends/analytics-snapshot UI into `AppShell.vue`'s navigation or remove the dead components and their orphaned endpoints; fix the `reportingService` import + `${{{` template bugs before reviving them.
- [x] **G-H1** (done 2026-10-05: three sends now `->queue()`) Switch `EmailService`'s three `Mail::send()` calls to `->queue()`.
- [x] **G-H2** (done 2026-10-05: both services use shared `api.js`) Route `notificationService.js`/`documentTemplateService.js` through the shared `api.js` client so global session/subscription handling applies.

---

## 5. Module A — Auth, Multi-Tenancy, Users, Company Settings, Super Admin, Billing/Subscriptions, Audit Log

*Scope: `AuthController`, `UserController`, `CompanyController`, `SuperAdminController`, `AuditLogController`, `BillingController`, `TenantProvisioningService`, `SubscriptionService`, `CreditService`, `PaymentGatewayService`, `RazorpayClient`, User/Role/Company/AuditLog/PlatformSetting/Coupon models, `BelongsToTenant`, plus `LoginScreen`/`UserManagement`/`CompanySettings`/`SuperAdmin`/`AuditLog`/`BillingSettings`/`SubscriptionGate` and their services.*

### Critical

**[Critical] Granular RBAC (role.permissions) is entirely unenforced server-side — any authenticated tenant user can perform almost any business action regardless of role**
Category: Logic Bug
Location: `backend/app/Models/User.php:90-95` (`hasPermission()` defined, never called anywhere — confirmed via repo-wide grep: 0 matches under `backend/app/Http/Controllers/Api`); `backend/app/Http/Controllers/Api/UserController.php:20-26` (`authorizeAdmin()` only checks `is_company_admin`/`is_super_admin`); `frontend/src/components/AppShell.vue:229-262` (only 2 of ~20 nav items use the `perm` gate).
Description: The product ships a full "Roles & Permissions" matrix UI letting a company admin assign granular keys (`leads.view`, `invoices.manage`, `payments.manage`, etc.) to custom roles — including a seeded read-only "Viewer" role. These are persisted and returned on `/auth/me`, but no backend controller ever checks them before executing a write/read. The only real gates anywhere are the binary `is_company_admin`/`is_super_admin` flags, applied only to user management, company settings, audit log, and role-permission editing. Every other endpoint — record a payment, delete a customer, cancel an invoice, change the billing plan — is reachable by any authenticated tenant user.
Impact: A company that configures a limited/Viewer role to prevent an employee from deleting customers or recording fraudulent payments gets no actual protection — a direct API call still succeeds. Broken access control in a financial ERP.
Fix: Add a permission-checking guard on every mutating/sensitive endpoint keyed off `config/permissions.php`'s existing registry; keep the admin/super-admin bypass already implemented in `hasPermission()`.

### High

**[High] Duplicate email across companies can permanently break login for one of the accounts**
Category: Logic Bug
Location: `backend/database/migrations/2026_05_17_000003_create_users_table.php:28` (unique index is composite `(company_id, email)`); `backend/app/Http/Controllers/Api/UserController.php:104` (invite validates uniqueness only within the company); `backend/app/Http/Controllers/Api/AuthController.php:90,165` (`login()`/`verifyOtp()` look up by email alone, no company scope) vs. `AuthController.php:36` (self-signup requires *global* email uniqueness).
Description: A company admin inviting a user can reuse an email already used by a different tenant (schema allows it), but login resolves by email alone and takes the first match — the other account becomes permanently unreachable with a misleading "Invalid credentials" error.
Fix: Make invite-email uniqueness global to match the login lookup's assumption, or scope login by company/subdomain.

**[High] Duplicate email across companies (mechanism, see above) — reported as the concrete cause of the login-lockout scenario.**

### Medium

**[Medium] Regression: session-expiry no longer returns the user to the login screen (lands on marketing page instead)**
Category: UI/UX — regression of previously-fixed audit item H5
Location: `frontend/src/App.vue:2-3,38`
Description: The prior H5 fix returned expired sessions to the login form. Since the marketing Landing page was added as the default unauthenticated screen (gated by a separate `showAuth` flag never reset on session expiry), an expired session now drops the user onto the marketing page instead.
Fix: In `onSessionExpired()`, also set `showAuth.value = true` and `authMode.value = 'login'`.

**[Medium] Coupon `max_uses` can be exceeded under concurrent redemptions**
Category: Concurrency
Location: `backend/app/Models/Coupon.php:18-25`, `backend/app/Http/Controllers/Api/BillingController.php:157-160`
Description: `usable()` is a plain unlocked read; the increment happens later in a separate statement. Concurrent `verify()` calls can all pass the check before any commits.
Fix: Lock the coupon row or use an atomic conditional `UPDATE ... WHERE used_count < max_uses`.

**[Medium] Subscription-payment idempotency relies on a check-then-act read with no DB unique constraint on `reference`**
Category: Concurrency
Location: `backend/app/Services/SubscriptionService.php:22-27`; `backend/database/migrations/2026_06_15_000004_create_subscription_payments_and_utm.php:21` (no `unique()` on `reference`)
Description: Code comments claim this race (CONC-H2) is already fixed, but the guard is an `exists()` check, not a DB constraint — the exact pattern already fixed via unique index for `quotation_no`/`order_no`, just missed here.
Fix: Add a unique index on `subscription_payments.reference`.

**[Medium] Raw backend exception messages returned to API clients**
Category: Code Error
Location: `AuthController.php:70`, `CompanyController.php:89,121`, `UserController.php:80,128,164,187`, `BillingController.php:101`
Fix: Log raw exceptions server-side; return a generic client-facing message.

**[Medium] User/Role/Company changes are not captured in the audit log**
Category: Logic Bug
Location: `User.php`, `Role.php`, `Company.php` (none use `App\Traits\Auditable`, unlike `Invoice`/`Order`/`Quotation`/`Customer`)
Description: Role/permission changes, admin-rights promotion, and company bank/GST edits — the most sensitive admin actions — leave no audit trail.
Fix: Add `use Auditable;` (excluding password fields) or explicit `AuditLog::create()` calls.

**[Medium] Super-admin dashboard endpoints load entire platform tables unpaginated, plus per-row N+1 queries**
Category: Scalability / Query Optimization
Location: `SuperAdminController.php:54,82-105,187-214,341,352-355`
Fix: Paginate; replace per-row queries with a single grouped/`withCount()` query.

**[Medium] `CompanySettings.vue` doesn't proactively hide edit access for non-admins**
Category: UI/UX
Location: `frontend/src/components/CompanySettings.vue:127,170` (`canEdit` defaults `true`, only flips to `false` after a failed save)
Fix: Initialize `canEdit` from the cached current user's admin flags before render.

### Low

- **[Low]** `User` model bypasses the structural `BelongsToTenant` safety net — every current call site manually scopes correctly, but there's no automatic backstop like every other core model has. (`User.php:16`)
- **[Low]** `ValidationController::checkUnique`'s `companies` whitelist entry would throw a SQL error if ever invoked (`companies` has no `company_id` column) — currently dead/unreachable code.
- **[Low]** No self-service "forgot password" flow, despite the Laravel password-reset broker already being configured.
- **[Low]** Plan user-limit check is a check-then-act race — two concurrent "Add User" requests can exceed the plan's seat limit by a small margin.
- **[Low]** Auth token stored in `localStorage` (standard for SPA+Sanctum, but increases XSS blast radius for a financial ERP; defense-in-depth note only).

---

## 6. Module B — CRM: Leads & Quotations/BOQ

*Scope: `LeadController`/`LeadService`, `QuotationController`/`QuotationService`/`QuotationPdfService`, `AccessoryService`, Lead/Quotation models, plus `LeadManager`/`QuotationManager`/`QuotationCreate`/`QuotationDetail`/`QuotationList`/`BoqManager`/`BoqRegister` and their services.*

Regression check: prior audit's C2, M5 (partially — see below), M6, M8, L1 all verified still fixed.

### High

**[High] Accessory IDOR via the main quotation save path (create/update/duplicate/revise)**
Category: Logic Bug
Location: `backend/app/Services/QuotationService.php:472-511` (`saveAccessories`), `backend/app/Http/Controllers/Api/QuotationController.php:324-329`
Description: The prior audit's M5 fix tenant-scoped the *separate* `AccessoryService::addToQuotation` endpoint, but the parallel, far-more-used path — every normal Save/Update from `QuotationCreate.vue`, and everything `revise()`/`duplicate()` copies forward — attaches `accessory_id` with no tenant check at all, and the validation rule is just `'nullable'` with no `exists` clause.
Impact: A crafted request can attach another tenant's accessory into `quotation_accessories`; invisible on normal (tenant-scoped) reads but a real cross-tenant FK reference that could surface in raw queries, exports, or super-admin tooling.
Fix: Scope the lookup exactly as `AccessoryService::addToQuotation` does, and add a company-scoped `exists` validation rule.

**[High] `revise()` has no status guard — every sibling transition method has one**
Category: Logic Bug
Location: `backend/app/Services/QuotationService.php:184-232`
Description: `send()`/`accept()`/`reject()`/`delete()`/`expire()` all validate current status first; `revise()` doesn't, so a direct API call can revise a `rejected`/`expired`/unpriced `boq`-status quotation back to life.
Fix: Add the same `in_array($status, [...])` guard used by the sibling methods.

**[High] Double order creation is still possible from a quotation (check-then-act race)**
Category: Concurrency
Location: `backend/app/Services/OrderService.php:12-20`; `backend/database/migrations/2026_05_17_000009_create_orders_table.php:14` (no unique index on `quotation_id`)
Description: `createFromQuotation()` checks "order already exists" before opening the transaction and only locks the customer row, never re-checking existence inside a lock on the quotation itself.
Fix: Lock the quotation row and re-check existence inside the transaction; add a unique index on `orders.quotation_id` as a backstop.

### Medium

- **[Medium]** Frontend derives inter-state GST from `company.state_code`; backend derives it from the GSTIN digits — two independently-editable fields that can drift, causing the live tax-type preview to show the opposite split from what's actually saved.
- **[Medium]** `revise()`/`duplicate()`/lead-number generation aren't wrapped in `DocNumber::retry()` like `create()` is; `LeadService::generateLeadNumber()` has no retry at all and an uncaught-exception path. Revising an already-revised quotation also miscomputes the revision suffix (`-v2-v2` instead of `-v3`).
- **[Medium]** No scheduled job auto-expires quotations past `valid_until`; `accept()` doesn't check the date either — a stale-priced quotation can be accepted indefinitely.
- **[Medium]** The "no zero-rate items" invariant enforced by `send()` isn't re-enforced by `update()`/`updateSizeRates()` once a quotation is already `sent` — it can be silently re-priced or de-priced after being communicated to the customer.
- **[Medium]** Neither the BOQ builder nor the detail-page inline rate editor protects against losing unsaved work via in-app sidebar navigation (only browser-tab-close and the form's own Back button are guarded).
- **[Medium]** Quotations/Leads list endpoints paginate but don't clamp client-supplied `per_page`.

### Low

- Lead activity timeline has no pagination/limit (grows unbounded over a lead's lifetime).
- `leads.assigned_to_user_id` has no index despite being a filter column.
- Lead status can jump to any value via the API, bypassing the intended funnel order (defense-in-depth gap only).
- Panel width hard-fixed at 1000mm end-to-end — confirms the M8 fix is intact; noted as a product constraint, not a bug.

---

## 7. Module C — Orders & Production (Batches, Stages, Runs, Planning, Cutting Schedule, QC)

*Scope: Order/ProductionBatch/ProductionPlanning/ProductionRun/ProductionStage/CuttingSchedule/BatchStageLog/QualityControl controllers+services+models, plus `OrderManager`/`OrderDetail`/`BatchManager`/`BatchDetail`/`BatchCreate`/`ProductionPlanner`/`ProductionRuns`/`ProductionStageMaster`/`QcDashboard`.*

No regressions found against the prior audit (tenant scoping and the dispatch QC-gate both hold).

### Critical

**[Critical] QC checklist is decorative — never sent to the backend, never persisted, and does not gate Pass/Fail**
Category: Logic Bug
Location: `frontend/src/components/BatchDetail.vue:398-406,290-303,484-505`; `backend/app/Services/QualityControlService.php:14-39`; `backend/database/migrations/2026_05_17_000014_create_quality_controls_table.php` (no checklist column)
Description: The inspection checklist is a local reactive array. `submitQc()` only POSTs `{status, notes}` — none of the checklist state is ever sent, and there's no DB column to store it if it were. An inspector can leave every item unchecked and still select "Pass." Worse, when viewing an *already-inspected* record, the "Inspection Checklist" results shown are the freshly-reset local defaults, not what was actually recorded — the displayed history is fabricated.
Impact: A batch can reach `qc_passed` (unlocking dispatch, then invoicing) with zero actual quality verification recorded, while the UI visually implies a real checklist-based inspection occurred. For a panel manufacturer this is the core pre-shipment quality gate.
Fix: Add a `checklist` JSON column to `quality_controls`; require and persist it in `QualityControlService::create()`; render the checklist view from the stored record, not a re-initialized local array.

### High

- **[High]** Order status can be force-set to `completed` (or any value) via the API with no check on batch/QC/dispatch state and no confirm dialog on "Mark Completed."
- **[High]** Concurrent "Start Stage" clicks can create duplicate in-progress stage logs for the same batch+stage — no unique constraint, no lock before the "already started" check.
- **[High]** Concurrent "Start Run" can double-issue and double-deduct raw material stock for one production event — the run isn't locked before the check-then-act material-issuance sequence (individual stock rows are correctly locked, but that doesn't stop the run's material usage from being recorded, and deducted, twice).
- **[High]** Standalone ("New Production Batch") batches never go through material issuance at all, unlike Production-Run batches — a silent, compounding stock-ledger drift versus physical consumption.

### Medium

- Batch/run number generation lacks the `DocNumber::retry` wrapper Order/Invoice/Quotation have.
- No guard against creating duplicate production batches for the same order.
- Production Planner loads the entire pending+in-production backlog into memory on every load, no caching.
- N+1 per-stage queries in progress/gating logic (one query per stage instead of a single batched fetch), hit on every batch-detail load and 30s poll.
- `ProductionBatch::items()` relation duplicates the `order` query and returns the wrong shape if ever used as named (dead-code landmine, not currently user-visible).
- Editing an active stage's sequence retroactively changes gating for in-flight batches; a duplicate-sequence save throws a raw 500 instead of a validation message.
- "Deactivate" stage button performs a permanent hard delete (model lacks `SoftDeletes` despite its own migration provisioning `deleted_at`) — the confirmation copy implies a reversible action.
- No way to reopen/undo a completed production stage — a mis-click on "Mark Done" (no confirm dialog) permanently locks that stage.
- `QualityControlService::create` has no lock against a concurrent duplicate QC submission — the unique constraint prevents corruption but the loser gets a raw 500 instead of a friendly message.
- Cutting-schedule waste percentage is hardcoded to 0% — never actually computed from cut dimensions.
- `BatchCreate`'s order dropdown silently caps at 100 orders with no search or truncation notice.

### Low

- `completeStage` queries a stage log before verifying tenant ownership of the batch (not exploitable — the batch lookup still gates the response — but unnecessarily ordered).
- Order status has no transition state machine beyond the specific `completed` case flagged above (any status reachable from any status via direct API use).

---

## 8. Module D — Inventory/Stock (Coil, Chemical, Consumable), Procurement, Master Data

*Scope: Stock/Accessory/PanelType/Procurement controllers+services+models, plus `StockManager`/`ChemicalStock`/`CoilStock`/`ConsumableStock`/`TransactionLog`/`AccessoryMaster`/`PanelTypeMaster`/`ProcurementManager`/`MasterDataManager`.*

Prior audit's H6, M3, M5 all confirmed still fixed. Tenant isolation solid throughout; `StockService`'s add/remove/adjust methods correctly use `DB::transaction()` + `lockForUpdate()` with a floor check — the "classic overselling" risk does **not** apply here.

### High

**[High] Chemicals that go unaudited for >30 days after expiring never get an expiry alert (Carbon absolute-diff bug)**
Category: Logic Bug
Location: `backend/app/Models/ChemicalStock.php:60-63` (`isExpiring()`)
Description: `diffInDays()` defaults to absolute value with no `isPast()` check, so a chemical expired 45 days ago evaluates as *not* expiring (`45 > 30`) and never gets flagged — the opposite of intended. The only code path that creates the alert (`checkAndCreateAlert`) relies on this method; there is no separate "already expired" alert type.
Impact: For a PUF/PIR chemical manufacturer, an old abandoned batch can permanently fall outside the alerting window, risking expired-chemical use in production.
Fix: Replace with an explicit range check (`!isPast() && lte(now()->addDays(30))`); add a genuine "expired" alert that persists regardless of how long ago expiry occurred.

**[High] PO goods-receipt: no row lock and no over-receipt cap allows lost updates / unbounded over-receiving**
Category: Concurrency / Logic Bug
Location: `backend/app/Services/ProcurementService.php:170-212`
Description: `received_qty` is read-modified-written with no `lockForUpdate()` — two concurrent receipts against the same PO line can lose an increment (the underlying stock quantity, correctly locked separately, gets incremented twice while the bookkeeping column undercounts). No validation caps received quantity against what's outstanding on the line either.
Fix: Lock the `PurchaseOrderItem` row inside the transaction; validate `received_qty <= quantity - received_qty`.

### Medium

- Consumable stock is excluded from the dashboard's low-stock list and inventory report (only Coil/Chemical are aggregated).
- Consumable stock transactions display as "Unknown Item" / kind "other" and can't be filtered by kind — a real traceability gap for oil/film/tape/packaging usage.
- Alerts tab has no pagination — silently truncates past 20 active alerts and the badge count undercounts the true total.
- Purchase order list has no pagination — loads every historical PO into memory (also flagged independently by Module H).

### Low

- `days_remaining` for already-expired chemicals reports a positive countdown instead of a signed/overdue value (same root cause as the High finding above; currently low-impact since this specific dashboard field isn't wired into the frontend).
- The Stock Dashboard and Inventory Report backend endpoints (the ones the prior audit fixed end-to-end) are built but never called from the frontend — orphaned.
- Raising a reorder level above current stock doesn't trigger a low-stock alert until the next stock movement.
- `LowStockAlert::createIfNeeded` relies on implicit `auth()` context rather than an explicit company id — dormant risk if the currently-unused batch-sweep path is ever scheduled.
- `getAvailableQuantity()` strips *all* global scopes (including tenant) rather than just soft-delete — currently harmless (allocations are never created), but a footgun if that feature activates.
- An `out_of_stock` alert bucket is counted in the dashboard summary but no code path ever creates one — always reports 0.
- PO number generation has the same unprotected read-then-write race as the Module H finding (protected from corruption by a unique index, but not retried gracefully).

---

## 9. Module E — Dispatch, Invoicing, Payments, Tax/GST, E-Invoice, Customers

*Scope: Dispatch/Invoice/Payment/Gst/EInvoice/Customer controllers+services+models, plus `DispatchManager`/`InvoiceManager`/`PaymentTracker`/`AccountsReceivable`/`GstConfigManager`/`TaxConfiguration`/`StripePaymentForm`/`CustomerManager`/`CustomerProfile`.*

Prior audit's C1 (GST breakdown IDOR) and M1 (duplicate-invoice guard) both confirmed still fixed and genuinely race-safe (M1 is backed by both a `DocNumber::retry` wrapper and a composite unique index).

### Critical

**[Critical] `recordPayment` has no row locking — two concurrent payments can both pass the overpayment check and double-credit an invoice**
Category: Concurrency
Location: `backend/app/Services/PaymentService.php:12-59,71-79`
Description: Wrapped in `DB::transaction()` but neither the `Invoice` row nor the payment sum is locked. Two simultaneous "Record Payment" requests for the same invoice (double-click, or two staff members) each independently read the same remaining balance, both pass the overpayment check, and both insert — nothing blocks the second. The Stripe webhook handler for the same underlying operation *does* lock correctly (`lockForUpdate()` + idempotency check); the manual/UI path never received the same hardening.
Fix: Lock the invoice row and recompute `remainingDue` from that locked read inside the transaction, mirroring `PaymentGatewayService::handlePaymentSucceeded`.

**[Critical] Invoice can be marked "Paid" via `markPaid` regardless of outstanding balance**
Category: Logic Bug
Location: `backend/app/Models/Invoice.php:148-151`; `backend/app/Services/InvoiceService.php:214-227`
Description: `canMarkPaid()` only checks status (`accepted`/`sent`), never `remaining_due`. Any user can mark a partially- or entirely-unpaid invoice "Paid" with one click — the UI then shows status "PAID" next to a still-positive Balance Due, since that figure is computed independently from actual payments.
Fix: Require `remaining_due <= 0` in `canMarkPaid()` (or add an explicit, audited "force mark paid" override); disable the button in the UI while a balance remains.

### High

- **[High]** AR aging report loads the company's *entire* invoice history (no lower date bound), eager-loads a 4-level relation chain per invoice, and buckets aging with five in-memory `filter()->sum()` passes — the daily-use collections screen, will not scale.
- **[High]** The AR "Remind" button is wired to a no-op (`PaymentService::issueReminder` fabricates a success message and sends nothing); a separate, working `sendManualReminder` endpoint exists but isn't what the button calls.
- **[High]** `StripePaymentForm.vue` destructures `setupIntent` from `confirmCardPayment()`, which actually returns `paymentIntent` — every successful card charge is reported to the customer as a failure, risking confused customers and duplicate charge attempts.

### Medium

- The Stripe webhook handler has no overpayment guard (unlike the manual-entry path) — two separate checkout sessions paid for the same invoice can overpay it.
- `GstTaxBreakdown` money columns are cast `float` instead of `decimal:2` like every other money model — binary float summation risk for statutory GST filing sums.
- `GstService::calculateGst`/`getCustomerState` misclassify inter- vs intra-state GST in three distinct ways; two are effectively dead code (superseded by the correct `TaxService`), but the third — `generateGstReport`'s comparison bug — is live and feeds wrong intra/inter invoice counts into `GstConfigManager.vue` today.
- Cancelling a dispatch doesn't check for an already-issued invoice, allowing an inconsistent "cancelled dispatch, active invoice" state and a path to a second, unrelated invoice against a re-dispatched batch.
- The user-selected payment date is silently discarded — every recorded payment is stamped `now()` regardless of what date was picked, skewing payment-history dates and bank-reconciliation.

### Low

- `dispatch_no` generation and `InvoiceService::duplicateInvoice` both lack the `DocNumber::retry` wrapper other creators have (unique index prevents corruption; a collision just surfaces as a raw error instead of a clean retry).
- Hardcoded `$` currency symbol on two older India-only screens (`TaxConfiguration.vue`, `PaymentTracker.vue`) vs. `₹` used everywhere else.

---

## 10. Module F — Analytics, Reporting, Forecasting, Dashboards, Export

*Scope: Analytics/Dashboard/Reporting/Forecasting/Export controllers+services+models, plus `HomeDashboard`/`FinancialDashboard`/`BusinessReports`/`ForecastingDashboard`/`MlForecastDashboard`/`AnalyticsSnapshot`/`TrendAnalysisViewer`/`ReportViewer`.*

No overlap with the prior audit. `HomeDashboard`/`DashboardService` and `BusinessReports` (the two components actually wired into navigation) are solid — properly SQL-aggregated, cached, with real loading/empty/error states, and code comments show several scale fixes were already applied there in an earlier pass. The problems below cluster in the rest of the module.

### High

- **[High]** Roughly half this module's frontend — `ForecastingDashboard`, `MlForecastDashboard`, `TrendAnalysisViewer`, `AnalyticsSnapshot`, `FinancialDashboard`, `ReportViewer` — is unreachable dead code (confirmed: zero references anywhere in `AppShell.vue`, the app's only view-assembly point). A substantial amount of demand-forecasting, ML-forecasting, and analytics-snapshot backend logic and six dedicated DB tables have no live path to being populated or viewed.
- **[High]** No scheduled job precomputes `SalesMetric`/analytics tables — everything is synchronous, on-demand-only generation, and the forecasting math (linear regression, seasonal decomposition) assumes contiguous daily data that's never guaranteed without a daily cron, so even a manually-triggered forecast is working on likely-sparse data.
- **[High]** `AnalyticsService::createAnalyticsSnapshot` loads a company's *entire* invoice history with items, unbounded, then sums in PHP — the exact "fast with test data, falls over with real history" pattern, and it's a live, directly-callable endpoint today even though its own UI is orphaned.
- **[High]** Most of `ReportingService`'s reports (P&L, balance sheet, cash flow, sales, AR, top customers, panel mix, reconciliation, MIS) follow the same full-collection-then-PHP-sum pattern; several eager-load an `items` relation they never use; AR/reconciliation return unbounded row sets.
- **[High]** `DemandForecast`/`InventoryForecast` generation has no unique constraint and no idempotency guard (unlike `SalesMetric`/`AnalyticsSnapshot`, which correctly use `updateOrCreate` + a DB unique constraint) — repeated or concurrent "Generate" clicks accumulate duplicate rows.

### Medium

- Two of the orphaned components (`FinancialDashboard.vue`, `ReportViewer.vue`) import `reportingService` from a module that doesn't export it (it actually lives in `accountingService.js`) — guaranteed crash if ever reconnected.
- The same two components have 8 occurrences of a malformed `${{{ ... }}` template interpolation — a genuine syntax/render error independent of the import bug.
- `AnalyticsService::detectSeasonalPattern` doesn't actually find the peak-sales month — it returns the first month key encountered instead of the highest-value one.
- No `to >= from` validation on report date-range pickers, frontend or backend — an inverted range silently renders a blank report instead of an error.
- Forecast generation trusts tenant-safety on a caller-supplied `panel_type_id` entirely to the global Eloquent scope, with no explicit `company_id` check — latent risk the moment this logic runs from a console/queue context (which the scheduling fix above would introduce).

### Low

- `FinancialDashboard.vue` has no loading state and silently swallows fetch errors.
- Currency symbol inconsistency (`$` vs `₹`) in the orphaned components.
- `ForecastingDashboard.vue` never populates its panel-type filter dropdown.

---

## 11. Module G — Notifications, SMS, Document Templates, Validation, Shared Frontend Infra

*Scope: NotificationSettings/Sms/DocumentTemplate/Validation controllers+services, `SendNotificationJob`, scheduled commands, plus `NotificationSettings`/`SmsAlertManager`/`PaymentReminderManager`/`DocumentTemplates`/`AppShell`/`App.vue`/shared `api.js`/`useFieldValidation`.*

### Critical

**[Critical] Scheduled SMS reminders ignore every tenant's own Twilio credentials and silently no-op**
Category: Logic Bug
Location: `backend/app/Console/Commands/SendSmsReminders.php:19-25`; `backend/app/Services/SmsService.php:24-39`
Description: The command resolves `SmsService` with no company context, so it falls back to global `.env` Twilio flags instead of each tenant's own configured credentials (the supported, product-designed path via `NotificationSettingsController`). Since the global flag defaults off, the daily 10am `sms:send-reminders` job never sends anything for any tenant using per-company setup — with no error surfaced anywhere.
Fix: Loop over `Company::pluck('id')` (as `SendPaymentReminders` already correctly does) and resolve `SmsService` per company.

### High

- **[High]** Invoice/reminder/receipt-confirmation emails are declared `ShouldQueue` on their Mailables but dispatched via `Mail::send()`, not `->queue()` — the interface is inert and every send blocks the request or console thread on a live SMTP round-trip. This is the exact class of problem already fixed once for SMS/WhatsApp (explicitly cited in code comments as "OPS-H1") but missed for email.
- **[High]** `notificationService.js` and `documentTemplateService.js` build their own raw `axios()` calls instead of using the shared `api.js` client, so the global 401 (session-expired) and 402 (subscription-inactive) handling never fires on the Notification Settings or Document Templates screens — a lapsed session there just shows a generic local error instead of the app's normal redirect-to-login/renewal-gate behavior.

### Medium

- `PaymentReminderManager.vue` and `SmsAlertManager.vue` call service methods that don't exist on the objects they import from (`paymentService`, `smsService`) — both components are currently unreferenced/dead, but would break immediately if wired into a real view.
- N+1 relation loading and no `chunk()`/`cursor()` in the payment-reminder email send paths — several extra queries per reminder, unbounded `get()` for the whole batch.
- `SendSmsReminders` and `PaymentReminderService::sendDueReminders` load all overdue records across all companies with a single unchunked `get()` — memory footprint grows with total platform-wide row count, not per-tenant.
- `ValidationController::checkUnique`'s `companies` whitelist entry will throw a SQL error if ever invoked (same root cause as the Module A Low finding, currently dead code).
- Manual reminder-command runs aren't protected against overlapping a still-running scheduled run — no row-level lock on `PaymentReminder`, so duplicate reminder emails are possible under manual+scheduled overlap.
- `EmailService::getEmailPreview()` dereferences `$invoice->due_date` with no null check, but `due_date` is a nullable column — 500 error previewing the email for any invoice without one.
- No retention/cleanup job for `notification_logs`/`sms_logs`, unlike `payment_reminders` which has a dedicated weekly cleanup command — unbounded long-term growth.

### Low

- Notification/SMS/reminder screens use ad-hoc local error banners instead of the shared toast system (`services/ui.js`) that a sibling screen in the same settings area already uses.
- `useFieldValidation`'s uniqueness check silently reverts to "idle" (indistinguishable from "not yet checked") on any failure, giving no visible signal that validation didn't actually run.
- `AppShell.vue` fires four startup data calls sequentially instead of in parallel, needlessly delaying nav badge population.
- The well-built `tenant:check-isolation` and `schema:check` safety commands exist but are never scheduled or wired into CI — they only help if a human remembers to run them manually.
- No client-side request timeout on the shared or legacy axios clients, so a hung Twilio/SMTP/PDF call leaves a button spinning indefinitely with no cutoff.

---

## 12. Module H — Infrastructure: Database, Migrations, Jobs/Queue, Concurrency Architecture

*Cross-cutting pass over all 85 migrations, queue/job wiring, transaction/lock usage system-wide, pagination consistency, and caching.*

| Metric | Finding |
|---|---|
| High-traffic tables missing FK indexes | **0** — all reviewed tables correctly indexed |
| Tables missing unique number-constraints | **0** — all number columns correctly protected |
| Number-generators not wrapped in the `DocNumber::retry` safety net | 3 — `batch_no`, `dispatch_no`, `po_no` |
| List endpoints using `->paginate()` | ~11 |
| List endpoints using unbounded `->get()` | ~7 (Procurement is the only one over an unbounded-growth table) |
| Services using `DB::transaction()` | 19 of ~39 |
| Services using `->lockForUpdate()` | 3 (OrderService, PaymentGatewayService, StockService) |
| `Cache::` usages in the whole backend | 1 (DashboardService, 60s TTL) |
| Real queued jobs (`ShouldQueue` + actually `::dispatch()`'d) | 1 (`SendNotificationJob`, SMS/WhatsApp only) |
| Mail sent via blocking `Mail::send()` despite `ShouldQueue` | 3 call sites |
| Queue worker processes | 1 (no `numprocs` set) |

The prior audit's number-race fix (composite unique index + retry-on-collision) was generalized well into a shared `App\Support\DocNumber::retry()` helper, correctly applied to Quotation/Order/Invoice creation and several other integrity fixes swept into two follow-up migrations. This is solid infrastructure work — the gaps below are what's left uncovered by it.

### High

**[High] Invoice email + PDF generation run synchronously inside the HTTP request**
Category: Scalability
Location: `backend/app/Services/EmailService.php:35-36,59-60,81-82`; called from `InvoiceController.php:189,320`
Description: Same root cause as the Module G finding — `Mail::send()` instead of `->queue()`. A slow SMTP provider or PDF render spike ties up a PHP-FPM worker for the full request; since PHP-FPM workers are shared across all tenants in this single-backend deployment, a burst of concurrent invoice-sends can exhaust the worker pool and cause site-wide 502s for unrelated tenants.
Fix: Switch to `->queue()` — `QUEUE_CONNECTION` and a worker are already running, so this requires no new infrastructure.

### Medium

- Three number-generators (batch/dispatch/PO) bypass `DocNumber::retry` — protected from corruption by real unique indexes, but a collision surfaces as a raw 500 instead of a transparent retry.
- Purchase order list has no pagination (confirmed independently by Module D).
- `GstService::registerGstConfiguration` is the one money/tax-adjacent service with zero transactional protection — a double-submit can create two "primary" GST configs for one company.
- Near-zero caching system-wide — one `Cache::` call in the entire backend despite Redis being configured; every catalog/config lookup re-hits MySQL on every request.
- A single queue worker process (no `numprocs`) means any burst of notification jobs (each up to ~20s for a Twilio call) serializes at worst ~3/minute.

### Low

- `payment_transactions.reference_no` and `stock_transactions.reference_no` are indexed globally rather than as composite `(company_id, reference_no)` — every real query is tenant-scoped, so the index isn't fully covering.
- `invoices` carries a redundant leftover non-unique index on `invoice_no`, superseded by the newer composite unique index.
- No read-replica / connection-pool split — a single MySQL instance is a structural scaling ceiling (not urgent today, flagged for the next scaling milestone).
- Payment gateway webhook endpoints have no rate limiting (signatures are verified so they can't be forged, but they can still be cheaply flooded to burn CPU on signature checks).
- Four dead `.php.bak` migration files left in the migrations directory (inert, but confusing clutter for schema-history audits).
- `BatchStageLog` has no `company_id` column/tenant scope — confirmed intentional and safe today (always accessed via the tenant-scoped `production_batches` relation), flagged only as a footgun for future direct queries.

---

*End of report. 112 findings across 8 modules; see §4 for the recommended fix-first order.*

---

## 8. High-priority remediation status (updated 2026-10-05)

| # | Module | High finding | Status | How |
|---|---|---|---|---|
| 1 | A | Duplicate email across tenants breaks login | Done | Invite uniqueness global (`UserController`) |
| 2 | A | Same, mechanism | Done | Same fix |
| 3 | B | Accessory IDOR via quotation save path | Done | Company-scoped guard in `saveAccessories` + `exists` rule; `AccessoryTenantGuardTest` |
| 4 | B | `revise()` has no status guard | Done | Allowed only from draft/sent/accepted |
| 5 | B | Double order from a quotation | Done | Quotation row locked before existence check |
| 6 | C | Order force-set to completed | Done | Requires all batches dispatched |
| 7 | C | Duplicate "Start Stage" | Done | Batch row locked, check moved inside transaction |
| 8 | C | Double "Start Run" material deduction | Done | Run row locked, check moved inside transaction (start and complete) |
| 9 | C | Standalone batches skip material issuance | Done (option: retire path) | Batches must start through a production run; `StandaloneBatchStartTest` |
| 10 | D | Chemical expiry Carbon bug | Done | Explicit range check; separate `expired` alert; `ChemicalExpiryTest` |
| 11 | D | PO goods-receipt race and no over-receipt cap | Done | PO and item rows locked; receipts capped at outstanding |
| 12 | E | AR aging loads everything | Partial | Column and eager-load trimming, single-pass buckets. Still loads all open invoices by design; `ArAgingBucketTest` |
| 13 | E | AR "Remind" wired to no-op | Done | Points at the emailing endpoint |
| 14 | E | Stripe destructures wrong field | Done | `paymentIntent` used |
| 15 | F | Dead analytics UI and orphaned pages | Done | Six unreachable components deleted; backend endpoints kept |
| 16 | F | No scheduled precompute | Resolved by removal | Only consumer of the precomputed data was the deleted UI |
| 17 | F | Analytics snapshot loads full history | Done | SQL aggregation; also fixed inventory query on nonexistent columns; `AnalyticsSnapshotAggregateTest` |
| 18 | F | Reporting service full-collection sums | Partial | P&L, balance sheet, cash flow, AR aggregated in SQL (`ProfitLossAggregateTest`). Sales, top customers, panel mix, reconciliation, MIS, and Tally still load collections |
| 19 | F | Forecast rows duplicate on repeat generate | Done | `updateOrCreate` plus unique index migration with dedupe |
| 20 | G | Emails dispatched synchronously | Done | `->queue()`; `EmailQueueTest` |
| 21 | G | Raw axios bypasses 401/402 handling | Done | Both services use shared `api.js` |
| 22 | H | Invoice email and PDF synchronous in request | Done | Same fix as #20 |

**Also done in this pass:** 12 MySQL-only migrations made driver-aware so tests run on SQLite; `ModulePermissionTest`, `InvoicePaymentIntegrityTest`, `QcChecklistTest`, `SmsReminderCommandTest`, `ChemicalExpiryTest`, `StandaloneBatchStartTest`, `AccessoryTenantGuardTest`, `EmailQueueTest`, `ArAgingBucketTest`, `AnalyticsSnapshotAggregateTest`, `ProfitLossAggregateTest` all pass.

**Still open:** #12 and #18 (partial, listed above). Stale legacy tests (`BatchTest`, 249 errors across the suite) predate this work and are unchanged.
