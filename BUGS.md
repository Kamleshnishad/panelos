# PanelOS — Bug Register

Running log of defects found, their severity, and where each one stands.
Newest batch at the top of each table.

Sequenced delivery plan: [PRODUCTION_READINESS_PLAN.md](PRODUCTION_READINESS_PLAN.md).
See [SYSTEM.md](SYSTEM.md) for how the system works and [CLAUDE.md](CLAUDE.md) for how to test.

**ID scheme:** `PB-###`, assigned once and never reused — even if a bug is rejected or turns out to be a
duplicate (mark it `Not a bug` / `Duplicate of PB-###` and move on). Next free id: **PB-047**. Feature requests use `FR-###` (next: **FR-006**).

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
| **PB-046** | `Critical` | Fixed, not deployed | Quotations / Orders / Invoices | **After the newest quotation is deleted, no new quotation can be created (500).** Quotation (and Order, Invoice) are soft-deleted, so a deleted row keeps its number in the unique key — but the number generators ignored deleted rows and handed the same number out again (`Duplicate entry '6-SCP-2027-013'`). It then fails on every attempt until the deleted row is purged. Found on live 2026-10-24 when a scratch quotation I deleted during verification blocked the next create. Fix: the three generators count soft-deleted rows (PO, run and lead numbers already did). Test: `DocumentNumberAfterDeleteTest`. **Live is affected until this ships.** |
| **PB-045** | `High` | Deployed | Quotations | **Editing a quotation in the form silently wipes its installation charge.** Installation rows are never stored as rows — `saveAccessories()` only adds them into `quotations.installation_amount`. The edit form rebuilds its accessory list from the saved rows, so installation (and any door/custom row with no master accessory) is not loaded, the form sends an `accessories` array without it, and `update()` rewrites `installation_amount` from that payload → **0**. Reproduced on the MySQL test DB: quotation with 364.5 MTR × ₹300 → `installation_amount` 109,350.00; open in the form and save with nothing changed → 0.00 and the total drops by ₹129k incl. GST. Rate entry on the detail page (`/rates`) is not affected, only the full edit form. Affects the live UMA quotation SCP-2027-012 if anyone edits and saves it. Root cause is the same as FR-002: installation has no row-level storage. **Fixed 2026-10-24:** installation, custom accessories and doors typed into the form are stored as rows in the new `quotation_lines` table (migration also backfills one lump row for every existing quotation that has an installation amount); `installation_amount` stays as their sum, so every total is unchanged; the edit form loads and re-sends the rows; revise and duplicate copy them; the invoice bills one line per row. Also fixed in passing: a Door/Window row from the form and the form's "— Custom —" accessory were being dropped or folded into the lump. Test: `QuotationLinesTest` (10), including the exact open-form-and-save regression. **Verified live 2026-10-24 (deploy `2c636d4`):** migration applied; the UMA quotation SCP-2027-012 now carries one lump row (₹109,350, total unchanged at 911,476.00); no quotation with an installation amount is without a row; a scratch quotation (installation 364.5 MTR × ₹300 + a typed-in door) saved exactly as the form sends it kept installation_amount 125,350.00, both rows and the same total — then was deleted. |
| **PB-044** | `Low` | Deployed | Quotations | **An unknown door type returned a 500 with the raw SQL error.** Found while seeding the client's UMA proforma through the API: `accessories.*.door_type` was validated as `string|max:50`, but the column is an enum (`sliding_door`, `hinged_door`, `window`, `pass_through`), so `"Single"` passed validation, failed at the INSERT, and the browser got the SQL text (another PB-043 leak). The web form only ever sends the four valid values, so only API callers hit it. Now validated with `in:` → clean 422. Test: `QuotationAccessoryValidationTest::test_an_unknown_door_type_is_rejected_with_a_422_not_a_sql_error`. |
| **PB-043** | `Medium` | Open | Security | **Raw database errors are returned to the browser.** (Also leaks internal class names: `POST /api/notifications/999999/read` → "No query results for model [App\Models\AppNotification] 999999", seen 2026-10-23.) Found while verifying PB-042: `GET /api/quotations?sort_by=nonexistent` returned the full SQL statement, the internal DB host (`172.30.0.1`), the database name and the table/column structure in the response body, because the controller's `catch` passes `$e->getMessage()` to the client. Not specific to one endpoint: **215 `getMessage()` uses across 33 controllers** (Stock 29, Payment 18, Quotation 17, Reporting 15, Invoice 15 …). Most are deliberate domain messages the UI needs ("Rates are pending", "Insufficient stock") and must stay — so the fix is not to delete `getMessage()`. Proposed: redact at one choke point. `ApiResponse::errorResponse()` would detect framework/DB text (`SQLSTATE[`, `Connection: mysql`, stack-trace or file-path fragments), log the original with a reference id, and return a generic message plus that reference. One edit covers every controller that goes through it; the few that build `response()->json` by hand need moving over. Also worth doing there: validate `sort_by` against an allow-list, since list endpoints pass it straight to `orderBy()` and a bad value is itself a 500. |
| **PB-041** | `High` | Deployed | Customers / Accessories | **Editing a record with any blank field returned a 500.** The edit form sends every field, with `""` for blanks; Laravel turns those into `null`; several columns are `NOT NULL` (customers: `address_line1`, `city`, `state`, `state_code`, `pincode`, `phone`; accessories: `unit_price`, `rate`, `unit`). `store()` coalesced them to `''`, but `update()` wrote the nulls straight through — `Column 'address_line1' cannot be null`. Any customer with no address on file could not be edited, which is most of them. It went unseen because the edit form had no way to open until it was wired up earlier today, which is also how PB-004's original update path stayed broken. The accessories version additionally leaked the raw SQL error to the user. Clearing a credit limit or payment terms hit it too. Reproduced on production with the exact modal payload (empty-body 500), and found the accessories twin by auditing the other edit screens: panel types validate `base_price` as required and fail readably, so they were fine. |
| **PB-042** | `High` | Deployed | Operations | **Server errors reached the browser as an empty 500, and were not being logged at all.** Found while chasing the customer-edit failure (PB-041): the crash returned HTTP 500 with no body, and `grep` of the live log found none of it. `storage/logs/laravel.log` on production was **root-owned, mode 0644**, last written 07:40 — while php-fpm runs as `panelos`. Every `docker exec ... php artisan` in the deploy and seed workflows runs as root, so anything they log creates the file root-owned, and `storage/` is a persistent volume so it survives every container recreate. The web workers therefore could not append to it. Worse, the app's catch-all handler calls `Log::error()` before building its `SERVER_ERROR` envelope, and the stack had `ignore_exceptions => false`, so the failed log write threw **inside the error handler** and the response died: no envelope, no support reference, no log line. Reproduced locally (an unwritable log path makes the exception escape the handler) before fixing. **Consequence for the whole of this project: since 07:40 on 2026-10-23 no web-request error has been logged on production, so the audit trail for every 500 reported in this session is gone** — they were diagnosed by reproduction instead. Fixed three ways: `ignore_exceptions => true` so logging can never kill a response; `permission => 0666` on the single channel so a root-created file stays writable; and the container entrypoint now chowns `storage/logs` to `panelos` on every start, repairing the file that is wrong today. Related and still open: the empty body for the original PB-004 customer 500 was a PHP fatal in the validation rules, a different cause. |
| **PB-038** | `High` | Deployed | Auth | **Users get signed out at random.** `refresh-token` deleted the old token, and AppShell refreshed on *every* page load — while the screen you had just opened was already fetching with that old token, and any second tab still held it. Reproduced on production with a throwaway account: the old token returned 401 the instant a refresh happened, and **2 of 40 simulated page loads** lost a request to it. The browser treats every 401 as an expired session and clears the token — which, because tabs share localStorage, also wipes the new one. Hard-refreshing made it more likely, not less. Fix: the old token now lives on for a 90-second grace window instead of being deleted (never lengthened by repeated refreshes), the browser retries once with the newer token when another tab has rotated it, and refreshing happens only when time is actually running low instead of on every load. |
| **PB-039** | `Medium` | Deployed | Auth | **Mistyping your current password signs you out.** `change-password` returned **401** for a wrong current password; the browser reads any 401 as a dead session. Now 422, and the browser only ends the session on a 401 carrying `error_code: UNAUTHENTICATED`. The mobile client copied the same any-401-logs-out rule, so this fixes its trigger too. |
| **PB-040** | `Medium` | Deployed | Auth / UX | **No warning before a session ends, and no explanation after.** Tokens last 12 hours from issue. The only renewal was a `setInterval`, which a sleeping laptop or background tab does not run, so a session could just lapse mid-work. On expiry the user was dropped to the *marketing landing page*, not the login form, with no message. Now: an active user is renewed quietly when under 30 minutes remain; someone idle sees a banner with a countdown and **Stay signed in** for the last 5 minutes; the check also runs the instant the tab becomes visible; and the login form says *"Your session expired"*. The warning is driven by a duration (`expires_in`) from the server, not a timestamp, so a wrong clock on the user's machine cannot make it fire early or never. Decision logic is a pure function with 9 tests. **Not covered: the mobile app has no session renewal at all** — it stops hard at 12 hours. |
| **PB-037** | `Medium` | Fixed – not deployed | Performance | **Four list screens loaded every row.** Purchase orders, suppliers, users and accounts receivable had no limit — a PO a day is 250 a year, all fetched and rendered at once. Nothing errored; the pages just got slower every month. Worse, AR called `getPaymentStatus()` per row (re-fetching the invoice and summing its payments), and `Invoice::$appends` exposes `remaining_due`, whose accessor sums `$this->payments` — so every `toArray()` lazy-loaded payments too. Two separate per-row queries on the one screen whose size grows with business. All four now paginate; AR keeps its full-scan ageing summary (it must, to be correct) but bounds the detail list; `payments` is eager-loaded. `ListPaginationTest` asserts the lists stay bounded and counts queries so the N+1 cannot come back. |
| **PB-036** | `High` | Fixed – not deployed | Frontend | **Every deploy breaks already-open tabs.** Screens are lazy chunks with content-hashed filenames; a deploy writes new hashes and deletes the old files. A tab opened before the deploy still holds the previous index chunk, so the first screen it opens afterwards requests a filename that 404s — `Failed to fetch dynamically imported module` — and the screen simply never renders. Reported live as "BOQ Register is not working"; the API was fine (200, 5 rows). Hits every user on every deploy, on whichever screen they open next. Fixed in the shared `lazy()` helper in AppShell: a chunk-fetch failure reloads the page once, stamped in sessionStorage so a genuinely missing chunk or an offline user cannot cause a reload loop. |
| **PB-035** | `Medium` | Fixed – not deployed | GST | **PB-005 was fixed in two places out of four.** `GstService::calculateGst()` still compared supplier and customer state as raw strings, so `/api/invoices/{id}/calculate-gst` read 'MH' vs '27' — the same state written two ways — as inter-state and reported IGST where the invoice itself charged CGST+SGST. `GstService::validateGstin()` had it too: it compared the GSTIN's numeric prefix against a caller-supplied alpha code, rejecting valid GSTINs as mismatched. Both now go through `App\Support\GstState`. Found by the Phase 6 tests, which is exactly what they are for. |
| **PB-033** | `Medium` | Fixed – not deployed | Inventory | **Opening stock is silently discarded on create.** `POST /api/stock/coils|chemicals|consumables` hard-codes `quantity_in_stock => 0` and does not even validate a supplied quantity, yet returns 201. A tenant entering opening stock sees success and gets zero — then every production start fails with *Insufficient stock* and no clue why. Found during Phase 1 on production. Arguably deliberate (stock should be booked in via `/add` so it writes a `stock_transactions` ledger row) but the silent discard is the trap. Fix: accept an optional opening quantity and book it **through `StockService`** so the ledger stays honest. |
| **PB-034** | `Low` | Cannot reproduce | Auth | `POST /api/auth/register` returned a bare **502** once, ~4 minutes after the Phase 0 container recreate, while `/api/health`, `/` and `/api/auth/login` were all healthy. A retry 12 minutes later succeeded (201 in 1.7s). Most likely a cold php-fpm worker right after the restart. Logged for pattern-matching; re-open if it recurs after a deploy. |
| **PB-030** | `Critical` | Deployed | Operations | **Nothing is scheduled.** `app/Console/Kernel.php` defines four jobs (nightly `db:backup --keep=14`, daily `reminders:send` 09:00, daily `sms:send-reminders` 10:00, weekly `reminders:cleanup`) but it is a Laravel 10 artifact — Laravel 11 does not load it, and `php artisan schedule:list` confirms *"No scheduled tasks have been defined."* Supervisord dutifully runs `schedule:run` every 60s and does nothing. **Consequence: there is no routine database backup** (dumps only happen at deploy time), and no automated payment or SMS reminders at all. Fix: port the four definitions into `routes/console.php` using Laravel 11's `Schedule` facade, then verify with `schedule:list`. |
| **PB-021** | `Medium` | Fixed – not deployed | Invoicing | Transport was never invoiced. The design question turned out to be the wrong one: freight is not fixed at quotation at all — the real cost depends on which vehicle is available and is renegotiated until the goods move, so the owner settles it **at dispatch**. `dispatches` had no transport fields, so there was nowhere to record it. Now: transport amount, transporter and vehicle on the dispatch (editable while pending), billed as an invoice line, with the quoted figure as a fallback when no actual was entered. Freight rides in the subtotal so it carries the same GST as the goods — the usual composite-supply treatment, worth confirming with the client's accountant. |
| **PB-022** | `Medium` | Deployed | PDF | **Re-prioritised 2026-10-23: measured on live, a quotation / BOQ PDF takes 8–13 s to generate (first byte at ~8–12 s, then ~1.5 s to download 1 MB) — this is the "system is not responding" wait.** Every generated PDF is ~1 MB (invoice 1,036 KB, challan 1,033, quotation 1,041, BOQ sheet 1,034 — within 8 KB of each other, which is the tell: it is a constant overhead, not content). **Corrected 2026-10-23 — this entry originally blamed the logo, which was an unmeasured guess and wrong.** Dissecting the live invoice PDF by object: **two whole font files 717 KB (69%)**, font metric dictionaries 117 KB (11%), logo 173 KB (17%), everything else 28 KB. Cause of the fonts: DomPDF defaults `isFontSubsettingEnabled` to `true`, but the Laravel wrapper's bundled `config/dompdf.php` overrides it to `false`, and this app never published its own config — so the whole DejaVu Sans and DejaVu Sans Bold files ride in every PDF. Cause of the logo cost: the PNG is 3381×1085 px but is displayed at max 200×46 px (~400× more pixels than shown); it is only 62 KB on disk because it is mostly flat colour, but DomPDF re-encodes it as a Flate image plus a separate alpha mask. Measured through the real wrapper with the real logo: as-is 1,030 KB (reproduces production's 1,036) → subsetting on 188 KB (-82%) → plus logo scaled to 400 px wide 28 KB (-97%). Fix is small: set `enable_font_subsetting` true in a published `config/dompdf.php`, and cap the longest edge when `HasImageDataUri` builds the data-URI (that trait is shared by Company, PanelType and Accessory). Still deferred — cost, not correctness. **Fixed 2026-10-23 — profiled before changing anything** (local, the real 3381×1085 live logo, 8-line quotation): as-is 3.0 s / 1,044 KB → font subsetting on 3.2 s / 217 KB → + logo shrunk to 600 px **0.76 s / 64 KB** → no logo at all 0.66 s. So the *time* was the logo (DomPDF decodes PNG transparency pixel by pixel in PHP; with the default 128 MB memory limit a PDF with this logo ran out of memory outright), and the *size* was the fonts. Live's CPU is ~3× slower than the dev machine, which matches the measured 8–12 s. Fix: `AppServiceProvider` turns subsetting on; `HasImageDataUri` shrinks any PNG/JPEG whose longest side exceeds 600 px (keeps aspect ratio and transparency) and caches the result against the file's modified time, so the resize is paid once. Originals are untouched; small images pass through byte-for-byte; an undecodable image never breaks a PDF. Test: `PdfWeightTest` (7). **Verified live 2026-10-23 (deploy `b3fd9b9`):** quotation PDF 8.1–11.4 s → **2.7–4.6 s**, BOQ sheet 10.6–13.3 s → **2.2–2.4 s**, file 1,024 KB → **73 KB** (BOQ 1,014 → 59 KB). Rendering identical (logo crisp, ₹ intact). What remains (~1.4 s) is the same overhead every API call has from the test machine (the dashboard takes 1.37 s), not PDF-specific. |
| **PB-023** | `Low` | Deferred | Tax | Quotations hard-code 18% while invoices read `TaxConfiguration`. They agree out of the box; they diverge the moment someone changes the invoice rate. |

