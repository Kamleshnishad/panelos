# PanelOS Mobile — Planning Documentation

> Enterprise-depth planning set for the PanelOS mobile app (React Native + Expo).
> Companion to the root-level docs ([01_BUSINESS_LOGIC.md](../01_BUSINESS_LOGIC.md), [03_API_ENDPOINTS.md](../03_API_ENDPOINTS.md), etc.) which describe the existing web ERP this mobile app extends.
> Origin decision record: `C:\Users\Kamlesh\.claude\plans\precious-spinning-spring.md` (approved 2026-09-26).

**Status:** Planning — no code written yet. Scope approved: React Native, single-company (Signature PUF) for now, covering all four user groups across two milestones (Staff App, then Partner Portal).

---

## How to read this folder

Start at 00, then 01 (the master roadmap) — everything else is a deep-dive referenced from the roadmap. If you only have ten minutes, read 00 and 01.

| # | Document | What it covers |
|---|---|---|
| 00 | [00_EXECUTIVE_SUMMARY.md](00_EXECUTIVE_SUMMARY.md) | Business case, scope, goals, non-goals, success criteria |
| 01 | [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) | Full phase → sub-phase breakdown with deliverables, exit criteria, effort |
| 02 | [02_ARCHITECTURE.md](02_ARCHITECTURE.md) | App architecture, tech stack, folder structure, environments |
| 03 | [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) | Who uses the app, device context, permission matrix |
| 04 | [04_PRODUCTION_MODULE.md](04_PRODUCTION_MODULE.md) | Floor worker / production manager screens, in depth |
| 05 | [05_DISPATCH_MODULE.md](05_DISPATCH_MODULE.md) | Dispatch executive screens, in depth |
| 06 | [06_SALES_ACCOUNTS_MODULE.md](06_SALES_ACCOUNTS_MODULE.md) | Sales manager & accounts screens, in depth |
| 07 | [07_PARTNER_PORTAL_MODULE.md](07_PARTNER_PORTAL_MODULE.md) | Dealer/customer portal screens, in depth |
| 08 | [08_API_INTEGRATION_MAP.md](08_API_INTEGRATION_MAP.md) | Every endpoint the app calls, screen by screen |
| 09 | [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) | New migrations/endpoints/guards the Laravel backend needs |
| 10 | [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md) | Outbox pattern, conflict rules, sync triggers |
| 11 | [11_PUSH_NOTIFICATIONS.md](11_PUSH_NOTIFICATIONS.md) | Notification types, FCM pipeline, deep links |
| 12 | [12_SECURITY_COMPLIANCE.md](12_SECURITY_COMPLIANCE.md) | Threat model, token/guard isolation, data protection |
| 13 | [13_DESIGN_SYSTEM_UI_GUIDELINES.md](13_DESIGN_SYSTEM_UI_GUIDELINES.md) | Mobile design system, accessibility, glove-friendly UI |
| 14 | [14_TESTING_QA_STRATEGY.md](14_TESTING_QA_STRATEGY.md) | Test pyramid, device matrix, UAT plan |
| 15 | [15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md) | CI/CD, environments, release channels, rollback |
| 16 | [16_TEAM_RESOURCING_ESTIMATE.md](16_TEAM_RESOURCING_ESTIMATE.md) | Roles needed, effort estimate per phase |
| 17 | [17_RISK_REGISTER.md](17_RISK_REGISTER.md) | Risks, likelihood/impact, mitigations, owners |
| 18 | [18_SUCCESS_METRICS_KPIS.md](18_SUCCESS_METRICS_KPIS.md) | How we'll know it worked |

---

## Scope recap

- **M1 — Staff App**: floor/production, dispatch, sales, accounts — one app, role-gated, built entirely on the existing Sanctum-authenticated API.
- **M2 — Partner Portal**: dealers/customers — same app, new login flow, requires new backend auth surface (none exists today).
- **Platform**: React Native + Expo, TypeScript.
- **Tenancy**: single company (Signature PUF) for this build; the backend's per-company scoping already supports more tenants later without app changes.

## Open decisions still pending user sign-off

1. Dealer/customer portal credential flow — admin-issued vs. self-serve OTP (blocks Phase 6/7, see [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md)).
2. Whether the Staff App ships as an internal-distribution build only, or goes through public app store listing (see [15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md)).
3. Budget/headcount for the resourcing plan in [16_TEAM_RESOURCING_ESTIMATE.md](16_TEAM_RESOURCING_ESTIMATE.md) — figures there are estimates pending confirmation of who's actually available.

*Last updated: 2026-09-26.*
