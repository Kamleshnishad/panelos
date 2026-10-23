# PanelOS — Production Readiness Plan

**Goal:** get PanelOS to the point where a real panel factory can run its daily operations on it, with the
shop-floor control the client has asked for.

**Status as of 2026-10-23:** 20 critical/high defects fixed and deployed (`7409193`). The order-to-cash
spine works end to end in code and under test. What remains is operational safety, shop-floor control,
and a handful of real-world gaps that will surface on day one.

Companion documents: [BUGS.md](BUGS.md) (defect register, `PB-###` / `FR-###` ids),
[SYSTEM.md](SYSTEM.md) (how the system works), [CLAUDE.md](CLAUDE.md) (how to test).

---

## Definition of "production ready"

Not a feeling — a checklist. We are ready when all of these are true:

1. A new tenant can sign up and reach their first sent quotation **without hitting a dead end**.
2. Every production step the client cares about is **recorded and signed off** before the next begins.
3. Money that was quoted is money that gets **invoiced** — no silent leaks.
4. The database is **backed up nightly** and a restore has been rehearsed at least once.
5. Automated reminders actually fire.
6. A full cycle — quote → order → produce → QC → dispatch → invoice → payment — has been run
   **on the production server**, not just in tests.
7. The money paths (tax, invoicing, payments) have **regression tests that run**.

Today: **3, 4, 5 and 6 are met** (Phases 0–1). **2 and 7 are not.** Item 1 is partially met — a new tenant still hits the opening-stock trap (PB-033).

---

## Phase 0 — Operational safety ✅ *(done 2026-10-23, commit `c6a05c5`)*

> Was: no routine database backup at all. Now fixed, deployed and verified on the live container —
> all four tasks registered, scheduler loop running, and a restore rehearsed successfully
> (773 statements, 0 failures, 65 tables). First unattended nightly backup is due at 01:30.

| Item | What |
|---|---|
| **PB-030** | `app/Console/Kernel.php` is a Laravel 10 artifact that Laravel 11 does not load. `php artisan schedule:list` reports *"No scheduled tasks have been defined."* Supervisord runs `schedule:run` every 60s and it does nothing. Port the four definitions into `routes/console.php` with the `Schedule` facade. |

**Consequences being fixed:** nightly `db:backup --keep=14` never runs (dumps only happen at deploy time);
`reminders:send` (09:00) and `sms:send-reminders` (10:00) never fire; `reminders:cleanup` never runs.

**Acceptance**
- `php artisan schedule:list` on the production container lists all four tasks.
- A dump appears in `storage/app/backups/` the morning after deploy, with no deploy having run.
- **A restore is rehearsed**: take the latest dump, load it into a scratch database, confirm row counts.
  A backup nobody has restored is a hope, not a backup.

**Effort:** 30 min + deploy. **Risk:** very low. **Blocks:** nothing.

---

## Phase 1 — Prove it works on the live server ✅ *(done 2026-10-23)*

> Walked end to end on production as the live tenant — 36 checks, all passed. Reconciliation for the test
> order: ordered 69,974 = invoiced 69,974 = paid 69,974, **gap 0**. Two new defects found on the way
> (PB-033 opening stock discarded, PB-034 a one-off 502 on signup).
> Test records left in the tenant are listed at the end of BUGS.md §3.

Everything deployed today is verified by tests and by reading code. **Nobody has logged into production and
walked the flow.** That is the single largest unknown in this plan.

**Scope:** one full cycle on production, as a tenant, with throwaway data:

1. Create a customer → confirm it saves (was a hard 500 before today).
2. Create a BOQ with two panel rows including a sub-2000 mm size → download the worker sheet → confirm DL flags.
3. Convert → enter rates → send → accept.
4. Create order → confirm the spec snapshot matches.
5. Create a production run → check material requirement → start → confirm stock actually dropped.
6. Complete with actuals → QC pass → approve.
7. Dispatch → complete → confirm the batch flips to `dispatched`.
8. Invoice from dispatch → **confirm 18% GST, split CGST+SGST for a same-state customer**, and that
   accessories/installation appear as lines.
9. Record a part payment, then the balance → confirm the invoice auto-flips to `paid`.
10. Mark the order completed.

**Acceptance:** every step above behaves as [SYSTEM.md §23](SYSTEM.md) describes. Any deviation becomes a
new `PB-###` row before we proceed.

**Effort:** 1–2 hrs. **Risk:** this is where we find out if anything deployed today misbehaves on real data.

---

## Phase 2 — Shop-floor control ✅ *(built 2026-10-23 — see commit `a8e2b11` + UI)*

