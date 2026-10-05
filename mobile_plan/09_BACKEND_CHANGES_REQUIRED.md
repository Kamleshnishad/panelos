# 09 — Backend Changes Required

Everything here is **new work on the Laravel backend**, confirmed necessary by direct inspection of the current code (not assumed from the design docs). Three independent gaps, each blocking a different part of the mobile plan.

---

## §1. Photo / Attachment Upload (blocks Phase 1.5, Phase 2.3)

### Current state
No route in `backend/routes/api.php` handles photo upload for batch stages or dispatch delivery confirmation. The design docs mention `upload-photo` and `upload-delivery-photos` but these were never implemented — confirmed by grepping the live route file for `photo|signature|upload`, which returned no matches.

### What to build

**New migration:**
```
attachments
├── id
├── company_id
├── attachable_type   (e.g. App\Models\BatchStageLog, App\Models\Dispatch)
├── attachable_id
├── type              (enum: qc_photo, stage_photo, delivery_proof, signature)
├── file_path
├── uploaded_by (user_id)
├── created_at
```
A single polymorphic `attachments` table (rather than one-off columns on each model) keeps this extensible — QC photos, stage photos, and delivery proof are all "a file attached to some record," and future needs (e.g. attaching a photo to a stock adjustment) reuse the same table instead of another migration.

**New endpoints:**
```
POST /batches/{batchId}/stages/{stageId}/attachments   (multipart, type=stage_photo|qc_photo)
POST /dispatches/{id}/attachments                       (multipart, type=delivery_proof|signature)
GET  /batches/{batchId}/stages/{stageId}/attachments
GET  /dispatches/{id}/attachments
```

**Storage:** use Laravel's existing filesystem disk configuration (check `backend/config/filesystems.php` for whether local or S3-compatible storage is already configured for other uploads, e.g. company logos — reuse that disk, don't introduce a second storage backend).

**Validation:** image mime-type whitelist (jpeg/png/webp), max size (recommend 5MB pre-compression — the app compresses client-side to ~200–500KB per [05_DISPATCH_MODULE.md](05_DISPATCH_MODULE.md), but the server must not trust the client and should enforce its own limit).

**Effort:** ~1 week (migration, model, controller, validation, basic tests).

---

## §2. Push Notification Pipeline (blocks Phase 4.1)

### Current state
`NotificationDispatchService` ([backend/app/Services/NotificationDispatchService.php](../backend/app/Services/NotificationDispatchService.php)) dispatches email, SMS, and WhatsApp only — confirmed by reading the service, which references `whatsapp_no`/phone-based channels with no device-token or FCM logic anywhere in the codebase.

### What to build

**New migration:**
```
device_tokens
├── id
├── user_id
├── token          (FCM registration token)
├── platform        (ios | android)
├── last_seen_at
├── created_at
```

**New endpoint:**
```
POST /notifications/device-token   { token, platform }   — called on login and on token refresh
DELETE /notifications/device-token — called on logout, to stop sending push to a signed-out device
```

**Service change:** add a `pushChannel()` method to `NotificationDispatchService`, following the same "no-op if not configured" pattern already used for the other channels (per the doc-comment at the top of that file). Wire it into whatever currently triggers the existing notification events (low stock, expiring chemical, overdue invoice, QC failed, etc. — confirm the exact trigger points by reading `NotificationSettingsController` and the events referenced in `GET /settings/notification-configs/events`).

**Firebase setup:** requires a Firebase project, server key stored in `backend/.env` (never committed — same discipline as the existing `.env` rule in the root [README.md](../README.md)), and the `kreait/laravel-firebase` package (or direct FCM HTTP v1 API calls) added to `composer.json`.

**Effort:** ~1 week.

---

## §3. Customer/Dealer Portal Auth (blocks Phase 6, all of Phase 7)

