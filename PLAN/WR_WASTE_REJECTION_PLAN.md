# WR — Waste & Rejection Management Plan

> Phase IDs use the **WR-** prefix (WR-0 … WR-8) so they never collide with the
> main roadmap's Phase 1–8 in `00_PROJECT_TRACKER.md`.
>
> Status: **Planned** · Created: 2026-10-26 · Pilot tenant: Uma Signature Puf Panel LLP

---

## 1. Why this plan exists

Today PanelOS tracks **material wastage** reasonably well (BOM wastage %, actual-vs-standard
kg per run, wastage report) but tracks **panel rejections and process scrap almost not at all**:

| Area | Today |
|---|---|
| Material wastage allowance (coil/chemical/consumable %) | ✅ `MaterialBomService` |
| Actual vs standard material per run + report | ✅ `ProductionMaterialService::recordActuals / wastageReport` |
| Cutting list (length × NOS, DL doubling) | ⚠️ `CuttingScheduleService` computes it — **not persisted, no UI** |
| Rejected panels (NOS, reason, length) | ❌ QC is one pass/fail per batch |
| What happens to a reject (scrap / remake / downgrade …) | ❌ |
| Start-up / changeover / trim scrap | ❌ |
| Cost of rejects / scrap recovery value | ❌ |
| Handling damage, transit damage, returns, credit notes | ❌ |
| Rejection KPIs on dashboard | ❌ |
| QC bugs | 🐞 `rework_required` strands batch in `qc_pending`; `qc_failed` is a dead end (unique `batch_id`); `update()` checks non-existent `pending_approval` status |

Goal: **every square metre that enters the line is accounted for** — as good panels,
reworked/recut panels, B-grade stock, or scrap with a reason, an owner and a ₹ value.

---

## 2. Research summary (what real panel plants deal with)

**Where waste happens on a continuous PUF/PIR/Rockwool line**

| # | Waste type | Unit | Where it comes from |
|---|---|---|---|
| A | Raw-material waste | kg / m | Coil edge trim, coil splice/joint scrap, damaged coil ends; chemical purge/flush, over-pour, expired drums; film/tape |
| B | Process scrap | running metres | Line start-up, changeover (thickness = belt gap, colour = coil change, PUF↔PIR ≈ 30 min recipe/thermal change), restart after stop, saw trims |
| C | Rejected panels | NOS × length | Defects found at saw / QC / stacking |
| D | Surplus panels | NOS × length | DL doubling with odd qty (1500 × 3 → produce 3000 × 2 → 1 extra), over-cuts |
| E | QC test consumption | pieces | Destructive tests (density, tensile/adhesion — EN 14509 FPC: 3 specimens ≈ per shift) |
| F | Storage / handling damage | NOS | Forklift, stacking, weather |
| G | Transit damage & customer returns | NOS | After dispatch |

**Typical defects and root causes** (feeds the reason-code master & root-cause hints):

| Defect | Usual root cause (parameter to check) |
|---|---|
| Delamination / weak bond | Mix ratio, low sheet/surface temp, insufficient curing for line speed |
| Voids / under-fill / overflow | Pour distribution, shot volume vs thickness, line speed vs foam reactivity |
| Thickness out of tolerance (±0.5 mm) | Line speed sync, belt pressure, dosing |
| Waviness / sheet deformation | Sheet tension, decoiler settings, roll-former alignment |
| Wrong length (±2 mm) / squareness (≤3 mm/m diag.) | Saw calibration, encoder/sensor |
| Density off target (±2 kg/m³) | Chemical temp (18–22 °C), ratio, recipe |
| Surface scratch / dent / paint damage | Handling, guard film, stacking |
| Coil defect (colour shade, coating) | Supplier — claimable |

**Disposition practice (MRB / NCR)** — industry-standard outcomes for non-conforming product:
use-as-is, rework/repair, reclassify (downgrade), scrap, return to supplier, no-fault-found.
Who may decide must be defined (authority by role). Adapted for panels (§3).

**Scrap value recovery** — steel facings are the value that survives (sold as steel scrap by kg);
PUR/PIR cores are hard to recycle (regrind as filler, or chemical glycolysis — not economical at
plant scale); some European makers now collect saw waste. So the realistic recovery in India is
**steel scrap kg × ₹/kg**, with foam as a disposal cost.

**Unique opportunity for panels: RECUT.** A long panel with a defect near one end can be cut
down into a shorter, fully good panel — matching a pending cut line of *any* open order with the
same spec. Plus surplus/off-cut panels can be kept as **remnant stock** and consumed by future
orders before producing new ones. This turns a large share of "scrap" back into revenue.

---

## 3. Core concepts (shared vocabulary)

- **Cut line** — one spec × one length × planned NOS for a batch/run (persisted cut list).
- **NCR (Non-Conformance Record)** — one record per rejection event: what, how many, where
  detected, why, photo, who.
- **Disposition** — the decision for an NCR's pieces. Configurable list; defaults:

