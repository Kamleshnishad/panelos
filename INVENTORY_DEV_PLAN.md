# Inventory / Raw-Material Consumption — Development Plan

Goal: PUF panel ke raw material (coil, polyol, isocyanate, additives, oil/release,
film, tape, packaging) ko system mein laana — purchase → stock → production
auto-consume → wastage → valuation. Built on existing `coil_stocks` /
`chemical_stocks` (additive, low-risk).

## Guiding rules (taaki error na aaye)
1. **Additive first** — purane tables/flows ko tab tak mat chhedo jab tak naya cover na ho.
2. **Reuse `StockService`** (addCoilStock/removeCoilStock/…/createTransaction) — naya deduction usi se.
3. **Ek phase = ek (ya do) git commit** = restore point. Har phase ke baad `npm run build` + `php artisan migrate` + smoke-test green hone par hi aage.
4. **Naye tables mein `softDeletes()` (deleted_at)** zaroori — BaseModel SoftDeletes force karta hai (warna crash).
5. **Migration files ABSOLUTE path se** likho (pichli baar relative path se `backend/backend/` ban gaya tha).
6. **Multi-tenant:** har query `company_id` se scoped.
7. **Double-deduction guard:** coil abhi dispatch pe katta hai; production pe katne se pehle dispatch ka coil-deduction hatao (Phase 2). Ye sabse risky step — alag commit + test.

---

## Phase 0 — Foundation (no behaviour change)  ✅ DONE
> Done: unit_cost on coil/chemical/consumable; `consumable_stocks` + full CRUD (model/service/controller/routes); Consumables tab in StockManager; coil-create endpoint; low_stock_alerts enum extended. Migrate + build + smoke-test green. (coil-create UI button = optional follow-up.)
**DB**
- [ ] `coil_stocks`, `chemical_stocks` mein `unit_cost` decimal(12,2) default 0 (valuation ke liye).
- [ ] Nayi table `consumable_stocks` (id, company_id, name, category[oil/film/tape/packaging/other], unit[litre/m/roll/nos/kg], quantity_in_stock, reorder_level, unit_cost, last_stock_in/out, timestamps, **softDeletes**).
**Backend**
- [ ] `ConsumableStock` model (+ company, transactions morph, isLowStock).
- [ ] `StockService`: addConsumable/removeConsumable/adjustConsumable + create endpoint (coil create bhi add karo — abhi missing hai).
- [ ] `StockController` + routes: `/stock/consumables` (GET/POST/{id}/add/remove/adjust/reorder), `POST /stock/coils` (create).
**Frontend**
- [ ] `ConsumableStock.vue` + `StockManager.vue` mein tab; coil "Add New" button.
**Verify:** migrate + build green; manually ek consumable add/remove; commit `feat: consumables + unit_cost`.

---

## Phase 1 — BOM engine (compute only, NO deduction)  ✅ safe
**DB**
- [ ] `material_settings` (per company): steel_density(7.85), iso_polyol_ratio, foam_overpack_pct, default wastage % (coil/chemical/consumable), film_per_sqm, tape_per_panel, oil_per_shift. (Ya company table mein columns.)
**Backend**
- [ ] `MaterialBomService::requirementForOrder(order)` / `forRun(run)` → returns standard qty per material:
      - coil_top_kg = area × top_skin_thickness × 7.85; coil_bottom_kg similarly
      - foam_kg = area × thickness(m) × density; polyol/iso = foam split by ratio × overpack
      - film = area × film_per_sqm; tape, oil (shift-based)
- [ ] Endpoint `GET /production/runs/{id}/material-requirement` (+ order version) — read-only.
**Frontend**
- [ ] Run detail / Planner group mein "Material Requirement" panel + **availability check** (stock se compare, sirf warning, koi deduction nahi).
**Verify:** numbers ek known example se match (100sqm/50mm/0.5/40 → coil 785kg, foam ~200kg); commit.

---

## Phase 2 — Production auto-consume (CORE, careful)  ⚠ risky
**DB**
- [ ] `production_material_usages` (id, company_id, run_id nullable, batch_id nullable, material_kind[coil/chemical/consumable], stock_id, material_name, unit, standard_qty, issued_qty, actual_qty nullable, wastage_pct, created_by, timestamps, **softDeletes**).
**Backend**
- [ ] `ProductionMaterialService::issueForRun(run)`:
      - BOM se standard nikaalo → ×(1+wastage%) = issue qty
      - **availability check** (short ho toh block, unless `force`)
      - `StockService` se deduct + `production_material_usages` rows banao
- [ ] `ProductionRunService::start()` mein hook → issue on start.
- [ ] **DispatchService se coil-deduction HATAO** (double-count rokne ke liye) — alag commit, dispatch test ke saath.
**Frontend**
- [ ] Run start se pehle requirement + "stock OK / short" dikhe; short pe confirm/force.
- [ ] Run detail mein "Materials issued" list.
**Verify (dev DB, reversible test):** stock note → run start → stock kam hua exactly standard×wastage → usage rows bane → dispatch pe coil dobara na kate. Cleanup. Do separate commits: (a) production consume, (b) dispatch deduction removal.

---

## Phase 3 — Actual vs Standard wastage  ✅ medium
**Backend**
- [ ] Run/batch complete pe operator `actual_qty` daale → `production_material_usages` update → real wastage% = (actual−standard)/standard.
- [ ] Endpoint `GET /reports/material-wastage` (per run / per period / per material).
**Frontend**
- [ ] Run complete modal mein "actual issued" inputs (pre-filled standard se).
- [ ] Wastage report screen (run-wise + summary).
**Verify + commit.**

---

## Phase 4 — Procurement (PO/GRN) + Valuation  ✅ medium
**DB**
- [ ] `suppliers` (basic), `purchase_orders` (po_no, supplier, status, total), `purchase_order_items` (material_kind, stock_id/new-item, qty, rate, amount), `goods_receipts` + items (received_qty, batch_no, expiry, cost). Sab **softDeletes**.
**Backend**
- [ ] `ProcurementService`: PO create (reorder suggestion se bhi), GRN → `StockService` se stock-in at received qty + unit_cost update + batch/expiry.
- [ ] Controller + routes `/procurement/...`.
**Frontend**
- [ ] PO list/create, GRN screen, reorder→PO one-click.
- [ ] **Valuation fix:** stock value = Σ qty×unit_cost (abhi 0 aata hai); stock dashboard + report update.
**Verify + commit.**

---

## Cross-cutting (har phase mein dhyaan)
- Stock Dashboard / Forecasting purane `with('coil'|'chemical')` se na toote (memory: ye already fixed hai — re-check).
- Quotation pricing ko per-panel material cost se link karna = optional later.
- Har naya endpoint: company-scoped + validation + ApiResponse trait.

## Rollback
Har phase ka apna git commit. Kuch bhi toote → `git revert <commit>` ya `git reset --hard <prev>`. DB → migration ka `down()`.