### Production workflow

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-031** | `High` | Deployed | Production | **Stage tracking is decorative.** `BatchStageLogService::areAllStagesCompleted()` and `getNextStage()` are defined but **never called** — `completeBatch()` only checks `status === 'in_progress'`. A batch can be completed, QC'd and dispatched with **zero stage logs**. Sequence is enforced *if* you use stages; using them at all is optional. |
| **PB-032** | `Medium` | Deployed | Production | **No sign-off on any production step.** `batch_stage_logs` records only `logged_by_user_id` — the same operator starts and completes a stage. QC is the only approved artefact in the whole system (`approved_by_user_id` + `approved_at`). No rejection/rework path on a stage. |

### Feature requests

| ID | Priority | Status | Area | Title |
|---|---|---|---|---|
| **FR-001** | `High` | Deployed | Production | **Per-stage approval gate.** Client wants every production step signed off before the next begins. Depends on PB-031 (enforcement) and PB-032 (approver fields). Design in §5. |
| **FR-002** | `Low` | Deployed | Quotations | **Installation does not appear as a line item on the quotation PDF.** It is folded into `installation_amount` and shown only in the totals block ("Installation: ₹109,350"). The client's own proforma lists it as line 9 with quantity (364.5 MTR) and rate (₹300), so customers will notice the difference. Same applies to door/custom rows with no master accessory. Needs the installation rows kept as data (qty/rate/unit), not only a sum. Done with PB-045: the Ledger template prints installation with its quantity, unit and rate. Classic is unchanged (totals only). |
| **FR-003** | `Medium` | Deployed | Notifications | **Real in-app notifications (the header bell).** The bell was only a shortcut to Receivables with a dot computed once at page load — nothing stored, no read/unread, a stage awaiting approval told nobody, and the dot led to the wrong page for stock alerts. Now a real notification store with a dropdown, unread count, mark-read, click-through, and a 60 s poll. Phase N1 covers stage awaiting approval / approved / rejected, low coil and chemical stock, and overdue invoices; N2/N3 add the rest. Plan: [NOTIFICATIONS_PLAN.md](NOTIFICATIONS_PLAN.md). Tests: `AppNotificationsTest` (13). Deployed 2026-10-23 16:50 UTC and verified live: endpoints respond, own-rows-only 404 for an unknown id, `notifications:scan` registered hourly in the live scheduler. |
| **FR-004** | `Medium` | Deployed | UX | **No feedback while something slow runs.** Clicking BOQ "Sheet" (and every other PDF/export) sat silent for 8–13 s, so the system looked frozen — and because the new tab was opened only *after* the wait, browsers (pop-up allowance ≈5 s) could silently block it. Now: every file download shows a progress card (estimate while the server builds the file, real % while it downloads, then Ready); the tab opens immediately with a "preparing" page; a thin top bar shows for any other request slower than 300 ms. Covers quotation/BOQ/invoice/challan/template-preview PDFs, CSV + Tally exports, platform-admin invoices. Tests: `src/services/transfers.test.mjs`. **Does not make the PDFs faster — see PB-022.** |
| **FR-005** | `Medium` | Phase 1 of 4 deployed | Documents | **Three new quotation PDF templates — Ledger, Showcase, Executive.** Designs approved for review in the "Quotation Templates" artifact. Needs a foundation first: bank-details bug (the PDF reads `bank_account` / `ifsc_code`, which do not exist), installation as real lines (PB-045), `legal_name` / editable terms / document title on the company, delivery address and site contact on the quotation, and one presenter class so the templates are layout only. Plan and phases: [QUOTATION_TEMPLATES_PLAN.md](QUOTATION_TEMPLATES_PLAN.md). **Phase 1 built 2026-10-23: the Ledger template** (`quotations.pdf_ledger`), registered as `ledger`; Classic untouched, no migration, no tenant switched. Runs on today's data, so some design items wait for Phase 0: ISO line, legal name, site contact / delivery address, installation as qty×rate lines (prints one lump line), editable terms (system default wording), and the Classic bank-details bug is not yet fixed (Ledger reads the right fields). Tests: `LedgerTemplateTest` (12, UMA fixture reconciles to 911,476.00). **Phase 1 verified live 2026-10-23 (deploy `3e7c640`):** `ledger` is listed beside Classic; the tenant's selection is still `classic`; preview of quotation SCP-2027-012 renders in 3.7 s / 67 KB / 3 pages with "Page x of 3" and the correct totals (Classic preview: 3.1 s / 72 KB, unchanged). On live the bank block prints dashes because the company has no bank details saved (Settings → Company). **Phase 0 progress 2026-10-24 (built, not deployed):** 0.1 bank-details bug fixed on the Classic quotation and the invoice (they read `bank_account` / `ifsc_code`, which do not exist, so account number and IFSC always printed as a dash) — `BankDetailsOnPdfTest`; 0.3 company fields `legal_name`, `quotation_title`, `certification_line`, `quotation_terms`, `quotation_exclusions` + a Settings → Company card with an editor that starts from the standard wording — `CompanyDocumentSettingsTest` (8); 0.4 quotation `delivery_address`, `site_contact_name`, `site_contact_phone` in the form, detail page and Ledger PDF — `QuotationDeliveryTest` (5). Migrations: `…000003`, `…000004` (additive, nullable). |

