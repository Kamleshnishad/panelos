# 10 — Offline & Sync Strategy

## Why this matters more than usual

Two of the four Staff App roles work in genuinely unreliable-connectivity environments as their default condition, not an edge case: the factory floor ([03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §1–2, Wi-Fi coverage gaps around heavy machinery are common) and delivery routes ([03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §3, rural roads with patchy mobile data). If the app requires a live connection for every action, it fails its core users on day one. Offline handling is therefore core architecture (Phase 0.5), not a later hardening pass.

## Pattern: local-first outbox

Every mutating action from a field role (stage start/complete, QC submit, delivery confirm + proof, payment record) follows the same pattern:

1. **Validate locally** (Zod schema matching server validation rules where known) before anything else — catch obvious errors before they ever hit the queue.
2. **Write to the local outbox** (SQLite table, `expo-sqlite`) as a pending job: `{id, action_type, payload, created_at, status: pending|syncing|failed, retry_count}`.
3. **Optimistically update the UI** — the TanStack Query cache is updated immediately as if the server had already confirmed, so the user is never blocked staring at a spinner because they have no signal.
4. **Background sync worker** attempts to drain the outbox whenever connectivity is detected (`@react-native-community/netinfo` connectivity listener + periodic retry while app is foregrounded).
5. **On success:** outbox entry removed, cache reconciled with the real server response (in case the server computed something the optimistic update guessed at, e.g. a generated batch/QC record ID).
6. **On failure:** exponential backoff retry (cap at a sane max, e.g. 5 attempts over an hour), then mark `failed` and surface it to the user explicitly rather than retrying silently forever.

## Compound actions

Some actions are not a single API call — most notably **delivery confirmation with proof** ([05_DISPATCH_MODULE.md](05_DISPATCH_MODULE.md) Screen 3), which involves uploading 1–3 photos/signature *and* calling `complete`. These must be modeled as **one outbox job with multiple steps**, not two independent jobs — a delivery must never be marked complete without its proof having also synced (or vice versa: proof uploaded but completion never recorded). The sync worker executes the steps in order and only clears the job when the whole sequence succeeds; a partial failure (e.g. photo 2 of 3 fails to upload) retries the whole job, not just the failed step, to avoid subtle partial-state bugs.

## Conflict resolution

The server is always authoritative. The app does not attempt merge logic — if a stage was already completed by someone else before this device's queued action synced, the server rejects the duplicate/invalid transition and the app surfaces "This stage was already completed by [name] — refreshing" and re-syncs the real state rather than silently discarding the user's queued action without explanation. This is intentionally simple: production stage sequencing and dispatch status are workflows with a real single-writer expectation most of the time (one person owns a batch's current stage), so heavyweight conflict resolution (CRDTs, merge UI) would be over-engineering for the actual failure mode here.

## What is NOT queued offline

Read-only screens (lists, dashboards, reports) use TanStack Query's normal cache — they show the last-fetched data with a "showing cached data, pull to refresh" indicator when offline, but there's no queue involved since there's nothing to sync, just data to refetch when connectivity returns.

Quotation/order lifecycle actions (Send/Accept/Reject) and payment recording are **not** offline-queued in v1 — these are Sales/Accounts actions that happen in generally-connected environments ([03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §4–5), and queuing a payment record offline raises reconciliation questions (was this payment already recorded by someone else in the meantime?) that aren't worth solving for a low-frequency edge case. These actions simply show a clear "no connection, try again" state.

## Test matrix (run at Phase 5.1, and per-module at Phase 1.3/2.3)

| Scenario | Expected behavior |
|---|---|
| Airplane mode, complete a stage, re-enable connectivity | Action syncs automatically within the retry window, web app reflects the change |
| Airplane mode, complete a delivery with 3 photos, re-enable connectivity | All 3 photos + completion sync as one unit, nothing partial |
| Kill the app mid-sync (queued but not yet synced), reopen | Outbox persists (SQLite survives app restart), sync resumes |
| Two devices complete the same stage while both offline, both reconnect | Second sync attempt is rejected by the server with a clear conflict message, not a silent overwrite |
| Weak/flaky connection (not fully offline, intermittent) | Retries with backoff, doesn't spam the server or duplicate the action on retry (idempotency — see below) |

## Idempotency

Every queued mutation includes a client-generated idempotency key (UUID) in the request. Backend controllers for the actions used by field roles should either already be naturally idempotent (e.g. "complete a stage that's already complete" is a no-op or clear error, not a duplicate log entry) or need a small check added — flag this as a verification item during Phase 1.2/2.3 backend touchpoints, not a separate backend project.
