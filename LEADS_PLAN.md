# Lead management plan

Source: the lead audit (https://claude.ai/artifact/2Ziqx4euz5GNqBytCzVrUM, finding ids `LD-`, `LC-`, `LP-`, `LI-`, `LX-`, `LE-`).
Goal: leads arrive by themselves (IndiaMART first), nothing is lost or duplicated, every lead has a next action, and the owner can see what brings money in.

## How we work through it

**"next" means: build the next sub-phase.** Each sub-phase is one small pull request that is useful on its own, tested (backend suite
on MySQL, schema check, a browser run for screens) and safe to deploy without the later ones. For each one I will:

1. build it and open the PR, CI green;
2. tell you in plain words what changed, what it cannot break, what I verified and what I could not;
3. **wait for your "merge"** (every merge deploys to production; an earlier yes never carries over);
4. after the deploy, check it on the live site and update this file, `BUGS.md`, `SYSTEM.md` and the lead audit page.

If you want it faster, say "next and merge as you go" once and I will merge each PR when CI is green and then verify live. Say "stop merging" to go back.
A sub-phase that needs something from the client says so first; where there is a sensible default I use it and say so, so "next" never blocks.

Rules for every sub-phase: additive migrations only (no data loss, rollback possible), tests first for anything that moves money or status,
production commands as `panelos`, no e-mail or message sent to real customers by a build or a test.

## Order and why

```
Phase 0  safety net            tests that pin today's behaviour, so we can change it
Phase 1  make the numbers true  fix status, won, duplicates, convert, reminders      (nothing new flows in yet)
Phase 2  the inbound door       one intake service, API, assignment, alerts, log       (still no outside channel)
Phase 3  IndiaMART              first real channel, on top of the door
Phase 4  other channels         website form, import, WhatsApp, Meta, portals           (each optional, independent)
Phase 5  working the lead       tasks, messages, quotation hand-off, board              (what salespeople use daily)
Phase 6  insight                lead-to-cash, forecast, source ROI, performance
Phase 7  field and polish       mobile, quick add, privacy review
```

Phases 1 to 3 are the core. 4 to 7 can be reordered by what the client says matters.

## Progress

| Sub-phase | What | Status |
|---|---|---|
| 0.1 | Lead lifecycle tests | not started |
| 1.1 | Lists as data (sources, lost reasons) | not started |
| 1.2 | Status engine | not started |
| 1.3 | Automatic won, lost-reason dialog | not started |
| 1.4 | Duplicate detection | not started |
| 1.5 | Convert fixes | not started |
| 1.6 | Numbering race and small hardening | not started |
| 1.7 | Follow-up reminders | not started |
| 2.1 | Source detail and raw payload | not started |
| 2.2 | Lead intake service | not started |
| 2.3 | Inbound API, tokens, import log | not started |
| 2.4 | Assignment rules and instant alert | not started |
| 2.5 | Lead sources screen | not started |
| 3.1 | IndiaMART settings and connection test | not started |
| 3.2 | IndiaMART pull job | not started |
| 3.3 | Connector health and alerts | not started |
| 3.4 | IndiaMART go-live | not started |
| 4.1 | Website enquiry form | not started |
| 4.2 | Spreadsheet import and export | not started |
| 4.3 | WhatsApp enquiries | not started |
| 4.4 | Meta lead ads, Google Ads | not started |
| 4.5 | Justdial, TradeIndia, ExportersIndia | not started |
| 4.6 | Missed-call leads | not started |
| 5.1 | List filters, saved views, search, address | not started |
| 5.2 | Next actions and my day | not started |
| 5.3 | Call, WhatsApp, e-mail from the lead | not started |
| 5.4 | Requirement lines and one-step quote | not started |
| 5.5 | Quotation chasing | not started |
| 5.6 | Stale leads and response time | not started |
| 5.7 | Attachments | not started |
| 5.8 | Merge, bulk actions, board, stages, scoring | not started |
| 6.1 | Lead-to-cash tracing | not started |
| 6.2 | Pipeline forecast | not started |
| 6.3 | Funnel and lost analysis | not started |
| 6.4 | Salesperson performance | not started |
| 6.5 | Source ROI | not started |
| 6.6 | Daily lead digest | not started |
| 7.1 | Mobile leads | not started |
| 7.2 | Quick add and lead screen polish | not started |
| 7.3 | Privacy and consent review | not started |

---

## Phase 0: safety net

### 0.1 Lead lifecycle tests (LD-13)
- **Goal:** pin what the lead module does today so later changes cannot break it unnoticed. No behaviour change.
- **Build:** feature tests for create, assign, status moves with the timeline entry, activities, convert (customer created or linked), quotation link flips to quoted, dashboard figures, follow-up badge count, soft delete, lead numbering, and "company B cannot see or touch company A's leads" on every endpoint.
- **Live check:** none needed (tests only). **Size:** S. **Needs from client:** nothing.

## Phase 1: make the numbers true

### 1.1 Lists as data: sources and lost reasons (LD-14, part of LD-09)
- **Goal:** a new channel or a new lost reason no longer needs a database migration.
- **Build:** `source` and `status` columns change from MySQL enums to plain strings (two-step, existing values kept); validation moves to code; tables `lead_sources` and `lead_lost_reasons` per company, seeded with today's nine sources and a starter list of reasons (price, competitor, no budget, timing, not genuine, no response); Settings screen to edit them; `lead_lost_reason_id` beside the existing free text (kept for old rows).
- **Tests:** every old value still validates; a custom source works; another company's list is separate; schema verified on MySQL.
- **Live check:** existing leads still list and filter. **Size:** M. **Needs from client:** nothing (they can edit the lists later).

### 1.2 Status engine (LD-02, LD-03, LP-07 statuses)
- **Goal:** every status change goes through one method that logs it and obeys rules.
- **Build:** `LeadService::transition()`; allowed moves defined in one table; reopening needs a reason and clears the lost reason; the Edit form can no longer change status; a new quotation moves a lead forward only while it is still open; new statuses **invalid** (junk, excluded from statistics) and **on hold** (with a return date); status change endpoint keeps working for the screen.
- **Tests:** each allowed and refused move; a won lead stays won when a second quotation is made; timeline entry for every change including via Edit attempts.
- **Live check:** move a test lead through the ladder on the live screen, then delete it. **Size:** M. **Needs from client:** nothing.

### 1.3 Automatic won and the lost-reason dialog (LD-01, LD-09)
- **Goal:** the win rate becomes true without anyone remembering a click.
- **Build:** when the lead's quotation is accepted or its order is created, the lead becomes **won** and the timeline says why; a one-off backfill command with a dry run that lists the leads it would change (nothing changes until you approve the list); the browser pop-up for "lost" becomes a proper dialog with the reason list from 1.1.
- **Default I will use unless told otherwise:** "won" = an order has been created from the lead's quotation. Other options the client may prefer: quotation accepted, or advance received.
- **Tests:** accept and order paths, double events do nothing twice, closed leads are not reopened, backfill dry run changes nothing.
- **Live check:** dry-run the backfill on the live data and read the list together. **Size:** M. **Needs from client:** the definition of won (default above).

### 1.4 Duplicate detection (LD-05)
- **Goal:** the same buyer is one lead, not three.
- **Build:** `LeadMatcher` (normalised phone and e-mail, ignoring spaces, +91 and punctuation); on create and edit the screen shows "this looks like LEAD-… (assigned to …), open it or create anyway"; the API returns the matches; the same matcher will be used by every importer later.
- **Tests:** phone formats, e-mail case, different companies never match, soft-deleted leads are not matched.
- **Live check:** try a duplicate on the live screen. **Size:** M. **Needs from client:** nothing.

### 1.5 Convert fixes (LD-04)
- **Goal:** converting a lead never creates a bad or duplicate customer.
- **Build:** before creating a customer, look for an existing one by phone, e-mail, GSTIN or name and offer to use it; ask for the state (or take it from the GSTIN) so the quotation's GST split is right; use the customer screen's code sequence instead of a random number; carry city and address.
- **Tests:** existing customer reused, state code set, code unique, GSTIN-derived state.
- **Live check:** convert a test lead. **Size:** M. **Needs from client:** nothing.

### 1.6 Numbering race and small hardening (LD-07)
- **Goal:** two leads created at the same instant never fail.
- **Build:** the same retry-on-conflict helper as quotations and invoices; decide whether the sequence restarts each year (default: keep counting, as now, so existing numbers are untouched).
- **Tests:** a simulated collision. **Size:** S. **Needs from client:** nothing.

### 1.7 Follow-up reminders (LD-06)
- **Goal:** a follow-up date that actually tells someone.
- **Build:** the hourly scan creates a bell notification for the assignee when a follow-up is due today and again when it is overdue; the owner is told about leads overdue for more than N days (N set in Settings, default 3); optional WhatsApp or e-mail through the existing channels, off by default and switchable.
- **Tests:** one notification per lead per day, none for won or lost or on-hold leads, assignee only.
- **Live check:** a test lead with today's date shows in the bell. **Size:** M. **Needs from client:** whether WhatsApp reminders should be on.

**Release point:** after 1.7 the lead module is trustworthy. Nothing outside has been connected yet.

## Phase 2: the inbound door

### 2.1 Where did it come from (LC-11)
- **Build:** columns `external_id` (unique per company and source), `campaign`, `utm_*`, `first_seen_at`; table `lead_payloads` keeping the raw incoming message out of the main screens.
- **Tests:** uniqueness, nullable for manual leads. **Size:** S. **Needs from client:** nothing.

### 2.2 Lead intake service (LE-01 core)
- **Goal:** one function every channel calls.
- **Build:** `LeadIntakeService::ingest($company, $source, $payload)`: normalise, match (1.4), then either create a lead or add an activity to the existing one ("enquired again on IndiaMART about …"); idempotent on `external_id`; keeps the raw payload; returns what it did. The manual form keeps its own path but shares the matcher.
- **Tests:** new, repeat, same id twice, malformed payload, other company's data never touched.
- **Size:** M. **Needs from client:** nothing.

### 2.3 Inbound API with tokens and an import log (LC-02, LE-02, LE-03, LC-12 part)
- **Goal:** anything outside can create a lead safely.
- **Build:** `POST /api/inbound/leads/{token}` (token is long and random, only its hash is stored, shown once, can be regenerated); tenant comes from the token only; rate limit per token, size cap, hidden spam field, clear 4xx and 429 answers; `lead_import_logs` (time, source, created, duplicates, errors) written for every request; a simple log screen.
- **Tests:** a token for A cannot create or read anything in B; wrong, revoked and rate-limited tokens; payload too large; idempotent retry.
- **Live check:** post a test lead with curl to the live URL, see it, delete it. **Size:** M. **Needs from client:** nothing.

### 2.4 Assignment rules and instant alert (LC-10, LD-08 part)
- **Build:** rules in Settings (default: none, leads stay unassigned as today): round-robin over chosen people, or by city, product or source; the assignee gets a bell notification and, if enabled, a WhatsApp message with name, product and a call link; reassign with history.
- **Default I will use:** manual assignment until the client picks a rule. **Tests:** rotation order, skipped inactive users, rule precedence, no alert to the wrong person. **Size:** M. **Needs from client:** the assignment rule.

### 2.5 Lead sources screen (UI for 2.1 to 2.4)
- **Build:** Settings page: sources list, inbound token (copy, regenerate), a ready-to-copy example request, assignment rules, the import log. **Size:** S to M. **Live check:** screenshot walk-through.

**Release point:** the door exists and is protected; no channel is connected yet except manual and API tests.

## Phase 3: IndiaMART

### 3.1 Settings and a connection test (LC-01)
- **Build:** an IndiaMART card (CRM key stored encrypted, shown as set or not, never returned); a "test connection" that pulls a tiny recent window and shows the count only, creating nothing.
- **Tests:** key never in any response or log; failure messages readable. **Needs from client:** the CRM key (Lead Manager, CRM Integration in their seller account) and their plan. **Without it:** built and tested against a fake IndiaMART.

### 3.2 The pull job (LC-01)
- **Build:** scheduled every five minutes; asks for the period since the last success minus a safety overlap; maps IndiaMART's fields (to be confirmed against IndiaMART's current documentation first) to the intake service with source IndiaMART and `external_id` = IndiaMART's enquiry id; manual "pull this date range" button; runs as the app user so the lock problem of PB-062 cannot recur.
- **Tests:** overlap does not duplicate, repeat enquiry becomes an activity, rate limit respected, job survives an API error.
- **Size:** M.

