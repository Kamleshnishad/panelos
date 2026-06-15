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

## Phase S0 — Isolation hardening (DO FIRST, non-negotiable)

Make tenant isolation structural, not opt-in. Without this, do not run ads/onboard strangers.

1. **`BelongsToTenant` trait + global scope**
   - New trait that adds a global scope: auto `where('company_id', auth user company)` on every read,
     bypassed when `auth()->user()->is_super_admin`.
   - Apply to all 21 BaseModel subclasses (and tenant-owned plain models like PaymentTransaction,
     NotificationSetting, GstConfiguration, AuditLog, SmsLog, etc.).
   - Keep the existing `creating` auto-inject for writes.
   - Child/line tables (OrderItem, QuotationItem, InvoiceItem, *_sizes, …) stay parent-scoped — but
     add a `company_id`-via-parent assertion in tests.
2. **Audit all 31 controllers** — with the global scope on, the manual `where('company_id')` becomes
   defence-in-depth; verify no endpoint relies on cross-tenant reads (except super-admin ones).
3. **Automated isolation test suite** (Pest/PHPUnit):
   - Seed 2 companies. With Company A's token, attempt to read/update/delete every major resource by
     Company B's IDs → must return 404/empty/403, never B's data.
   - Run on every deploy (CI gate).
4. **Login tenant resolution** — email is unique *per company*, not global. Decide: (a) global-unique
   email (simplest), or (b) subdomain/tenant-scoped login. Pick (a) for v1: add a global-unique guard
   on signup so one email = one tenant.

**Exit criteria:** isolation test suite green; a forgotten `where` can no longer leak data.

## Phase S1 — Signup, trial & onboarding

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
