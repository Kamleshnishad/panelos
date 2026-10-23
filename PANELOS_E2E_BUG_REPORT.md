# PanelOS — End-to-End Test Bug Report

**Date:** 2026-10-23
**Environment:** production, `https://pufpanel.digitalwebweaver.com` — tenant **Signature PUF Panel** (company_id 6), logged in as Company Admin (user 12)
**Method:** Drove the full spine from SYSTEM.md §23 (lead → customer → BOQ → quotation → order → run → stages → QC → dispatch → invoice → payments → order complete), mostly via `/api` with the browser's bearer token, plus UI checks. Compared every result with SYSTEM.md §21 formulas.
**Reference:** `SYSTEM.md` (section numbers below refer to it)

> **For Claude Code:** fix items in priority order. Each item has repro, evidence, where to look, the expected behaviour, and acceptance criteria. Don't trust SYSTEM.md where it disagrees with the code; several doc errors are listed at the end. Add a feature/regression test for each fix.

---

## Summary

| # | Severity | Area | One-line |
|---|---|---|---|
| 1 | **Critical** | Invoicing / Tax | Invoices always carry ₹0 GST; there is no API or UI to create the `TaxConfiguration` that `TaxService` reads |
| 2 | **Critical** | Dispatch / Invoicing | Accessories + installation never reach dispatch or invoice, so that revenue is lost |
| 3 | **Critical** | Production | Starting a standalone batch never moves the order to `in_production`; the planner re-offers already-produced orders, which risks double production and double stock issue |
| 4 | **High** | Customers | `POST /api/customers` and `PUT /api/customers/{id}` return 500 with an empty body; the UI New Customer form is broken too |
| 5 | **High** | GST state logic | State-code format is inconsistent (`"24"` vs `"GJ"`), so intra- and inter-state GST will be wrong |
| 6 | Medium | Invoicing | Invoice line = weighted-avg rate × qty, so paise drift; dispatch qty rounding overbills |
| 7 | Medium | Pricing | Suggested rate returns ₹160/m²: `base_price=0` gets no fallback and skin premiums aren't applied |
| 8 | Medium | Production | Cutting schedule overstates material about 6× (known gap §24.12, now quantified) |
| 9 | Medium | Orders | Duplicate create-order returns 500 instead of the 400 domain message |
| 10 | Medium | Quotations | An accepted quotation that already has an order can still be revised |
| 11 | Low | Misc | `gst-breakdown` 404, GST config `state_name: "Unknown"`, float `remaining_due`, lead `source` case-sensitive, generic validation message |

**The system's own reconciliation report confirms the impact:** `GET /api/reports/reconciliation` shows `revenue_leak: 128725.88` with both orders flagged `under_invoiced`.

| Order | Ordered | Invoiced | Gap |
|---|---|---|---|
| ORD-2027-001 (real) | 4,79,406.00 | 3,77,100.00 | 1,02,306.00 |
| ORD-2027-002 (test) | 1,40,420.00 | 1,14,000.12 | 26,419.88 |

---

## 1. CRITICAL — Invoices always have zero GST; TaxConfiguration cannot be created

**Repro**
1. `POST /api/gst/register` `{gstin:"24AAFFU9050M1ZS", state_code:"24", registration_type:"regular"}` → 201, creates a `GstConfiguration` (id 1, `is_active: true`).
2. Create an invoice: `POST /api/invoices/from-dispatch {dispatch_id: 5}`.

**Actual:** INV-2026-000002 has `subtotal 114000.12, tax_amount 0.00, total 114000.12`, and every item has `tax_rate 0.00`. The existing real invoice INV-2026-000001 also has `tax 0.00` on ₹3,77,100. The MIS report shows GST liability `cgst 0, sgst 0, igst 0`.

**Root cause (from SYSTEM.md §17.2 and the behaviour):** `TaxService::applyTaxToInvoice()` reads the active **`TaxConfiguration`** (`tax_type`, `default_tax_rate`). `/gst/register` writes a **`GstConfiguration`**, which is a different model. Nothing in the app creates a `TaxConfiguration`:
- These GETs all return 404: `/tax-configurations`, `/settings/tax-configuration`, `/gst/tax`, `/tax`, `/taxes`, `/company/tax`.
- **Settings → Master Data** shows only *Panel Types / Accessories / Production Stages*. The `GstConfigManager` and `TaxConfiguration` tabs described in SYSTEM.md §6 aren't rendered.
- `/gst/register` ignores the doc's `tax_type` / `default_tax_rate` fields.

