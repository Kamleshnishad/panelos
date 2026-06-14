# Handoff: PUF Panel ERP — App Shell & Module Screens

## Overview
A unified app shell + visual redesign for the **PUF/PIR insulated-panel ERP** (Signature PUF, Vadodara). It covers the order-to-cash pipeline: Dashboard, Quotations (list → detail → BOQ builder), Production batch detail, Receivables (AR aging), and Reports. The goal is a cohesive, modern, dense back-office UI to replace the current plain inline-styled views.

## About the Design Files
The files in this bundle are **design references created in HTML** — prototypes showing the intended look and behavior, **not production code to copy verbatim**. The task is to **recreate these designs in the existing target codebase** (per the brief: **Vue 3 Composition API, `<script setup>`, scoped plain CSS, axios** — no component library yet) using its established patterns. Lift the exact tokens, layouts, and copy from here; wire them to the real Laravel API. The backend and individual feature views already exist and are functional — this is a presentation/shell layer.

Files in this folder:
- `PUF Panel ERP — standalone.html` — fully self-contained, open in any browser to see the live, interactive design (BOQ live totals, QC toggles, batch timer, chart tooltips, receivables filtering all work).
- `PUF Panel ERP.dc.html` — the source. It's authored as a "Design Component": markup lives between `<x-dc>…</x-dc>`; the `class Component` block holds all data + interaction logic (`renderVals()` returns the values consumed by the `{{ }}` holes). Read this for exact structure, sample data, and computation logic (GST, SQM, aging buckets). **Ignore the `support.js` runtime** — it's just the prototyping harness; do not port it.

## Fidelity
**High-fidelity (hifi).** Final colors, typography, spacing, and interactions. Recreate pixel-accurately with the codebase's own primitives. All hex values, sizes, and copy below are authoritative.

## Design Tokens

### Color
| Token | Hex | Use |
|---|---|---|
| Primary / cobalt | `#2B50E0` | primary buttons, active nav, links, KPI accents |
| Primary hover | `#2140C0` | button hover |
| Primary tint | `#EEF1FE` | selected chips, light accents |
| Primary tint border | `#D6DEFB` / `#E2E8FB` | tinted card borders |
| Ink (text) | `#15181E` | headings, primary values, dark sidebar bg |
| Body / muted | `#667085` | secondary text |
| Subtle | `#98A2B3` | labels, captions |
| App background | `#F5F6F8` | content area |
| Surface | `#FFFFFF` | cards |
| Border | `#E4E7EC` (rows `#F2F4F7`, headers `#EAECF0`) | dividers |
| Sidebar bg | `#15181E`; item `#C2C7D0`; hover `#23272F`; active bg `#1C2230`; active accent bar `#2B50E0`; section label `#5C636E` | left nav |
| Success (green) | `#14894E`, tint `#E4F5EC` | paid, in, done, advance |
| Danger (red) | `#D6322A`, tint `#FCEBEA` | overdue, danger, out, rejected |
| Warning (amber) | `#B5740A`/`#C77900`, tint `#FBF0DA` | warnings, pending, DL flag |
| Purple | `#6B3FC9`, tint `#EFEAFB` | accepted, orders, chemical |
| Teal | `#0E8C8C`, tint `#EAF6F6` | SQM, batches |

**AR aging severity ramp (graded amber→red):** Current `#14894E` · 1–30 `#7BA528` · 31–60 `#C9A227` · 61–90 `#D9822B` · 90+ `#C62828`. Each has a matching pale row tint (e.g. 90+ row `#FDF4F3`).

### Typography
- **UI / body:** `Public Sans` (Google Fonts; weights 400/500/600/700/800).
- **Numbers, doc IDs, metrics:** `JetBrains Mono` (weights 400–700), with `font-variant-numeric: tabular-nums` feel. Always mono for: quotation/order/invoice numbers (`SCP-2026-014`), currency, SQM, counts, timers, percentages.
- Scale (px): page H1 17/800; card H2 14.5/800; section label 10–11/700 uppercase `.4–.5px` tracking; body 12.5–13.5; KPI value 19–23 mono/700; table cell 12–13. Headings use `letter-spacing:-.2 to -.6px`.

