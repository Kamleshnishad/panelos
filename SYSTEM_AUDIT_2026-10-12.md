# System Audit — 2026-10-12

> Follow-up to `SYSTEM_AUDIT_2026-08-14.md`. That audit's 5 Critical + 22 High findings were remediated in commits `d66f122` (backend) and `9321e38` (frontend) on `feat/validation-hardening-rollout`, already pushed to origin. **This audit found that the remediation itself introduced new Critical and High bugs**, on top of fresh findings in areas the August audit covered lightly, and the first-ever review of the `mobile/` React Native app. Nothing in this document has been fixed yet — audit only, per request.

**Total new findings: 35** — 10 Critical · 8 High (1 invalidated on re-check, 7 real) · 9 Medium · 8 Low.

**Status as of 2026-10-12 (second pass): all 7 real High findings resolved and tested** (H2, H3, H4, H6, H7, M1, M2, plus QB2 from the Quotation/BOQ follow-up). H1 was re-verified and found not to reproduce against the real seeded roles — corrected in place rather than "fixed." New backend tests added this pass: `DefaultRolePermissionsTest` (5 tests) and `QuotationAccessoryValidationTest` (1 test), all passing; full suite confirmed at 351 tests / 81 passing with the same 249 pre-existing, unrelated errors as before (no regressions).

**Status as of 2026-10-12 (third pass): 7 of 8 Medium findings resolved and tested** (MD1, MD2, MD3, MD4, MD5, MD6, MD7, MD8 — all resolved; see each entry below). QB3 (transport GST rate) was raised with the user and explicitly deferred — left as-is pending accountant/business input, not fixed. New backend tests added this pass: `SubscriptionCouponAndWebhookTest` (2 tests), `ProcurementStockValidationTest` (2 tests), and 2 new tests added to `DefaultRolePermissionsTest`; full suite now at 357 tests / 87 passing with the same 21 pre-existing failures and 249 pre-existing errors as before (no regressions — all 6 new tests pass).

**Status as of 2026-10-12 (fourth pass): all 9 Critical findings resolved and tested** (C1–C9), plus QB1 from the Quotation/BOQ follow-up. Two decisions were raised with the user before fixing: C2's Dispatch ownership (resolved → Production role) and C1's standalone-batch architecture (resolved → standalone batches self-issue their own material, mirroring what a run already does). **While verifying C1/C9, this pass found and fixed a new, previously-undocumented, sweeping bug — see "NEW-1" below** — `Company::$fillable` deliberately excludes `subscription_plan`/`subscription_status`/`trial_ends_at`/`subscription_ends_at`/`is_active`, but 7 call sites across the codebase were mutating them via plain mass-assignment (`update()`/`create()`), which Eloquent silently no-ops for non-fillable keys with no error. This meant: every subscription activation (manual, Razorpay verify, Razorpay webhook) recorded a correct payment row while never actually updating the company's plan/status/expiry; every trial lazily never expired; and every new self-signup silently landed on the `growth` plan (DB column default) instead of `starter`, with a permanently-null trial end date. All 7 sites now use `forceFill()`. Also found and fixed in the same pass: `MaterialBomService::settings()` returned a model with null numeric fields (not DB defaults) on a company's very first call, silently zeroing coil/wastage/overpack calculations. All 10 Critical, all 8 Medium-adjacent new findings, and both Low-tier carryovers are covered; remaining open items are the original Low tier (L3-L8), QB3 (deferred), QB4, QB5. New backend tests added this pass: `ForecastIdempotencyTest` (2), `StripeWebhookRecordingTest` (2), `StandaloneBatchStartTest` (rewritten, 3), `RazorpayRenewalWebhookTest` (2), `SuperAdminTenantMutationTest` (2), `TenantLifecycleGuardedFieldsTest` (2), plus 5 new tests in `DefaultRolePermissionsTest` and 1 new test in `QuotationAccessoryValidationTest`. Full suite now at 375 tests / 105 passing with the same 21 pre-existing failures as before (no regressions — all 18 new tests pass).

Sources: direct investigation (this session), six deep-audit passes (mobile app; regression check on commit `d66f122`; fresh pass on previously-light-coverage backend areas; exhaustive route-by-route audit of the new permission middleware; Vue component-level pass; Razorpay/scheduled-commands pass), plus a targeted follow-up re-check of the Quotation/BOQ module requested separately (section 9).

**Headline, updated after the exhaustive route audit (section 8):** the permission-middleware rollout doesn't just mis-map a couple of routes — it leaves **three entire modules (Dispatch, Procurement, and Stock writes) completely unusable by any non-admin role**, because the permission keys those routes require were never granted to any seeded role in the first place. Combined with C1's batch-creation lockout, this means Production and Sales staff currently cannot do most of their job through the API as shipped on `feat/validation-hardening-rollout` — only Company Admin can. **Status (2026-10-12, fourth pass): fixed — Production now holds Dispatch, Procurement, and Stock-write permissions, and the batch-creation/dispatch-creation route mismatches are resolved; see C1/C2/C7/C8 above.**

---

## 1. Critical

### C1 — ✅ RESOLVED — Standalone batch creation is now completely non-functional for every role, including admins
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` (MODULES map) + `backend/app/Services/ProductionBatchService.php::startProduction`
**Found by:** direct check (this session) + regression-check agent
**Two stacked bugs, same root flow:**
1. `POST /api/orders/{orderId}/batches` (`ProductionBatchController::createFromOrder`) has URL segment `orders`, so the new permission middleware gates it on `orders.manage`. **No default role** (`TenantProvisioningService::createDefaultRoles()`) holds `orders.manage` — Sales has `orders.view` only. So no non-admin can create a batch at all.
2. Even if creation succeeds (e.g. as Company Admin, who bypasses permission checks), `ProductionBatchService::startProduction` now throws unless the batch has a non-null `run_id`. But the only UI path that creates batches — `frontend/src/components/BatchManager.vue` → `BatchCreate.vue` → `createFromOrder` — never sets `run_id`. So **every batch created through the live "Production" tab can never be started**, for anyone, admin included.

**Impact:** the "Production" tab (separate from "Production Runs") is completely dead for its core create→start workflow. This was supposed to be a security fix (audit finding C-H4, closing a material-tracking gap) and a permission fix; instead it broke a live, actively-used feature twice over.
**User decisions (raised before fixing):** (1) the batch-creation route's permission key should check `production.manage`, fixed regardless of the second decision; (2) standalone batches should **self-issue their own material** rather than retiring the tab — chosen over the alternative of folding the workflow into Production Runs.
**Fix applied:**
- Added a `ROUTE_OVERRIDE` map to `EnforceModulePermissions` (route name → required key, checked before the segment-based `MODULES` lookup) with `'batches.create' => 'production.manage'`, fixing the segment/permission mismatch at its root.
- Added `ProductionMaterialService::issueForBatch()`/`alreadyIssuedForBatch()`, mirroring `issueForRun()` but keyed on `batch_id` instead of `run_id` and computing the BOM requirement from the batch's own order (`MaterialBomService::requirementForOrder()`, which already existed).
- `ProductionBatchService::startProduction()` now takes a `$force` parameter, locks the batch row, and calls `issueForBatch()` when `run_id === null` instead of unconditionally throwing. `ProductionBatchController::startProduction()` passes `$request->boolean('force')` through, matching the existing Production Run override pattern exactly.
- Granted `production.manage` is already held by Production; no role change needed for the permission half beyond the route fix.
**Side effect found while verifying this fix:** `MaterialBomService::settings()` — see "NEW-1" below.
**Verified by:** `DefaultRolePermissionsTest::test_c1_production_role_can_create_a_standalone_batch` (permission routing) and 3 tests in `StandaloneBatchStartTest` (rewritten — it previously asserted the old, now-wrong behavior that a standalone batch could never start): no-material-requirement batches start immediately; batches with sufficient stock issue material and deduct it; batches with insufficient stock block with a clear message unless `force` is passed.

### C2 — ✅ RESOLVED — The entire Dispatch module, including just viewing it, is unreachable by any non-admin role
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php`
**Found by:** regression-check agent; confirmed and expanded by the exhaustive route audit
Originally found as "dispatch creation requires `production.manage` while every other dispatch route requires `dispatch.manage`, and no role holds both." The exhaustive route audit found the problem is worse: **`dispatch.view` and `dispatch.manage` are granted to zero seeded default roles** (Sales, Production, Accounts, Viewer). So it's not a mismatch between two keys someone might hold separately — nobody but Company Admin can even run `GET /dispatches` (list dispatches), let alone view, allocate, complete, or create one. The entire Dispatch module is admin-only in practice. One accidental side door exists: `GET /batches/{batchId}/dispatches` sits under the `batches` segment (→ `production.view`), so the Production role can list a batch's dispatches — but no other dispatch functionality.
**User decision (raised before fixing):** Production owns Dispatch by default (not Sales, not a new dedicated role) — dispatch is the natural next step after a batch is produced.
**Fix applied:** granted `dispatch.view`/`dispatch.manage` to the Production default role in both `TenantProvisioningService::createDefaultRoles()` (the real signup path) and `DefaultRolesSeeder.php`. Fixed the creation-route mismatch via the same `ROUTE_OVERRIDE` mechanism added for C1: `'dispatches.create' => 'dispatch.manage'` and `'dispatches.by_batch' => 'dispatch.view'` (both live under the `/batches/...` URL segment, which would otherwise gate them on `production.*`).
**Verified by:** `DefaultRolePermissionsTest::test_c2_production_role_can_list_and_create_dispatches`.

