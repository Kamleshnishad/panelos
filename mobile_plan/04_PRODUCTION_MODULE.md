# 04 — Production Module (Floor Worker / Production Manager)

Phase reference: [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) Phase 1. Persona: [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §1–2. Web equivalent: [05_PRODUCTION_MODULE.md](../05_PRODUCTION_MODULE.md).

## Screen 1: Batch List

**Purpose:** the day's work, at a glance.

- **Data source:** `GET /batches` (real route) — filter client-side or via query params to `status=in_progress|qc_pending`.
- **Layout:** card list, one batch per card — batch no, order/customer, planned vs completed qty, status pill, QC badge if applicable.
- **Filters:** status chips (All / In Progress / QC Pending / Completed today). Production Manager sees all batches; Floor Worker's default filter is "assigned to my line" if the backend exposes a line/machine assignment — **note:** current schema does not appear to track per-user batch assignment; confirm during Phase 1.1 whether this needs a small backend addition or whether "all in-progress batches" is acceptable for v1.
- **States:** loading skeleton, empty ("No batches today"), error with retry, offline banner if list is stale (served from cache).
- **Interaction:** tap card → Batch Detail.

## Screen 2: Batch Detail

**Purpose:** the shop-floor screen — the one this module exists for.

- **Data source:** `GET /batches/{id}`, `GET /batches/{batchId}/timeline`, `GET /batches/{batchId}/progress`.
- **Layout:**
  - Header: batch no, order/customer, planned/completed quantity, overall progress bar (X/N stages).
  - **Vertical stage timeline** (mirrors the web design): each stage as a dot + connector — Raw Material Inspection → Sheet Cutting → Foam Injection → Curing → Quality Check → Packaging (exact stage list is company-configurable via `GET /production-stages`, do not hardcode).
  - In-progress stage: pulsing indicator + live elapsed timer (client-side ticking clock based on `started_at` from the server, not a device-local timer that drifts).
  - Completed stages: duration + any notes.
  - Locked stages (not yet reached): visually muted, "Complete previous stage first" — enforced by disabling the Start button, not just a warning, since this mirrors a real production sequencing rule.
- **Actions:**
  - **Start Stage** → `POST /batches/{batchId}/stages/{stageId}/start`. Optional notes field only — confirmed against the live `BatchStageLogController::startStage`, which validates just `{notes}`.
  - **Complete Stage** → `POST /batches/{batchId}/stages/{stageId}/complete`. **Correction (verified during Phase 1 build):** also just `{notes}` — `BatchStageLogController::completeStage` validates only `notes`. The checklist/parameters (e.g. Chemical Injection ratios) described in the older `03_API_ENDPOINTS.md` were never implemented; there is no structured-parameter storage on `BatchStageLog` at all (fillable: `batch_id, stage_id, status, started_at, completed_at, duration_minutes, notes, logged_by_user_id`).
  - **Attach photo** on any stage (e.g. a defect found mid-process) — new endpoint, see [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1. Not built in Phase 1; the stage-complete flow ships without it.
- **Offline behavior:** Start/Complete queue via the outbox ([10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md)) — **not built in Phase 1** (that phase's mutations call the API directly); the outbox lands in a later hardening pass per the roadmap.
- **Edge cases:**
  - Two people tapping "Start" on the same stage on different devices — the server enforces sequencing and rejects an invalid transition with a descriptive message (`BatchStageLogService::validateStageStartable`); the mobile app surfaces that real message rather than a generic failure.
  - **Bug found and fixed during Phase 1 build:** `BatchStageLogService::getBatchTimeline()` was eager-loading a relation (`loggedByUser`) that didn't exist on the `BatchStageLog` model (only `user()` does) — this would have thrown a 500 on every call to `GET /batches/{id}/timeline`. Fixed server-side to load `user` instead.

## Screen 3: QC Verdict (not a checklist)

**Purpose:** run and record the quality inspection when a batch reaches `qc_pending`.

**Correction (verified during Phase 1 build, supersedes the "QC Checklist" description below):** QC is **not** a multi-item checklist. `QualityControlController::createForBatch` validates only `{status: 'pass'|'fail', notes}`, and the `QualityControl` model has no `checklist_data` column — fillable is `batch_id, company_id, status, inspected_by_user_id, inspected_at, approved_by_user_id, approved_at, notes`. The 7-item toggle list in the original web design brief and the Artifact mockup was aspirational, not what the backend implements.

- **Data source:** `GET /batches/{id}/qc` (existing record, if any — 404 if none), `POST /batches/{id}/qc` (create), `POST /quality-control/{id}/approve`.
- **Layout:** two large Pass/Fail buttons (not a checklist) + a notes field. Once submitted, shown read-only with inspector name.
- **Actions:** Submit → creates the QC record, which also flips the batch's status to `qc_passed` or `qc_failed` server-side. **Approve** (gated on the `production.manage` permission key from `backend/config/permissions.php`, not a role-name guess) → `POST /quality-control/{id}/approve`. **The server refuses to approve a `fail` verdict** (`QualityControlService::approve` throws — failed QC requires rework, not approval) — the mobile UI hides the Approve button entirely for a fail verdict rather than showing an action that will always error.
- **States:** if a QC record already exists, show it read-only (verdict pill, notes, inspector) — no duplicate-submission path exists server-side anyway (`getForBatch` returns the one record per batch).

## Screen 4: QC Dashboard (Production Manager only)

**Purpose:** the trend view — mirrors the web QC dashboard but condensed.

- **Data source:** `GET /quality-control/statistics`, `GET /quality-control` (list).
- **Layout:** stat row (Total Inspections, Passed, Failed, Pass Rate % with a simple color-graded bar — green/amber/red per the existing web semantics), then a scrollable list of recent QC records with pass/fail badge and "View Batch" deep link.
- **Filter:** date range (default: last 7 days, matching the "quick chip" pattern used elsewhere in the app per [SYSTEM_OVERVIEW.md:125](../SYSTEM_OVERVIEW.md#L125)).

## Dashboard tile (shown on the shared Dashboard screen, see [11_PUSH_NOTIFICATIONS.md](11_PUSH_NOTIFICATIONS.md) for the alert side of this)

- "In Production" count, "QC Pending" count — both clickable straight into the filtered Batch List, matching the KPI-card pattern from the web dashboard ([SYSTEM_OVERVIEW.md:87](../SYSTEM_OVERVIEW.md#L87)).

## Explicitly not in this module

- Creating a new batch from an order — that's an office/planning action, stays on desktop.
- Editing the cutting schedule — desktop-only, dense tabular data.
