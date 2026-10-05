# 06 — Sales & Accounts Module

Phase reference: [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) Phase 3. Persona: [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §4–5. Web equivalent: [SYSTEM_OVERVIEW.md §5.2, 5.9](../SYSTEM_OVERVIEW.md).

This module is split by tab within the app, but described together here since both roles look at overlapping data (a Sales Manager cares about a customer's outstanding balance; Accounts cares about the same number from the collections side).

## Screen 1: Quotations & Orders List (Sales Manager)

- **Data source:** `GET /quotations`, `GET /orders`, toggled via a segmented control.
- **Layout:** list — quotation/order no, customer, project, amount, status pill, validity/delivery date with overdue flag.
- **Filters:** status, customer search.
- **Interaction:** tap → Detail screen (read-only summary — **not** the BOQ builder; see [00_EXECUTIVE_SUMMARY.md](00_EXECUTIVE_SUMMARY.md) non-goals for why the full builder stays desktop-only).

## Screen 2: Quotation/Order Detail (read + lifecycle actions)

**Correction (verified during Phase 3 build):** quotation status is a real 7-value enum — `boq, draft, sent, accepted, rejected, revised, expired` — and each lifecycle action is server-gated by the current status (`send()` requires `draft`; `accept()` requires `sent`; `reject()` requires `draft` or `sent`), confirmed in `QuotationService`. The mobile app only shows an action when the current status allows it, falling back to the real server error if it's tapped anyway.

**Correction (verified during Phase 3 build):** there is no `/orders/{id}/production-status` endpoint — it was never built. `OrderService::getDetails()` already eager-loads the order's `batches` relation, so production status is simply `order.batches[].status` rendered as a list — no extra call needed.

- **Data source:** `GET /quotations/{id}` / `GET /orders/{id}`.
- **Layout:** summary card (customer, project, totals — no editable panel-row/size sub-tables, just the rolled-up numbers and a "View full BOQ" link that opens the PDF rather than an editable form), status, revision history for quotations.
- **Actions available on mobile:**
  - Quotation: **Send** (`POST /quotations/{id}/send`), **Accept** (`POST /quotations/{id}/accept`), **Reject** (`POST /quotations/{id}/reject`) — these are quick decisions a Sales Manager plausibly makes standing in front of a customer. Note: `accept()` here is just the quotation-status transition — converting an accepted quotation into an order (`POST /quotations/{id}/create-order`) is a separate, credit-limit-gated action (`CreditService`, can reject with a structured `CREDIT_LIMIT_EXCEEDED` error) and stays a desktop/office action, not built into the mobile app.
  - Order: linked production batches, read directly off the order detail response (see correction above).
- **Actions deliberately excluded from mobile:** Revise, Duplicate, editing any BOQ field, creating an order from an accepted quotation — all desktop-only.
- **PDF access:** `GET /quotations/{id}/pdf` opened in an in-app viewer with native share.

## Screen 3: Customer 360 — deferred

Not built in the Phase 3 mobile slice (scoped out to keep that phase proportional to Phases 1–2's effort) — the endpoints below are believed accurate but unverified against live controllers; verify before building.

- **Data source:** `GET /customers/{id}/profile`, `GET /customers/{id}/credit-status`, `GET /customers/{id}/orders`, `GET /customers/{id}/outstanding`.
- **Layout:** contact card, outstanding balance (large, prominent — this is usually the first thing a Sales Manager wants before a customer visit), recent orders/invoices list.
- **Access:** available to both Sales Manager and Accounts (read-only for Accounts if they're not otherwise editing customer records).

## Screen 4: Receivables Aging (Accounts, view-only for Sales)

**Correction (verified during Phase 3 build):** `ReportingService::getAccountsReceivable()` returns a single, already-flattened, unpaginated payload — `{summary: {total_ar, current, 30_days, 60_days, 90_days, over_90_days, invoice_count, overdue_count}, details: [{invoice_id, invoice_no, customer_name, invoice_date, due_date, total_amount, paid_amount, remaining_due, status, days_overdue, is_overdue}]}` — with customer name already joined in. This one endpoint **doubles as the invoice list** for the whole module; no separate Invoices List screen was built.

- **Data source:** `GET /reports/accounts-receivable`.
- **Layout:** aging summary stat tiles (Total / Current / 1-30 / 31-60 / 61-90 / 90+ days, matching the real bucket keys above) as a horizontally scrollable row, then a list of invoices with a per-row **age pill** and **"Remind" button**.
- **Action:** Remind → `POST /invoices/{id}/send-payment-reminder` — this is the single most valuable Accounts action on mobile: seeing an overdue invoice and firing off a reminder in two taps instead of waiting to be back at a desk.

## Screen 5: Invoice Detail + Record Payment

**Correction (verified during Phase 3 build):** the invoice's own `subtotal`/`tax_amount`/`total_amount` columns aren't what the payment logic actually trusts — `GET /invoices/{id}/payment-status` (`PaymentService::getPaymentStatus`) is the authoritative source for total/paid/remaining/percentage, and it's the same computation `recordPayment` itself uses internally to block overpayment. Fetch both `/invoices/{id}` (items/customer/notes) and `/payment-status` (the numbers) rather than computing tax math client-side from the bare invoice record.

- **Data source:** `GET /invoices/{id}`, `GET /invoices/{id}/payment-status`.
- **Layout:** payment summary from `/payment-status` (total/paid/balance), items list from `/invoices/{id}` (read-only).
- **Action:** **Record Payment** → `POST /payments/record` — inline form with amount (a "Full" quick-fill button pulling from `remaining_due`, matching the web UX, [SYSTEM_OVERVIEW.md:136](../SYSTEM_OVERVIEW.md#L136)), method (bank/cheque/UPI/cash/other), reference number. The server enforces the overpay guard and auto-flips the invoice to `paid` — no client-side overpay validation needed beyond a sane positive-number check.
- **PDF access:** `GET /invoices/{id}/pdf`.

## Screen 6: Stock & Chemical Alerts (shared tile, Accounts-visible if they also track inventory)

- **Data source:** `GET /stock/alerts`.
- **Layout:** simple list, severity-colored rows (low stock / expiring / expired), matching the web alerts panel.

## Explicitly not in this module

- BOQ/quotation creation or editing.
- Master data (panel types, pricing rules, accessories) — desktop/admin only.
- Full financial reports (P&L, balance sheet, GST reports) — these are dense, desktop-appropriate reporting screens; the mobile app surfaces only the action-oriented subset (receivables, payments) that benefits from being available anywhere.