### C7 — ✅ RESOLVED — The entire Procurement module is unreachable by any non-admin role
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` vs. `TenantProvisioningService::createDefaultRoles()`
**Found by:** exhaustive route audit
`procurement.view`/`procurement.manage` are granted to zero default roles. Every procurement route — suppliers (list/create/update), purchasable materials, valuation, reorder suggestions, purchase orders (list/create/view/receive/cancel) — is admin-only. Both read and write are blocked, not just write.
**Fix applied:** granted `procurement.view`/`procurement.manage` to the Production default role (it already owns `stock.view`/`stock.manage`, so purchasing naturally sits alongside it) in both `TenantProvisioningService::createDefaultRoles()` and `DefaultRolesSeeder.php`.
**Verified by:** `DefaultRolePermissionsTest::test_c7_production_role_can_reach_procurement`.

### C8 — ✅ RESOLVED — No non-admin role can adjust stock or resolve stock alerts
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` vs. `TenantProvisioningService::createDefaultRoles()`
**Found by:** exhaustive route audit
`stock.manage` is granted to zero default roles — Production has `stock.view` only. Every stock-mutating route (`add`/`remove`/`adjust`/`reorder` for coils, chemicals, consumables, plus resolving a stock alert) is admin-only. This is a core, daily Production function (receiving coil stock, adjusting for wastage, clearing a low-stock alert) now blocked for the role whose entire job description is production and stock.
**Fix applied:** granted `stock.manage` to the Production default role in both `TenantProvisioningService::createDefaultRoles()` and `DefaultRolesSeeder.php`.
**Verified by:** `DefaultRolePermissionsTest::test_c8_production_role_can_adjust_stock`.

### C9 — ✅ RESOLVED — Razorpay subscription-renewal webhook silently never fires; the controller's always-200 response prevents any retry
**Where:** `backend/app/Http/Controllers/Api/BillingController.php:173-200` (`webhook()`)
**Found by:** Razorpay/scheduled-commands agent
**Pre-existing, not from last session.** This is the Razorpay analogue of C5's Stripe bug, reached a different way. The webhook is documented as a backstop for when the frontend's own `verify()` call never completes after a successful Razorpay payment (closed tab, crashed app, dropped network). But line 193 guards the actual crediting with `if ($company && $company->subscription_status !== 'active')` — which is wrong for a **renewal**: a company renewing before its current plan expires is, by definition, still `'active'` (status only flips to `'expired'` lazily on the next request past `subscription_ends_at`, via `EnsureActiveTenant` — no scheduled expiry job exists). So for exactly the renewal scenario the backstop exists to catch, `activate()` is never called — no payment row, no extension — while line 199 unconditionally returns `{'ok': true}`, so Razorpay marks the event delivered and never retries. The customer's payment is captured; PanelOS never reflects it, and there is no automatic recovery path, only a support complaint and manual reconciliation. (First-time payments are unaffected — a trial company's status is `'trial'`, not `'active'`, so the guard doesn't block those.)
**Fix applied:** removed the `subscription_status !== 'active'` condition entirely, relying solely on `activate()`'s own `reference`-based idempotency check (exactly as `verify()` already does).
**Second, deeper bug found while writing this fix's test:** even after removing the bad guard, the renewal silently had no effect — see "NEW-1" below. The fix for C9 above was necessary but not sufficient on its own; both had to be fixed together before a renewal actually worked end-to-end.
**Verified by:** `RazorpayRenewalWebhookTest` (2 tests) — a renewal webhook for an already-`active` company now extends `subscription_ends_at`; the idempotency guard still prevents double-crediting the same payment reference.