### Spacing / Radius / Shadow
- Card radius `13–14px`; inputs/buttons `8–9px`; pills `20px`; small chips `5–8px`.
- Card padding `15–22px`; grid gaps `12–18px`.
- Inputs: height `36–38px`, `1px #E4E7EC` border, focus `border-color:#2B50E0; box-shadow:0 0 0 3px rgba(43,80,224,.10–.12)`.
- Card hover lift: `box-shadow:0 6px 18px rgba(16,24,40,.09); transform:translateY(-1px)`.
- Summary card shadow: `0 4px 18px rgba(16,24,40,.06)`.
- Currency: Indian digit grouping `₹2,31,320` + short forms `K / L (lakh) / Cr (crore)` — see `inr()`, `grp()`, `short()` in source.

## App Shell
- **Layout:** fixed 100vh flex. Left sidebar `236px` (dark) + main column (top bar `57px` + scrollable content). Content padding `22px 24px 56px`, `max-width:1520px`, centered. Desktop-first: content has `min-width:1080px` (horizontal scroll only on very narrow windows).
- **Sidebar:** brand (cobalt rounded-square logo + "Signature PUF / PANEL ERP"), grouped nav (MAIN / INVENTORY / SALES & FINANCE / SETTINGS), user footer (avatar + name + role + logout). Nav items: 18px icon + label + optional mono count badge; active item = `#1C2230` bg, white text, 3px cobalt left accent bar, lighter icon `#7CA0FF`. Receivables badge is red-toned (overdue).
- **Top bar:** breadcrumb + screen H1 (left); search field (`#F2F4F7` fill, ⌘K hint), bell with red dot, divider, primary "New Quote" button (right).
- **Icons:** simple 1.9px-stroke line icons, 24×24 viewBox (see `ic()` map in source — dashboard, quote, orders, production, quality, stock, dispatch, invoice, receivables, reports, etc.). Replace with the codebase's icon set, matching weight.
- **Status pills (everywhere):** `2px 8px`, radius 20, 10.5px/700, capitalized, leading 5px dot, bg = pale tint / fg = accent. Map in `pill()`: draft, sent, accepted, rejected, revised, expired, pending, in_production, completed, overdue, delivered, qc_passed, qc_pending, in_progress, paid.

## Screens / Views

### 1. Dashboard
- **6 KPI cards** (grid `repeat(6,minmax(0,1fr))`, gap 13): icon chip (tinted) + mono value + label + colored delta line; whole card clickable → its module. KPIs: Open Quotations(14)→quotations, Active Orders(9)→orders, In Production(6)→production, Pending Dispatch(4)→dispatches, Outstanding(₹48.73L)→receivables, Collected FY(₹2.15Cr)→reports.
- **Two-column body** `minmax(0,1fr) 366px`:
  - Left: **Order-to-Cash Pipeline** (4 stage cards Quotations→Orders→Batches→Invoices, each: dot+label, big mono total, sub-count rows; arrows between; clickable). Then **Recent Activity** feed (icon chip, mono ref, status pill, description, amount, relative time).
  - Right: **Alerts** (severity-colored rows: danger/warning icon, title, meta, chevron — overdue invoices, low coil/chemical stock, expiring chemicals, expiring quotes). Then **Receivables Aging** (single stacked horizontal bar Current→90+, with legend rows: swatch, label, mono amount).

