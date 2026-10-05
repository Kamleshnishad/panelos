# 05 — Dispatch Module (Dispatch Executive)

Phase reference: [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) Phase 2. Persona: [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md) §3. Web equivalent: [SYSTEM_OVERVIEW.md §5.7](../SYSTEM_OVERVIEW.md).

## Screen 1: Dispatch List

**Correction (verified during Phase 2 build):** dispatch status is a real 2-transition enum — `pending → delivered` or `pending → cancelled`. There is no `allocated` state; see Screen 2's correction for why.

- **Data source:** `GET /dispatches`. Note the response envelope differs from Production's: `DispatchController` uses the base `Controller::apiResponse()` helper (`{success, data, message}`, no `meta`), and `DispatchService::listDispatches()` returns `->paginate()` directly — so `data` **is** a raw Laravel paginator object (`{current_page, data: [...], total, per_page, last_page}`), not a plain array. Three different response shapes now exist across this app's endpoints (Auth, Production/QC, Dispatch) — always verify against the live controller, never assume one convention.
- **Layout:** card per dispatch — dispatch no, linked batch, expected delivery date (overdue flag if past due), status pill (pending/delivered/cancelled).
- **Filters:** status chips.
- **States:** standard loading/empty/error, same pattern as [04_PRODUCTION_MODULE.md](04_PRODUCTION_MODULE.md).

## Screen 2: Dispatch Detail

**Correction (verified during Phase 2 build):** `DispatchService::allocateStockForDispatch()` is a hard-coded no-op (`return [];`) — raw coil stock is now consumed at production-run start, not at dispatch, so the `/allocate` endpoint is kept only so the route doesn't 404, not because it does anything. **There is no Allocate Stock step** — a `pending` dispatch goes straight to Mark Delivered.

- **Data source:** `GET /dispatches/{id}`.
- **Layout:** info grid (batch no, customer address, tracking number, expected delivery date), items table (panel type, qty, computed line amount). `total_amount`/`total_items` are PHP accessors on the `Dispatch` model, not serialized JSON fields (not in `$appends`) — compute the total client-side by summing `items[].amount`/`items[].quantity`, same as the accessor does server-side.
- **Actions:**
  - **Mark Delivered** → `POST /dispatches/{id}/complete`. Only accepts `{actual_delivery_date}` server-side — no notes or photo/signature field exists yet (same backend gap as Production's QC photo, [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1). Screen 3 below (photo/signature proof) describes the target design, not what Phase 2 shipped — see its correction note.
  - **View Challan** → `GET /dispatches/{id}/challan/pdf`. This route sits behind Sanctum auth, so it cannot be opened via a plain `Linking.openURL`/browser redirect (no auth header would be sent) — fetch it through the authenticated API client as bytes, write to the device filesystem, then hand it to the OS share/view sheet (`expo-file-system` + `expo-sharing`).

## Screen 3: Delivery Confirmation (Photo + Signature) — target design, not yet built

**Not shipped in Phase 2** — the backend `complete` endpoint only accepts `actual_delivery_date` today, so Phase 2's Mark Delivered is a plain confirm action with no capture step. This section describes the target once the photo/signature backend work in [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1 ships.

**Purpose:** the proof-of-delivery moment — this is the single highest-value screen in the whole app for cash-flow impact, since delivery confirmation currently happens hours late and delays invoicing.

- **Flow:**
  1. Dispatch Executive taps "Mark Delivered."
  2. Camera opens (`expo-camera`) — capture 1–3 photos of the delivered goods/site.
  3. Optional: signature pad (`react-native-signature-canvas`) for the receiving customer contact to sign on-screen.
  4. Delivery notes field (optional free text — "left with security guard," etc.).
  5. Submit → images compressed client-side (`expo-image-manipulator`, target ~200–500KB per photo to keep uploads viable on weak mobile data) → uploaded to the new delivery-proof endpoint ([09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1) → `POST /dispatches/{id}/complete` called once upload succeeds (or queued together if offline — see below).
- **Offline behavior:** this is the most likely screen to be used with zero signal (rural delivery routes). Photos and signature are stored locally (device filesystem, referenced by the outbox entry) and the full "complete + attach proof" action queues as a single atomic outbox job — it must not be possible for the completion to sync without its proof, or vice versa. See [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md) §"Compound actions."
- **Failure handling:** if upload fails repeatedly (e.g. image too large, storage quota), surface a clear error rather than silently dropping the delivery confirmation — a lost delivery record blocks invoicing downstream.

## Screen 4: Challan Viewer

- **Data source:** `GET /dispatches/{id}/challan/pdf`.
- **Layout:** read-only PDF view, with a "Share" action (native share sheet) so the driver can hand a digital copy to the customer if needed.

## Explicitly not in this module

- Creating a dispatch from a batch — that's a planning/office action tied to picking a QC-passed batch, stays on desktop for now (could revisit if pilot feedback says otherwise).
- Editing dispatch line items — desktop-only.