### 3.3 Connector health (LC-12)
- **Build:** import log entry per run; alert to the owner when no successful pull for an hour or the key is rejected; warning before the 15-day idle expiry; a line on `/api/health` and on the uptime check.
- **Tests:** each alert condition. **Size:** S.

### 3.4 Go-live with the client
- **Build:** nothing new: a checklist. Generate the key with the client, run a dry pull and compare the list with the IndiaMART app, then switch the connector on, optionally backfill the last seven days, watch the first day together.
- **Live check:** compare counts for a day against IndiaMART. **Size:** S (mostly sitting with the client).

**Release point:** IndiaMART enquiries appear on their own, de-duplicated, assigned and alerted.

## Phase 4: other ways in (independent; do the ones the client uses)

- **4.1 Website enquiry form (LC-03):** embed snippet with spam protection, page and campaign captured, posts to the inbound API. **S to M.**
- **4.2 Spreadsheet import and export (LC-09):** template, dry run listing rejects and duplicates, undo of the last import, export of the filtered list. **M.**
- **4.3 WhatsApp enquiries (LC-07):** Twilio inbound webhook creates a lead or adds to an existing one, optional auto-reply. **M.** Needs the client's WhatsApp number setup.
- **4.4 Meta lead ads and Google Ads (LC-04, LC-05):** Meta webhook plus Graph read (needs a Meta app review and the page admin), Google form webhook to the inbound API. **M each.** Only if they advertise.
- **4.5 Justdial, TradeIndia, ExportersIndia (LC-06):** first ask each for a partner API; otherwise an inbound e-mail address with a parser per portal, with a visible "could not read this e-mail" list. **M.**
- **4.6 Missed-call leads (LC-08):** cloud-telephony webhook, callback task. **M.** Optional.