### Current state
`User` model is exclusively for internal staff — fillable fields are `company_id, name, email, phone, password, role_id, is_company_admin, is_active` ([backend/app/Models/User.php](../backend/app/Models/User.php)), with no concept of an external/customer user. `Customer` model has contact fields (`email`, `whatsapp_no`, etc.) but no password, no auth guard, no login capability at all — confirmed by grepping the whole backend for `portal|customer_login|CustomerAuth`, which returned no matches outside of e-invoice-related files (a false positive on the word "invoice").

**This is the one piece of the whole plan that is genuinely new backend architecture, not just new endpoints on existing patterns — treat it with proportionate care.**

### What to build

**New migration:**
```
customer_users
├── id
├── customer_id      (FK to customers — one login can represent one customer account)
├── phone
├── email (nullable)
├── password (nullable if OTP-only flow is chosen)
├── is_active
├── last_login_at
├── created_at
```

**New Sanctum guard:** register a `customer` guard in `backend/config/auth.php` pointing at `customer_users`, distinct from the default `web`/`sanctum` guard used by staff `User` records. This is the mechanism that makes cross-access structurally impossible rather than just "not currently allowed" — a portal token authenticates as a `CustomerUser`, which cannot resolve to a `User` no matter what, so any staff-only controller that type-hints `User $user` simply cannot be satisfied by a portal token.

**New middleware:** a `portal.active` (or reuse `tenant.active` with a portal-aware check) ensuring a suspended customer or a customer whose company access has been revoked can't keep using a portal token.

**New routes, isolated route group:**
```php
Route::prefix('portal')->group(function () {
    Route::post('/auth/login', [PortalAuthController::class, 'login']); // or request-otp/verify-otp
    Route::middleware(['auth:customer'])->group(function () {
        Route::get('/orders', [PortalOrderController::class, 'index']);
        Route::get('/orders/{id}', [PortalOrderController::class, 'show']);
        Route::get('/dispatches', [PortalDispatchController::class, 'index']);
        Route::get('/invoices', [PortalInvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [PortalInvoiceController::class, 'show']);
    });
});
```
Each new Portal* controller is a **thin, read-only wrapper** around existing business logic — it should call the same underlying query/service the staff `OrderController`/`DispatchController`/`InvoiceController` use, just pre-scoped with `->where('customer_id', $authCustomer->customer_id)` and stripped of any field the customer shouldn't see (e.g. internal cost/margin data if that's ever added to an order response). Do not duplicate the business logic — duplicating it is exactly the kind of drift that causes the portal to show stale/wrong data later when the staff-side logic changes and nobody remembers to update the portal copy.

**Invite flow — depends on the open decision (Phase 6.1):**
- If admin-issued: add a "Generate Portal Access" action to the existing Customer detail screen on the web app (small addition, not a new module), which creates the `customer_users` row and sends credentials via the existing SMS/email infrastructure.
- If self-serve OTP: add `POST /portal/auth/request-otp` (phone must match an existing `Customer.phone` or `whatsapp_no`) and `POST /portal/auth/verify-otp`, reusing `SmsController`'s existing send capability ([backend/routes/api.php:382-386](../backend/routes/api.php#L382-L386)).

**Effort:** ~3.5 weeks total (schema + guard + endpoints + isolation testing) — this is the single largest backend chunk in the whole plan, which is why it's sequenced after the Staff App ships rather than in parallel.

### Isolation testing (non-negotiable before Phase 6 is considered done)
1. A valid portal token must receive `403`/`401` on every single `/api/v1/*` staff route.
2. A valid portal token for Customer A must never be able to view/derive data for Customer B's orders/dispatches/invoices, including by guessing sequential IDs (route-level scoping must be enforced server-side on every portal endpoint, not just hidden client-side).
3. A revoked/suspended `customer_users` row must be immediately rejected on the next request, not just on next login.

Full security framing: [12_SECURITY_COMPLIANCE.md](12_SECURITY_COMPLIANCE.md).
