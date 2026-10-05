# 03 — Roles & Personas

## Source of truth

Roles are not invented for the mobile app — they're the same six roles already modeled server-side: `User.role_id` → `Role.permissions` (array), [backend/app/Models/User.php](../backend/app/Models/User.php), [backend/app/Models/Role.php](../backend/app/Models/Role.php). The mobile app reads `GET /auth/me` and gates navigation off the returned role/permissions — it does not maintain its own copy of the permission model.

## Persona profiles

### 1. Floor Worker / Production Operator
- **Context:** Standing at a machine on the shop floor. Hands may be gloved, wet, or dusty. Shared device likely (one tablet mounted per line, or a small pool of phones), not necessarily a personal device.
- **Primary jobs:** start/complete production stages, run the QC checklist, attach a photo when something looks off.
- **Device implication:** large touch targets (≥48dp), high-contrast status colors, minimal typing (checkboxes/toggles over free text), works with an unreliable/no factory Wi-Fi signal.
- **Today's pain:** paper checklists or walking to the nearest terminal, batch data entered well after the fact.
- **Module:** [04_PRODUCTION_MODULE.md](04_PRODUCTION_MODULE.md).

### 2. Production Manager
- **Context:** Walks the floor but also needs the fuller picture — multiple batches, QC pass-rate trends.
- **Primary jobs:** everything the Floor Worker can do, plus reviewing/approving QC records, viewing the QC statistics dashboard.
- **Device implication:** personal phone likely; same offline constraints as Floor Worker but more read/reporting screens.
- **Module:** [04_PRODUCTION_MODULE.md](04_PRODUCTION_MODULE.md).

### 3. Dispatch Executive
- **Context:** Loading bay, then in a delivery vehicle en route to the customer site — genuinely mobile, genuinely disconnected at times.
- **Primary jobs:** allocate stock to a dispatch, mark delivered, capture proof of delivery (photo/signature).
- **Device implication:** camera access is core, not incidental; must tolerate long offline stretches (rural delivery routes); GPS/location could be a future nice-to-have (not in scope now).
- **Today's pain:** delivery confirmation happens hours later back at the office, delaying invoicing.
- **Module:** [05_DISPATCH_MODULE.md](05_DISPATCH_MODULE.md).

### 4. Sales Manager
- **Context:** On the road visiting customers, or in the office between meetings.
- **Primary jobs:** check quotation/order status, view a customer's outstanding balance before a meeting, send/accept/reject a quotation quickly.
- **Device implication:** personal phone, generally good connectivity; app should feel like a "quick glance" tool, not a place to build a BOQ.
- **Module:** [06_SALES_ACCOUNTS_MODULE.md](06_SALES_ACCOUNTS_MODULE.md).

### 5. Accounts Staff
- **Context:** Mostly in-office, but receivables follow-up is interrupt-driven — wants to act on an overdue-invoice alert the moment it arrives, from wherever they are.
- **Primary jobs:** view receivables aging, send a payment reminder, record a payment, check stock/chemical alerts if also covering inventory.
- **Module:** [06_SALES_ACCOUNTS_MODULE.md](06_SALES_ACCOUNTS_MODULE.md).

### 6. Dealer / Installer / Customer (Partner Portal — Milestone 2)
- **Context:** Entirely external to the company; varies from a large dealer's back-office staff to an individual customer checking on one order.
- **Primary jobs:** check the status of their own order, see when it will/did dispatch, view/download their invoice, know their outstanding balance.
- **Device implication:** unmanaged personal device, no assumption of app familiarity — must be self-explanatory, minimal onboarding friction.
- **Today's pain:** calls the office to ask "where's my order" — this is the volume the portal is meant to reduce.
- **Module:** [07_PARTNER_PORTAL_MODULE.md](07_PARTNER_PORTAL_MODULE.md).

## Permission matrix (Staff App)

Derived from the existing role model; exact `Role.permissions` values are configured server-side per company, this table shows the *intended* mobile-visible capability per role — mobile navigation hides tabs/actions a role's permissions don't grant, it does not introduce new restrictions beyond what the backend already enforces.

| Capability | Floor Worker | Production Manager | Dispatch Exec. | Sales Manager | Accounts |
|---|---|---|---|---|---|
| View assigned batches | ✅ | ✅ (all) | — | — | — |
| Start/complete stage | ✅ | ✅ | — | — | — |
| Submit QC checklist | ✅ | ✅ | — | — | — |
| Approve QC | — | ✅ | — | — | — |
| View/allocate dispatches | — | — | ✅ | view only | view only |
| Mark delivered + proof | — | — | ✅ | — | — |
| View/act on quotations & orders | — | — | — | ✅ | view only |
| Record payment | — | — | — | — | ✅ |
| Send payment reminder | — | — | — | ✅ | ✅ |
| View receivables aging | — | — | — | ✅ | ✅ |
| View stock/chemical alerts | ✅ (own line) | ✅ | — | — | ✅ |

Company Admin / Super Admin retain full desktop access; the mobile app is not the primary tool for those roles and is out of scope for their workflows (settings, master data, user management stay desktop-only per [00_EXECUTIVE_SUMMARY.md](00_EXECUTIVE_SUMMARY.md) non-goals).

## Device & environment assumptions

| Factor | Staff App (internal) | Partner Portal (external) |
|---|---|---|
| Device ownership | Mix of company-issued (floor/dispatch) and personal (sales/accounts) | Entirely personal, unmanaged |
| OS split | Assume majority Android (cost-sensitive factory/field deployment), test iOS too | Both, unknown split — build for both from day one |
| Connectivity | Frequently poor/absent (factory floor, delivery routes) | Generally normal |
| Onboarding | In-person training during pilot rollout (Phase 5.4) | Self-serve, must be obvious without training |