| Disposition | Effect |
|---|---|
| **Use-as-is** | Cosmetic, approved by authorised role → counted as good (flagged) |
| **Rework** | Repair (touch-up, re-film, re-pack) → back to good after re-check |
| **Recut** | Cut to a shorter good length → becomes remnant stock or fills a matching cut line; trim becomes scrap metres |
| **Downgrade (B-grade)** | Moves to B-grade stock with a reduced rate, sellable |
| **Remake** | NOS added back to the cut list as a remake line (same order) |
| **Scrap** | Written off with ₹ cost; steel scrap kg recovered |
| **Return to supplier** | Raw-material defect (coil/chemical) → supplier claim |

- **Remnant stock** — good panels not tied to an order (surplus, recut, cancelled order) by spec + length.
- **B-grade stock** — downgraded panels by spec + length + defect note.
- **COPQ (Cost of Poor Quality)** — ₹ of scrap + rework + downgrade loss − recovery.

---

## 4. Phases

Each phase is shippable on its own and leaves the system consistent.

### WR-0 · Foundations & QC bug fixes
**Goal:** a correct base and the masters everything else uses.
- Fix QC bugs: support `rework_required` (batch → `in_progress`, re-QC allowed); allow a new QC
  after `qc_failed` (drop unique `batch_id`, keep history, latest wins); remove dead
  `pending_approval` path.
- **Reason-code master** (per tenant, editable): code, label, category
  (`defect` · `process_scrap` · `handling` · `transit` · `supplier` · `stock_adjust`),
  linked root-cause hint, active flag. Seed with §2 defaults.
- **Disposition master** (per tenant): the §3 list, each with `requires_approval_role`
  and `approval_threshold_sqm`.
- Settings: steel scrap rate ₹/kg, foam disposal ₹/kg, rejection alert % (default 5% per shift).
- Permissions: `waste.record`, `waste.approve`, `waste.settings`.

**Acceptance:** QC can be re-done after fail/rework; masters editable in Settings; tests for QC transitions.

### WR-1 · Persisted cut list + NOS recording (prerequisite)
**Goal:** NOS exists in production.
- New table `production_cut_lines`: batch_id/run_id, order_item_size_id, spec signature,
  order_length_mm, production_length_mm, dl_factor, planned_nos, cut_nos, good_nos,
  rejected_nos, sequence, is_remake, status.
- Generate from `CuttingScheduleService` on batch/run start (reuse its DL logic); sequence by
  core → thickness → colour → length desc, with changeover markers.
- Operator screen (mobile-friendly): per line enter **cut / rejected** with big buttons;
  good = cut − rejected; batch `completed_quantity` (SQM) derived, no longer typed.
- Batch/run progress: NOS and SQM, per length.

**Acceptance:** an order's size rows appear as cut lines; recording 58 good + 2 rejected of 60
shows 58/60 and correct SQM; test for DL doubling and SQM derivation.

### WR-2 · Rejection capture (NCR)
**Goal:** every bad panel recorded with why and where.
- Table `nonconformances`: cut_line_id, batch/run, line/shift (when lines exist), stage detected
  (saw / QC / stacking / store / dispatch / customer), nos, length_mm, sqm, reason_code_id,
  severity, photo(s), notes, raised_by, status (`open` → `dispositioned` → `closed`).
- Rejecting on the cut-list screen opens an NCR (reason required).
- QC screen records **rejected NOS per cut line** + measured values (thickness, density) vs
  tolerance; QC fail auto-raises NCRs and puts the batch on **hold** (blocked from dispatch).

**Acceptance:** rejected NOS on cut line == sum of open+closed NCR NOS; held batch cannot be dispatched.

### WR-3 · Disposition workflow
**Goal:** every NCR ends in a decision with an owner.
- Disposition screen: split an NCR's NOS across dispositions (e.g. 3 → 1 recut, 2 scrap).
- Approvals by role / threshold (from WR-0 master); audit-logged.
- Effects:
  - **Remake** → adds a remake cut line (`is_remake`) to the same batch or next run.
  - **Recut** → choose new length; creates remnant panel(s) or fills a matching open cut line;
    trim recorded as scrap metres.
  - **Rework / Use-as-is** → back to good NOS (flagged).
  - **Downgrade** → B-grade stock (WR-4).
  - **Scrap** → scrap entry (WR-6 costing).
  - **Return to supplier** → supplier claim against coil/chemical lot.

**Acceptance:** NCR cannot close with undispositioned NOS; remake appears on cut list; test per disposition.

### WR-4 · Remnant & B-grade stock
**Goal:** turn rejects and surplus into revenue.
- Finished-panel stock tables for **remnant** and **B-grade** (spec + length + NOS + location + source NCR/batch).
- DL surplus and cancelled-order panels go to remnant automatically.
- **Planner match:** when planning a new order, suggest remnant panels with same spec and
  length ≥ needed (recut if longer) before producing.
- Sell B-grade/remnant: pick from stock in quotation/order as a flagged line at a reduced rate.

**Acceptance:** surplus from DL odd qty lands in remnant; planner suggests it for a matching order; consumption reduces stock.

### WR-5 · Process scrap & raw-material waste
**Goal:** capture the waste that isn't a "panel".
- Per run: start-up metres, changeover metres (auto-prompted at each changeover marker),
  restart metres, saw-trim metres — each with reason code.