### 2. Quotations — List
- Toolbar: status filter chips (All/Draft/Sent/Accepted/Revised/Rejected/Expired) each with a count badge; active chip = cobalt; right side count + "New BOQ" button.
- Table (horizontal-scroll wrapper, `min-width:840px`), columns: Quotation no (mono cobalt) · Customer + project (2 lines) · SQM (mono) · Amount (mono) · Status pill · Validity (with "Nd left"/"Expired" sub-flag in amber/grey when ≤7 days or past) · chevron. Row click → detail.

### 3. Quotation Detail
- Back link → list. Header card: mono quote no + status pill, customer, project · location, meta chips (Valid till, grade, SQM teal). Actions (right): **Mark Accepted** (green), **Create Order** (cobalt), Revise, PDF.
- Two-column `minmax(0,1fr) 340px`:
  - Left "Bill of Quantities": per-panel cards (PANEL n badge, title, mono spec line, top/bottom skin lines, flag chips) + read-only size table (Length×1000 · Nos · SQM · Amount, DL flag) + panel subtotal. Then Accessories table.
  - Right: **Financial Summary** (subtotal, discount, taxable, CGST/SGST, transport, dark Grand Total band, advance/balance), **Revision History** (vertical timeline, current = cobalt dot), **Customer** card (name, contact, GSTIN).

### 4. BOQ Builder (Quotation Create) — most complex form
- Two-column `minmax(0,1fr) 344px`; right summary is `position:sticky; top:0`.
- **Quotation Details** card: 6-col grid — Customer (select, drives GST), Quality grade, Project (span 4), Location (span 2), Validity days, Discount %, Advance %, Transport ₹ (numeric, mono right-aligned). GSTIN + GST-type note strip below (auto: state code `24`=Gujarat → intra CGST+SGST; else inter IGST).
- **Panel cards** (repeatable): header = PANEL n badge + editable type + live SQM chip + delete. Spec grid (Thickness/Density/HSN/Rate). Top Skin + Bottom Skin sub-cards (material / color / surface Plain|Ribbed select). Guard Film & Cello Tape toggle chips. **Size sub-table**: Length (editable, mono) · Width fixed 1000 · Nos (editable) · **SQM auto** · **Amount auto** · delete; "Add size" row; panel subtotal. **DL flag** (⚠ amber) when length < 2000mm. "Add panel type" dashed button.
- **Accessories** card (standard / door-window / installation rows with type pills).
- **Sticky Quotation Summary**: total SQM, Subtotal, Discount (%), Taxable, CGST 9%+SGST 9% **or** IGST 18% (conditional), Transport, Round-off, dark **Grand Total** band, Advance (%), Balance; actions (Save & Send / Save Draft / Cancel) + info note.
- **Live math (see `renderVals` / `updSize` / totals block):** `SQM = L/1000 × W/1000 × Nos`; `amount = SQM × rate`; `taxable = subtotal − discount`; intra → CGST=SGST=9% else IGST=18%; `grand = round(taxable+tax+transport)`; round-off shown; `advance = grand × advance%`; `balance = grand − advance`. Every edit recomputes instantly.

### 5. Production Batch Detail (shop-floor)
- Header card: mono batch no + qc_pending pill, order link · customer · project, action buttons; **overall progress bar** ("Stage 4 of 6 complete · 75%", cobalt gradient fill); Planned qty.
- Two-column `minmax(0,1fr) 320px`:
  - Left **Production Stages**: vertical timeline (6 stages: Raw Material Inspection → Sheet Cutting → Foam Injection → Curing → Quality Check → Packaging). Each row: 26px dot (done=green w/ white check; current=cobalt w/ white center dot + **pulse** `@keyframes pufpulse`; locked=grey w/ number) + connector line (green when done) + name + status pill + duration (done) or **live elapsed timer chip** (current, mono, pulsing dot) ; done shows note; locked shows "Complete the previous stage first".
  - **QC inline** at the current Quality Check stage: **7-point checklist** (each: label + Pass/Fail segmented toggle, green/red active), inspector notes, verdict band (PASS/FAIL auto from all-pass) + "Approve & Pass".
  - Right: Batch Details info grid + a live "Quality Check" status card (pulsing dot, elapsed timer).