### C3 — ✅ RESOLVED — `/reports/*` is completely unprotected by the new permission middleware
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` (MODULES map)
**Found by:** regression-check agent
Every report route (`/reports/profit-loss`, `/balance-sheet`, `/cash-flow`, `/accounts-receivable`, `/sales`, `/tax`, `/mis`, `/tally/xml`, etc.) has URL segment `reports`, which was never added to the module map. Unmapped segments pass through with no permission check at all. **Any authenticated user of any role — including the read-only Viewer role — can read full P&L, balance sheet, cash flow, and AR data**, which is exactly the class of exposure the new middleware was built to close. This is the single most sensitive data category in the system and it's the one left open.
**Fix applied:** added `'reports' => ['reports.view', 'reports.view']` to the MODULES map.
**Verified by:** `DefaultRolePermissionsTest::test_c3_reports_module_is_now_protected_by_reports_view` — a role with `reports.view` (Accounts) gets 200; a role without it (Production) now correctly gets 403.

### C4 — ✅ RESOLVED — Forecast "idempotent upsert" fix doesn't work, and can crash in production
**Where:** `backend/app/Services/ForecastingService.php::generateDemandForecast`, `backend/app/Services/MlForecastingService.php::generateMlForecast`
**Found by:** regression-check agent (empirically reproduced)
Last session's fix for "repeated Generate creates duplicate rows" switched `create()` to `updateOrCreate()` keyed on `forecast_date`, but:
- `ForecastingService` matches on `$today = now()` (a full `Carbon` instance with time-of-day). The `DemandForecast` model's `'date'` cast has no format suffix, so saved values carry a full datetime, and the match value's time-of-day never lines up between calls — **confirmed by direct reproduction: two calls seconds apart created two separate rows**, not one updated row.
- `MlForecastingService` matches on `now()->toDateString()` (a plain date string), which looks safer, but the *stored* value still carries the full-datetime cast — so the match still fails, and the second call hits the new unique index and **throws an unhandled `SQLSTATE[23000]` duplicate-key error** (reproduced directly).
- On real MySQL, `ForecastingService`'s path is expected to throw the same duplicate-key error on a same-day re-run (DATE-vs-DATETIME coercion won't match a nonzero time-of-day against stored midnight).

**Impact:** the fix converts a silent, harmless duplication bug into a crash. No test was added for this despite the commit message explicitly claiming it was fixed.
**Root cause confirmed (not just inferred):** `HasAttributes::fromDateTime()` (the method Eloquent uses to format a date-cast attribute before writing it) uses `getDateFormat()` — a separate model property, always `'Y-m-d H:i:s'` for this app's drivers — **not** the cast's own `:Y-m-d` format suffix. So the `'date:Y-m-d'` cast the original fix direction suggested would not by itself have fixed the write path; only the match VALUE passed into `updateOrCreate()`'s `where()` clause needed to exactly equal what gets written.
**Fix applied:** both services now compute `$forecastDate = now()->startOfDay()->format('Y-m-d H:i:s')` once and pass that identical, pre-formatted string as the match value — guaranteed to equal what `fromDateTime()` writes on create, on both MySQL (DATE column truncation) and SQLite (literal string storage). Also fixed a second bug found in the same method: `ForecastingService::generateDemandForecast()` reused one shared `$today` Carbon instance across the panel-type loop, and `addDays()` mutates the instance it's called on — so each iteration's reorder-date calculation silently drifted "today" forward by the prior iteration's lead time. Replaced with a fresh `now()->addDays(...)` per iteration. `ForecastingService::getDemandForecast()` (a read method, not named in the original finding) had the identical match-mismatch bug and is fixed the same way.
**Verified by:** `ForecastIdempotencyTest` (2 tests) — generating a demand/ML forecast twice in a row now updates the existing row in place instead of creating a duplicate or throwing.

### C5 — ✅ RESOLVED — Stripe webhook silently never records a successful card payment
**Where:** `backend/app/Http/Controllers/Api/PaymentController.php:333-356`, `backend/app/Services/PaymentGatewayService.php:171-183`
**Found by:** fresh-pass agent
**Pre-existing, not from last session.** `handleStripeWebhook()` does `(object) json_decode($payload, true)` — the cast only converts the *top level* to `stdClass`; `$event->data` is still a plain array, so `$event->data->object` (property access on an array) silently evaluates to `null`. `handlePaymentSucceeded()` then always returns `{'success': false, 'message': 'No invoice ID in metadata'}` — but the controller **ignores that return value** and unconditionally tells Stripe `{'success': true}`. Stripe never retries, nothing is logged. `StripePaymentForm.vue` never calls any payment-recording endpoint itself — the webhook is the *only* mechanism that credits a Stripe payment. **A customer can be charged in full and the invoice stays open/unpaid with no error anywhere.**
**Fix applied:** changed the decode to plain `json_decode($payload)` (no `true`), keeping nested structures as `stdClass` all the way down instead of a shallow top-level cast over a still-array `data`. The controller now checks `$result['success']` and returns a 422 with the failure reason (logged via `Log::warning`) instead of unconditionally telling Stripe `{'success': true}`.
**Test-environment note:** the full happy path to `invoice.status = 'paid'` isn't exercised end-to-end in CI because `payment_transactions.payment_method` is a MySQL-only `ENUM` widened to include `'stripe'`/`'razorpay'` (migration `2026_06_17_000001`); that migration's own comment documents the widening is skipped on SQLite (the test driver) — a pre-existing, unrelated gap, not something this fix touches.
**Verified by:** `StripeWebhookRecordingTest` (2 tests) — directly demonstrates the old decode path fails to reach `metadata.invoice_id` (property access on an array) while the fixed decode path reaches it correctly; and that a webhook failure now returns 422/`success:false` instead of a fake 200/`success:true`.

### C6 — ✅ RESOLVED — "Mark Completed" on orders now fails for a live, always-visible button with a confusing error
**Where:** `backend/app/Services/OrderService.php::update` vs. `frontend/src/components/OrderDetail.vue:18-23`
**Found by:** regression-check agent
`OrderDetail.vue` shows "Mark Completed" for every order with `status === 'in_production'`, unconditionally. The backend now throws unless every linked batch is `dispatched` — a real business rule, but the frontend never hides/disables the button or explains the requirement beforehand, and (compounding C1/C2 above) most orders currently *can't* reach that state through the live UI at all. Users will hit this on orders that could previously always be marked complete.
**Fix applied:** added a `canMarkCompleted` computed property mirroring `OrderService::update()`'s own rule (`order.batches` is already loaded and present in the order-detail response) — the button is now disabled with a tooltip, and an inline banner explains the requirement, until every batch shows `dispatched`.

---

### NEW-1 — ✅ RESOLVED — Company's own tenant-lifecycle guard silently blocked itself: 7 call sites mass-assigned fields Company deliberately excludes from `$fillable`

**Where:** `backend/app/Services/SubscriptionService.php` (`activate()`, `extendTrial()`), `backend/app/Http/Controllers/Api/SuperAdminController.php` (`extendTrial()`, `setActive()`), `backend/app/Http/Middleware/EnsureActiveTenant.php`, `backend/app/Services/TenantProvisioningService.php::provision()`, `backend/database/seeders/DefaultRolesSeeder.php`, `backend/database/seeders/RoleUserSeeder.php`, `backend/app/Console/Commands/CheckTenantIsolation.php`
**Found by:** discovered directly this session while writing C9's regression test (`subscription_ends_at` wasn't extending even though `activate()` appeared to run correctly and a `SubscriptionPayment` row was created with the right numbers) — not a previously-logged finding from any prior audit pass.

`Company::$fillable` deliberately excludes `subscription_plan`, `subscription_status`, `trial_ends_at`, `subscription_ends_at`, and `is_active` — the model's own comment says "only SubscriptionService / SuperAdminController may mutate them via `forceFill()`." But the actual code in both of those places (plus several others) used plain `->update([...])` / `::create([...])` — ordinary mass-assignment, subject to the exact same guard it was supposed to be exempt from. Eloquent's `fill()` filters non-fillable keys out **before** `setAttribute()` ever runs, so this isn't just "doesn't persist" — the in-memory model object never reflects the new value either, even within the same request. No exception, no log line, nothing — the call site gets back a model that looks like the update worked (same `$company` object, `->fresh()` called and all) while the five guarded columns are silently frozen at whatever they were before.

**Impact, confirmed by direct reproduction for the two worst cases:**
1. **Every subscription activation silently failed to activate anything.** `SubscriptionService::activate()` — called from manual super-admin activation, Razorpay `verify()`, and the Razorpay webhook — correctly computed the new plan/status/expiry and correctly wrote a `SubscriptionPayment` row with the right invoice number, GST breakdown, and period dates… and then silently failed to write any of that onto the `Company` row itself. A customer could pay, get a correct-looking invoice record, and remain on their old (or expired) plan forever, with the response still reporting success.
2. **A trial could never actually expire.** `EnsureActiveTenant`'s lazy trial-expiry flip (`$company->update(['subscription_status' => 'expired'])`, run on every request once `trial_ends_at` is past) silently no-op'd, and the *very next line* in the same method re-read the same unmodified `$company->subscription_status` to decide whether to block the request — so the block condition could never see `'expired'`, not on this request or any future one. The entire lazy-expiry enforcement mechanism was dead code in effect.
3. **Compounding #2:** `TenantProvisioningService::provision()` (the real self-signup path) passed all five guarded fields into `Company::create([...])`, so they were silently dropped there too — every new tenant landed on the `companies` table's raw column defaults instead of the intended values: `subscription_plan` defaults to `'growth'` (not the intended `'starter'` — every self-signup was silently placed on a higher/different plan), and `trial_ends_at` has no column default at all, so it was permanently `NULL` — meaning the trial could never expire via #2's mechanism even if that had been working, because the lazy-flip condition requires a non-null `trial_ends_at` in the past.
4. Lower-impact instances with the same root cause: `SuperAdminController::extendTrial()` and `::setActive()` (manual trial extension / tenant suspend-unsuspend from the admin panel both silently did nothing), `SubscriptionService::extendTrial()` (a second, differently-invoked copy of the same bug), and three seed/dev-tool call sites (`DefaultRolesSeeder`, `RoleUserSeeder`, `CheckTenantIsolation`) where the DB defaults happened to coincidentally match the intended values in most cases, masking the bug locally.

**Not affected (checked and ruled out):** `database/factories/CompanyFactory.php` sets the same fields but Laravel's model-factory pipeline (`Illuminate\Database\Eloquent\Factories\Factory::newInstance()`) wraps instantiation in `Model::unguarded(...)`, which temporarily disables the fillable guard entirely — by design, so factories aren't constrained by production mass-assignment protection. `CompanyController@update`'s `$company->update($validated)` only passes an explicit whitelist that never includes these five keys — safe.

**Fix applied:** every genuinely-broken call site now uses `$company->forceFill([...])->save()` instead of `update()`/`create()` for the guarded fields specifically (non-guarded fields still go through normal `create()`/mass-assignment where that's fine).
**Verified by:** `TenantLifecycleGuardedFieldsTest` (2 tests — an expired trial is now actually locked out with a persisted flip; a new signup now correctly lands on the `starter` plan with a real, future `trial_ends_at`), `SuperAdminTenantMutationTest` (2 tests — extend-trial and suspend/un-suspend now actually persist), plus `RazorpayRenewalWebhookTest`'s `subscription_ends_at` assertion (C9, above) which would not have passed without this fix.

### NEW-2 — ✅ RESOLVED — `MaterialBomService::settings()` returns zeroed defaults on a company's very first BOM calculation

**Where:** `backend/app/Services/MaterialBomService.php::settings()`
**Found by:** discovered directly this session while building C1's standalone-batch-start test (a coil requirement line was unexpectedly absent from a freshly-seeded company's BOM computation).

`MaterialSetting::firstOrCreate(['company_id' => $companyId])` only sets the `company_id` attribute it was given — Eloquent's mass-assignment `fill()` doesn't retroactively reflect a table's column defaults (`steel_density`, `iso_polyol_ratio`, wastage percentages, etc.) onto the in-memory model after a fresh `INSERT`. So the very first time any company's material requirement was ever computed, every numeric setting read as `null`/`0`: `$overpack`/`$wChem`/`$wCoil` all became `1.0` (no wastage or overpack applied at all), and — worse — coil lines were **silently skipped entirely**, since `compute()` does `if ($v['kg'] <= 0) continue;` and `steel_density = 0` makes every coil-weight calculation zero. A brand-new company's first production run or standalone batch would understate its raw-material requirement, with no error or warning anywhere, until some later call happened to re-fetch the settings row correctly.
**Fix applied:** `settings()` now checks `$settings->wasRecentlyCreated` and calls `->fresh()` in that case, so the very first caller gets the same correctly-populated values as every subsequent one.
**Verified by:** `StandaloneBatchStartTest::test_standalone_batch_issues_its_own_material_when_stock_is_sufficient`, which exercises a brand-new company's very first material computation and asserts coil stock is actually deducted.

---

## 2. High

### H1 — ⚠️ INVALIDATED on re-verification (not a real bug — see below)
**Originally:** "Send Reminder" 403s because the seeded Accounts role allegedly has `payments.manage` without `invoices.manage`.
**Correction (2026-10-12):** re-read `TenantProvisioningService::createDefaultRoles()` directly — the real, live signup path — and the seeded Accounts role already holds **both** `invoices.view`/`invoices.manage` **and** `payments.view`/`payments.manage`. This finding was based on an incomplete read by the original agent (likely from `config/permissions.php`'s descriptive comment alone, without checking what's actually granted). No code change made; confirmed via the new `DefaultRolePermissionsTest::test_accounts_role_can_send_an_invoice_reminder`, which passes against the real seeded role with no fix needed. Leaving this entry in place rather than deleting it, as a record that it was checked and found not to reproduce.

### H2 — ✅ RESOLVED — Billing self-service recovery path blocked for non-admins by the new middleware
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` (`billing` → `settings.manage`) vs. `backend/app/Http/Middleware/EnsureActiveTenant.php` (explicitly allow-lists `billing.checkout`/`billing.verify` for any user of an expired tenant)
**Found by:** fresh-pass agent
`EnsureActiveTenant` is written so any authenticated user of a lapsed tenant can reach billing checkout/verify to reactivate — that's its whole purpose, per its own code comments. The new permission middleware now requires `settings.manage` (admin-only in practice) on the same routes, silently overriding that design. A non-admin user whose company's subscription lapses while the admin is unavailable cannot reactivate it.
**Fix applied:** added a `ROUTE_ALLOW` list (`billing.status`, `billing.checkout`, `billing.verify`) to `EnforceModulePermissions`, checked by route name before the module-segment gate — same mechanism and same three routes `EnsureActiveTenant` already allow-lists. Verified by `DefaultRolePermissionsTest::test_non_admin_can_reach_billing_checkout_and_verify` (a Sales-role user, which holds no `settings.manage`, gets a non-403 response).