### Production data — not code

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-024** | ~~`Critical`~~ | **Not a bug** | Data | `INV-2026-000001` — ₹3,77,100 part-paid, ₹0 GST, ~₹29,250 accessories unbilled. Owner confirmed 2026-10-23 that the Signature PUF Panel tenant holds **dummy data only**, so no credit note or reissue is needed. The audit report labelled this order "real"; that label was wrong. |
| **PB-025** | ~~`High`~~ | **Not a bug** | Data | `ORD-2027-001` stuck `pending`. Same reason — dummy data. PB-003 stops it recurring. |

> **Answered 2026-10-23.** PB-001 zero-rated GST on every invoice for every tenant, so the worry was that
> other tenants held wrong historical invoices. Measured from the production backup during the Phase 0
> restore rehearsal: **6 companies, 2 invoices in total across the whole platform**, both in the dummy
> tenant. There is no historical GST exposure to clean up. PB-001 is fully closed.

---

## 2. Test suite

Separate from the live defects: **test-code problems, not production problems.** Confirmed — every
production path that creates a company sets `subdomain` correctly. Worth fixing so the suite can catch the
next PB-001, but no customer sees any of this.

Baseline on MySQL: 418 tests, 152 passing, 266 failing/erroring.

| ID | Severity | Status | Area | Title |
|---|---|---|---|---|
| **PB-026** | `Medium` | Fixed – not deployed | Tests | 19 test files call `Company::create(['name' => 'Test Company'])` in `setUp()` without `subdomain` (`NOT NULL`), killing **all 184 tests** in those classes before any of them runs. Fails identically on MySQL *and* SQLite — this was never an engine difference. The other 82 failures are unrelated (PB-027/028/029). **Fixed** by dropping the NOT NULL on `companies.subdomain` — the subdomain concept is unused (tenancy resolves from `auth()->user()->company_id`, never the host), so the constraint bought nothing. **Measured yield: +3 passing, not +184.** It was the first of a stack of blockers in the same `setUp()` chains; the 184 now run and immediately hit the next one (see PB-027). |
| **PB-027** | `Low` | Fixed – not deployed | Tests | Missing factories, now the largest blocker: `Dispatch::factory()` undefined (**129 tests** — the model lacks `HasFactory` and there is no factory), `ProductionBatchFactory` missing (25), plus bare creates hitting NOT NULL `company_id` (25) and `name` (22). Expect the same pattern: each fix exposes the next layer. |
| **PB-028** | `Low` | Fixed – not deployed | Tests | Tests call `Coil::factory()` / `Chemical::factory()` — models renamed to `CoilStock` / `ChemicalStock` → 19 errors. |
| **PB-029** | `Low` | **Needs triage** | Tests | Stale payloads (`items` instead of `panel_rows`) and tests asserting **deliberately removed** behaviour — e.g. `DispatchTest` still tests coil allocation at dispatch, which was replaced by consumption at production start. Those should be **deleted, not fixed**; making them pass would mean re-implementing removed behaviour. |