## Phase 5: working the lead

- **5.1 List filters, saved views, search and address (LD-10, LX-02, LD-12, LD-08):** source, assignee, overdue filters on screen; sorting; saved and ready-made views (my overdue, due today, new from IndiaMART, unassigned); "my leads" default for sales roles and an optional own-leads-only permission; leads in the header search; `/leads/12` addresses. **M.**
- **5.2 Next actions and my day (LP-01, LX-05):** tasks with type, due date and time, assignee, reminders; completing one prompts the next; a "my day" list. **M.**
- **5.3 Call, WhatsApp and e-mail from the lead (LP-02):** tap-to-call, WhatsApp templates, e-mail through the company mailbox, each logged on the timeline. **M.**
- **5.4 Requirement lines and one-step quote (LP-03, LP-04):** structured requirement (panel type, thickness, application, area, accessories) prefilling the quotation rows; "Quote this lead" creates the draft. **M.**
- **5.5 Quotation chasing (LP-05):** follow-ups at +3 and +7 days and before the validity ends, as tasks. Uses the "sent" event; richer once quotations can be e-mailed from the system (FT-02). **M.**
- **5.6 Stale leads and response time (LP-10):** first-response time recorded, alert after N quiet days. **S.**
- **5.7 Attachments (LP-12):** drawings, photos, BOQ files on a lead, carried to the quotation. **M.**
- **5.8 Merge, bulk actions, board, stages, scoring (LP-08, LP-09, LP-11, LP-06):** merge two leads, select-many actions, board view, editable stages with probabilities, hot/warm/cold and a score. **L, split in sub-PRs when we get there.**

