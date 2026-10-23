# PanelOS — Bug Register

Running log of defects found, their severity, and where each one stands.
Newest batch at the top of each table. See [SYSTEM.md](SYSTEM.md) for how the system works and
[CLAUDE.md](CLAUDE.md) for how to test.

**ID scheme:** `PB-###`, assigned once and never reused — even if a bug is rejected or turns out to be a
duplicate (mark it `Not a bug` / `Duplicate of PB-###` and move on). Next free id: **PB-030**.

**Severity** — what it costs if left alone:

| | |
|---|---|
| `Critical` | Money, tax compliance or material is being lost right now, or a core flow is dead. Fix and deploy immediately. |
| `High` | A real flow is broken or produces wrong data, but there is a workaround or limited blast radius. |
| `Medium` | Wrong numbers at the margin, bad UX, or a trap that will bite later. |
| `Low` | Cosmetic, hygiene, or internal-only (e.g. test tooling). |

**Status** — where it stands:

| | |
|---|---|
| `Open` | Not started. |
| `Needs decision` | Blocked on a business call, not on code. |
| `Fixed – not deployed` | Code fixed and tested locally; **production is still broken**. |
| `Deployed` | Live on the server and verified there. |
| `Deferred` | Understood, deliberately not doing it yet. |
| `Not a bug` | Investigated and rejected — keep the row and say why. |

---

## 1. Live defects

Found by the end-to-end production audit on 2026-10-23 (`PANELOS_E2E_BUG_REPORT.md`), unless marked
*(internal)* — those I found while fixing the others.

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-001** | `Critical` | Fixed – not deployed | Invoicing / Tax | Every invoice ever issued carries ₹0 GST — nothing creates the `TaxConfiguration` that `TaxService` reads |
| **PB-002** | `Critical` | Fixed – not deployed | Dispatch / Invoicing | Accessories, doors and installation never reach an invoice — that revenue is never billed |
| **PB-003** | `Critical` | Fixed – not deployed | Production | A standalone batch leaves its order `pending`, so the planner re-offers delivered work → double production and double material issue |
| **PB-004** | `Critical` | Fixed – not deployed | Validation / API | Every `app/Rules/*` class is a PHP fatal → customer, company, supplier **and** user writes all return an empty 500 |
| **PB-005** | `High` | Fixed – not deployed | GST | Quotation and invoice disagree on intra- vs inter-state (`"24"` vs `"GJ"`) → wrong tax head on real invoices |
| **PB-006** | `High` | Fixed – not deployed | Frontend | Validation composable called a computed as a function → New Customer form dead, and form validation silently never ran anywhere |
| **PB-007** | `High` | Fixed – not deployed | Customers | `credit_limit` / `payment_terms_days` hard-coded on create, so credit control could never engage |
| **PB-008** | `Medium` | Fixed – not deployed | Invoicing | Over-billing: `quantity` was an INTEGER column (312.93 m² → 313) and line amounts were recomputed from an averaged rate |
| **PB-009** | `Medium` | Fixed – not deployed | Production | Cutting schedule overstates material ~6× — reads m² as a piece count and ignores the DL rule |
| **PB-010** | `Medium` | Fixed – not deployed | Pricing | Suggested rate returns ₹160 — zero `base_price` skips the ₹850 fallback, nested payload stripped by validation |
| **PB-011** | `Medium` | Fixed – not deployed | Orders | Duplicate `create-order` returns a generic 500 instead of the real 400 message |
| **PB-012** | `Medium` | Fixed – not deployed | Quotations | A quotation that already has an order can still be revised → two live orders for one job |
| **PB-013** | `Medium` | Fixed – not deployed | Quotations | *(internal)* `installation_amount` never reset to 0, so a removed installation row stays in the total forever |
| **PB-014** | `Low` | Fixed – not deployed | GST | `/gst-breakdown` 404s on a valid invoice (`gst_tax_breakdown` only written by an explicit `/calculate-gst`) |
| **PB-015** | `Low` | Fixed – not deployed | GST | GST config saves `state_name: "Unknown"` for numeric state codes |
| **PB-016** | `Low` | Fixed – not deployed | Payments | `remaining_due` leaks raw floats (`64000.119999999995`) |
| **PB-017** | `Low` | Fixed – not deployed | Leads | `source` is case-sensitive — `website` 422s, `Website` passes |
| **PB-018** | `Low` | Fixed – not deployed | Leads | Validation returns a generic "Validation failed" where other modules name the field |
| **PB-019** | `Low` | Fixed – not deployed | Customers | No "Add Customer" button on the Customers screen — the BOQ form was the only way in |
| **PB-020** | `Low` | Fixed – not deployed | Infrastructure | *(internal)* Document numbering uses MySQL-only `REGEXP`, so that path can't run on any other engine |

