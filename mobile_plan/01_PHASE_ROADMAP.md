# 01 — Phase Roadmap

> Master roadmap. Every phase lists: **Goal**, **Sub-phases** (with deliverables + exit criteria), **Dependencies**, and **Rough effort** (person-weeks, one RN developer unless stated — see [16_TEAM_RESOURCING_ESTIMATE.md](16_TEAM_RESOURCING_ESTIMATE.md) for how these roll up into a team plan). Effort figures are planning estimates, not commitments.

---

## Phase 0 — Discovery & Foundation

**Goal:** Nothing user-facing yet — get the project, tooling, and design foundation in place so every later phase can move fast.

### 0.1 Requirements sign-off
- Deliverables: this `mobile_plan/` doc set reviewed and approved; open decisions (portal invite flow, distribution model) resolved or explicitly deferred with an owner.
- Exit criteria: written sign-off from the business owner (Kamlesh) on scope in [00_EXECUTIVE_SUMMARY.md](00_EXECUTIVE_SUMMARY.md).
- Effort: 0.5 week.

### 0.2 Environment & tooling setup
- Deliverables: Expo project created inside this repo (`mobile/`), TypeScript configured, ESLint/Prettier matching the Vue frontend's conventions where sensible, EAS account + build profiles for dev/staging/prod ([15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md)).
- Exit criteria: a blank app builds and runs on both an Android and iOS test device via Expo Go / dev client.
- Effort: 0.5 week.

### 0.3 Design system port
- Deliverables: mobile design tokens derived from the existing brand palette ([13_DESIGN_SYSTEM_UI_GUIDELINES.md](13_DESIGN_SYSTEM_UI_GUIDELINES.md)), base component library (buttons, cards, status pills, form fields) matching web semantics (green=good, red=overdue, amber=warning).
- Exit criteria: a Storybook-style screen in the app showing every base component in both light states.
- Effort: 1 week.

### 0.4 Auth integration
- Deliverables: Staff login screen wired to existing `POST /auth/login`, token stored in `expo-secure-store`, auto-attach to all API calls, `GET /auth/me` used to hydrate role/permissions, logout flow, session-expiry handling (existing 12h Sanctum token expiry per commit `9dc6455` — app must detect a 401 and route back to login, not crash).
- Exit criteria: a staff test user can log in, see a role-appropriate empty dashboard, and log out; killing/reopening the app preserves the session until token expiry.
- Effort: 1 week.

### 0.5 API client & offline foundation
- Deliverables: typed API client modules (one per resource), TanStack Query wired with the persisted cache, the outbox table created (empty, no producers yet) per [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md).
- Exit criteria: app can fetch and cache `GET /dashboard` and survive airplane mode showing the last-cached response.
- Effort: 1 week.

**Phase 0 total: ~4 weeks.** No backend changes required.

---

## Phase 1 — Staff App: Production Module

**Goal:** Floor/production workers can see their batches and progress them without a desktop.
Full screen spec: [04_PRODUCTION_MODULE.md](04_PRODUCTION_MODULE.md).

### 1.1 Batch list
- Deliverables: list screen consuming `GET /batches` (or `/production/batches`), status filter chips, pull-to-refresh.
- Exit criteria: matches what the web batch list shows, minus BOQ-heavy fields not needed on a phone.
- Effort: 0.5 week.

### 1.2 Batch detail + stage timeline
- Deliverables: info header, vertical stage timeline UI, live elapsed timer on the in-progress stage, Start/Complete buttons wired to `POST /batches/{batchId}/stages/{stageId}/start|complete`.
- Exit criteria: a full batch can be walked stage-by-stage end to end against a staging batch.
- Effort: 1.5 weeks.

### 1.3 Offline queueing for stage actions
- Deliverables: stage start/complete routed through the outbox; pending/synced/failed indicators.
- Exit criteria: airplane-mode test in [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md) passes for this module.
- Effort: 0.5 week (infrastructure already built in 0.5).

### 1.4 QC checklist
- Deliverables: pass/fail checklist screen (`GET/POST /batches/{id}/qc`), inspector notes, overall verdict, approve action (`POST /quality-control/{id}/approve`).
- Exit criteria: a QC record created on mobile appears correctly on the web QC dashboard.
- Effort: 1 week.

