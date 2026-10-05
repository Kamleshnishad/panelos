# 11 — Push Notifications

Backend pipeline spec: [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §2. Phase: [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) Phase 4.

## Notification catalog

| Event | Recipients | Channel(s) today | Add push? | Deep link target |
|---|---|---|---|---|
| Stock below reorder level | Production Manager, Accounts (if covering inventory) | none confirmed / in-app only | Yes | Stock Alerts tile |
| Chemical expiring/expired | Production Manager | none confirmed / in-app only | Yes | Stock Alerts tile |
| Invoice overdue | Accounts, Sales Manager (own customer) | Email/SMS/WhatsApp reminder (manual trigger) | Yes — passive alert, reminder itself stays a manual action | Invoice Detail |
| Quotation expiring soon | Sales Manager | none confirmed | Yes | Quotation Detail |
| QC failed | Production Manager | none confirmed | Yes | Batch Detail / QC Checklist |
| Batch reached QC-pending | Production Manager | none confirmed | Yes | Batch Detail |
| Dispatch allocated to me | Dispatch Executive | none confirmed | Yes | Dispatch Detail |
| Payment received | Sales Manager (own customer), Accounts | Email/SMS confirmation (existing `sendPaymentConfirmation`) | Optional, low priority | Invoice Detail |
| New order status change (portal) | Dealer/Customer | none | Yes (Phase 7+) | Order Detail (portal) |

This table is a starting point — confirm the exact event list against `GET /settings/notification-configs/events` (existing endpoint, [backend/routes/api.php](../backend/routes/api.php)) during Phase 4.1, since the company can already configure notification events per module and the mobile push channel should hook into that same configuration surface rather than inventing a parallel one.

## Client-side flow

1. On first login, request notification permission (iOS requires explicit prompt; Android 13+ also requires runtime permission).
2. On grant, register the device with Firebase, get an FCM token, send to `POST /notifications/device-token`.
3. On token refresh (FCM tokens rotate periodically), re-register automatically in the background.
4. On logout, call `DELETE /notifications/device-token` so a shared/handed-back device (common for floor-worker tablets, per [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md)) stops receiving another user's notifications.
5. On notification tap: deep-link directly into the relevant screen (table above), not just opening the app to its default tab — a QC-failed notification should open that exact batch's QC screen.
6. On notification received while app is foregrounded: show as an in-app toast/banner rather than a native OS notification (standard mobile UX pattern, avoids double-surfacing).

## Quiet hours / noise control

Floor-worker and dispatch devices are often shared/company-owned and may be physically present but not "in use" outside shift hours — respect a configurable quiet-hours window (default e.g. 9pm–7am) for non-urgent categories (stock, expiring quotation) but never suppress anything time-critical the business explicitly wants immediate (this is a judgment call to confirm with the business owner during Phase 4 — default to "no suppression" until they say otherwise, since silently dropping an alert is worse than one arriving at an inconvenient hour).

## Per-user notification preferences

Reuse the existing per-event, per-channel toggle model already described in the root docs ([README.md:124](../README.md#L124): "Notifications — Per event, per channel") rather than building a separate mobile-only preferences screen — add "push" as a channel option alongside the existing email/SMS/WhatsApp toggles in Settings, so admins configure it in one place for all channels.

## Testing

Trigger each event server-side in staging (e.g. manually drop a stock quantity below reorder level) and confirm: push arrives within a reasonable window (<30s), tapping it deep-links correctly, and a user who disabled that event/channel combination does not receive it.
