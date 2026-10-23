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
| `Deployed` | Live on the server. The deployment that carried it is recorded in §4. |
| `Deferred` | Understood, deliberately not doing it yet. |
| `Not a bug` | Investigated and rejected — keep the row and say why. |

---

## 1. Live defects

Found by the end-to-end production audit on 2026-10-23 (`PANELOS_E2E_BUG_REPORT.md`), unless marked
*(internal)* — those I found while fixing the others.

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-001** | `Critical` | Deployed | Invoicing / Tax | Every invoice ever issued carries ₹0 GST — nothing creates the `TaxConfiguration` that `TaxService` reads |
| **PB-002** | `Critical` | Deployed | Dispatch / Invoicing | Accessories, doors and installation never reach an invoice — that revenue is never billed |
| **PB-003** | `Critical` | Deployed | Production | A standalone batch leaves its order `pending`, so the planner re-offers delivered work → double production and double material issue |
| **PB-004** | `Critical` | Deployed | Validation / API | Every `app/Rules/*` class is a PHP fatal → customer, company, supplier **and** user writes all return an empty 500 |
| **PB-005** | `High` | Deployed | GST | Quotation and invoice disagree on intra- vs inter-state (`"24"` vs `"GJ"`) → wrong tax head on real invoices |
| **PB-006** | `High` | Deployed | Frontend | Validation composable called a computed as a function → New Customer form dead, and form validation silently never ran anywhere |
| **PB-007** | `High` | Deployed | Customers | `credit_limit` / `payment_terms_days` hard-coded on create, so credit control could never engage |
| **PB-008** | `Medium` | Deployed | Invoicing | Over-billing: `quantity` was an INTEGER column (312.93 m² → 313) and line amounts were recomputed from an averaged rate |
| **PB-009** | `Medium` | Deployed | Production | Cutting schedule overstates material ~6× — reads m² as a piece count and ignores the DL rule |
| **PB-010** | `Medium` | Deployed | Pricing | Suggested rate returns ₹160 — zero `base_price` skips the ₹850 fallback, nested payload stripped by validation |
| **PB-011** | `Medium` | Deployed | Orders | Duplicate `create-order` returns a generic 500 instead of the real 400 message |
| **PB-012** | `Medium` | Deployed | Quotations | A quotation that already has an order can still be revised → two live orders for one job |
| **PB-013** | `Medium` | Deployed | Quotations | *(internal)* `installation_amount` never reset to 0, so a removed installation row stays in the total forever |
| **PB-014** | `Low` | Deployed | GST | `/gst-breakdown` 404s on a valid invoice (`gst_tax_breakdown` only written by an explicit `/calculate-gst`) |
| **PB-015** | `Low` | Deployed | GST | GST config saves `state_name: "Unknown"` for numeric state codes |
| **PB-016** | `Low` | Deployed | Payments | `remaining_due` leaks raw floats (`64000.119999999995`) |
| **PB-017** | `Low` | Deployed | Leads | `source` is case-sensitive — `website` 422s, `Website` passes |
| **PB-018** | `Low` | Deployed | Leads | Validation returns a generic "Validation failed" where other modules name the field |
| **PB-019** | `Low` | Deployed | Customers | No "Add Customer" button on the Customers screen — the BOQ form was the only way in |
| **PB-020** | `Low` | Deployed | Infrastructure | *(internal)* Document numbering uses MySQL-only `REGEXP`, so that path can't run on any other engine |

### Still open

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-021** | `Medium` | **Needs decision** | Invoicing | Fixed transport is never invoiced. The quotation adds it *after* tax and untaxed; a per-line invoice can't express an untaxed line, so billing it would silently change its tax treatment. **Decide whether transport should be taxed** — then it's a one-line change on both sides. |
| **PB-022** | `Low` | Deferred | PDF | Generated PDFs are ~1 MB each, dominated by the company logo embedded as a full-size base64 data-URI. Correct, just heavy. |
| **PB-023** | `Low` | Deferred | Tax | Quotations hard-code 18% while invoices read `TaxConfiguration`. They agree out of the box; they diverge the moment someone changes the invoice rate. |

### Production data — not code

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-024** | ~~`Critical`~~ | **Not a bug** | Data | `INV-2026-000001` — ₹3,77,100 part-paid, ₹0 GST, ~₹29,250 accessories unbilled. Owner confirmed 2026-10-23 that the Signature PUF Panel tenant holds **dummy data only**, so no credit note or reissue is needed. The audit report labelled this order "real"; that label was wrong. |
| **PB-025** | ~~`High`~~ | **Not a bug** | Data | `ORD-2027-001` stuck `pending`. Same reason — dummy data. PB-003 stops it recurring. |

> **Open question, not yet answered:** PB-001 zero-rated the GST on *every* invoice for *every* tenant, not
> just this one. If any other tenant issued invoices before 2026-10-23, those are still wrong in the
> database and a deploy cannot fix them. Worth one query against production before considering PB-001 fully
> closed.

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

## 3. Deployment log

| When | Commit | Carried | Outcome |
|---|---|---|---|
| 2026-10-23 11:42 UTC | [`7409193`](https://github.com/Kamleshnishad/panelos/commit/7409193) | PB-001 → PB-020 | ✅ Deployed. Pre-migration backup taken (221.9 KB, archived as GH artifact `db-backup-7409193`). Migration `2026_10_23_000001_fix_invoice_dispatch_item_precision_and_non_panel_lines` applied. `schema:check` → *Schema matches baseline*. `/api/health` → `{"status":"OK","database":"OK"}`. |

**Rollback**, if ever needed: run the *Rollback* workflow with SHA `9f89154` and a reason. Note it does
**not** reverse the migration — that needs `php artisan migrate:rollback --step=1` on the server. The
migration is additive and lossless (widened `quantity` to `decimal(12,4)`, made `panel_type_id` nullable,
added `description`), so rolling the code back without it is safe.

---

## 4. Order of work

1. ~~Deploy PB-001 → PB-020.~~ **Done** — see §3.
2. **Verify on the live server while logged in** (not yet done): raise one invoice and confirm it now
   carries 18% GST split correctly, that accessories/installation appear as lines, and that saving a
   customer works. The deploy only proves the app is healthy, not that each fix behaves on real data.
3. **Check whether any other tenant has pre-2026-10-23 invoices** — those still carry ₹0 GST (see the
   note under §1). ~~PB-024 / PB-025~~ are closed: dummy data.
4. **Decide PB-021** — does fixed transport get taxed? A business call, not an engineering one.
5. **Test suite PB-026 → PB-029**, in that order; PB-026 alone is expected to clear ~212.

---

## How to add a new one

Append a row with the next free id (update "Next free id" at the top), pick a severity from the table
above, and start at `Open`. When it's fixed, move it to `Deployed` and name the regression
test; when it's live and verified on the server, `Deployed`. A row is never deleted — a rejected bug stays
with `Not a bug` and a one-line reason, so the same thing doesn't get re-reported in six months.