### 1.5 Stage photo attachment — ✅ built
- Deliverables: camera capture + upload UI, wired to the photo endpoints from [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1 (now shipped).
- Exit criteria: a photo taken on-device is visible on the corresponding web batch detail screen (served from the `public` disk, same as existing accessory/company-logo images).
- **Shipped** (2026-10-22): `Attachment` model/migration + `AttachmentController` (backend), `mobile/src/api/attachments.ts` + `pickPhoto()` helper (app). Wired into `BatchDetailScreen.tsx` — a camera button appears on any active/completed stage row, uploads a `stage_photo`, and shows a running count. `qc_photo` is a supported type on the same endpoint but has no dedicated UI yet — `QcScreen.tsx` is batch-level (post-stages), not stage-scoped, so it doesn't have a natural `stageId` to attach through; revisit if QC needs its own photo flow.

### 1.6 Module QA & floor UAT
- Deliverables: a real floor worker uses the module for a live batch under supervision; issues logged and triaged.
- Exit criteria: 3 consecutive batches completed mobile-only with no data-integrity issues.
- Effort: 0.5 week.

**Phase 1 total: ~5 weeks app-side**, gated on backend Phase 1-supporting work (see backend track below).

---

## Phase 2 — Staff App: Dispatch Module

**Goal:** Dispatch executives can allocate, confirm, and prove delivery from the field.
Full screen spec: [05_DISPATCH_MODULE.md](05_DISPATCH_MODULE.md).

### 2.1 Dispatch list
- Deliverables: `GET /dispatches` list, filtered to pending/allocated, overdue flag.
- Effort: 0.5 week.

### 2.2 Dispatch detail + allocate
- Deliverables: detail screen, `POST /dispatches/{id}/allocate`.
- Effort: 1 week.

### 2.3 Mark delivered + proof capture — ✅ built
- Deliverables: `POST /dispatches/{id}/complete`, photo capture wired to the delivery-proof endpoint ([09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1, now shipped).
- Exit criteria: proof visible on the web dispatch detail.
- **Shipped** (2026-10-22): `DispatchDetailScreen.tsx` now has a "Delivery Proof" card — thumbnail row + an always-available camera/library button, independent of the Mark Delivered action (so proof can be captured before or after confirming). `signature` is a supported attachment type on the same endpoint but has no dedicated capture UI yet (no signature-pad library added) — revisit if a drawn signature (not just a photo) is actually required.

### 2.4 Challan viewer
- Deliverables: `GET /dispatches/{id}/challan` rendered read-only (reuse existing PDF via in-app viewer or `GET /dispatches/{id}/challan/pdf`).
- Effort: 0.5 week.

### 2.5 Module QA & UAT
- Deliverables: a real dispatch run tracked mobile-only.
- Effort: 0.5 week.

**Phase 2 total: ~4 weeks app-side.**

---

## Phase 3 — Staff App: Sales & Accounts Module

**Goal:** Sales and accounts staff can check status and act on alerts without a laptop.
Full screen spec: [06_SALES_ACCOUNTS_MODULE.md](06_SALES_ACCOUNTS_MODULE.md).

### 3.1 Quotations & Orders (read + lifecycle actions)
- Deliverables: list + detail for `GET /quotations`, `GET /orders`; Send/Accept/Reject actions where it makes sense on a phone (not BOQ editing).
- Effort: 1.5 weeks.

### 3.2 Customer 360
- Deliverables: `GET /customers/{id}/profile`, `/credit-status` — quick outstanding-balance and history view.
- Effort: 0.5 week.

### 3.3 Receivables aging + reminders
- Deliverables: `GET /reports/accounts-receivable` aging list, tap-to-call/WhatsApp, `POST /invoices/{id}/send-payment-reminder`.
- Effort: 1 week.

### 3.4 Invoice detail + payment recording
- Deliverables: read-only invoice detail, `POST /payments/record`.
- Effort: 1 week.

### 3.5 Stock/chemical alerts
- Deliverables: `GET /stock/alerts` surfaced on the accounts/production dashboard.
- Effort: 0.5 week.

### 3.6 Module QA & UAT
- Effort: 0.5 week.

**Phase 3 total: ~5 weeks app-side.** No backend dependency — can run in parallel with Phase 1/2 if a second developer is available (see [16_TEAM_RESOURCING_ESTIMATE.md](16_TEAM_RESOURCING_ESTIMATE.md)).

---

## Phase 4 — Cross-Cutting: Notifications & Dashboard

**Goal:** Every role gets a relevant home screen and real-time alerts.

### 4.1 Push notification backend pipeline ⚠ backend
- Deliverables: `device_tokens` table, registration endpoint, FCM channel in `NotificationDispatchService` — full spec in [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §2.
- Effort: 1 week backend.

### 4.2 Push client integration
- Deliverables: permission prompt, token registration on login, notification tap → deep link into the right screen.
- Effort: 1 week app-side — depends on 4.1.

### 4.3 Role-based dashboard — ✅ built
- Deliverables: `GET /dashboard` rendered per role — production sees batch/QC KPIs, dispatch sees pending deliveries, sales/accounts see receivables + pipeline.
- Effort: 1 week.
- **Shipped.** `GET /dashboard` (`DashboardService::getDashboard`) is real, complete, and already cached 60s server-side — built exactly as scoped, in `mobile/src/features/dashboard/`.

### 4.1/4.2/4.4 — blocked, not built
**Confirmed during the dashboard build:** the in-app notification inbox has **no backend at all** — not a partial gap, nothing. No `Notification`/`UserNotification` model, no `GET /notifications` or `PUT /notifications/{id}/read` route exists anywhere in `backend/routes/api.php`. `NotificationLog` exists but is a company-wide outbound-delivery audit log (channel/recipient/type/status for WhatsApp/SMS sends) with no `user_id` and no `is_read` — it cannot back a per-user inbox as-is. Building 4.4 means designing that model from scratch first (a real backend-architecture decision: per-user vs per-company, what events generate a row, retention), not just adding an endpoint.

4.1 (push backend pipeline) additionally needs a Firebase project and its server credentials, which only the business owner can create and hand over — still accurately scoped in [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §2, just confirmed still entirely unbuilt.

Given both, 4.1/4.2/4.4 are deferred as a group until there's a concrete backend-design decision for the notification inbox and a Firebase project exists — revisit as their own phase rather than bundled with the dashboard.

**Phase 4 total: ~3.5 weeks (1 backend + 2.5 app)** — revise once 4.1/4.2/4.4 are re-scoped; only 4.3's ~1 week shipped so far.

---

## Phase 5 — Staff App Hardening & Release

**Goal:** Ship v1 of the Staff App to real users.

### 5.1 Performance & offline test pass
- Deliverables: full run of the offline test matrix in [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md); cold-start time, list-scroll performance on a low-end Android device (see [14_TESTING_QA_STRATEGY.md](14_TESTING_QA_STRATEGY.md) device matrix).
- Effort: 1 week.

### 5.2 Security review
- Deliverables: checklist from [12_SECURITY_COMPLIANCE.md](12_SECURITY_COMPLIANCE.md) run end to end (token storage, transport security, photo upload validation).
- Effort: 0.5 week.

### 5.3 Internal beta
- Deliverables: TestFlight (iOS) / Play internal testing track (Android) build distributed to a small pilot group (1–2 people per role).
- Exit criteria: no P0/P1 bugs open for 5 consecutive days.
- Effort: 1 week (elapsed, low active effort).

### 5.4 Pilot rollout at Signature PUF
- Deliverables: full shift/team onboarding, in-person or video training per role, printed quick-reference cards.
- Exit criteria: adoption metrics from [18_SUCCESS_METRICS_KPIS.md](18_SUCCESS_METRICS_KPIS.md) hit for two consecutive weeks.
- Effort: 1 week + 2 weeks observation.

### 5.5 GA release (v1.0)
- Deliverables: tagged release, release notes, versioning per [15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md).
- Effort: 0.5 week.

**Phase 5 total: ~4 weeks + 2 weeks observation.**

---

## STAFF APP MILESTONE COMPLETE — cumulative estimate: ~26–28 weeks elapsed with 1 RN developer + part-time backend support; compresses to ~14–16 weeks with 2 developers working Phase 1/2 and Phase 3 in parallel. See [16_TEAM_RESOURCING_ESTIMATE.md](16_TEAM_RESOURCING_ESTIMATE.md).

---

## Phase 6 — Partner Portal: Backend Foundation

**Goal:** Build the auth surface that doesn't exist yet, safely isolated from staff data.
Full spec: [07_PARTNER_PORTAL_MODULE.md](07_PARTNER_PORTAL_MODULE.md), [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §3.

### 6.1 Invite-flow decision
- Deliverables: written decision — admin-issued credentials from the Customers screen, vs. self-serve phone/WhatsApp OTP signup.
- **This is a blocking open decision — see [README.md](README.md).**
- Effort: decision only, no dev effort, but nothing below starts without it.

### 6.2 Customer auth guard & schema
- Deliverables: `customer_users` table, new Sanctum guard, password reset flow (or OTP flow per 6.1).
- Effort: 1.5 weeks.

### 6.3 Portal API endpoints
- Deliverables: `/portal/orders`, `/portal/dispatches`, `/portal/invoices` (+ detail routes), each hard-scoped to the authenticated customer's `customer_id`.
- Effort: 1.5 weeks.

### 6.4 Isolation security testing
- Deliverables: automated tests proving a portal token cannot reach any `/api/v1/*` staff route, and cannot see another customer's rows even by guessing IDs.
- Exit criteria: all tests green, reviewed against [12_SECURITY_COMPLIANCE.md](12_SECURITY_COMPLIANCE.md).
- Effort: 0.5 week.

**Phase 6 total: ~3.5 weeks backend**, fully blocked on 6.1.

---

## Phase 7 — Partner Portal: App

### 7.1 Portal login/onboarding
- Effort: 0.5 week.

### 7.2 Order status screens
- Deliverables: list + detail, read-only BOQ summary, `GET /orders/{id}/production-status`.
- Effort: 1 week.

### 7.3 Dispatch tracking
- Deliverables: status, expected date, challan PDF view.
- Effort: 0.5 week.

### 7.4 Invoices & balance
- Deliverables: list, detail, PDF download.
- Effort: 0.5 week.

### 7.5 Optional pay-now integration
- Deliverables: wraps the already-existing `POST /invoices/{id}/payment/checkout-session` (Stripe/Razorpay) — decide at this phase whether to include in v1 or defer.
- Effort: 1 week if included.

**Phase 7 total: ~2.5–3.5 weeks app-side.**

---

## Phase 8 — Partner Portal Release

### 8.1 Pilot with select dealers
- Deliverables: 3–5 friendly dealers/customers onboarded manually, feedback collected.
- Effort: 1 week + 2 weeks observation.

### 8.2 Feedback-driven iteration
- Effort: 1 week.

### 8.3 GA release
- Deliverables: rollout to full customer/dealer base, distribution decision finalized ([15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md)).
- Effort: 0.5 week.

**Phase 8 total: ~2.5 weeks + 2 weeks observation.**

---

## PARTNER PORTAL MILESTONE COMPLETE — cumulative estimate: ~9–10 weeks elapsed after the invite-flow decision is made.

---

## Phase 9 — Post-Launch: Continuous Improvement

Ongoing, not a fixed-duration phase.

### 9.1 Analytics & usage monitoring
- Track adoption/engagement per [18_SUCCESS_METRICS_KPIS.md](18_SUCCESS_METRICS_KPIS.md); monthly review cadence.

### 9.2 Feedback-driven backlog
- A living backlog fed by pilot/UAT notes and support tickets; triaged monthly.

### 9.3 Multi-tenant readiness (future SaaS)
- Not built now, but flagged: if PanelOS becomes multi-tenant for other manufacturers (per the SaaS roadmap in the root [README.md](../README.md)), the app's login/session layer will need a company-selection step. Architected today assuming single-tenant to avoid speculative complexity — revisit when a second tenant is actually signed.

---

## Timeline summary

| Milestone | Elapsed (1 dev) | Elapsed (2 devs, parallelized) |
|---|---|---|
| Staff App (Phases 0–5) | ~26–28 weeks | ~14–16 weeks |
| Partner Portal (Phases 6–8), after invite-flow decision | ~9–10 weeks | ~6–7 weeks |
| **Total** | **~35–38 weeks** | **~20–23 weeks** |

These are planning estimates for scoping conversations, not delivery commitments — refine once actual team composition is confirmed ([16_TEAM_RESOURCING_ESTIMATE.md](16_TEAM_RESOURCING_ESTIMATE.md)).
