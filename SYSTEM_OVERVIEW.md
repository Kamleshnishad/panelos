# PUF Panel ERP — System Overview (for UI/UX Design)

> A complete order-to-cash ERP for a **PUF/PIR insulated panel manufacturer** (Signature PUF, Vadodara, Gujarat, India).
> This document describes the full system so a designer can design a cohesive, modern UI for it.
> **Backend (Laravel API) and all feature components exist.** What is missing is a unified **app shell / navigation layer** and a polished visual design.

---

## 1. What the business does

The company manufactures **PUF (Polyurethane Foam) and PIR insulated sandwich panels** — used for cold storage, clean rooms, roofing, and wall cladding. Each panel is two metal skins (e.g. PPGI steel) with foam injected between them, cut to specific lengths.

The full business workflow (the "order-to-cash" pipeline):

```
Quotation (BOQ) → Order → Production Batch → QC → Dispatch → Challan → Invoice → Payment → Receivables
```

Supporting data: Customers, Panel Types, Accessories, Production Stages, Coil & Chemical stock, Users/Roles, Company profile.

---

## 2. Who uses it (roles)

There are 6 roles. Design should accommodate role-based visibility (admins see everything; others see their function).

| Role | Primary screens they live in |
|------|------------------------------|
| **Super Admin** | Everything + settings |
| **Company Admin** | Everything + settings, users, master data |
| **Sales Manager** | Quotations, Orders, Customers, Receivables |
| **Production Manager** | Orders, Batches, QC, Stock |
| **Accounts** | Invoices, Payments, Receivables, Reports |
| **Viewer** | Read-only dashboards |

---

## 3. Brand & visual language (current)

- **Primary color:** Indigo `#1a237e` (deep blue) — used for headers, primary buttons, KPI values.
- **Secondary:** `#3949ab`.
- **Accents:** green `#2e7d32` (success/paid/in), red `#c62828` (danger/overdue/out), amber `#f57f17` / orange `#e65100` (warnings), purple `#4527a0` (accepted/chemical).
- **Surfaces:** white cards on light grey; `12px` rounded corners; subtle `1px #e0e0e0` borders; soft shadows on hover.
- **Currency:** Indian Rupee `₹` with Indian digit grouping (e.g. `₹ 2,31,320.40`) and short forms (K / L = lakh / Cr = crore).
- **Status everywhere is a colored pill badge.**
- Font: system/inherited sans-serif. Monospace for document numbers (e.g. `SCP-2026-001`).

A designer is free to modernize this — but the color semantics (green=good, red=overdue/danger, amber=warning) should stay consistent across modules.

---

## 4. Navigation structure (the shell that needs designing)

The app needs a persistent **left sidebar** (or top nav) switching between these areas. Suggested grouping:

**MAIN**
- 🏠 Dashboard (home KPIs)
- 📝 Quotations / BOQ
- 📦 Orders
- 🏭 Production Batches
- ✅ Quality Control

**INVENTORY**
- 🪙 Stock (Coils / Chemicals / Transaction Log — tabbed)

**SALES & FINANCE**
- 🚚 Dispatches
- 🧾 Invoices
- 💰 Receivables (aging)
- 📊 Reports

**SETTINGS** (admin only)
- 🏢 Company Settings
- 🗂️ Master Data (Panel Types / Accessories / Production Stages)
- 👤 Users & Roles

Top bar should show: company name + logo, current user, logout. Each module opens to a **list view**; clicking a row opens a **detail view**; "New" opens a **modal or full-page create form**.

---

## 5. The screens, module by module

Each module follows the same pattern: **List → Detail → Create**. Status is always a pill badge.

### 5.1 Dashboard (home)
The command center. Contains:
- **6 KPI cards** (clickable → navigate to module): Open Quotations, Active Orders, In Production, Pending Dispatch, Outstanding ₹, Collected ₹ (this financial year).
- **Alerts panel** — low stock, expiring/expired chemicals, overdue invoices, expired quotations (severity-colored rows).
- **Receivables aging mini-bar** — a single stacked horizontal bar (Current → 90+ days) with legend.
- **Order-to-Cash pipeline** — 4 stages (Quotations → Orders → Batches → Invoices), each showing status counts, with arrows between.
- **Recent activity feed** — latest quotations/orders/dispatches/invoices with type badge, reference, status, amount, relative time ("2h ago").