---

## 3. Deployment log

| When | Commit | Carried | Outcome |
|---|---|---|---|
| 2026-10-23 15:46 UTC | PB-041 + PB-042 fixes | PB-041, PB-042 | ✅ Deployed and verified on production. **PB-041:** the exact payload that returned an empty-body 500 now returns 200 and saves. **PB-042:** provoked a harmless server error — the browser now gets a proper `SERVER_ERROR` envelope with reference `dea70508`, and that reference is in the live log with the file and line (`CustomerController.php:24`). `laravel.log` went from `root:root 0644` to `panelos:panelos 0666` and is growing again (44 KB → 64 KB). Note: no web-request errors were logged between 07:40 and this deploy, so the evidence for every 500 reported earlier today was lost; the only application errors that survive are two old `created_at` ones from the original audit. |
| 2026-10-23 15:30 UTC | session fixes (see git log) | PB-038, PB-039, PB-040 | ✅ Deployed and verified on production against the same experiment that failed before. **Before:** old token 401 the instant a refresh happened; 2 of 40 simulated page loads lost a request. **After:** old token 200 (grace window), **0 of 40**. Also confirmed live: login and refresh both report `expires_in=43200`; a wrong current password is 422 and leaves you signed in; and the old token is **dead (401 UNAUTHENTICATED) once the 90s grace window has passed** while the new one is unaffected. The new banner, login notice and retry-with-newer-token logic are all present in the served bundle. Not exercised from here: the banner itself on screen — that needs a real session left idle. |
| 2026-10-23 12:48 UTC | [`628942a`](https://github.com/Kamleshnishad/panelos/commit/628942a) | PB-031, PB-032, FR-001 | ✅ Deployed. Migration applied, `schema:check` clean, health OK. Verified live: enforcement and four-eyes both **off**, `requires_approval` exposed on all 6 of the tenant's stages, approve endpoint responding. Dormant until the tenant opts in at Settings → Company → Production Control. |
| 2026-10-23 12:35 UTC | — (verification only) | **Phase 1** | ✅ Full cycle walked on production as the live tenant: BOQ → quotation → order → run → QC → dispatch → invoice → payment → order closed. 36 checks, all passed. `GET /reports/reconciliation` for the new order: **ordered 69,974 = invoiced 69,974 = paid 69,974, gap 0** — the report that showed `revenue_leak: 128,725.88` before the fixes. Found PB-033 and PB-034 on the way. |
| 2026-10-23 12:16 UTC | [`c6a05c5`](https://github.com/Kamleshnishad/panelos/commit/c6a05c5) | PB-030 (+ PB-026 migration) | ✅ Deployed. Verified on the live container: `schedule:list` shows all four tasks with next-due times, and the supervisord `schedule:run` loop is running. **Restore rehearsed**: the deploy's own backup artifact was loaded into a scratch database — 773 statements, 0 failures, 65 tables, data intact. |
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

---

## 6. Test records created on production (Phase 1, 2026-10-23)

Left in place deliberately so the numbers can be re-checked. All are prefixed **ZZ TEST** and are safe to
delete once reviewed. Note the stock movements are real and would need manual `adjust` entries to reverse.

| Type | Identifier |
|---|---|
| Customer | id 9 — "ZZ TEST Cold Store (Claude E2E)", credit limit ₹50,00,000 |
| Quotation | **SCP-2027-008** (accepted) — 37.2 sqm + ₹5,000 installation |
| Order | **ORD-2027-003** (completed) — ₹69,974 |
| Production run | **RUN-2026-0002** (completed) |
| Batch | **BATCH-2026-000003** (dispatched) |
| Dispatch | **DISP-2026-000003** (delivered) |
| Invoice | **INV-2026-000003** (paid, ₹69,974 incl. ₹10,674 GST) |
| Payments | ZZ-TEST-1 ₹30,000 · ZZ-TEST-2 ₹39,974 |
| Coil stock | New row for panel type 9, +310 kg booked in, 300.78 kg consumed, **9.22 kg remaining** |
| Chemicals consumed | Polyol 79.68 kg · Isocyanate 87.65 kg · Film 38.70 sqm (incl. the 2% actuals overage) |
| Tenant (signup probe) | company id 7 — "ZZ TEST Signup Probe", trial. Created while retesting PB-034; **delete this one** |