### Still open

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-021** | `Medium` | **Needs decision** | Invoicing | Fixed transport is never invoiced. The quotation adds it *after* tax and untaxed; a per-line invoice can't express an untaxed line, so billing it would silently change its tax treatment. **Decide whether transport should be taxed** — then it's a one-line change on both sides. |
| **PB-022** | `Low` | Deferred | PDF | Generated PDFs are ~1 MB each, dominated by the company logo embedded as a full-size base64 data-URI. Correct, just heavy. |
| **PB-023** | `Low` | Deferred | Tax | Quotations hard-code 18% while invoices read `TaxConfiguration`. They agree out of the box; they diverge the moment someone changes the invoice rate. |

### Production data — not code

These cannot be fixed by deploying. They need a decision and a deliberate correction.

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-024** | `Critical` | **Needs decision** | Data | `INV-2026-000001` — ₹3,77,100, already part-paid ₹1.5L, issued with ₹0 GST and ~₹29,250 of accessories unbilled. Needs a credit note + reissue. **Do not mutate it silently.** |
| **PB-025** | `High` | Open | Data | `ORD-2027-001` is still `pending` despite being produced, dispatched and invoiced. PB-003 stops it recurring but won't correct this row. |

---

## 2. Test suite

Separate from the live defects: **test-code problems, not production problems.** Confirmed — every
production path that creates a company sets `subdomain` correctly. Worth fixing so the suite can catch the
next PB-001, but no customer sees any of this.

Baseline on MySQL: 418 tests, 152 passing, 266 failing/erroring.

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-026** | `Medium` | Open | Tests | 19 test files call `Company::create(['name' => …])` without `subdomain` (`NOT NULL UNIQUE`) → **184 errors** under MySQL strict mode, plus ~28 cascade failures. One fix clears most of the suite. |
| **PB-027** | `Low` | Open | Tests | `ProductionBatchFactory` doesn't exist (4 files use it) → 25 errors. |
| **PB-028** | `Low` | Open | Tests | Tests call `Coil::factory()` / `Chemical::factory()` — models renamed to `CoilStock` / `ChemicalStock` → 19 errors. |
| **PB-029** | `Low` | **Needs triage** | Tests | Stale payloads (`items` instead of `panel_rows`) and tests asserting **deliberately removed** behaviour — e.g. `DispatchTest` still tests coil allocation at dispatch, which was replaced by consumption at production start. Those should be **deleted, not fixed**; making them pass would mean re-implementing removed behaviour. |

---

## 3. Order of work

1. **Deploy PB-001 → PB-020.** This is what actually stops the bleeding. Commit, push, run the migration
   on production, then verify there (re-check §23 of SYSTEM.md against live).
2. **Decide PB-021 and PB-024** — both are business calls, not engineering ones.
3. **Correct PB-025.**
4. **Test suite PB-026 → PB-029**, in that order; PB-026 alone is expected to clear ~212.

---

## How to add a new one

Append a row with the next free id (update "Next free id" at the top), pick a severity from the table
above, and start at `Open`. When it's fixed, move it to `Fixed – not deployed` and name the regression
test; when it's live and verified on the server, `Deployed`. A row is never deleted — a rejected bug stays
with `Not a bug` and a one-line reason, so the same thing doesn't get re-reported in six months.
