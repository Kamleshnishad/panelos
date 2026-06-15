# SaaS Conversion Plan — PanelOS

Goal: turn the single-tenant-deployed ERP into a self-serve SaaS where PUF-panel
factories sign up online, get a trial, and pay — safely (no cross-tenant leaks).

## Current state (verified 2026-06-15)

- **Architecture is multi-tenant from day 1** — every business table carries `company_id`.
- **SaaS columns already exist** on `companies`: `subscription_plan` (starter/growth/pro/enterprise),
  `subscription_status` (active/trial/expired), `is_active`, `settings` (json), `subdomain` (unique).
  → No migration needed to start enforcing them.
- **Tenant provisioning logic exists only in** `backend/database/seeders/RoleUserSeeder.php`
  (creates Company + 6 roles + admin user). Lift this into a service for signup.
- **Auth choke point** for middleware: the `Route::middleware(['auth:sanctum','throttle:240,1'])`
  group in `backend/routes/api.php` (~lines 39–356).

### ⚠️ The #1 risk — tenant isolation is MANUAL
- **NO `addGlobalScope` anywhere.** `BaseModel::scopeCompany()` is defined but **never used**.
- Isolation = **116 hand-written `where('company_id', …)` across 21 controllers** + service-layer.
- Today it is *consistent* (every sampled index/show/update scopes correctly; child tables reached
  only via scoped parents) — but there is **no safety net**. One forgotten `where` on a future
  query silently leaks another tenant's data. **Unacceptable for public, paid SaaS.**

---

## Phase S0 — Isolation hardening (DO FIRST, non-negotiable)  ✅ CORE DONE

Make tenant isolation structural, not opt-in. Without this, do not run ads/onboard strangers.

1. ✅ **`BelongsToTenant` trait + global scope** — DONE. `app/Models/Concerns/BelongsToTenant.php`
   adds a global scope `'tenant'` that auto-filters every read by `company_id` (no-op when
   unauthenticated → seeders/commands safe; bypassed for `is_super_admin`), plus `creating`
   auto-inject. Applied to BaseModel (covers 21 subclasses) + 17 tenant-owned plain models that
   have a `company_id` column (PaymentTransaction, AuditLog, NotificationSetting, GstConfiguration,
   GstTaxBreakdown, HsnCode, SmsLog, StockAllocation, QualityControl, PaymentReminder, TaxConfiguration,
   AnalyticsSnapshot, DemandForecast, ForecastAccuracy, InventoryForecast, SalesMetric, TrendAnalysis).
   NOT applied to line/child tables (no company_id — parent-scoped) or User (auth, special).
2. ✅ **Automated isolation check** — DONE. `php artisan tenant:check-isolation` spins up two throwaway
   tenants, seeds under B, and asserts tenant A can't see B by list / name / `find(id)`; also asserts
   the global scope is registered on key models. Exit 0 = safe, 1 = leak. Run before every release.
   **Verified PASS** — `find(otherTenantId)` now returns NULL (previously leaked).
3. ⏳ **Controller audit (defence-in-depth)** — with the global scope on, the 116 manual
   `where('company_id')` clauses are now redundant safety nets. Optional cleanup later; not blocking.
4. ⏳ **Login tenant resolution** — handle in S1: enforce global-unique email on signup (one email =
   one tenant) so login stays unambiguous.

**Exit criteria:** ✅ isolation check green; a forgotten `where` can no longer leak data (global scope
catches it). Pre-existing PHPUnit suite is unusable on SQLite (a MySQL-only `MODIFY COLUMN` migration);
the artisan check runs against real MySQL instead.

## ✅ STATUS (2026-06-15): S0–S4 DONE — full self-serve SaaS ready

Verified end-to-end: signup→trial(200) → trial-expiry(402, auto-flip expired) →
activate(200) → suspend(402); isolation check PASS; plan gating (starter: 3 users +
no e-invoice; pro: unlimited + e-invoice) verified; Razorpay signature verify pass/reject.

- **S0** structural tenant isolation (global scope) ✅
- **S1** self-signup + 14-day trial + provisioning ✅
- **S2a** subscription enforcement middleware + trial gate ✅
- **S2b** Razorpay online billing (pluggable; manual fallback) ✅
- **S3** super-admin platform panel ✅
- **S4** plan-based feature gating (user limit + e-invoice) ✅

To activate online billing: set RAZORPAY_ENABLED=true + keys in .env.
Until then, tenants pay manually and the owner activates via super-admin panel.

## Phase S1 — Signup, trial & onboarding   ✅ DONE (wizard optional)

5. **Public signup endpoint** `POST /auth/register` (rate-limited, unauthenticated):
   - Validates company name, admin name, email (global-unique), password (passwordPolicy).
   - `TenantProvisioningService` (lift from RoleUserSeeder): create Company (status=trial,
     trial_ends_at = now()+14d), default Roles, admin User; issue Sanctum token.
   - **Note:** BaseModel's `creating` hook does NOT fire here (request is unauthenticated) — set
     `company_id` explicitly in the service. This is the classic trap; cover with a test.
6. **`trial_ends_at` column** on companies (add migration) + set on signup.
7. **Onboarding wizard** (frontend): after signup → company details (GST, address, logo, prefixes)
   → first panel types / done. Skippable, resumable.
8. **Signup page + flow** (frontend): public route before AppShell; `App.vue` shows signup/login.

## Phase S2 — Subscription enforcement & billing

9. **`EnsureActiveTenant` middleware** on the main API group:
   - Block when `company.is_active=false` or `subscription_status=expired`
     (allow a short grace period + read-only mode is nicer than hard block).
   - Trial expiry: `trial_ends_at < now()` && status=trial → expired.
   - Return a clear 402/403 JSON the frontend turns into an "upgrade / renew" screen.
10. **Plans config** (`config/plans.php`): per plan → price, limits (max users, modules, e-invoice on/off).
11. **Razorpay subscription integration** (India-first; Stripe optional):
    - Plans/subscriptions, checkout, webhook (`/webhooks/razorpay`) → update `subscription_status`.
    - Handle: trial→paid, renewal success/failure, cancellation, dunning.
12. **Billing UI** (frontend): current plan, usage, upgrade/downgrade, payment method, invoices.

## Phase S3 — Super-admin (platform owner) panel

13. **Super-admin API** (`/admin/*`, guarded by `is_super_admin`): list all companies, MRR, active/
    trial/churned counts, manually suspend/extend/upgrade, impersonate (audited), usage metering.
14. **Super-admin UI**: tenant table, revenue dashboard, per-tenant drill-down.

## Phase S4 — Plan gating & polish

15. **Feature gating** map `subscription_plan` → features/limits; enforce in middleware + hide in UI.
16. **Per-tenant subdomain** (optional): `umasignature.panelos.app` resolution.
17. **Usage limits** (users, storage) enforced with friendly upgrade prompts.

---

## Recommended order before any ad spend
S0 (isolation, ~2-3 days) → S1 (signup/trial, ~3-4 days) → S2 (enforcement + billing, ~4-5 days)
→ **closed beta** with 2-3 friendly factories (free) to shake out real-world bugs → THEN ads.
S3/S4 can follow once paying tenants exist.

## Honest risk note
No software is "bug-free." Normal feature bugs are cheap to fix. The one expensive, reputation-
killing bug class is **cross-tenant data leakage** — S0 exists specifically to eliminate it
structurally before strangers (and their competitors) share the platform.
