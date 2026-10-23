# Quotation PDF templates — plan to build the three approved designs

Designs (Ledger, Showcase, Executive) and the field-by-field data map are in the "Quotation Templates"
artifact: https://claude.ai/artifact/Fca6x2PHs7d3dyYLGFWHYg. Tracked as **FR-005** in [BUGS.md](BUGS.md).
Reference: SYSTEM.md (Document Templates), `config/document_templates.php`,
`app/Services/DocumentTemplateService.php`, `resources/views/quotations/pdf.blade.php` (the current "Classic").

## How the system already works

A template is a Blade view plus one line in `config/document_templates.php`. The company picks one in
Settings → Doc Templates; `DocumentTemplateService::viewFor()` falls back to Classic if the choice is missing, so
**adding a template can never break PDF generation**. Preview renders the tenant's latest quotation. Classic stays
untouched as the safe default.

## Principles

1. **Templates are layout only.** All logic moves into one presenter, `QuotationDocumentData`, that returns
   ready-to-print blocks. Three templates then cannot drift apart, and the business rules are unit-tested once.
2. **DomPDF-safe CSS only** — tables, no flex/grid/calc, DejaVu fonts (₹ needs them; Serif is bundled).
3. **One template per release**, each verified on live before the next starts.
4. **Every template passes the same gate:** valid PDF, under 400 KB, renders in about 3 s, expected text present,
   visually compared against its artboard.

## What differs between the artboards and a real PDF

The artboards are three fixed pages with a hand-placed "continued" line. Real quotations have any number of lines,
so the PDF **flows**: page 1 carries the letterhead; later pages repeat the table header and a running footer
(reference + "Page x of y"); the totals block and each line stay together (`page-break-inside: avoid`); terms start
on a new page. DomPDF cannot give continuation pages a different header from page 1, so the slim running header in the
artboards becomes the running footer. "Page x of y" is new work: it is added after rendering through the canvas API.

---

## Phase 0 — Foundation (ships on its own and already improves Classic)

| Step | Work | Why |
|---|---|---|
| 0.1 | **Bank-details bug fix.** Read `bank_account_no`, `bank_ifsc`, `bank_branch` (the template reads two names that don't exist, so Account No and IFSC print as "—"). | Visible bug on every quotation today. One-line fix. |
| 0.2 ✅ deployed | **Installation (and other non-master) lines stored as rows** — resolves **PB-045** and FR-002. First the quick fix so editing never wipes installation, then a `quotation_lines` table for installation / custom / non-master door rows (qty, unit, rate, amount, SAC), shown in the edit form, detail page, PDF and invoice. Existing quotations are backfilled with one lump line. | All three designs show installation as a real line. The master-accessory pivot cannot hold it (unique key, and the join drops rows with no master). |
| 0.3 | **Company fields:** `legal_name`, `certification_line` (the "ISO 9001:2015" text), `quotation_title` (Proforma Invoice / Quotation), `quotation_terms` and `quotation_exclusions` (editable lists, with placeholders such as `{advance_amount}` `{valid_until}`). Settings UI to edit them. Defaults: the system's current wording for everyone; the client's own wording seeded for the Signature PUF Panel tenant. | Removes text typed into the template; the "pay to" name differs from the trading name. |
| 0.4 | **Quotation fields:** `delivery_address`, `site_contact_name`, `site_contact_phone` — form, validation, copied on revise and duplicate. | The client's own sheet carries both; there is nowhere to store them today. |
| 0.5 | **`QuotationDocumentData` presenter.** Builds: company block, buyer block (address lines, contact, PAN, place of supply), project/delivery block, numbered lines (panel / accessory / door / installation with specs, sizes, HSN, image), totals, amount in words (reuse `InvoiceService::amountInWords`), GST summary by HSN/SAC, payment schedule, resolved terms, signatory, bank. Handles the conditional cases: discount, IGST, revision tag, fixing system, DL panels under 2000 mm, fixed transport, missing image. | The single source for all templates. |
| 0.6 | **PDF infrastructure:** shared footer partial with "Page x of y", repeating table header, page-break rules, and a **test harness that renders every registered template** with a fixture quotation (valid PDF, size, page count, key strings, time). | Gate used by every later phase. |

Tests: presenter unit tests (rounding of the per-HSN tax split must reconcile to the totals exactly — the UMA figures
are the fixture: 772,437.50 / 139,038.75 / 911,476), field round-trip tests, the template harness.

## Phase 1 — Ledger (Template 1)

**Status: built, not deployed (2026-10-23).** Built ahead of Phase 0 on today's data so nothing existing changes: new files only (`QuotationDocumentService`, `config/quotation_terms.php`, `quotations/pdf_ledger.blade.php`, one registry line, an opt-in page-number step), Classic untouched. Still to come from Phase 0: ISO line, legal name, site contact / delivery address, installation as real lines, editable per-company terms, Classic bank-details fix.

Closest to Classic, so the lowest risk and the proof of the foundation. Blade `quotations/pdf_ledger.blade.php`,
registered as `ledger`. Build from the artboard; compare a rendered PNG against it page by page; deploy; check
the live preview (time, size, ₹, logo, a quotation with many lines so it really flows to a second and third page).

## Phase 2 — Executive (Template 3)

Reuses Ledger's table structure with the serif headings, double rules, clause table and seal box. Extra checks:
column widths line up across pages, `border-style: double` renders, DejaVu Serif embeds with subsetting on.

## Phase 3 — Showcase (Template 2)

Last because it carries the most CSS risk: cards, the summary band, the thickness badge, and **product images**
(shrunk to 600 px by the PB-022 fix). Extra checks: with and without panel-type images, long descriptions, and
the payment-step blocks.

## Phase 4 — Polish (after all three are live)

Thumbnails in the template chooser; use the company brand colour (`primary_color`) for the accent, with a picker in
Company settings if none exists; offer the same three styles for the invoice and the BOQ sheet.

## Each template phase follows the same checklist

1. Blade built from the artboard, one line added to `config/document_templates.php`.
2. Harness green; feature test for the template choice (a tenant that selects it gets that view).
3. Local render compared with the artboard.
4. Full-suite comparison (no newly failing tests), commit, push.
5. After deploy: preview on live, timing and size, a PDF opened and read. The tenant's selected template is
   **not changed** until you say so.

## Risks

* DomPDF layout limits — mitigated by the harness and by comparing renders, not just by testing text.
* Render time — every template must stay near 3 s on the server (the PB-022 baseline); the gate measures it.
* Existing quotations lacking the new fields — every new field is optional and the presenter hides empty blocks.

## Decisions needed

1. **Title:** "Proforma Invoice" or "Quotation" as the default (becomes a company setting either way).
2. **Order:** Ledger → Executive → Showcase as above, or another order.
3. **Cadence:** deploy after Phase 0 and after each template (recommended), or batch.
4. **Installation first:** do 0.2 as the opening step (recommended — it also closes PB-045, which is live-exposed).
5. **Default for new tenants:** keep Classic, or one of the new three.