### H3 — ✅ RESOLVED (with a correction on actual impact) — stale/nonexistent permission keys in an unused demo seeder
**Where:** `backend/database/seeders/DefaultRolesSeeder.php` vs. `backend/config/permissions.php` / `EnforceModulePermissions.php`
**Found by:** mobile audit agent
**Correction on impact (2026-10-12):** confirmed `backend/database/seeders/DatabaseSeeder.php::run()` never calls `DefaultRolesSeeder` — it only creates one test user via factory. `DefaultRolesSeeder` is dead code, not reachable via `php artisan db:seed`, and has no effect on live tenants (those are seeded by `TenantProvisioningService::createDefaultRoles()`, which already uses correct, current permission keys). The original "roles are effectively permission-less" framing overstated live impact — but the file is still wrong and would mislead anyone who runs it manually expecting working demo roles.
**Fix applied:** rewrote every role's `permissions` array in `DefaultRolesSeeder.php` to use real keys from the current `config/permissions.php` registry, mirroring `TenantProvisioningService`'s role shape (Company Admin → `['*']`, Sales Manager/Production Manager/Accounts/Viewer → their real view/manage keys) instead of the stale `quotations.create`, `batches.view`, `production.complete`, `invoices.create`, and the non-matching wildcard `'*.view'`.