**Where to look:** `TaxService::applyTaxToInvoice`, `GstController`, `routes/api.php` (gst group), `MasterDataManager.vue`, `TaxConfiguration.vue`, `GstConfigManager.vue`, models `TaxConfiguration` and `GstConfiguration`.

**Fix (recommended)**
- Expose CRUD for `TaxConfiguration` (or have `/gst/register` create/update one) and mount the Tax/GST tabs in `MasterDataManager.vue`.
- **Safer default:** when no active `TaxConfiguration` exists, fall back to 18% exclusive, the same rate the quotation uses, or block invoice creation with a clear 422 error. Never silently issue a ₹0-tax invoice.
- Consider auto-provisioning an 18% exclusive `TaxConfiguration` in `TenantProvisioningService::provision()`.
- One-off data fix: INV-2026-000001 (sent, ₹1.5L paid) needs a business decision (credit note + reissue). **Do not mutate it automatically.**

**Acceptance**
- A fresh tenant can set GST rate and type from the UI.
- Invoice from dispatch for a same-state customer → CGST = SGST = 9% of taxable; for a different-state customer → IGST 18%.
- Invoice `total_amount = subtotal + tax_amount`.
- With no config, invoice creation either uses 18% or fails loudly. It never produces 0.

---

## 2. CRITICAL — Accessories / installation never invoiced

**Repro:** Quotation SCP-2027-002 has one panel row (₹1,14,000) plus an installation row of ₹5,000 (`installation_amount 5000`, quotation subtotal 1,19,000). Accept → order → produce → dispatch → invoice.

**Actual:** Dispatch DISP-2026-000002 has one item (78 m² × 1461.54). The invoice subtotal is 1,14,000.12, so the ₹5,000 installation is missing. On real order ORD-2027-001, the order subtotal is 4,06,276.50 but invoice INV-2026-000001 subtotal is 3,77,100, so about ₹29,250 of accessories/installation went unbilled.

**Cause:** `DispatchService` copies only `order_items` (panels) into `dispatch_items` (SYSTEM.md §16.1), and `InvoiceService` (from-dispatch) copies only dispatch items. Order-level accessories / `installation_amount` / transport have no path to an invoice.

**Where to look:** `DispatchService::create…`, `InvoiceService::createFromDispatch`, `InvoiceService::createFromOrder`, `OrderService::createFromQuotation`. Check whether accessories are snapshotted onto the order at all; if they aren't, snapshot them.

**Fix:** Snapshot accessories, installation and transport onto the order when it's created. When invoicing, add non-panel lines to the invoice. Recommended: add them to the **first** invoice for the order, or the one that completes it, and track `accessories_invoiced` so they're never billed twice. Use their HSN/SAC and apply GST (transport per current quotation rule, §21.2).

**Acceptance:** For SCP-2027-002-style data, invoice subtotal = 1,19,000 and total = 1,40,420 (with 18% GST). `/reports/reconciliation` shows `invoice_gap 0` for a fully dispatched single-batch order.

---

## 3. CRITICAL — Standalone batch doesn't move order to `in_production` (risk of double production)

**Evidence (real data):** ORD-2027-001 → BATCH-2026-000001 (`run_id null`) is `dispatched`, DISP-2026-000001 is `delivered`, and INV-2026-000001 was issued. Yet `GET /api/orders/4` → `status: "pending"`.
`GET /api/production/planning` still lists ORD-2027-001 in two groups (312.93 m² ROOF + 51 m² WALL) as work to be produced. Creating a run from those groups would produce it again **and issue raw material again**.

**Cause:** SYSTEM.md §11.2 says only `ProductionRunService::createFromOrders()` sets `in_production`. The standalone path (`POST /orders/{id}/batches` → `POST /batches/{id}/start`, which also issues material via `issueForBatch`) never updates the order.

**Where to look:** `ProductionBatchService::createFromOrder` / `startProduction`, `ProductionRunService::createFromOrders`, `ProductionPlanningService` (its pending-order query).