## Phase 6: insight

- **6.1 Lead-to-cash tracing (LI-07, part of LI-02):** lead number on the quotation, order and invoice; revenue by source and campaign. **S.**
- **6.2 Pipeline forecast (LI-01, LD-11):** expected close date, stage probabilities, value by stage from the latest quotation total. **M.**
- **6.3 Funnel and lost analysis (LI-04, LI-05):** stage-to-stage conversion, days per stage, lost value by reason, source and person. **S.**
- **6.4 Salesperson performance (LI-03):** leads, response time, activities, quotations, wins per person; owners and managers only. **M.**
- **6.5 Source ROI (LI-02):** monthly spend per channel, cost per lead and per order. **M.**
- **6.6 Daily lead digest (LI-06):** morning message to the owner. **S.**

## Phase 7: field and polish

- **7.1 Mobile leads (LX-01):** today's follow-ups, quick add (with a photo of a card), log a call or visit, call and WhatsApp buttons, push for a new assigned lead. **M to L.**
- **7.2 Quick add and lead screen polish (LX-03, LX-04):** three-field quick add, create-from-search, linked quotation shown inline, edit and delete own notes. **S.**
- **7.3 Privacy and consent review (LE-04):** record source and basis, keep marketing messages apart from enquiry replies, opt-out, delete on request. Confirm specifics with the client's adviser. **M.**

## Things that can change the order

- The client's answers (definition of won, assignment rule, channels used, IndiaMART key) decide 1.3, 2.4, Phase 4 and 3.1.
- If IndiaMART access is slow to arrive, 3.1 to 3.3 are built against a fake and 3.4 waits; Phase 5 can run meanwhile.
- Partial dispatch (PR #12) and quotation e-mailing (FT-02) are separate tracks and do not block this plan.
