# 08 — API Integration Map

Every endpoint the mobile app calls, grouped by module, with the screen that uses it and any mobile-specific note. Endpoints are taken from the **live** `backend/routes/api.php` (verified by direct grep, not from the older `03_API_ENDPOINTS.md` doc, which describes some routes — e.g. `upload-photo` — that don't actually exist yet; see [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) for the gap list). **Correction (confirmed during Phase 0 build):** routes are prefixed `/api` only — there is **no** `/v1` segment; `backend/routes/api.php` has no wrapping `Route::prefix('v1')` group, just Laravel's default `api.php` → `/api` mount (confirmed against [DOCKER_SETUP.md:109](../DOCKER_SETUP.md#L109), which documents the real base as `http://localhost/api`). The mobile API client ([mobile/src/shared/env.ts](../mobile/src/shared/env.ts)) points at `/api`. All routes sit behind `auth:sanctum` + `tenant.active` middleware ([backend/routes/api.php:76](../backend/routes/api.php#L76)) unless marked "NEW."

## Auth

| Endpoint | Method | Used by | Note |
|---|---|---|---|
| `/auth/login` | POST | Login screen (Staff) | Returns Sanctum bearer token |
| `/auth/me` | GET | App boot, after login | Hydrates role/permissions for nav gating |
| `/auth/logout` | POST | Profile/Settings | Revokes token server-side |
| `/auth/refresh-token` | POST | Background, on app foreground | Sliding session per commit `9dc6455`; app should call this on resume, mirroring the web `AppShell` behavior |
| `/auth/change-password` | POST | Profile | |

## Dashboard

| Endpoint | Method | Used by |
|---|---|---|
| `/dashboard` | GET | Shared Dashboard screen, filtered/rendered per role client-side |

## Production ([04_PRODUCTION_MODULE.md](04_PRODUCTION_MODULE.md))

| Endpoint | Method | Used by | Note |
|---|---|---|---|
| `/batches` | GET | Batch List | Confirm pagination params before Phase 1.1 — add `?status=` support if not present |
| `/batches/{id}` | GET | Batch Detail | |
| `/batches/{batchId}/timeline` | GET | Batch Detail (stage timeline) | |
| `/batches/{batchId}/progress` | GET | Batch Detail (progress bar) | |
| `/batches/{batchId}/stages/{stageId}/start` | POST | Batch Detail | Routed through offline outbox |
| `/batches/{batchId}/stages/{stageId}/complete` | POST | Batch Detail | Routed through offline outbox |
| `/production-stages` | GET | Batch Detail (stage list/sequence) | Company-configurable, never hardcode stage names |
| `/batches/{id}/qc` | GET/POST | QC Checklist | |
| `/quality-control/{id}/approve` | POST | QC Checklist (Production Manager) | |
| `/quality-control/statistics` | GET | QC Dashboard | |
| `/quality-control` | GET | QC Dashboard (recent list) | |
| `POST /batches/{batchId}/stages/{stageId}/photo` | POST | Batch Detail / QC (photo attach) | **NEW — see backend doc §1** |

## Dispatch ([05_DISPATCH_MODULE.md](05_DISPATCH_MODULE.md))

| Endpoint | Method | Used by | Note |
|---|---|---|---|
| `/dispatches` | GET | Dispatch List | |
| `/dispatches/{id}` | GET | Dispatch Detail | |
| `/dispatches/{id}/allocate` | POST | Dispatch Detail | |
| `/dispatches/{id}/complete` | POST | Delivery Confirmation | Called together with the new proof upload, see below |
| `/dispatches/{id}/challan` | GET | Challan Viewer | |
| `/dispatches/{id}/challan/pdf` | GET | Challan Viewer | |
| `/inventory/coils/available` | GET | Allocate flow (manual pick, if no auto-allocate) | Confirm this route's real path against `StockController` — doc lists it under `inventory/coils/available`, live routes show `/stock/coils`; reconcile during Phase 2.2 |
| `POST /dispatches/{id}/delivery-proof` | POST | Delivery Confirmation | **NEW — see backend doc §1** |

## Sales & Accounts ([06_SALES_ACCOUNTS_MODULE.md](06_SALES_ACCOUNTS_MODULE.md))

| Endpoint | Method | Used by |
|---|---|---|
| `/quotations` | GET | Quotations List |
| `/quotations/{id}` | GET | Quotation Detail |
| `/quotations/{id}/send` | POST | Quotation Detail |
| `/quotations/{id}/accept` | POST | Quotation Detail |
| `/quotations/{id}/reject` | POST | Quotation Detail |
| `/quotations/{id}/pdf` | GET | Quotation Detail |
| `/orders` | GET | Orders List |
| `/orders/{id}` | GET | Order Detail |
| `/orders/{id}/production-status` | GET | Order Detail |
| `/customers/{id}/profile` | GET | Customer 360 |
| `/customers/{id}/credit-status` | GET | Customer 360 |
| `/customers/{id}` (orders/invoices/payments/outstanding sub-resources per `03_API_ENDPOINTS.md`) | GET | Customer 360 — confirm exact live sub-routes during Phase 3.2 |
| `/reports/accounts-receivable` | GET | Receivables Aging |
| `/invoices/{id}/send-payment-reminder` | POST | Receivables Aging ("Remind" button) |
| `/invoices/{id}` | GET | Invoice Detail |
| `/invoices/{id}/payments` | GET | Invoice Detail |
| `/invoices/{id}/payment-status` | GET | Invoice Detail |
| `/invoices/{id}/pdf` | GET | Invoice Detail |
| `/payments/record` | POST | Invoice Detail (Record Payment) |
| `/stock/alerts` | GET | Stock/Chemical Alerts tile |

## Notifications ([11_PUSH_NOTIFICATIONS.md](11_PUSH_NOTIFICATIONS.md))

| Endpoint | Method | Used by | Note |
|---|---|---|---|
| `/notifications` | GET | In-app inbox | |
| `/notifications/{id}/read` | PUT | In-app inbox | |
| `/notifications/read-all` | PUT | In-app inbox | |
| `POST /notifications/device-token` | POST | App boot, after login | **NEW — see backend doc §2** |

## Partner Portal ([07_PARTNER_PORTAL_MODULE.md](07_PARTNER_PORTAL_MODULE.md)) — all NEW, Phase 6

| Endpoint | Method | Used by |
|---|---|---|
| `/portal/auth/login` (or `/portal/auth/request-otp` + `/portal/auth/verify-otp` if Option B chosen) | POST | Portal Login |
| `/portal/orders` | GET | Order Status List |
| `/portal/orders/{id}` | GET | Order Detail |
| `/portal/dispatches` | GET | Dispatch Tracking |
| `/portal/invoices` | GET | Invoices & Balance |
| `/portal/invoices/{id}` | GET | Invoice Detail |
| `/invoices/{id}/payment/checkout-session` | POST | Optional Pay Now (Phase 7.5) — reuses the existing staff-side Stripe/Razorpay endpoint; needs a portal-guard-compatible variant, see backend doc §3 |

## Reconciliation task before Phase 1 starts

Several endpoints referenced in the older `03_API_ENDPOINTS.md` doc (photo uploads, `upload-delivery-photos`, some `inventory/*` paths) do not match the current `backend/routes/api.php`. **Before writing any app code against a given endpoint, verify it against the live route file and the relevant controller, not the doc.** This map will be updated as each Phase confirms its endpoints; treat rows without a "NEW" tag as "believed to exist, verify on contact" rather than guaranteed-correct until a developer has actually hit them from Postman/Insomnia during that phase's first week.
