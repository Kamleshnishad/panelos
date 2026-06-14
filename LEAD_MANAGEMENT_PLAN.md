# Lead / Inquiry Management — Development Plan

**Goal:** Sales funnel ka top add karo — Inquiry aaye → Lead banao → qualify/follow-up → **Convert to Quotation** (existing flow) → Order. Abhi system Quotation se shuru hota hai; Lead uske pehle ka step hai.

**Funnel:** `Inquiry → Lead (new → contacted → qualified) → Quotation (quoted) → Won/Lost`

## Research takeaways (B2B/manufacturing CRM)
- **Lead status** = next action (new/contacted/qualified/quoted/won/lost); keep it simple for an SME.
- **Source tracking** zaroori (Website/WhatsApp/Referral/IndiaMART/Exhibition/Walk-in…) — kaunsa channel kaam kar raha hai.
- **Fast follow-up wins deals** → har lead pe `next_follow_up_date` + "aaj/overdue follow-up" alerts.
- **Activity log** — har call/note/email record ho (interaction history).
- **One-view**: intake + assignment + history ek jagah.
- **Convert lead → quote directly** (prefill details), back-link rakho.

## Safety rules (taaki kuch toote/error na aaye)
1. **Pure additive** — naye tables (`leads`, `lead_activities`) + naya screen + nav item. Existing quotation/order/stock ko nahi chhedna.
2. **BaseModel rule** (recent bug se seekha): dono naye tables mein `company_id` + `deleted_at` honge → BaseModel safe. Child `lead_activities` bhi company_id rakhega.
3. **Quotations table ko touch nahi** — link sirf `leads.quotation_id` se (one-way), quotations mein koi column nahi add.
4. Migrations **absolute path** se; har phase ke baad **migrate + build + authenticated smoke-test (`Auth::setUser`) + field-vs-column audit** green hone par hi aage.
5. Har phase = **ek git commit** (restore point).

## Data model

### `leads`
`id, company_id, lead_no (LEAD-2026-0001), contact_name, company_name, phone, email, city, source(enum), requirement(text), application(nullable), est_qty_sqm(nullable), est_value(nullable), status(enum: new/contacted/qualified/quoted/won/lost), assigned_to_user_id(nullable), next_follow_up_date(date nullable), lost_reason(nullable), customer_id(nullable), quotation_id(nullable), notes, timestamps, softDeletes`

### `lead_activities`
`id, company_id, lead_id, user_id(nullable), type(enum: note/call/email/whatsapp/meeting/status_change), description, activity_date, timestamps, softDeletes`

---

## Phase 1 — Core Lead CRUD + list  ✅ DONE
> `leads` table + Lead model (BaseModel, has company_id+deleted_at) + LeadService + LeadController + routes /leads. LeadManager.vue (status tabs, source/search filter, create/edit modal, detail slide-over with status flow + edit/delete). Nav "Leads" (top of Main) + overdue-follow-up red badge. Verified: authed create/status/list, field-vs-column none, build green.
- Migration: `leads` table.
- `Lead` model (BaseModel; has company_id+deleted_at), `LeadService`, `LeadController`, routes `/leads` (index/show/store/update/destroy + status change).
- Frontend: `LeadManager.vue` (Manager pattern) — list (filter by status/source/assigned, search), create/edit modal (all fields), detail view. Nav item **"Leads"** in Main group (Quotations ke upar — funnel order).
- Status as a coloured badge; source dropdown; assigned-to (users list).
- **Verify:** migrate, authed create/list test, field audit, build.

## Phase 2 — Activity log + follow-ups  ✅ DONE
> `lead_activities` table + LeadActivity model; `LeadService::addActivity` + status changes auto-log a `status_change` activity; `getDetails` loads activities.user; `POST /leads/{id}/activities`. Frontend: detail drawer activity timeline + log-activity form (note/call/email/whatsapp/meeting). Follow-up overdue/today highlight + nav badge (Phase 1). Verified: field mismatch none, 2 activities logged, build green.
- Migration: `lead_activities`.
- Add activity (note/call/email/whatsapp/meeting) on lead detail; status changes auto-log an activity.
- `next_follow_up_date` → "Today" / "Overdue" indicators + a **nav badge** (overdue follow-up count), like the Production Planner alert badge.
- Lead detail timeline (activities, newest first).
- **Verify** + commit.

## Phase 3 — Convert to Quotation / Customer  ✅ DONE
> `LeadService::ensureCustomer` (create/link customer from lead — mirrors all NOT-NULL customer columns) + `linkQuotation`; `POST /leads/{id}/convert`; `QuotationService::create` accepts optional `lead_id` and back-links the lead (status→quoted, quotation_id) — additive, normal quotation flow unaffected. Frontend: "→ Convert to Quotation" in drawer → AppShell sets prefill → QuotationManager opens QuotationCreate prefilled (customer + lead_id) → on save lead linked. **Also fixed latent CustomerController bug** (passed null to NOT-NULL address/state/pincode columns → would crash the new-customer modal; now ''). Verified: convert→quote→lead quoted, normal quote still works, all models field-audit clean.
- "Convert" on a qualified lead → pick existing customer OR create one (reuse `quotationService.createCustomer`) → open existing **QuotationCreate** prefilled (customer + requirement note) → on save set `lead.status = quoted`, `lead.quotation_id`, `lead.customer_id`.
- "Mark Won" (auto when its quotation→order) / "Mark Lost" (with `lost_reason`).
- Quotations table untouched — only `leads` stores the link.
- **Verify** (incl. that quotation flow is unaffected) + commit.

## Phase 4 — Lead dashboard / insights  ✅ DONE
> `LeadService::dashboard` (funnel by status, win-rate, by-source conversion %, top lost reasons, follow-up overdue/today/7-day) + `GET /leads/dashboard` (registered before /leads/{id}). Frontend: List/Insights toggle in LeadManager → KPI cards + funnel bars + source table + follow-ups + lost reasons. Verified + build green.
- Funnel counts (new→won), source-wise conversion %, this-week follow-ups, win/loss reasons.
- Endpoint + a small dashboard section.

## Rollback
Har phase ka apna git commit. Toote → `git revert <commit>`. DB → migration `down()`. Naye tables drop ho jaayenge, baaki system untouched.
