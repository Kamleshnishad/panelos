# 07 — Partner Portal Module (Dealer / Customer)

Phase reference: [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) Phases 6–7. Persona: [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §6. Backend dependency: [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §3 — **this entire module is blocked until that backend work ships**, since no customer-facing auth exists today.

## Design intent

This is deliberately the thinnest module in the app. External users have no training, no context on PanelOS internals, and a low tolerance for a confusing app. Every screen should answer one question as directly as possible: *"where's my stuff, and what do I owe?"*

## Screen 1: Portal Login / Onboarding

- **Depends on the invite-flow decision (Phase 6.1, still open — see [README.md](README.md)):**
  - **Option A — admin-issued:** the dealer receives a username/temporary password (generated from the existing Customers screen on the web app) via SMS/WhatsApp/email, logs in, sets a permanent password.
  - **Option B — self-serve OTP:** dealer enters their registered phone number, receives an OTP (reusing the existing SMS infrastructure — `SmsController`, [backend/routes/api.php:382-386](../backend/routes/api.php#L382-L386)), no password to manage.
- **Recommendation to flag to the user when this decision is made:** Option B (OTP) is lower-friction for a first release aimed at reducing "status check" phone calls — a password to remember is one more reason someone gives up and just calls the office instead. This is a recommendation, not a decision — final call is the open item in [README.md](README.md).
- **Session:** once authenticated, token stored in `expo-secure-store` exactly as the Staff App does, but tagged so the app knows to route to `PortalNavigator`, never `StaffNavigator`.

## Screen 2: Order Status List

- **Data source:** `GET /portal/orders` (new, customer-scoped).
- **Layout:** card per order — order no, project, delivery date, status pill (pending/in production/completed), a simple progress indicator ("Production 60% complete" derived from `GET /orders/{id}/production-status` equivalent).
- **No BOQ editing, no pricing detail beyond what's already on their accepted quotation** — this is status visibility, not a self-service ordering tool (that would be a much larger, separate product decision, out of scope here).

## Screen 3: Order Detail

- **Data source:** `GET /portal/orders/{id}`.
- **Layout:** read-only summary — panel types/quantities/SQM (no rates/pricing breakdown unless the business wants dealers to see their own agreed pricing, which they already know from the accepted quotation PDF), delivery date, linked dispatches.

## Screen 4: Dispatch Tracking

- **Data source:** `GET /portal/dispatches`.
- **Layout:** list — dispatch no, linked order, expected/actual delivery date, status, tracking reference if entered. Tapping opens the challan PDF (`GET /dispatches/{id}/challan/pdf`, portal-scoped).

## Screen 5: Invoices & Balance

- **Data source:** `GET /portal/invoices`.
- **Layout:** list with balance due prominent, tap → detail (read-only) → PDF download.
- **Optional (decide at Phase 7.5):** a "Pay Now" button reusing the already-existing `POST /invoices/{id}/payment/checkout-session` (Stripe/Razorpay integration already present server-side per [backend/routes/api.php:327](../backend/routes/api.php#L327)) — this is genuinely low-effort to add since the payment gateway integration already exists, it's a question of whether the business wants dealers paying through the app in v1 or wants to hold that back.

## Screen 6: Profile / Support

- **Layout:** company contact details (from `Company` settings), a "Raise a query" action (simplest version: opens WhatsApp/phone/email to the assigned Sales Manager — no in-app ticketing system needed for v1).

## Explicitly not in this module

- Any write action beyond payment (optional) and raising a query — no editing of anything.
- Any visibility into other customers' data, other companies' data, or internal staff screens — enforced server-side by the isolated guard (see [12_SECURITY_COMPLIANCE.md](12_SECURITY_COMPLIANCE.md)), and the app itself never even requests staff-only endpoints from a portal session.
- Multi-user accounts per dealer (e.g. a dealer's own staff having separate logins) — v1 is one login per `Customer` record; revisit only if pilot feedback demands it.