### H4 — ✅ RESOLVED — Tests for the permission middleware never exercised a real default role
**Where:** `backend/tests/Feature/ModulePermissionTest.php`, `StandaloneBatchStartTest.php`
**Found by:** regression-check agent
Both test files build custom ad hoc permission sets or use the admin bypass — neither exercises the actual seeded Sales/Production/Accounts/Viewer roles against real routes. This is exactly why C1/C2/C3/H6/H7 above shipped undetected: the tests that were supposed to validate the RBAC rollout never tested RBAC against the system's own default configuration.
**Fix applied:** new `backend/tests/Feature/DefaultRolePermissionsTest.php` — provisions a company through the real `TenantProvisioningService::provision()` signup path (not a hand-built permission set), creates a user on each real seeded role, and exercises real routes: Sales can view/edit orders, Accounts can send an invoice reminder, a role without `reports.view` (Production) is blocked from bulk export while one with it (Accounts) isn't, and a non-admin (Sales) can reach billing checkout/verify. All 5 pass. Deliberately does not assert on stock/dispatch/procurement routes — those are still broken under the unfixed Critical findings C2/C7/C8, and asserting success there would fail for a reason unrelated to this test's purpose.

### H5 — Mobile: two Critical crash/dead-end bugs in the Production module — ✅ RESOLVED, see M1 and M2 below
Filed as High here because, unlike C1-C6, these don't block web users, only mobile. Both fixed — see section 5.

### H6 — ✅ RESOLVED — Sales role cannot edit an order despite owning the Orders module
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` (`orders` write key → `orders.manage`) vs. `TenantProvisioningService::createDefaultRoles()`
**Found by:** exhaustive route audit
`PUT /orders/{id}` requires `orders.manage`. The Sales default role — described as managing orders, and holding `orders.view` — does not hold `orders.manage`. No role does. Only Company Admin can ever edit an order.
**Fix applied:** added `orders.manage` to the Sales role's permission list in `TenantProvisioningService::createDefaultRoles()`. Verified by `DefaultRolePermissionsTest::test_sales_role_can_view_and_edit_orders`.

### H7 — ✅ RESOLVED — Bulk CSV export of full customer/financial data is completely unprotected
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` (`export` segment absent from MODULES)
**Found by:** regression-check agent; confirmed and escalated by the exhaustive route audit
Originally filed as Low; the exhaustive audit's own assessment is that this is arguably worse than C3's reports gap, since it's raw row-level data, not aggregated figures. `GET /export/customers`, `/export/quotations`, `/export/orders`, `/export/invoices` have no permission check at all — any authenticated user, including a Viewer-role or a custom role with zero grants, can bulk-download full customer PII plus quotation/order/invoice detail.
**Fix applied:** added `'export' => ['reports.view', null]` to the MODULES map. Verified by `DefaultRolePermissionsTest`'s two export tests (Production, lacking `reports.view`, gets 403; Accounts, holding it, gets 200).

---

## 3. Medium

### MD1 — ✅ RESOLVED — Coupon redemption count can be bypassed indefinitely via webhook/client race
**Where:** `backend/app/Http/Controllers/Api/BillingController.php:146-162`
**Found by:** fresh-pass agent
`verify()`'s idempotency check returns early (before incrementing `used_count`) whenever a `SubscriptionPayment` row already exists for that payment ID. The Razorpay `webhook()` handler creates that exact row via the same reference, but never touches the coupon. Since webhooks commonly arrive before the client's own `verify()` call completes, the typical ordering lets `verify()`'s early-return skip the coupon increment every time — a capped/single-use coupon can be reused indefinitely. This is structural (the expected ordering), not a rare race.
**Fix applied:** moved the coupon increment into `SubscriptionService::activate()` itself (new optional `$couponCode` trailing parameter), inside the same idempotency-guarded block that records the `SubscriptionPayment` row, so it only ever runs on whichever call (verify or webhook) actually wins the race. `BillingController::verify()` and `webhook()` both now pass `$notes['coupon'] ?? null` into `activate()` instead of incrementing separately. Verified by new `backend/tests/Feature/SubscriptionCouponAndWebhookTest.php` (2 tests): calling `activate()` twice with the same reference and coupon increments `used_count` exactly once.

### MD2 — ✅ RESOLVED — Mobile: silent failure on reminder-send errors
**Where:** `mobile/src/features/sales/ReceivablesScreen.tsx:29-31`
**Found by:** mobile audit agent
An empty `catch {}` around `sendPaymentReminder` means any failure (including the H1 403 above, or a network error) gives the user zero feedback — the button just stops spinning with no indication anything went wrong. `InvoiceDetailScreen.tsx`'s equivalent action correctly shows an `Alert`.
**Fix applied:** replaced the empty catch with `Alert.alert('Could not send reminder', e?.response?.data?.message ?? 'Please try again.')`, matching `InvoiceDetailScreen.tsx`'s pattern exactly. `tsc --noEmit` clean.

### MD3 — ✅ RESOLVED — Mobile: mutating actions aren't gated by `.manage` permissions client-side
**Where:** `mobile/src/features/sales/QuotationDetailScreen.tsx`, `InvoiceDetailScreen.tsx`, `mobile/src/features/dispatch/DispatchDetailScreen.tsx`
**Found by:** mobile audit agent
Only tab-level `.view` permissions gate visibility (`StaffTabs.tsx`). Send/Accept/Reject, Record Payment, and Mark Delivered buttons are shown to any user who can see the relevant tab, regardless of whether they hold the actual `.manage` permission — unlike `QcScreen.tsx`'s Approve button, which correctly checks `hasPermission(user, 'production.manage')` first. View-only staff see live, enabled buttons that will always 403.
**Fix applied:** added the same `hasPermission(user, '<module>.manage')` gate `QcScreen.tsx` already uses to all three screens — `quotations.manage` around the Send/Accept/Reject action row in `QuotationDetailScreen.tsx`, `invoices.manage` around Record Payment and Send Reminder in `InvoiceDetailScreen.tsx`, and `dispatch.manage` around Mark Delivered in `DispatchDetailScreen.tsx`. `tsc --noEmit` clean.

### MD4 — ✅ RESOLVED — Mobile: no pagination on any list screen
**Where:** `mobile/src/features/production/BatchListScreen.tsx`, `mobile/src/features/sales/SalesHomeScreen.tsx`, `mobile/src/features/dispatch/DispatchListScreen.tsx`
**Found by:** mobile audit agent
None of these call their list APIs with a `page` parameter, so only the server's default first page (20 records) per status filter is ever visible, with no "load more" and no indication more records exist.
**Fix applied:** converted `useBatches`/`useQuotations`/`useOrders`/`useDispatches` (in `production/hooks.ts`, `sales/hooks.ts`, `dispatch/hooks.ts`) from `useQuery` to TanStack Query's `useInfiniteQuery`, keyed on the existing pagination metadata each API already returns (`meta.pagination.{current_page,per_page,total}` for batches/quotations/orders, the raw Laravel paginator shape for dispatches). Each screen now flattens `data.pages` and wires `onEndReached` to `fetchNextPage()` with a footer spinner while `isFetchingNextPage`. `tsc --noEmit` clean.

