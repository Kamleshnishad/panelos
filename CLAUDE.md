# PanelOS — working notes for Claude

Multi-tenant manufacturing ERP for PUF/PIR sandwich-panel factories.
Laravel 11 API (`backend/`) + Vue 3 SPA (`frontend/`) + Expo React Native app (`mobile/`).

**[BUGS.md](BUGS.md) is the bug register** — every known defect with an id, severity and status.
Check it before investigating anything; add a row when you find something new, and move the row to
`Fixed – not deployed` → `Deployed` as it progresses. Never delete a row.

**[SYSTEM.md](SYSTEM.md) is the system reference** — architecture, every flow end to end, state
machines, the calculation formulas, the error catalogue and the known gaps. Read it before changing
business logic, and update it when a state transition, the permission map or a BOM formula changes.

---

## Testing — always MySQL

**Production runs MySQL/MariaDB. Tests must run on the same engine.** `backend/phpunit.xml` is already
set to `DB_CONNECTION=mysql`, `DB_DATABASE=panelos_test` (both `force="true"`).

```bash
cd backend
php vendor/bin/phpunit --no-coverage                 # whole suite
php vendor/bin/phpunit --filter SomeTest --no-coverage
```

One-time setup (host, port and credentials come from `backend/.env`):

```sql
CREATE DATABASE panelos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Rules:

* **Never point a test run at `panelos_dev`.** `RefreshDatabase` wipes whatever database it is given.
  `DB_DATABASE` is forced in `phpunit.xml` precisely so a stray `.env` cannot cause that — leave it forced.
* **Do not switch the suite back to SQLite.** It hid real bugs: SQLite has no `REGEXP` (which made the
  whole quotation/order numbering path unrunnable), and it is lax about strict-mode inserts and column
  types — the integer `quantity` column that silently over-billed fractional square metres passed every
  SQLite test.
* After a schema change, verify the **actual** resulting MySQL schema, not just that the migration ran:
  `SHOW COLUMNS FROM <table>` plus an `information_schema.REFERENTIAL_CONSTRAINTS` check if foreign keys
  are involved. MySQL/MariaDB refuses to redefine a column an FK points at, so such migrations must drop
  the constraint, change the column, and re-add it (see
  `2026_10_23_000001_fix_invoice_dispatch_item_precision_and_non_panel_lines.php`).
* When fixing a bug, **capture a baseline first** (`git stash` → run suite → `git stash pop`) and compare
  the failing *set*, not just the count. ~24 tests in the legacy suite already fail because they post
  long-superseded payloads (`items` instead of `panel_rows`) or call routes that no longer exist; they are
  unrelated to current work. A fix is clean when it adds zero newly-broken tests.

Every bug fix gets a feature test under `backend/tests/Feature/`. Useful traits:
`Tests\Traits\HasDatabaseTestHelpers` (`createAdminUser()`) and `Tests\Traits\HasAuthTestHelpers`.

## Frontend

```bash
cd frontend
npm run dev      # Vite on :5173, proxies /api and /storage to 127.0.0.1:8000
npm run build    # always run this before calling a frontend change done
```

## Conventions that matter

* **All business logic lives in `app/Services/`.** Controllers validate, authorize and shape the response —
  nothing else. State transitions and invariants belong in the service, where every caller hits them.
* **Tenancy is structural.** `BaseModel` + `BelongsToTenant` scope every read to `company_id` and fill it
  on create. A cross-tenant id therefore returns 404, not 403. Use
  `Model::withoutGlobalScope('tenant')` only deliberately.
* **Guarded company columns** (`subscription_plan`, `subscription_status`, `trial_ends_at`, `is_active`)
  are not `$fillable` and must be written with `forceFill()->save()`, only from
  `TenantProvisioningService`, `SubscriptionService` or `SuperAdminController`. `update()` is
  mass-assignment and will silently drop them.
* **Money:** copy a stored `amount` rather than recomputing `quantity × unit_price` — rates are weighted
  averages and recomputation drifts by paise. Quantities carrying square metres are `decimal(12,4)`.
* **GST:** one helper, `App\Support\GstState`, decides intra- vs inter-state, and both `QuotationService`
  and `TaxService` use it. State codes are stored as the 2-digit GST code (`24`); `GJ`/`Gujarat`/a GSTIN
  all normalise to it. Never compare `state_code` strings directly.
* **No SQL that only MySQL has in portable paths** was the old reason to avoid `REGEXP`; now that tests run
  on MySQL this is less critical, but prefer plain queries plus PHP-side matching for document numbering.
* API responses use the `ApiResponse` envelope (`success`, `data`, `message`, `error_code`). Domain
  rejections are 400/422 with the real message — never a generic 500.

## Git

Private repo `Kamleshnishad/panelos`. Commit before risky changes; `backup.bat` commits and pushes.
Never commit `backend/.env`.