- **Live timer:** `setInterval` 1s incrementing `batch.elapsed`, formatted `H:MM:SS` (`fmtElapsed`). QC verdict recomputes on each toggle.

### 6. Receivables (AR Aging)
- **6 summary cards** (grid `repeat(6,…)`): Total Receivable (dark card) + Current/1–30/31–60/61–90/90+ (color-graded), each: label, mono amount, invoice count, bottom accent bar. **Clickable → filters the table**; active card = tinted bg + accent border.
- Toolbar: "Showing N invoices · <bucket>", "As of <date>", Export.
- Table (scroll wrapper `min-width:960px`): Invoice (mono) · Customer · Invoiced · Due · Total · Paid (green) · **Balance** (bold) · **Age pill** (mono, bucket-colored, "Current" or "Nd") · **Remind** button. **Rows tinted by severity bucket.**

### 7. Reports
- 5 KPI cards: Net Sales, GST Collected, Gross Revenue, Cash Collected, Avg Invoice (mono value + green delta). Period picker chips (This FY / Last 6 months / Custom) + Export.
- **Monthly Revenue Trend** — grouped bar **SVG chart** (`reportChart()`): y-axis gridlines + ₹L labels, baseline, 8 months × 2 bars (Invoiced cobalt `#3A5DE0` / Collected green `#2E9D5F`), legend; **hover a month** → highlight + dark tooltip (month, Invoiced ₹X.XXL, Collected ₹X.XXL). Drives off `state.repHover` via per-group transparent overlay rects. Recreate with the codebase's chart lib (axes/gridlines/tooltip) or an SVG component.
- Two columns: **Top Customers** table (Customer · Invoiced · Outstanding red) + **Panel Type Mix** (per type: swatch, name, SQM, mono value, %, horizontal % bar).

### Placeholder modules
Orders, Quality Control, Stock, Dispatches, Invoices, Company Settings, Master Data, Users & Roles are wired into nav but render a styled empty-state in the prototype. Build them out using the same List → Detail → Create(modal) pattern, status pills, and tokens above. The brief (`SYSTEM_OVERVIEW.md`, if available) describes each in detail.

## Interactions & Behavior
- Client-side router by `state.screen`; nav + KPIs + pipeline + list rows all set it. In production, use Vue Router with the same groupings.
- BOQ: every numeric input recomputes SQM/amount/totals live; customer change re-detects GST type; add/remove panel & size rows.
- Batch: 1s live timer on the in-progress stage; QC pass/fail toggles flip the verdict.
- Receivables: card click filters table by aging bucket.
- Reports chart: mouseover month → tooltip.
- Patterns to preserve: status pills everywhere; overdue/low-stock/expiry surfaced as colored flags; modals for create/payment/reset, inline drawers for stock; success/error banners after actions; cross-module links (order→batch→dispatch→invoice).

## State Management
Prototype keeps it in one component `state`: `screen`, `qFilter`, `arBucket`, `repHover`, `boq` (customerId, project, location, grade, validityDays, discountPct, advancePct, transport, `panels[]` with `sizes[]`, `accessories[]`), `batch` (elapsed, `stages[]`, `qc[]`, qcNotes). In Vue, split into a Pinia store per module (quotation draft store, batch store) + server data via axios. Computed totals/aging/verdict map directly to `computed()`.

## Assets
- Fonts: Public Sans + JetBrains Mono (Google Fonts).
- No raster images; logo is a CSS/SVG cube placeholder — swap for the real Signature PUF logo (Company Settings has a logo upload). Icons are inline SVG line icons — replace with your icon set.

## Files
- `PUF Panel ERP — standalone.html` — open to view/interact.
- `PUF Panel ERP.dc.html` — annotated source (markup + all data + logic). Treat the `support.js` reference as the prototyping runtime only; do not port it.
