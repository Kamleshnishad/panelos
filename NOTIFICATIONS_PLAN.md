# In-app notifications (the header bell) — plan and status

Before: the bell was a shortcut to Receivables with a red dot computed once at page load from the
dashboard `alerts`. Nothing was stored, nothing could be read or cleared, and a stage waiting for
approval told nobody. (The "Notifications" settings page is unrelated — it configures SMS/WhatsApp sent to
*customers*; it is now labelled **Customer Messaging**.)

Tracked as **FR-003** in [BUGS.md](BUGS.md). Reference: SYSTEM.md §19.5.

## Design

* **Table `app_notifications`** — one row per recipient (read state is per person): `company_id`,
  `user_id`, `type`, `severity` (info / warning / danger), `title`, `body`, `link_section` + `link_id`
  (an AppShell section key and optional record id), `dedupe_key`, `read_at`. Unique on
  (`user_id`, `dedupe_key`) so a standing condition is refreshed in place, never duplicated.
* **`AppNotificationService`** — `notify(company, permission-key | user-ids, …)` resolves recipients from
  roles (admins hold everything); `syncStanding()` makes a set of standing notifications match the current
  state (adds/refreshes, removes what has cleared). Both swallow and log their own failures — a
  notification can never break the business action that raised it.
* **API** (own rows only): `GET /notifications`, `GET /notifications/unread-count`,
  `POST /notifications/{id}/read`, `POST /notifications/read-all`.
* **Bell** (`NotificationBell.vue`) — count badge, dropdown list, mark-all-read, click opens the linked
  page. Polls the unread count every 60 s, paused while the tab is hidden. Polling does not count as user
  activity, so it does not keep an idle session alive.
* **Scan** — `notifications:scan` hourly: low coil / chemical stock, overdue invoices; clears what has
  resolved; prunes read notifications older than 30 days.
* Polling, not websockets: production is one container with a database queue and no Redis.

## Events and recipients

| Event | Recipients (permission) | Opens | Phase |
|---|---|---|---|
| Stage awaiting approval | `production.approve` | Production | **N1 ✅** |
| Stage approved / rejected | the operator who ran it (not if they approved it themselves) | Production | **N1 ✅** |
| Low coil / chemical stock | `stock.manage` | Stock | **N1 ✅** |
| Invoices overdue (one summary, refreshed) | `payments.view` | Receivables | **N1 ✅** |
| BOQ created, rates pending | `quotations.manage` | Quotation | N2 |
| Quotation accepted | `quotations.manage`, `orders.manage` | Order | N2 |
| Quotation expiring / expired | creator | Quotation | N2 |
| Order created, run completed | `production.manage` | Order | N2 |
| QC failed | `production.manage` | Production | N2 |
| Dispatch delivered ("raise the invoice") | `invoices.manage` | Dispatch | N2 |
| Payment received | `invoices.manage` | Invoice | N2 |
| Lead follow-up due | lead owner | Leads | N2 |
| Chemical expiring / expired | `stock.manage` | Stock | N3 |
| Backup failed | admins | Settings | N3 |

## Phases

1. **N1 — foundation (done, not deployed):** table, service, API, bell, scan, the four events above.
2. **N2 — workflow events:** the remaining event rows, one service hook each.
3. **N3 — housekeeping:** expiry and backup-failure scans, a "View all" page.
4. **N4 — optional:** per-user mute settings per type, an email digest, Expo push for the mobile app
   (reuses the same table).

## Decisions taken (change on request)

Recipients are chosen by permission, not per user · no on-screen toast, the bell only · in-app only, no
email · N1 first and shipped on its own.

## Tests

`tests/Feature/AppNotificationsTest.php` (13): recipient resolution by permission, no notification when a
stage needs no approval, operator told on approve/reject but not for their own approval, own-rows-only
API, cross-user and cross-company isolation, read / read-all, scan idempotency, resolved conditions
disappear, overdue count follows reality, 30-day pruning, no-op with nobody to tell, scheduled.