**Fix**
- Move the order `pending → in_production` when a batch is created or started for it, whether or not it belongs to a run. Put this in one shared place (e.g. `OrderService::markInProduction()`).
- Make `ProductionPlanningService` and `ProductionRunService::createFromOrders` exclude orders that already have any non-cancelled batch, as a second safeguard.
- Data fix: set ORD-2027-001 to `in_production`. It can't be `completed` while it's under-invoiced; let the business decide.

**Acceptance:** After creating a standalone batch, the order is `in_production` and absent from `/production/planning`. `POST /production/runs` with that order id returns "No eligible pending orders".

---

## 4. HIGH — Customer create/update broken (API 500, UI dead)

**API repro**
```
POST /api/customers {"name":"TEST- X","code":"TSTE001","type":"wholesale","state_code":"24"}   → 500, empty body
PUT  /api/customers/8 {"name":"TEST- Claude E2E Cold Store"}                                   → 500, empty body
```
Every variant returns 500 (only name, + state_code, + type, + credit_limit). The body is **empty**, not the `SERVER_ERROR` envelope with `reference` from §2, so the error probably happens outside the exception handler (fatal error, a type error in a FormRequest or observer, or a response serialisation failure). Customer *creation via the service* works: `POST /leads/{id}/convert` created customer 8. So the problem is in `CustomerController` or its request/validation layer, not the model.

**UI repro:** BOQ Register → Add BOQ → Customer "+ New" → fill the form → **Add Customer**. Nothing happens and no network request is sent. The console repeats on every keystroke:
```
TypeError: a is not a function
    at FormInput-B9zWaj9W.js:2:4973
```
The minified code is a validation composable doing `watch(() => a ? a() : null, …)`. The caller passes a non-function (probably the reactive form object instead of a getter `() => form`) as the "values" argument, so validation never runs and submit stays blocked.

**Also:**
- The New Customer modal has **no credit limit, payment terms or type-dependent fields** except Customer Type. `credit_limit` can't be set anywhere, so the credit check (§8) is untestable from the UI.
- The **Customers** screen has no "Add customer" button; the BOQ form's "+ New" is the only entry point.
- Lead convert drops the lead's `state`. Customer 8 got `state_code: ""` even though the lead had `state: "Gujarat"`.

**Where to look:** `CustomerController::store/update` and any `StoreCustomerRequest`/`UpdateCustomerRequest`, the Customer observers / `Auditable`, `storage/logs/laravel.log` around **2026-10-23 09:49–09:52 UTC**, `CustomerEditModal.vue`, the form-validation composable used by `FormInput`, `LeadService::ensureCustomer`.

**Acceptance:** Create and update return 201/200 with the standard envelope. The UI modal creates a customer and selects it in the BOQ. Credit limit and payment terms are editable. Lead convert carries state and state_code over.

---

## 5. HIGH — State-code format inconsistency breaks intra/inter-state GST

**Evidence**
- Company: `state_code: "24"`, GSTIN `24AAFF…`.
- Existing customer 7: `state_code: "08"` (numeric).
- The UI New Customer placeholder says **`e.g. GJ`**, and SYSTEM.md examples use `MH`.
- Quotations derive the company state from the GSTIN prefix and map it (`27 → MH`) before comparing with `customer.state_code` (§21.2). Invoices compare `company.state_code` (`"24"`) directly with `customer.state_code` (§17.2).

**Impact:** For a Gujarat customer entered as `GJ`, the quotation says intra-state but the invoice says inter-state (`"24" ≠ "GJ"`), giving IGST instead of CGST+SGST. With an empty state_code, quotations treat it as intra-state while invoices treat it as inter-state. This is hidden today only because of bug #1.

**Fix:** Pick **one canonical format**: the 2-digit GST state code (`"24"`), which is what GSTIN, e-invoice and e-way bill use. Then:
- Normalise on save in a Customer mutator. Accept `GJ`, `Gujarat`, `24`, `"7"`, and derive from the customer's GSTIN when present (there's already a `derived_state_code` attribute; use it).
- Use **one** helper (e.g. `GstState::isInterState($company, $customer)`) in both `QuotationService` and `TaxService`.
- Change the UI field to a state dropdown.
- Migrate existing rows.