- Per coil: metres/kg consumed, splice scrap, damaged ends; per chemical drum: purge/flush kg,
  expired kg.
- Stock adjustments get **reason codes** (damaged, expired, count difference, theft) + value.
- Supplier defects: coil/chemical lot claims with qty and status.

**Acceptance:** run summary shows input SQM = good + rejected + process scrap (± tolerance), test for the balance.

### WR-6 · Costing, recovery & COPQ
**Goal:** put a ₹ value on all of it.
- Standard cost per SQM by spec = material (from recipe/BOM at current stock cost) + conversion
  ₹/SQM (tenant setting).
- Scrap entry value = SQM × standard cost; recovery = steel kg (from skin thickness × area) × scrap rate;
  foam disposal cost; net loss.
- Downgrade loss = (standard rate − B-grade rate) × SQM; rework cost (setting per rework type).
- Steel scrap sale register (kg sold, ₹ received).
- **COPQ** per month / line / spec.

**Acceptance:** scrapping 2 × 3660 mm of a known spec shows correct ₹ loss and steel recovery; tests for cost formulas.

### WR-7 · Downstream damage, returns & credit notes
**Goal:** close the loop after the factory gate.
- Handling/storage damage NCRs from finished stock.
- Transit damage reported on delivery (NOS × length, photos) against the dispatch.
- **Customer return** → goods-return note → stock (remnant/B-grade/scrap via disposition).
- **Credit note** against invoice (GST-correct), and **Tally export** of credit notes (extends existing Tally export).

**Acceptance:** credit note reduces receivable and appears in Tally XML; test for GST split.

### WR-8 · Analytics, alerts & reports
**Goal:** management sees and acts.
- KPIs: **First-Pass Yield** (good first time ÷ produced), rejection rate % (NOS & SQM),
  scrap rate % of input SQM, material yield (actual vs recipe), recut/recovery %, COPQ ₹.
- Breakdowns: by line, shift, operator, spec, defect, coil lot, chemical lot, supplier.
- **Pareto** of top defects; trend charts (reuse `BiChart.vue`) on the BI dashboard + a
  dedicated Quality & Waste report.
- Alerts: shift rejection > threshold, same defect repeating, density/thickness out of tolerance,
  supplier lot with repeated defects.
- Monthly MIS email gets a Waste & Quality section.

**Acceptance:** dashboard numbers reconcile with NCR and scrap tables; alert fires in a test.

---

## 5. Dependencies & order

```
WR-0 ─┬─> WR-1 ─> WR-2 ─> WR-3 ─┬─> WR-4
      │                          ├─> WR-6 ─> WR-8
      └────────> WR-5 ───────────┘
                         WR-3 ─> WR-7 ─> WR-8
```
Recommended start: **WR-0 + WR-1** together (small, unblocks everything).

---

## 6. Questions to confirm with the factory (before WR-2)

1. Where are rejects found today — at the saw, at QC, at stacking, or at dispatch?
2. Current defect names they use on the floor (to seed reason codes in their language).
3. Who may approve scrap / downgrade? Any ₹ or SQM limit?
4. Do they recut damaged long panels into shorter ones today? Do they keep a remnant pile?
5. Do they sell B-grade panels? At what discount?
6. Steel scrap: sold to whom, at what ₹/kg; what happens to foam waste?
7. Typical start-up and changeover scrap in metres?
8. Do they raise claims on coil suppliers for defects?
9. Do customers return panels / ask for credit notes for transit damage?

---

## 7. Sources

- KINDUS — step-by-step sandwich panel process & tolerances: https://kindus.com/production-lines/sandwich-panel-manufacturing-process-step-by-step-technical-overview/
- Common production defects & prevention: https://www.sandwichpanelline.com/common-production-defects-in-sandwich-panel-production-lines-and-how-to-prevent-them/
- KINDUS — continuous PIR line chemistry (changeover ~30 min): https://kindus.com/production-lines/continuous-pir-panel-line-chemistry/
- EN 14509 FPC schedule (excerpt): https://pdfcoffee.com/0-en-14509pdf-pdf-free.html
- EOTA EAD 340282 (panel testing): https://www.eota.eu/download?file=%2F2017%2F17-34-0282%2Ffor+ojeu%2Fead+340282-00-0303_ojeu+2025.pdf
- Infor LN — NCR & dispositions: https://docs.infor.com/ln/10.4.in/en-us/lnolh/help/qm/onlinemanual/000104.html
- FDA — nonconforming product dispositions: https://fda.gov/media/109408/download
- FactoryLogix — non-conformance review & disposition: https://docs.aiscorp.com/en/factorylogix-learning/FL/op/non-conformance-review-and-disposition
- SG Systems — Material Review Board: https://sgsystemsglobal.com/glossary/material-review-board-mrb/
- Sandwich panel reuse & trimming (MDPI 2026): https://www.mdpi.com/2071-1050/18/5/2454
- Panel steel/PIR separation & saw-waste collection: https://stedenbouw.nl/en/general/after-ingestion-sandwich-panels-again-become-a-raw-material/
- PU foam glycolysis review: https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11433504/