> Delivered: per-stage `requires_approval`, approve/reject with a rework path, `production.approve` as a
> separate duty, four-eyes toggle, and `completeBatch()` refusing while stages are outstanding. Enforcement
> is **opt-in per company** so nothing changes for anyone until they switch it on in
> Settings → Company → Production Control. 9 feature tests.

Today the stage system is **decoration**. `areAllStagesCompleted()` and `getNextStage()` exist and are
**never called**; `completeBatch()` checks only that the batch is `in_progress`. A batch can be completed,
QC'd and dispatched having logged **zero** stages. And nothing is signed off — `batch_stage_logs` records
only `logged_by_user_id`, so the same operator starts and finishes a step.

### 2a — PB-031: make stages mean something

Turn on the dead enforcement: `completeBatch()` refuses unless every active stage for that company is
`completed`.

> ⚠ **Migration hazard.** This breaks the current habit of completing batches with no stages. Apply the
> rule only to batches created after the change (or backfill open batches), otherwise in-flight work
> becomes uncompletable.

### 2b — PB-032: add sign-off to the stage log

| Table | Change |
|---|---|
| `batch_stage_logs` | `approved_by_user_id`, `approved_at`, `rejected_at`, `rejection_reason`; status gains `pending_approval`, `rejected` |
| `production_stages` | `requires_approval` (bool) — so the client chooses which steps need sign-off, not all of them |

### 2c — FR-001: the approval workflow

```
operator starts stage        → in_progress
operator completes stage     → pending_approval   (when stage.requires_approval)
                             → completed          (when it does not)
supervisor approves          → completed  → next stage unlocks
supervisor rejects + reason  → rejected   → operator redoes that stage
```

- New permission **`production.approve`**, so Production runs the line and a supervisor signs off.
- Optional **four-eyes** rule (approver ≠ logger) as a company setting — a single-shift factory may need
  it off.
- Batch detail UI: per-stage state, Approve / Reject with reason, approver name and timestamp, and the
  existing stage photos surfaced next to the decision.

**Acceptance**
- A batch with an unapproved stage cannot be completed; the error names the stage.
- A rejected stage returns to the operator and records who rejected it and why.
- The batch timeline shows, per stage: who ran it, who approved it, when, and any photos.
- A user without `production.approve` gets 403 on the approve endpoint.

**Effort:** ~1.5 days (schema + service ~4 hrs, UI the larger half, tests included).
**Depends on:** nothing. **Risk:** medium — the migration hazard above.

---

## Phase 3 — Close the money leaks

| Item | What | Blocked on |
|---|---|---|
| **PB-021** | Fixed transport is never invoiced. The quotation adds it *after* tax and untaxed; a per-line invoice can't express an untaxed line, so billing it today would silently change its tax treatment. | **Your decision: is transport taxed?** Once decided it's ~1 hr on both sides. |
| **PB-023** | Quotations hard-code 18% while invoices read `TaxConfiguration`. They agree out of the box and diverge the moment someone changes the invoice rate. Make the quotation read the same configuration. | PB-021 (same code path) |

**Acceptance:** for an order with accessories, installation **and** transport,
`GET /api/reports/reconciliation` shows `invoice_gap 0`.

**Effort:** ~2 hrs once decided. **Risk:** low, but it changes customer-facing totals — verify against a
real quotation before deploying.

---

## Phase 4 — Partial dispatch *(FR-002 — the biggest real-world gap)*

Today: **one dispatch per batch**, and that dispatch copies **all** of the batch's panel rows. A real
factory ships a cold-storage job in three lorries over two weeks. The system cannot express that.

**Scope:** per-line dispatch quantities, remaining-to-ship tracking per order item, multiple dispatches per
batch, and invoices that bill only what shipped. The non-panel billing logic added in PB-002 already
handles "charge accessories once per order", so that part is solved.

**Acceptance:** an order of 100 panels can ship 60 then 40, producing two challans and two invoices whose
sum equals the order value exactly.

**Effort:** 2–3 days. **Risk:** high — this is structural, touching dispatch, invoicing and reconciliation.
**Recommendation:** confirm with the client *before* building. If they always ship complete batches, skip it.

---

## Phase 5 — Onboarding a new tenant without a dead end ✅ *(done 2026-10-23)*

> Signup now seeds the six production stages, the four material rows the BOM looks up by exact string
> (Polyol, Isocyanate, film, tape) at zero quantity, a tax configuration and material settings. PB-033 is
> fixed: stock creation accepts an opening balance and books it through `StockService`, so it lands in the
> ledger. The dashboard carries a setup checklist that names what is still missing, why it matters and
> where to go. Panel types are deliberately not seeded — those are the tenant's own product range.