**Acceptance:** The quotation and invoice for the same customer always agree on `is_inter_state`. Unit tests cover `GJ`, `24`, `Gujarat`, an empty value, and a GSTIN-derived value.

---

## 6. MEDIUM — Rounding errors on invoice lines

- **Weighted rate drift:** the order item is 78 m², amount ₹1,14,000.00 (60 × 1450 + 18 × 1500). Dispatch/invoice use `rate_per_sqm 1461.54 × 78 = 114000.12`, overbilling ₹0.12. With more size rows this grows.
- **Quantity rounding:** on ORD-2027-001 the item `total_sqm` is 312.93, but the dispatch/invoice qty is `313.00` × 1050 = 3,28,650 instead of 3,28,576.50, overbilling **₹73.50**.

**Fix:** Copy the **amount** from the order item (or rebuild from size rows) instead of recomputing rate × qty, and store quantities with 2–4 decimals (check the `dispatch_items.quantity` / `invoice_items.quantity` column scale). Alternatively, invoice per size row.

**Acceptance:** The invoice line amount equals the order item amount to the paisa.

---

## 7. MEDIUM — Suggested rate is ₹160/m²

`POST /api/quotations/suggested-rate {panel_type_id:7, quality_grade:"High", customer_id:8, row_data:{thickness:50, top_skin_thickness:0.5, bottom_skin_thickness:0.5, density_type:"PUF", top_surface:"PLAIN"}}` → `{"rate":160}`.

Per §21.1 it should be `850 + 160 + 60 + 30 = 1100` (retail × High). The result contains only the thickness premium, so:
- `base_price` is `0.00` on **all 8** panel types, and the "default 850" fallback only triggers on null. Use `?:` / `> 0`, or make base_price required > 0 in `PanelTypeMaster`.
- Skin premiums aren't applied. The lookup key is probably a float vs string mismatch (`0.5` vs `"0.50"`). Normalise with `number_format($x, 2)`.

---

## 8. MEDIUM — Cutting schedule wrong (quantified)

`GET /api/batches/5/cutting-schedule/json` for 30 panels (20 × 3000 mm + 10 × 1800 mm = 78 m²) returns `total_items: 78`, `material_length 6000` each, `total_material_length 468000` mm (468 m). Real requirement is about 78 m (and the 1800 mm panels should be DL-paired). The service uses `order_items.quantity` (= m²) as a piece count and `panelType->length` (6000) instead of the size rows.

**Fix:** Rewrite `CuttingScheduleService` to iterate `order_item_sizes` (length_mm, nos), applying the DL rule (`length < 2000` → pair). Reuse the logic behind the BOQ sheet (`_boq_sheet_body.blade.php`). Also fix `batch.planned_quantity` semantics (§24.17).

---

## 9. MEDIUM — Duplicate create-order returns 500

A second `POST /api/quotations/6/create-order` → **500 "Failed to create order"**. SYSTEM.md §22 expects 400 "An order already exists for this quotation." No duplicate order was created, so the guard works, but the domain exception is being caught and re-thrown as a generic 500. Return a 400/409 with the real message. Check the controller's `catch (\Exception)`.

---

## 10. MEDIUM — Quotation with an order can still be revised

Real data: SCP-2027-001 is `revised` (superseded by draft SCP-2027-001-v2), but ORD-2027-001 was created from SCP-2027-001. `revise()` allows `accepted` even when an order exists (§10.3). That leaves an open draft v2 that could be accepted and ordered, producing a second order for the same job.

**Fix:** Block `revise` (and `reject`/`expire`) when `quotation->order()->exists()`. Return 400 "This quotation already has an order."

---

## 11. LOW — Smaller issues