### 5.2 Quotations / BOQ
The most complex form in the system.
- **List:** quotation no, customer, project, amount, SQM, status (draft/sent/accepted/rejected/revised/expired), validity date.
- **Create (BOQ builder):** This is a large multi-section form:
  - Header: customer, project name/location, quality grade, validity days, discount %, advance %, transport.
  - **Panel rows** (repeatable cards): panel type, thickness, density (PUF/PIR + kg/m³), **top skin** (material/thickness/color/surface ribbed-or-plain), **bottom skin**, guard film, cello tap, HSN.
  - Each panel row has a **size sub-table**: length × width(1000 fixed) × nos → auto SQM → rate → amount. Lengths < 2000mm get a "⚠ DL" (double-length) warning tag.
  - **Accessories** section: standard / door-window (with width/height) / installation.
  - **Live totals summary**: subtotal, discount, taxable, CGST+SGST or IGST (auto-detected from customer state), transport, round-off, grand total, advance, balance.
- **Detail:** full read-only BOQ + revision history + actions (Send, Accept, Reject, Revise, Duplicate, Expire, Create Order, PDF).
- **PDF:** 3-page document — Proforma Invoice + Terms & Conditions + BOQ Cutting Sheet.

### 5.3 Orders
- **List:** order no, customer, project, total SQM, amount, delivery date (with overdue flag), status (pending/in_production/completed/cancelled), batch count.
- **Detail:** full BOQ snapshot (same spec + size tables as quotation), financial summary, inline-editable delivery date/notes, status transitions (Start Production / Mark Completed / Cancel), linked production batches table.

### 5.4 Production Batches
- **List:** batch no, order, customer, planned/completed qty, status (draft/in_progress/qc_pending/qc_passed/qc_failed/completed/dispatched), QC badge.
- **Create:** pick a pending/in-production order (shows order summary), planned quantity, notes.
- **Detail:** the shop-floor screen —
  - Info grid + **overall progress bar** (X/N stages, %).
  - **Vertical stage timeline**: each stage (Raw Material Inspection → Sheet Cutting → Foam Injection → Curing → Quality Check → Packaging) as a dot+connector. In-progress stage **pulses** and shows a **live elapsed timer**; completed stages show duration + notes. Per-stage Start / Mark-Done buttons with optional notes. Locked stages show "complete previous first".
  - **QC inspection** (when qc_pending): 7-item checklist with pass/fail toggles, overall Pass/Fail verdict, inspector notes, then Approve.

### 5.5 Quality Control (dashboard)
- Stats bar: Total Inspections, Passed, Failed, **Pass Rate %** (animated bar, green/amber/red).
- Table of all QC records with pass/fail badge, inspector, approver, notes, "View Batch" link. Date-range filter.

### 5.6 Stock (tabbed)
Three tabs: **Coils**, **Chemicals**, **Transaction Log**, plus an **Alerts** tab.
- **Coil / Chemical cards:** one card per item with a **color-coded stock-level bar** (green/amber/red vs reorder level), current qty, reorder threshold. Click a card → **inline action drawer** with tabs: Add / Remove / Adjust / History.
- Chemicals additionally track **batch number + expiry date** with expiring/expired badges, and a "+ New Chemical" modal.
- **Transaction Log:** filterable audit trail (kind, in/out/adjustment, date range, 7/30/90-day quick chips) with color-coded movement badges and signed quantities.

### 5.7 Dispatches
- **List:** dispatch no, batch, dates (overdue flag), item count, total value, tracking, status (pending/delivered/cancelled).
- **Create:** pick a QC-passed/completed batch (shows summary), delivery address (auto-filled from customer), expected date, tracking no, "auto-allocate stock" toggle.
- **Detail:** info grid (with allocation status), inline-editable delivery fields, items table, stock allocations table, actions (Allocate Stock / Mark Delivered / Cancel), **Challan PDF** (open + download).
- **Challan PDF:** 1-page GST-style delivery challan with consignee, item specs, signatures.

### 5.8 Invoices
- **List:** invoice no, customer, dates (overdue flag), total, **balance due**, status (draft/sent/accepted/paid/cancelled).
- **Create:** source toggle (From Dispatch / From Order), source picker, invoice/due dates, notes, terms.
- **Detail:** info grid with payment summary, draft inline-edit, items table with **tax breakdown**, totals (subtotal/tax/total/paid/balance), payment history, lifecycle actions (Send/Accept/Mark Paid/Duplicate/Cancel), **PDF**, and **+ Record Payment** (modal: amount with "Full" quick-fill, method bank/cheque/UPI/cash, reference).