A new tenant today gets roles and nothing else. Before they can run production they must create stock rows
whose categories match **exact magic strings**, or every production start fails with
`Insufficient stock` and no explanation of why:

| Material | Lookup |
|---|---|
| Coil | by `panel_type_id` |
| Chemicals | `category` exactly `Polyol`, `Isocyanate` |
| Consumables | `category` exactly `film`, `tape` |

Also: panel types ship with `base_price` 0 (now falls back to a generic ₹850, which is not their price),
and company `state_code`/GSTIN must be set or all GST defaults to intra-state.

**Scope:** seed default production stages, chemical/consumable stock rows (zero quantity, correct
categories) and a tax configuration at signup; add a setup checklist on the dashboard that tells the tenant
what is still missing before they can quote and produce.

**Acceptance:** a brand-new tenant can reach a sent quotation and a started production run without reading
documentation or contacting support.

**Effort:** ~1 day. **Risk:** low.

---

## Phase 6 — A safety net over the money paths

184 tests covering **tax, GST, invoicing, payments and reporting** do not run. The four critical bugs fixed
today had no coverage, and still don't — the next regression there ships silently.

The chain, measured rather than guessed:

| Blocker | Tests held up |
|---|---|
| `Dispatch::factory()` undefined (no `HasFactory`, no factory) | 129 |
| `ProductionBatchFactory` missing | 25 |
| Bare creates hitting NOT NULL `company_id` / `name` | 47 |
| `Coil` / `Chemical` models (renamed long ago) | 19 |
| Stale payloads (`items` instead of `panel_rows`) | ~22 |

**Recommendation — go narrow, not wide.** Fix the factories, then repair only
`TaxServiceTest`, `GstMultiStateTest`, `InvoiceServiceTest`, `PaymentServiceTest` (~45 tests). That covers
what we just fixed. The remaining ~140 cover forecasting, ML and Stripe — no known defects, and the ML ones
mostly assert array shapes.

> Some tests assert **deliberately removed** behaviour — `DispatchTest` still tests coil allocation at
> dispatch, replaced by consumption at production start. Those get **deleted**, not repaired. Making them
> pass would mean re-implementing removed behaviour.

**Effort:** ~3 hrs narrow, 2+ days wide. **Risk:** none to production.

---

## Phase 7 — GST e-invoicing *(conditional)*

`EInvoiceService` is stubbed: IRN and e-way generation return "not enabled" until GSP credentials exist.
Manual entry from the GST portal works today.

**Only needed if the client's turnover is above the e-invoicing threshold**, in which case IRN is mandatory
on B2B invoices. Confirm the current threshold with their CA — it has moved several times.

**Effort:** 2–3 days plus a GSP account (ClearTax, Karvy or similar). **Risk:** external dependency.

---

## Phase 8 — Deferred

| Item | Why it waits |
|---|---|
| **PB-022** | PDFs ~1 MB from a full-size embedded logo. Cost, not correctness. |
| Remaining ~140 tests | No known defects behind them. |
| `costing.view` enforcement | Defined in the registry, not enforced by middleware — currently a UI-only flag. |

---

## Sequence and rough timeline

| Phase | Depends on | Effort |
|---|---|---|
| 0 — Operational safety | — | 0.5 day |
| 1 — Verify live | 0 | 0.25 day |
| 2 — Shop-floor control | 1 | 1.5 days |
| 3 — Money leaks | your decision on PB-021 | 0.25 day |
| 5 — Onboarding | 1 | 1 day |
| 6 — Safety net (narrow) | — | 0.5 day |
| 4 — Partial dispatch | client confirmation | 2–3 days |
| 7 — e-Invoicing | turnover check | 2–3 days |

**Core path (0,1,2,3,5,6): ~4 days.** Phases 4 and 7 are conditional and roughly double it if needed.

Estimates are honest guesses, not commitments — Phase 2's UI and Phase 4's structural work are the two most
likely to run long.

---

## Decisions needed from you

| # | Question | Blocks |
|---|---|---|
| 1 | **Is fixed transport taxed?** | Phase 3 |
| 2 | **Does the client ever ship an order partially?** | Phase 4 — skip it entirely if not |
| 3 | **Is their turnover above the e-invoicing threshold?** | Phase 7 |
| 4 | **Should approval be four-eyes** (approver ≠ operator), or may one person do both? | Phase 2c |
| 5 | **Which stages need approval** — all, or only critical ones like foaming and QC? | Phase 2b |

---

## Recommended start

**Phase 0 today** — you have no routine backup, and that is the only item here that risks losing data
rather than merely annoying someone. Then Phase 1 to confirm today's deploy behaves on real data, then
Phase 2, which is what the client actually asked for.