- `GET /api/invoices/{id}/gst-breakdown` → 404 "GST breakdown not found" even right after creation, probably because `tax_calculations` has no row when tax is 0. Return a zero breakdown instead of 404.
- `GstConfiguration.state_name` saved as `"Unknown"` for state code `24`, because the state map is keyed by letter codes. It's the same root as #5.
- `GET /invoices/{id}/payment-status` returns `remaining_due: 64000.119999999995`. Round to 2 dp (or use decimal strings).
- `POST /api/leads` `source` is case-sensitive: `website` → 422 "selected source is invalid", `Website` → 201. The doc's example uses lowercase. Normalise or accept both.
- Validation error `message` is the generic `"Validation failed"` on leads, but §2 says it should be the first field error (customers/gst do return the first field error). Make this consistent.
- BOQ/quotation/challan/invoice PDFs are each about **1.05–1.1 MB**, probably an unoptimised embedded logo. Downscale or compress the logo before embedding.

---

## SYSTEM.md corrections (doc is wrong vs live behaviour)

- §6 / §23: `/gst/register` requires `gstin`, `state_code`, `registration_type`. It does **not** accept `gst_number`, `tax_type`, `default_tax_rate` and doesn't create a `TaxConfiguration`.
- §6: Master Data UI does not include `GstConfigManager` / `TaxConfiguration` tabs.
- §23: lead `source` must be `Website` (capitalised).
- `state_code` examples (`MH`) conflict with live data (`24`, `08`). Document the canonical format chosen in fix #5.

---

## What passed (no action needed)

| Check | Result |
|---|---|
| BOQ `total_sqm` (width fixed 1000 mm) | 78.0000 ✓ |
| BOQ hidden from `/quotations`, shown with `?status=boq` | ✓ |
| Lead → `quoted`, `quotation_id` linked on BOQ save | ✓ |
| Send blocked while rates are 0 | 400 with correct message ✓ |
| `/rates` → item weighted avg 1461.54, amount 1,14,000 | ✓ |
| Quotation totals: subtotal 1,19,000, CGST/SGST 10,710 each, total 1,40,420 | ✓ (§21.2) |
| Order snapshot equals quotation items and sizes | ✓ |
| Credit status with limit 0 → no limit | ✓ |
| Run material requirement (coil 630.67, polyol 81.90, iso 90.09, film 79.56 kg/m²) | matches §21.3 exactly ✓ |
| Run start deducts exactly the required quantities; second start rejected; no double deduction | ✓ |
| Order → `in_production` on run creation | ✓ |
| Stage sequence enforcement, duplicate stage rejected | ✓ |
| Complete with actual > issued deducts the difference (9.33 kg); wastage 4.52% | ✓ |
| Dispatch blocked until QC pass is approved; one dispatch per batch | ✓ |
| Dispatch complete → batch `dispatched` | ✓ |
| One invoice per dispatch | ✓ |
| Payment on draft blocked; over-payment blocked; mark-paid blocked while outstanding | ✓ |
| Full payment auto-flips invoice to `paid` | ✓ |
| Order → `completed` once all batches dispatched | ✓ |

**Not tested:** stock return when `actual < issued` at run completion (actuals payload didn't match the Polyol row); Stripe/Razorpay; notifications; e-invoice; procurement/GRN; multi-batch orders; credit-limit block (customer credit_limit can't be set, see #4).

---

## Test records created in the live tenant (company 6)

| Type | Identifier |
|---|---|
| Lead | id 8, "TEST- Claude Lead" (status `quoted`) |
| Customer | id 8, code `TEST678`, "TEST- Claude E2E Cold Store" (type retail, state_code empty) |
| Quotation | id 6, **SCP-2027-002** (accepted) |
| Order | id 5, **ORD-2027-002** (completed) |
| Production run | id 1, **RUN-2026-0001** (completed) |
| Batch | id 5, **BATCH-2026-000002** (dispatched) |
| QC | id 5 (pass, approved) |
| Dispatch | id 5, **DISP-2026-000002** (delivered) |
| Invoice | id 4, **INV-2026-000002** (paid, ₹1,14,000.12 — zero tax) |
| Payments | TEST-UTR-1 ₹50,000; TEST-UTR-2 ₹64,000.12 |
| GST configuration | id 1, GSTIN 24AAFFU9050M1ZS, regular, active (**intentionally kept**) |
| Stock consumed | Coil (panel type 7) 640.00 kg · Polyol 81.90 kg · Isocyanate 90.09 kg · Protective Film 79.56 m² |

These are soft-deletable test rows. Remove or keep them on the business owner's decision; stock consumption would need manual `adjust` entries to reverse.
