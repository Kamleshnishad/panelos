# Super-Admin (Platform Owner) — Feature Research & System Mapping

What a mature SaaS platform-admin panel does, mapped to **what PanelOS already
has** vs **what's needed**, with effort (S = <0.5d, M = ~1d, L = 2d+).
Today's panel: overview KPIs, company list/filter, activate, extend-trial, suspend.

Legend: ✅ done · 🟡 partial/data-exists · ⬜ to build

---

## 1. Tenant Management
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| List all companies + filter/search | ✅ | `SuperAdminController@companies` | — |
| Activate plan (months) / extend trial / suspend | ✅ | `@activate/@extendTrial/@setActive` | — |
| **Tenant detail drill-down** (users, invoices, usage) | 🟡 | `@show` exists; needs a UI screen | S |
| **Login-as / Impersonate** (enter a tenant to debug, audited) | ⬜ | issue a scoped Sanctum token for the tenant admin; log to `audit_logs`; banner "viewing as X" | M |
| **Edit tenant** (plan, limits override, contact, GST) | 🟡 | Company is editable; add admin endpoint + form | S |
| **Force password reset** for a tenant admin | ⬜ | reuse `UserController` reset logic cross-tenant | S |
| **Offboard / delete tenant** (+ data export first) | ⬜ | soft-delete Company + cascade; export JSON/zip | M |
| **Resend welcome / verify email** | ⬜ | EmailService already exists | S |

## 2. Billing & Revenue (India-specific)
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| Est. MRR | 🟡 | naive sum in `@overview`; make it real from active subs | S |
| **ARR, churn %, ARPU, LTV** | ⬜ | compute from companies + subscription history | M |
| **Subscription payment history** per tenant | ⬜ | NEW `tenant_subscription_payments` table (Razorpay payments) | M |
| **Upcoming renewals / expiring this week** | 🟡 | query `subscription_ends_at`/`trial_ends_at` ranges | S |
| **Failed-payment / dunning list** | ⬜ | from Razorpay webhook `payment.failed` | M |
| **GST tax-invoice for subscription** (you bill them) | ⬜ | reuse Invoice/PDF engine for SaaS billing; needs your GSTIN as seller | L |
| **Plan distribution chart** (how many on each plan) | 🟡 | group companies by plan | S |
| **Promo codes / discounts** | ⬜ | NEW `coupons` table applied at checkout | M |

## 3. Usage & Health Metrics (per tenant + platform)
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| Users per tenant, last login | 🟡 | `users.last_login_at` exists | S |
| **Activity/records count** (quotations, orders, invoices, sqm) | ⬜ | count per company across models (withoutGlobalScope) | M |
| **Last-active / dormant tenants** (engagement) | ⬜ | max(last_login_at) per company → flag inactive | S |
| **Feature adoption** (who uses production/e-invoice/WhatsApp) | ⬜ | presence checks per tenant | M |
| **Storage usage** (logos, uploads) per tenant | ⬜ | sum file sizes in storage/{company} | M |

## 4. Onboarding & Growth (you're running ads)
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| **Signup funnel** (signups/day, trial→paid conversion %) | ⬜ | from companies.created_at + status transitions | M |
| **Trials expiring in N days** (proactive outreach list) | 🟡 | query trial_ends_at; one-click WhatsApp/email | S |
| **Lead source / UTM capture** at signup | ⬜ | add `utm_source/campaign` cols to companies; pass from signup form | S |
| **Auto welcome + onboarding emails** (drip) | ⬜ | EmailService + scheduled command | M |

## 5. Communication to Tenants
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| **Broadcast banner** (maintenance/announcement to all) | ⬜ | NEW `platform_announcements` table; show in AppShell | M |
| **Email/WhatsApp blast** to tenant admins | ⬜ | EmailService + TwilioStreamClient (already built) | M |
| **Per-tenant note / support log** | ⬜ | NEW `tenant_notes` table | S |

## 6. Plan & Pricing Config (from UI)
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| Plans/prices/limits | 🟡 | in `config/plans.php` (code); move to DB-editable | M |
| **Custom enterprise quote** per tenant | ⬜ | per-company price/limit override in companies.settings json | S |

## 7. Support & Operations
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| **Cross-tenant audit log viewer** | 🟡 | `audit_logs` exists (per-tenant); super-admin all-tenant view | S |
| **System health** (DB up, last backup, queue) | 🟡 | `/health` exists; add backup status from `db:backup` | S |
| **Add more platform admins** | ⬜ | create users with is_super_admin (careful) | S |
| **Error/exception feed** | ⬜ | tail Laravel logs / integrate Sentry | M |

## 8. Platform Security
| Feature | Status | System mapping | Effort |
|---|---|---|---|
| **2FA for super-admin** (critical — owns all data) | ⬜ | email-OTP on super-admin login | M |
| **Super-admin action audit** (every activate/suspend logged) | 🟡 | Auditable trait — extend to SuperAdminController actions | S |
| **IP allow-list for /admin** | ⬜ | middleware option | S |

---

## Recommended build order (for your ad-launch situation)

**Phase A — Operate the SaaS (highest value now):**
1. Tenant detail drill-down screen (see who signed up, their usage) — S
2. Trials-expiring list + one-click WhatsApp/email outreach — S (uses existing WhatsApp!)
3. Login-as / impersonate (debug a tenant's issue) — M
4. Super-admin action audit (every activate/suspend recorded) — S
5. Add-more-platform-admins — S

**Phase B — Money & growth:**
6. Subscription payment history + real MRR/ARR/churn — M
7. Signup funnel + conversion % + UTM capture — M
8. GST tax-invoice for subscriptions (you're selling in India) — L

**Phase C — Scale & polish:**
9. Broadcast announcement banner — M
10. DB-editable plans + promo codes — M
11. 2FA for super-admin — M

---

## Honest note
Today's panel is enough to *operate* a small SaaS (see + activate/suspend tenants).
Phase A makes it genuinely usable day-to-day once ads bring signups. Phase B is
needed once money flows (accounting, India GST compliance). Phase C is scale.