### 5.9 Receivables (AR aging)
- **6 aging summary cards**: Total Receivable + Current / 1–30 / 31–60 / 61–90 / 90+ days — color-graded amber→dark-red, **clickable to filter the table**.
- Table: invoice, customer, dates, total, paid, **balance**, an **age pill** (e.g. "44d"), and a per-row **"Remind" button**. Rows tinted by severity. "As of" date picker.

### 5.10 Reports
- **5 headline KPIs** (Net Sales, GST Collected, Gross Revenue, Cash Collected, Avg Invoice).
- **Monthly revenue trend** — dual-bar chart (invoiced vs collected) per month.
- **Top Customers** table (invoiced + outstanding).
- **Panel Type Mix** — horizontal value-share bars (SQM + value + %).
- Date-range period picker driving everything.

### 5.11 Settings (admin)
- **Company Settings:** logo upload + preview, name, brand colors, address, **state code & GSTIN** (drives GST type), PAN, financial-year-start month, e-invoice/TCS toggles, bank details, signatory, document number prefixes.
- **Master Data (tabbed):** Panel Types (code, name, category, HSN, base price), Accessories (code, name, unit, HSN, rate), Production Stages (sequence, name) — each with table + create/edit modal + deactivate.
- **Users & Roles:** user table (name, email, role badge, admin badge, active), create/edit modal (role dropdown, admin/active toggles), reset-password modal.

---

## 6. Core entities (data the UI displays)

- **Customer** — name, GSTIN, state/state_code (drives GST), address, contact, type, outstanding balance.
- **Quotation** — no, status, project, quality grade, panel rows + size rows, discount/tax/transport/advance, totals, revisions.
- **Order** — immutable snapshot of an accepted quotation + delivery date + production batches.
- **Production Batch** — order link, planned/completed qty, status, stage logs, QC record.
- **Production Stage** — name + sequence (the shop-floor steps).
- **Dispatch** — batch link, items, delivery info, stock allocations.
- **Invoice** — dispatch/order link, items with tax, totals, payments, due date.
- **Payment** — invoice link, amount, method, reference, date.
- **Coil Stock** — keyed by panel type, qty, reorder level.
- **Chemical Stock** — name, category, qty, unit, reorder level, batch, expiry.
- **Company** — profile, branding, GST, bank, prefixes.
- **User / Role** — auth + permissions.

---

## 7. Key UX patterns to preserve

1. **List → Detail → Create(modal)** for every module.
2. **Status pills** everywhere with consistent color semantics.
3. **Overdue / low-stock / expiry** always surfaced as colored flags.
4. **Inline action drawers** (stock) and **modals** (create/payment/reset) rather than full page reloads.
5. **Live feedback**: success/error banners after every action; auto-refresh where state changes (batch stages).
6. **Indian currency + SQM** formatting throughout.
7. **Cross-module navigation**: detail screens link to related records (order→batch→dispatch→invoice).

---

## 8. What to design

The backend and all individual feature views are functional but visually basic (plain tables/cards, inline styles). The design deliverables needed:

1. **App shell** — sidebar/top-nav, top bar with logo + user, the overall layout frame that hosts every module.
2. **Design system** — refined color palette, typography scale, spacing, button styles, form controls, badges, cards, tables, modals, empty/loading/error states.
3. **Module screens** — restyle the ~25 screens above into a cohesive, modern, responsive look (desktop-first; this is an internal back-office tool used on laptops/desktops, occasionally tablets).
4. **Dashboard & Reports** — these are the most visual; charts, KPI cards, aging bars deserve special attention.
5. **The BOQ builder** (quotation create) — the most complex form; needs careful layout for the repeatable panel rows + size sub-tables.

**Tech context for the designer:** Vue 3 (Composition API, `<script setup>`), plain CSS (scoped), axios. No component library currently — a designer may propose Tailwind or a component kit. Mobile is secondary; optimize for desktop data density.

---

*Generated as a design brief for the PUF Panel ERP. The system is feature-complete on the backend across Quotation → Order → Production → QC → Stock → Dispatch → Invoice → Payment → Reports → Settings.*
