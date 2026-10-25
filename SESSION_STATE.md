# Session state: resume here

Saved 24 Oct 2026 (evening IST) when the owner switched Claude accounts. Read this first, then `CLAUDE.md`, `LEADS_PLAN.md`, `BUGS.md`.
Nothing secret is in this file or anywhere in the repo or memory: the Signature admin password, the Zoho mailbox password and live session tokens
were shared in chat only. They are not stored. Ask the owner if a live login is needed (live tokens last 12 hours).

## Where things stand

**Live production** (https://pufpanel.digitalwebweaver.com, tenant Signature PUF Panel, company_id 6) runs `main` up to `f1f904f`
(Merge: test e-mail can go to a typed address). `/api/health` at 17:20 UTC: status OK, database OK, scheduler OK, **backup OK**, 0 errors, 0 failed jobs.

**Deployed and verified live today (all merged by the owner's explicit yes):** CI test gate and green suite (EN-01/02), tenant-neutral Classic quotation (BG-01/02),
company mailbox for customer e-mail with main + notifications mailboxes, admin routing table, test e-mail to a typed address (FR-007/008), health + error alerts +
uptime workflow + off-server backup workflow (EN-03/04/05, needs `BACKUP_PASSPHRASE` secret), audit log coverage (EN-06), Indian digit grouping on documents and
e-mails (UX-06), page addresses (UX-01), global header search (UX-04), the scheduler/backup permission fix (PB-062).
System audit page: https://claude.ai/artifact/7vrGKYzm34XzBnNumWNrPi (10 of 53 done, 3 waiting on the owner).

**Open on GitHub, not merged:**
- **#12** `ft-03-partial-dispatch` (FR-006): several loads per batch. Waiting for the client's answers: is "pieces" the right unit, and when are accessories/installation billed (default: first invoice). Changes a DB index (unique on `dispatches.batch_id` replaced by a plain one).
- **#16** `leads-0-1-lifecycle-tests`: tests only. Superseded by the local branch below; leave it.

**Local only, by the owner's decision (24 Oct):** branch **`leads-program`** (commits on top of `main`): 0.1 lifecycle tests (25), the protocol change, and
**1.1 lists as data** (migration `2026_10_25_000001_lead_lists_as_data`, `LeadListService`, `LeadListController`, `LeadListsSettings.vue`, 13 tests; backend suite 662/662,
schema check matches, migration verified on MySQL against enum-era data, rollback and re-run clean). **Nothing for the lead programme is pushed or deployed until the whole
plan is complete**; then push once, let CI run on MySQL 8, review, merge and deploy (recommend releasing in batches). Local `main` is one commit ahead of `origin/main`
(the LEADS_PLAN.md docs commit); do not push it on its own (a docs push redeploys production).

## How to continue the lead programme

The owner says **"next"**. Each "next" = build the next sub-phase from `LEADS_PLAN.md` on branch `leads-program`, run the local gates, commit locally, report. Gates: full backend
suite (`cd backend; php -d memory_limit=1G vendor/bin/phpunit --no-coverage`), `DB_DATABASE=panelos_test php artisan schema:check` when the schema changed, frontend `npm run build`
and `npm test`, a headless-browser run for screens (see "Tools"), and update `LEADS_PLAN.md` progress, `SYSTEM.md`, `BUGS.md` (row **FR-009** is the programme row) in the same commit.

**Done locally:** 0.1, 1.1 lists as data, 1.2 status engine, 1.3 automatic won + lost dialog, 1.4 duplicate detection, **1.5 convert fixes** (state/GSTIN, look-alike customers, `Customer::nextCode`, PB-063), 1.6 numbering race, **1.7 follow-up reminders**. **Phase 1 is complete**, then 2.1 source detail and raw payload, 2.2 lead intake service, **2.3 inbound API with tokens and import log, **2.4 assignment rules and instant alert, **2.5 lead intake screens**. **Phase 2 is complete**, then **3.1 IndiaMART settings and connection test** (against a fake; the plan's IndiaMART facts were corrected from IndiaMART's own help page: key idle expiry 7 days, poll every 10 to 15 minutes). Then **3.2 the IndiaMART pull job** (against the fake; every 10 minutes, off until a company switches it on), then **3.3 connector health** (bell alert for admins, banner, `lead_pull` on `/api/health`), then **4.1 website enquiry form** (`/lead-form.js` embed, landing page on the lead, allowed websites per address, too-fast check), then **4.2 spreadsheet import and export** (check, import, undo the last, export; PB-067), then **5.1 list filters, views, own leads, search and addresses** (PB-068), then **5.2 next actions and my day**, then **5.3 call, WhatsApp and e-mail from the lead** (click-to-chat, company mailbox, message templates), then **5.4 requirement lines and one-step quote** (calls `QuotationService::create`, the quotation and BOQ code is unchanged), then **5.5 quotation chasing** (the hourly scan reads sent quotations and plans next actions; the quotation code is unchanged). Browser runs for 1.2 to 1.5, 2.1, 2.3, 2.5, 3.1, 3.2, 3.3, 4.1, 4.2, 5.1 to 5.5. (The old 1.7 browser script now fails on a HomeDashboard mock gap that is unrelated to the leads work.)

**Next sub-phase:** see the progress table in `LEADS_PLAN.md` (3.4 IndiaMART go-live needs the client's real key and waits for the release; 4.3 to 4.6 are only for channels the client uses (WhatsApp, Meta/Google ads, Justdial/TradeIndia, missed calls: ask which); Phase 5 continues with 5.6 stale leads and response time; the e-mail part of 5.3 only sends once the client's mailbox is set up in Customer Messaging). Open: PB-065 (client address behind the proxy), PB-066 (stacked throttle counts twice, low). Open items found on the way: PB-065 (client address behind the proxy: verify an `audit_logs.ip` value on the live server). Use the `sd` dialog pattern in `LeadManager.vue` for any screen that needs a reason or date.

Defaults agreed unless the client says otherwise: "won" = an order was created from the lead's quotation (1.3); no automatic assignment until they choose a rule (2.4);
the IndiaMART connector is built against a fake until the client's CRM key exists (3.x). Needs from the client are listed in the lead audit page:
https://claude.ai/artifact/2Ziqx4euz5GNqBytCzVrUM.

## Rules the owner set (do not relitigate)

- **Merge and deploy only on an explicit "yes" for that PR.** Every merge to `main` deploys to production. Say what can break and what was verified. See memory `production-stability-first`.
- **Lead programme: everything local until complete** (above).
- Production commands as `panelos` (`docker exec -u panelos ...`), never root. Tests always on MySQL (`panelos_test`); never touch `panelos_dev`.
- Never skip or loosen a test; `backend/tests/ci/known-failures.txt` stays empty. Every fix gets a feature test.
- Amounts printed with `App\Support\Inr::fmt()`, never `number_format()` (a test enforces it).
- No e-mail or message to real customers from a build or a test. The owner enters mailbox passwords in the app themselves.

## Waiting on the owner or the client

- **Zoho mailbox**: the owner is testing Settings → Customer Messaging (main mailbox, optional notifications mailbox, test button with a typed address). If a test fails, they paste the message shown and I diagnose. Advised: use a Zoho app password and rotate the one pasted in chat.
- **`BACKUP_PASSPHRASE`** repo secret (EN-05). The nightly off-server backup refuses to run without it.
- **Rotate the live Signature admin password** (it was shared in chat).
- **Check tomorrow morning:** the 01:30 (server time, UTC) nightly dump exists and is owned by `panelos`, `/api/health` still shows `backup: OK` (PB-062 fix proof).
- **Client questions for partial dispatch** (#12) and for the lead programme (IndiaMART plan/key, volumes, assignment rule, response target, channels, what counts as won).
- A stray empty folder `E:\tmp_mig_hold` (created by mistake while testing a migration) could not be removed by me; the owner can delete it.

## Remaining audit items (system audit, 43 left)

High: FT-01 forgot-password and FT-02 e-mail quotations (both need the mailbox, now available), FT-03 partial dispatch (#12), FT-04 credit notes. Medium worth doing: BG-06 cost
permission not enforced (verify), EN-11 dependency scanning, EN-12 security headers (verify live), EN-08 record-level permissions, BG-05 dates in IST, FT-06 discount limits, FT-07 bulk import,
FT-10 remaining bell notifications, UX-02 responsive check, UX-03 accessibility. Low: BG-07/08/09/10/11/12/13, EN-13/14, FT-13 to 17, UX-05/07/08/09. Full list with details is on the audit page.

## Tools and practical notes

- Windows, Git Bash. **Write PHP/Python/JS files with the Write tool**: bash heredocs break on quotes and backslashes. When patching existing files normalise CRLF to LF, edit, convert back (see any `patch_*.py` pattern).
- Browser checks: Playwright for Python was installed with `pip install --target <scratchpad>/pw playwright`; Chrome at `C:/Program Files/Google/Chrome/Application/chrome.exe`; the scripts mock `/api/**`
  with `page.route`. The scratchpad folder is per session, so the scripts (`routing_e2e.py`, `two_e2e.py`, `lead11_e2e.py` and others) may not exist in a new session; rewrite from the pattern.
  Start the Vite dev server with `npm --prefix frontend run dev -- --port 5199 --strictPort`, stop it with a PowerShell `Get-CimInstance Win32_Process` filter on `port 5199`.
- Live checks without a login: `/api/health`, fetch `index.html` then the hashed chunk to confirm a screen shipped, and unauthenticated endpoints answering 401. A live login needs fresh credentials from the owner.
- Production log and scheduler checks are read-only manual workflows (`check-logs.yml`, `check-scheduler.yml`); `gh workflow run`.
- Test helper traps: `createAdminUser()` makes a platform super admin that bypasses the tenant scope (use `User::factory()->create(['company_id' => ..., 'is_company_admin' => true])` for isolation tests); `$this->post()` is a TestCase method; MySQL 8 sorts JSON keys (use `assertEquals` on decoded JSON).
