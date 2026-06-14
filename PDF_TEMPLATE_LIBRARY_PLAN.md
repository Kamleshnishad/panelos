# PDF Template Library — Development Plan (BOQ / Quotation / Invoice)

**Goal:** Har document type (Quotation, BOQ sheet, Invoice) ke liye **multiple PDF design templates** ho. Admin ek choose kare (preview dekh ke), aur wahi template us company ke PDFs mein use ho. Templates **code se** banenge (fixed library); admin sirf select karega.

## Current state (grounded)
PDF yahan se bante hain (DomPDF):
- Quotation → `quotations.pdf` (QuotationPdfService) — data: `$quotation` (items.sizes, panelType, accessories, customer, company loaded)
- BOQ sheet → `quotations.boq_sheet` (QuotationPdfService) — data: `$quotation`, `$company`
- Invoice → `invoices.pdf` (InvoiceService) — data: `$invoice`, `$total`

Abhi har doc ka **ek hi hardcoded blade** hai. Feature = us ek ko "default template" bana ke, uske bagal aur templates add karne dena + selection.

## Core design (clean + zero-disturbance)

1. **Template registry** — `config/document_templates.php`:
   ```
   'quotation' => [ 'classic' => ['name'=>'Classic','view'=>'quotations.pdf','desc'=>'…'] , 'modern' => […] ],
   'boq'       => [ 'classic' => ['name'=>'Classic','view'=>'quotations.boq_sheet'] , … ],
   'invoice'   => [ 'classic' => ['name'=>'Classic','view'=>'invoices.pdf'] , … ],
   ```
   Naya template add karna = blade file banao + yahan ek line. (Tum yahi karoge.)

2. **Selection storage** — chhoti table `company_document_templates` (company_id, doc_type, template_key, unique[company_id,doc_type]). (settings-JSON ke बजाय table — clobber-safe, clean.)

3. **Resolver** — `DocumentTemplateService::viewFor($companyId, $docType, $fallbackView)`:
   chosen key → registry view; **agar kuch select nahi → `$fallbackView` (current blade)**. → jab tak admin choose na kare, **bilkul aaj jaisa hi** chalega (zero change).

4. **Refactor 3 generation points** to use resolver (default = current view). Data variables **same** rehte hain.

5. **CONTRACT (important):** ek doc-type ke saare templates ko **same data variables** milte hain (`$quotation` / `$invoice` / `$company`+`$total`). Naya template usi contract pe code hoga.

6. **DomPDF limitation (jaan lo):** templates **DomPDF-compatible HTML/CSS** mein hone chahiye — flexbox/grid nahi chalta, **tables + inline-block** use karna padta hai (jaise current blades). Images base64 (GD chahiye). Ye constraint har naye template pe lagega.

## Phases

### Phase 1 — Framework (no visible change)  ✅ DONE
> `config/document_templates.php` (classic = current blades); `company_document_templates` table + model; `DocumentTemplateService` (viewFor with View::exists guard + fallback, setTemplate, listForCompany); QuotationPdfService + InvoiceService refactored to resolve view (default = current). Verified: defaults resolve to current blades, selecting classic unchanged, DI OK. Existing PDFs untouched.
- `config/document_templates.php` (existing blades registered as `classic`).
- Migration `company_document_templates` + model + `DocumentTemplateService`.
- Refactor QuotationPdfService (quotation + boq) & InvoiceService(pdf) to `viewFor(...)` with current views as fallback.
- **Verify existing PDFs byte-identical** (default still = classic). Commit.

### Phase 2 — Settings API + UI
- `GET /document-templates` (registry + current selection), `PUT /document-templates` (save {doc_type, template_key}).
- Frontend "Document Templates" screen (under Settings): per doc-type cards (name + desc), current highlighted, **Apply**.

### Phase 3 — Preview before apply
- `GET /document-templates/preview?doc_type=&template=&id=` → renders that template (override resolver) with the **latest real record** of that type (or "create a record first" if none) → streams PDF.
- UI: **Preview** button per template → opens PDF in new tab / iframe modal.

### Phase 4 (ongoing, yours)
- Naye design templates banao: blade file + registry line. Auto-available to choose.

## Complexity & risk
- **Framework complexity: Medium (3/5).** Resolver + table + 3 refactors + settings UI + preview.
- **Risk: LOW** — kyunki default fallback = current view; jab tak koi select na kare, kuch nahi badalta. Existing PDFs untouched.
- **Real effort** har naye *design* ka (HTML/CSS) — woh tum karoge; framework ek baar banega.

## Safety rules (same as before)
- Additive; default = current; multi-tenant scoped; new table BaseModel-safe (company_id + deleted_at) or plain Model.
- Har phase: migrate + build + authed test + **existing PDF still renders** check + commit.

## Rollback
Per-phase git commit. Resolver hata do / fallback hi rahe → current behaviour. Table drop → no effect.
