# UX Change Log — rollback record

> **NOTE (superseded):** The project is now under Git version control
> (https://github.com/Kamleshnishad/panelos). Use `git log` / `git restore` / `git revert`
> for rollback — see the README. The `_ux_rollback_2/` and `_ux_rollback_3/` snapshot
> folders referenced below have been **removed**; this file is kept only as a change history.

**Rollback snapshot:** `_ux_rollback_2/` holds a copy of `frontend/src` and `backend/routes/api.php` taken **before this batch of changes**.

**To roll back everything in this batch:**
```
# from project root
cp -r _ux_rollback_2/frontend_src/. frontend/src/
cp _ux_rollback_2/backend/api.php backend/routes/api.php
cd frontend && npm run build
```
To roll back a single file, copy just that one file back from the snapshot.

Each entry below lists the file, the change, and is grouped by phase so partial rollback is possible.

---

## Batch 3 — Product-range expansion (sell the FULL catalogue)

**Goal:** let the system quote every product the customer lists on umapufpanel.in / signaturepufpanel.com
(PUF/PIR **+ Rockwool/EPS/Glasswool**, Wall/Roof/**Cold Room/Partition/Clean Room/Ceiling/PEB/Architectural**,
**Cam-Lock/Secret-Fix/Standing-Seam** fixings, **RAL** colour codes) — not just PUF/PIR wall-or-roof.

**Snapshot for this batch:** `_ux_rollback_3/` (pre-change copies of every file below).
**Full revert:**
```
# from project root
cp -r _ux_rollback_3/backend/.  backend/
cp    _ux_rollback_3/frontend/src/components/QuotationCreate.vue frontend/src/components/
cd frontend && npm run build
# then roll back DB:
cd ../backend && php artisan migrate:rollback --step=2   # drops the 2 batch-3 migrations
```

### New migrations (additive)
- `backend/database/migrations/2026_06_14_060000_add_product_range_fields_to_quotation_items.php` — adds `application`, `fixing_system`, `top_color_ral`, `bottom_color_ral`; widens `density_type` enum → PUF/PIR/Rockwool/EPS/Glasswool. (down() reverses both.)
- `backend/database/migrations/2026_06_14_060500_add_product_range_fields_to_order_items.php` — same fields onto `order_items` **+ adds `bottom_surface`** (was never present); widens enum. (down() reverses.)

### Backend
- `app/Models/QuotationItem.php` / `app/Models/OrderItem.php` — added new columns to `$fillable`.
- `app/Services/QuotationService.php` — `saveItems()` now persists `application`, `top_color_ral`, `bottom_color_ral`, `fixing_system`, and **`bottom_surface` from the row** (was hardcoded `'PLAIN'`).
- `app/Services/OrderService.php` — order snapshot now copies the 5 new fields (incl. `bottom_surface`, previously dropped) into `order_items`.
- `app/Http/Controllers/Api/QuotationController.php` — validation: `density_type` enum widened, `density_kgm3` → numeric 10–200 (was fixed list, blocked Rockwool), thickness adds 10/25, new nullable rules for application/fixing/RAL/bottom_surface; `getSuggestedRate` density_type widened.
- `resources/views/quotations/pdf.blade.php` — PI description no longer guesses ROOF/FLAT from ribbing; uses real `application`; shows core type, fixing system, RAL codes, **branded panel-type name**; bottom surface no longer hardcoded PLAIN. (TCS deliberately NOT added — repealed Apr-2025.)
- `resources/views/quotations/_boq_sheet_body.blade.php` — cutting sheet shows application, fixing system, RAL, real bottom surface.

### Frontend (page: New/Edit Quotation **and** New/Edit BOQ — `QuotationCreate.vue`)
- Added **Application**, **Core/Insulation** (5 types), **Fixing System** selects; **RAL Code** inputs on both skins (with `ral-list` datalist).
- **Fixed latent bug:** skin-material dropdown sent `SS`/`AL` and lacked `PPGL` → backend 422. Now `PPGI / PPGL / GI / SS304 / Aluminium` (matches validation).
- Bottom-skin **Surface** is now selectable (was disabled "Plain always"); "Same as top" mirrors surface + RAL too.
- Thickness options add 10/25 mm (Cool Roof etc.); density input range 10–200.
- `onPanelTypeChange` derives Application + core from the SKU name (overridable).
- Payload, `loadForEdit`, `makePanelRow`, `rowSummary` all carry the new fields.

**Verified:** both migrations ran; DB columns + widened enums confirmed; `npm run build` green.

---

## Batch 2 (in progress)

### P3 rollout — table treatment to remaining lists
- `frontend/src/components/OrderList.vue` — added SortIcon + aria-sort to existing sortable headers; skeleton rows on load; zebra/sticky header/tabular-nums; tokenized colors. Behavior unchanged (sort already existed).
- `frontend/src/components/InvoiceList.vue` — skeleton rows; zebra/sticky/tabular; tokenized. (No sort added — backend sort not wired.)
- `frontend/src/components/BatchList.vue` — skeleton rows; zebra/sticky/tabular; tokenized; **fixed QC badge** so `pass`/`fail` statuses render styled (were unstyled).
- `frontend/src/components/DispatchList.vue` — skeleton rows; zebra/sticky/tabular; tokenized.
- Each file: added `import SkeletonRows from './SkeletonRows.vue'` (and SortIcon for OrderList).

**Rollback any one:** `cp _ux_rollback_2/frontend_src/components/<File>.vue frontend/src/components/<File>.vue`

### P5 — keyboard-operable rows
- QuotationList, OrderList, InvoiceList, BatchList, DispatchList, BoqRegister — clickable `<tr>` now have `tabindex="0"`, `role="button"`, `:aria-label`, and `@keyup.enter`. Purely additive.

### P3d — QuotationDetail action bar + feedback
- `frontend/src/components/QuotationDetail.vue` — regrouped action bar (document · contextual primary solid · secondary · destructive pushed right with divider/spacer); added aria-label to ⬇; replaced bespoke pastel button classes with semantic `.btn-primary/.btn-ghost/.btn-edit/.btn-danger`; converted persistent success/error banners to **toasts**; delete still uses existing modal. Behavior unchanged; only one contextual primary action is solid now.

### P6b — responsive form grids
- `frontend/src/components/QuotationCreate.vue` — added @media: 4-col grids → 2-col (≤1100px) → 1-col (≤600px); skin spec stacks; size table scrolls; sticky-totals wraps. Added persistent **DL legend** above the size table (was hover-only).

### R1 — QcDashboard table treatment
- `QcDashboard.vue` — skeleton rows; sticky header; zebra; hover tint; tabular `.mono`; tokenized table-wrap.

### R2 — detail-screen icon aria-labels
- `InvoiceDetail.vue` (download ⬇, modal close ✕), `DispatchDetail.vue` (download challan ⬇) — added `aria-label`.

### R3 — responsive form grids
- `CompanySettings.vue` (3→2→1 col), `PanelTypeMaster.vue`, `AccessoryMaster.vue`, `UserManagement.vue` (2→1 col) — added @media breakpoints.

### R4 — QuotationCreate depth
- `QuotationCreate.vue` —
  - **Collapsible panel rows**: header toggle (▸/▾) + body wrapped in `v-show`; clicking header collapses; summary shows when collapsed.
  - **"Same as top" skin**: per-row `same_skin` (default true) hides the bottom-skin block and mirrors top→bottom on save; `loadForEdit` derives it from existing data.
  - **Scroll-to-error validation**: `failValidation()` sets the banner, toasts, expands the offending row, and scrolls the banner into view; messages now name the row number.
  - (Skipped: per-size autofocus — lowest value / fragile.)

**Rollback this batch:** `cp -r _ux_rollback_2/frontend_src/. frontend/src/` (backend untouched in R1–R4 except earlier reorder endpoints; those are in `_ux_rollback_2/backend/api.php` + StockController which is additive).

### Still OPEN (deferred)
- Routing / deep-linking (architectural — would replace the Manager pattern).
- Action-bar regrouping on Order/Invoice/Batch/Dispatch detail (only QuotationDetail done).