### MD5 — ✅ RESOLVED — PO creation accepts a nonexistent/cross-reference `stock_id` with no validation at creation time
**Where:** `backend/app/Http/Controllers/Api/ProcurementController.php:93-106`
**Found by:** fresh-pass agent
No `exists:` rule on `items.*.stock_id`. A bad ID is silently accepted at PO creation and only surfaces later, confusingly, as a raw `ModelNotFoundException` message when someone tries to receive against it. Not cross-tenant exploitable (receive-time lookup is correctly scoped) — a data-integrity/UX gap, not a security hole.
**Fix applied:** since `material_kind` picks which of three stock tables (`CoilStock`/`ChemicalStock`/`ConsumableStock`) `stock_id` must belong to, a plain `exists:` rule can't scope it (one fixed table only). Added a `Validator::make(...)->after()` closure in `store()` that checks each item's `stock_id` exists in the correct table, scoped to the requesting company, and returns a 422 with a field-level error if not. Verified by new `backend/tests/Feature/ProcurementStockValidationTest.php` (2 tests): a `stock_id` belonging to another company is rejected; a company's own `stock_id` is accepted.

### MD6 — ✅ RESOLVED — Read-only Viewer role can trigger forecast generation and write analytics snapshots
**Where:** `backend/app/Http/Middleware/EnforceModulePermissions.php` (`forecasts`/`analytics` both map write key to `reports.view`)
**Found by:** exhaustive route audit
Both segments use `['reports.view', 'reports.view']` — the same key for read AND write. Since Viewer holds `reports.view`, a role explicitly documented as "Read-only" can call `POST /forecasts/demand`, `/forecasts/ml`, `/forecasts/ml/compare-models`, `/forecasts/ml/record-actual`, `/analytics/metrics/sales`, `/analytics/trends`, `/analytics/snapshot` — all compute/write actions. Minor privilege-boundary leak, not a lockout.
**Fix applied:** added a new `reports.manage` permission key to `config/permissions.php`, changed `forecasts`/`analytics`'s write key from `reports.view` to `reports.manage` in `EnforceModulePermissions`, and granted `reports.manage` to the Accounts default role (the only non-admin role that previously had `reports.view`) in both `TenantProvisioningService::createDefaultRoles()` (the real signup path) and `DefaultRolesSeeder.php` (kept in sync per H3). Viewer — which only ever had `reports.view` — is now correctly blocked from forecast/analytics writes. Verified by 2 new tests in `backend/tests/Feature/DefaultRolePermissionsTest.php`: Viewer gets 403 on `POST /forecasts/demand` and `/analytics/snapshot`; Accounts does not.

### MD7 — ✅ RESOLVED — Razorpay `order.paid` webhook can bypass its own idempotency check on a missing payment id
**Where:** `backend/app/Http/Controllers/Api/BillingController.php:192`
**Found by:** Razorpay/scheduled-commands agent
`$paymentId = $r->input('payload.payment.entity.id') ?? '';` falls back to an empty string if that nested field is ever absent for an `order.paid` event. `SubscriptionService::activate()`'s idempotency check (`$reference && ...`) short-circuits on an empty string, bypassing the duplicate-reference guard entirely — a redelivered webhook with no payment id would double-credit (duplicate `SubscriptionPayment` row, double-extend `subscription_ends_at`). Unconfirmed whether Razorpay's `order.paid` payload shape ever actually omits this field in practice — flagged as a real gap in the code either way.
**Fix applied:** added an explicit `$paymentId !== ''` guard in `webhook()` alongside the existing `$company` and `subscription_status !== 'active'` checks — an empty payment id now skips crediting entirely instead of falling through to `activate()` with a reference that can't dedupe. Covered by the same `SubscriptionCouponAndWebhookTest` fixture used for MD1 (both fixes share the `activate()` idempotency path).

### MD8 — ✅ RESOLVED — Pagination/filter clicks have no in-flight request guard across ~9 list components (stale-response race)
**Where:** `frontend/src/components/BoqRegister.vue:56-58,92`, `QuotationList.vue:79-81,125-129,166-174`, and the same pattern in `TransactionLog.vue:92-96`, `ConsumableStock.vue:52-56`, `ChemicalStock.vue:84-88`, `CoilStock.vue:73-78`, `LeadManager.vue:96-100`, `AccessoryMaster.vue:34-38`, `ProductionRuns.vue:115-119`
**Found by:** Vue component-level agent
Prev/Next and sort-column handlers call `load()`/`goPage()` directly with no `loading`-based disable and no discarding of a stale response if a newer request has since been fired. **Reproduction (BoqRegister):** click "Next →" rapidly 2-3 times on a slow connection — if an earlier response resolves after a later one, the table silently reverts to an older page's rows while the pagination widget still shows the newer page number.
**Fix applied:** added a sequence-number guard (`let reqSeq = 0`, incremented at the start of each load call, captured in a local `seq`) to the `load`/`loadList` function in all 9 components. Every point that writes to reactive state (`rows.value`, `pagination.*`, `error.value`, `loading.value`) now checks `seq === reqSeq` first and silently returns otherwise, so only the response from the most recently fired request can ever update the UI — stale in-flight requests become no-ops rather than overwriting newer data. `npx vite build` clean.

---

## 4. Low

### L1 — Mobile: `hasPermission` exact-match semantics incompatible with wildcard-style seeded keys
Covered under H3 — the exact-match lookup itself is reasonable; the bug is the mismatched seed data. Listed here only as the mechanism, not a separate action item.

### L2 — (removed, escalated to H7 — bulk export exposure was more severe than first assessed)

### L3 — GST state-code typos silently stored as "Unknown" instead of rejected
**Where:** `backend/app/Http/Controllers/Api/GstController.php:22-26`, `backend/app/Services/GstService.php:71-72`
**Found by:** fresh-pass agent
Any syntactically-valid-but-wrong 2-letter state code is accepted and stored with `state_name = 'Unknown'` rather than rejected, silently degrading GST classification used elsewhere.
**Fix direction:** validate against the actual state-code map, not just `size:2`.

### L4 — Debounced search timers not cleared on unmount
**Where:** `frontend/src/components/BoqRegister.vue:94-95`, `InvoiceList.vue:101-102`, `QuotationList.vue:125-129`
**Found by:** Vue component-level agent
A module-scope `setTimeout` for search debounce is only cleared on the next keystroke, never in `onUnmounted`. Typing then navigating away before the 350ms delay elapses leaves a pending timeout that fires after the component is detached, performing a wasted network call. Not crash-causing, just a minor leak.
**Fix direction:** clear the timer in `onUnmounted`.

### L5 — GST calculation routes trust a lower permission bar than GST configuration routes
**Where:** `backend/routes/api.php` (`/invoices/{id}/calculate-gst`, `/gst-breakdown` under `invoices` segment → `invoices.manage`) vs. every other `/gst/*` route (→ `settings.manage`, admin-only)
**Found by:** exhaustive route audit
Inconsistent trust boundary: GST configuration/registration requires admin, but GST calculation on an invoice is reachable by anyone holding plain `invoices.manage` (the Accounts role). Not a lockout, just an inconsistency worth a deliberate decision rather than an accident of which URL segment a route happened to land under.

### L6 — Segment-mismatch fragility: invoice sub-routes that are really payment/e-invoice actions
**Where:** `backend/routes/api.php` (`/invoices/{id}/payment-status`, `/payment-reminder`, `/write-off`, `/payment-link`, `/einvoice/*`, etc., all under the `invoices` segment → `invoices.manage`)
**Found by:** exhaustive route audit
Not a lockout today only because the seeded Accounts role happens to hold both `invoices.manage` and `payments.manage` together. A future custom role granted only `payments.manage` would be unable to record a payment via these endpoints; one granted only `invoices.manage` gets implicit payment/e-invoice write access that may not have been intended. Fragile, not broken yet.

### L7 — Dead/orphaned code found during the Vue component pass (no functional impact today)
**Found by:** Vue component-level agent
Three small items, each confirmed harmless under current behavior but worth cleaning up:
- `frontend/src/components/SuperAdmin.vue:543` — `saveSettings()` takes no parameter, but three call sites pass `true`/`false`/nothing (lines 237, 252, 311) expecting per-section saves; today it saves everything every time regardless, so no bug yet, but the differentiated call sites would silently misbehave if the sections are ever split into separate reactive objects.
- `frontend/src/components/LeadManager.vue:295-299` — `computeCounts()` is defined but never called (tab counts actually come from the API's `body.counts`); orphaned fallback logic that would stay silently broken if the server ever stopped sending counts.
- `frontend/src/components/NotificationSettings.vue:246-248` — checks `twilio_auth_token === '••••••••'`, but `load()` always sets that field to `''`, so the condition can never match. Harmless only because the backend separately treats an empty string as "don't change." Should check for `''` instead.

### L8 — Dead permission-key definitions never referenced anywhere
**Where:** `backend/config/permissions.php` (`inventory.view`/`inventory.manage`)
**Found by:** exhaustive route audit
Defined in the registry but `EnforceModulePermissions` uses `stock.view`/`stock.manage` instead, and no default role is ever granted these keys. Harmless, but worth removing or reconciling so the registry doesn't imply a control that doesn't exist.

---

## 5. Mobile app — module-specific findings (also listed as H5 above)

### M1 — ✅ RESOLVED — Crash rendering any batch that reaches `dispatched` status
**Where:** `mobile/src/api/production.ts:10` (`BatchStatus` type), `mobile/src/features/production/statusMeta.ts:4-12` (`batchStatusMeta`)
Both omit the `'dispatched'` status value, but `Dispatch::markAsCompleted()` sets exactly that status on a batch the moment a dispatch is completed — which the mobile "Mark Delivered" action itself triggers. `BatchListScreen.tsx` and `BatchDetailScreen.tsx` then throw on `undefined.label` when rendering that batch's status pill.
**Reproduction:** staff marks a dispatch delivered → opens the Production tab → app crashes on that batch's card.
**Fix applied:** added `'dispatched'` to the `BatchStatus` type and to `batchStatusMeta` (success-green pill, "Dispatched"), plus a matching filter chip. TypeScript's own `Record<BatchStatus, ...>` typing would have refused to compile without the map entry, so this is now structurally guaranteed to stay in sync if another status is ever added. `tsc --noEmit` and `expo export --platform web` both clean.

### M2 — ✅ RESOLVED — Batches worked entirely through the mobile app can never reach QC
**Where:** `mobile/src/api/production.ts` (no `startBatch`/`completeBatch` calls existed, only stage-level `startStage`/`completeStage`)
The mobile app never called `POST /batches/{id}/start` or `/complete` (both exist and are used by the web app). `ProductionBatchService::completeBatch` requires `status === 'in_progress'`, which is only set by `startProduction()` — a call mobile never made. A batch worked entirely via mobile could never reach `qc_pending`, so the QC screen's entry point (gated on exactly that status) was permanently unreachable for mobile-only workflows.
**Fix applied:** added `startBatch()`/`completeBatch()` to `mobile/src/api/production.ts` and matching hooks (`useStartBatch`/`useCompleteBatch`). `BatchDetailScreen.tsx` now calls `startBatch()` when the first stage is started on a `draft` batch, and `completeBatch()` right after the last stage is completed — both wired through the same error-surfacing path as the existing stage actions. `tsc --noEmit` and `expo export --platform web` both clean.
**Known remaining dependency (not fixed — out of scope for this High-only pass):** if the batch itself has `run_id = null` (i.e. it was created through the still-broken standalone "Production" tab, per unfixed Critical finding C1), the new `startBatch()` call will fail server-side with "Start this batch through a production run," exactly like the web app does today. This is a real improvement over the previous silent dead-end either way — the user now gets a clear, actionable error instead of a batch that mysteriously never reaches QC — but full end-to-end mobile functionality for this flow still depends on C1 being fixed.

---

## 6. What's already been verified clean

To avoid re-auditing: Leads/CRM tenant scoping and cross-tenant guards, Super Admin route/method guards, Razorpay signature verification (`hash_equals`, checked against the raw request body before any payload field is trusted) and its duplicate-credit idempotency (transaction-wrapped reference check), `DocumentTemplateService`'s fallback behavior, E-Invoice's manual-entry-only scope, lock-ordering across the new `lockForUpdate()` calls (no deadlock cycle found), the SQL-aggregation correctness of the reporting/analytics rewrites themselves (the numbers are right — only the *access control* around them is wrong, per C3), all 12 driver-conditional migrations (MySQL branch preserved byte-for-byte), the mobile app's auth/session boot/token-refresh flow and QC-checklist-optional handling, all five non-SMS scheduled console commands plus `Kernel::schedule()`'s overlap/frequency/timing (all correctly guarded, no collisions), and — across the full 21-component Vue pass — no prop-mutation, no side-effecting computed properties, no unguarded `v-html` on user-originated data, and no string/number arithmetic bugs anywhere currency values are handled.

---

## 7. Suggested attention order

**Done (2026-10-12):** H2, H3, H4, H6, H7, M1, M2, QB2 (High pass) — MD1-MD8, all 8 (Medium pass) — and C1-C9, all 9, plus QB1, plus two newly-discovered findings (NEW-1, NEW-2) surfaced while verifying C1/C9 (Critical pass) — see each entry above for what changed and which test covers it. QB3 was raised with the user and explicitly deferred, not fixed.

**Still open:**
1. **Remaining Low** — QB4, QB5, L3-L8 are cleanup-grade, no urgency.
2. **QB3** (transport GST rate) — deferred pending business/accountant input, not forgotten — see its entry in section 9.

Every Critical and Medium finding, and all real High findings, are now resolved and covered by an automated test. The only intentionally-unaddressed items are the Low tier (routine cleanup) and QB3 (needs a rate decision from outside this session).

---

## 9. Quotation / BOQ module deep-dive (requested follow-up, 2026-10-12)

Scope: `backend/app/Http/Controllers/Api/QuotationController.php`, `backend/app/Services/QuotationService.php`, `frontend/src/components/QuotationCreate.vue` (the BOQ/Quotation builder — shared by both, since a BOQ is just a `Quotation` with `status='boq'`), `QuotationDetail.vue`, `QuotationList.vue`, `BoqRegister.vue`, `frontend/src/services/quotationService.js`. Verified by reading full source and tracing the exact request payload from form submission through validation to the database write — not speculative.

### QB1 — ✅ RESOLVED (Critical) — saving a quotation/BOQ with a Door/Window or Installation accessory row fails outright
**Where:** `frontend/src/components/QuotationCreate.vue:734,739` (row creation) + `:920-930` (submit payload) vs. `backend/app/Http/Controllers/Api/QuotationController.php:327` (`Rule::exists('accessories', 'id')`)
When a user clicks the live "+ Door/Window" or "+ Installation" button, the new row is initialized with a literal placeholder string as its `accessory_id` — `accessory_id: 'door'` or `accessory_id: 'installation'` — not `null` and not a numeric ID. Nothing in the component ever overwrites this placeholder for those two row types (the dropdown that updates `accessory_id` only exists for `type === 'standard'`). The submit payload passes it straight through: `accessory_id: acc.accessory_id || null` is truthy for a non-empty string, so the literal value is sent to the API as-is.
The backend validates `accessories.*.accessory_id` with `nullable` plus `Rule::exists('accessories', 'id')->where('company_id', $companyId)`. `nullable` only skips the `exists` check when the value is actually `null` — a non-null string like `'door'` still runs the `exists` lookup, which finds no row (accessory IDs are auto-increment integers), and validation fails with a 422.
**Reproduction:** open the Quotation/BOQ builder, add any panel row, click "+ Door/Window" (or, outside BOQ mode, "+ Installation"), fill it in, click Save. The request fails validation on `accessories.N.accessory_id`; the entire quotation fails to save, including all its panel rows — not just that one accessory.
**Impact:** Door/Window and Installation are two of this form's three accessory types and are reachable via dedicated, documented buttons — not an edge case. Every quotation or BOQ that needs a door/window opening or an installation charge line — routine for a panel manufacturer — currently cannot be saved at all.
**Fix applied:** changed the frontend submit mapping to `accessory_id: acc.type === 'standard' ? (acc.accessory_id || null) : null` — door/installation rows now send `null`, which the backend's existing `nullable` rule already correctly accepts (confirmed `QuotationService::saveAccessories()` already anticipated a null `accessory_id` for door rows, adding the amount to `installation_amount` — only the validation layer was blocking it, so no backend change was needed). The audit's suggested additional backend change (skip the `exists` check based on `type`) was considered and deliberately not made: a non-null `accessory_id` on a door/installation row from a non-browser caller is safely rejected as-is, which is the correct failure mode, not a gap.
**Verified by:** `QuotationAccessoryValidationTest::test_door_accessory_row_with_no_master_accessory_saves_successfully` — a door-type row with `accessory_id: null` now saves successfully and its amount is correctly added to `installation_amount`.

### QB2 — ✅ RESOLVED (High) — accessory description and door dimensions are silently discarded on every save, independent of QB1
**Where:** `backend/app/Http/Controllers/Api/QuotationController.php:324-329` (validation rules) vs. `frontend/src/components/QuotationCreate.vue:920-930` (submitted fields) vs. `backend/app/Services/QuotationService.php:476-517` (`saveAccessories`, reads description/door_type/door_width/door_height)
The frontend sends `description`, `unit`, `door_type`, `door_width`, and `door_height` for every accessory row. None of these five fields had a validation rule declared under `accessories.*` — only `type`, `accessory_id`, `qty`, and `rate` did. Laravel's `$request->validate()` returns a validated array containing only the keys that have a declared rule; for a nested wildcard array, sibling keys with no rule at all are dropped before the controller's `$validated` variable is built, so `saveAccessories()` always saw these fields as absent and fell back to its own defaults (null for description/door fields, `'NOS'` for unit).
**Fix applied:** added the five missing validation rules (`description => nullable|string|max:255`, `unit => nullable|string|max:20`, `door_type => nullable|string|max:50`, `door_width => nullable|numeric|min:0`, `door_height => nullable|numeric|min:0`) to `QuotationController::quotationRules()`. Verified by new `backend/tests/Feature/QuotationAccessoryValidationTest.php` — a standard accessory's `description`/`unit` now round-trip correctly through the update path (routed through `update()` rather than `store()` in the test, since quotation-number generation needs MySQL's `REGEXP`, unsupported on the SQLite test driver — a pre-existing, unrelated test-environment gap, not something this fix touches).
**Still blocking door-row dimensions specifically — QB1 (Critical, not in this pass's scope):** this fix alone is not sufficient for Door/Window accessories, because those rows never reach `saveAccessories()` at all — they fail earlier, at the `accessory_id` validation step described in QB1, since the frontend sends the literal string `'door'` instead of a numeric ID or `null`. QB2's fix is necessary-but-not-sufficient for that one row type; it fully resolves the issue for ordinary "standard" accessories, which were already saving successfully and now also keep their description/unit.

### QB3 — Medium — ⏸ DEFERRED (business decision, not fixed): transport charges carry zero GST despite the system's own HSN/GST reference stating 5%/12%
**Where:** `backend/app/Services/QuotationService.php:537,544-545` (GST computed from `taxableAmt` before `transport` is added to the grand total) — mirrored exactly in `frontend/src/components/QuotationCreate.vue:807-810`
Both the authoritative backend calculation and the frontend's live preview compute GST only on panels+accessories+installation (less discount), then add the transport charge afterward with no tax applied to it at all. The repo's own root `README.md` documents Transportation (HSN 996511) as taxable at 5%/12%. Frontend and backend agree with each other, so this isn't a desync bug — it's a shared missing feature that under-collects GST on every quotation that includes a transport charge.
**Fix direction:** decide the correct transport GST rate per actual filing practice and apply it to both the backend calculation and the frontend preview, as its own line at its own rate rather than folded into the 18% panel rate.
**Status (2026-10-12 Medium pass):** raised with the user directly (5%, 12%, 18%-same-as-panels, or leave as-is) — user chose to leave it as-is for now pending accountant input. No code change made. Revisit once a rate is confirmed.

### QB4 — Low: `lead_id` accepted with no existence or tenant-scope check
**Where:** `backend/app/Http/Controllers/Api/QuotationController.php:298` (`'lead_id' => 'nullable|integer'`)
No `exists:leads,id` rule and no company-scoping, unlike every other reference field in the same rule set (`customer_id`, `panel_type_id`, `accessory_id` all use a scoped `exists`). Flagging the inconsistency at the validation layer; `LeadService::linkQuotation()` may scope internally, not re-verified here.
**Fix direction:** add `Rule::exists('leads', 'id')->where('company_id', $companyId)` to match the pattern already used for `customer_id`/`accessory_id`.

### QB5 — Low: editing a `sent` quotation silently changes its numbers without resetting status or `sent_at`
**Where:** `backend/app/Services/QuotationService.php:92-138` (`update()`)
The edit guard allows editing a quotation in `boq`, `draft`, or `sent` status. Editing a `sent` quotation updates panel rows/accessories/totals in place but never touches `status` or `sent_at` — a quotation already emailed to a customer as a PDF can have its totals silently changed afterward while still showing as "Sent" with the original timestamp, with nothing flagging that the emailed PDF and the current stored data have diverged.
**Fix direction:** either block editing a `sent` quotation (the existing `revise()` action already exists for exactly this purpose) or reset it to `draft` on edit so a resend is clearly required.

### Verified clean in this pass
SQM/length calculations are consistent and correctly duplicated between frontend preview and backend authoritative calc. The "⚠ DL" doubled-length warning is correctly cosmetic/production-only and does not affect billed SQM in either layer. All currency/number fields in `QuotationCreate.vue` are explicitly `Number()`-coerced before arithmetic (the component's own code comment flags the exact string-concatenation pitfall and guards against it) — no string/number bugs found. The `revise()`/`send()`/`accept()`/`reject()`/`expire()` status guards are all correctly in place and consistent with each other. Tenant scoping on `customer_id`, `panel_type_id`, and (for accessories that reach the service) `accessory_id` is correctly enforced both in validation and in `saveAccessories()`'s own re-check.
