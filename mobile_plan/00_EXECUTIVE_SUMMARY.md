# 00 — Executive Summary

## The problem

PanelOS today is a desktop-first Vue 3 web app. It's excellent for BOQ building, reports, and anything data-dense — and deliberately so ([SYSTEM_OVERVIEW.md:191-195](../SYSTEM_OVERVIEW.md#L191-L195) states the design brief explicitly optimized for desktop density). But four groups of people who touch this business daily are not sitting at a desk:

| Group | Where they actually are | What the desktop app costs them today |
|---|---|---|
| Floor / Production workers | Standing at a machine, hands often gloved or wet | Must walk to a terminal to log stage start/complete or QC results; paper checklists in the interim |
| Dispatch executives | In a loading bay or delivery vehicle | No way to confirm delivery, capture proof, or check challan details on the move |
| Sales managers | Meeting customers, on the road | Can't check quotation status or send a payment reminder without opening a laptop |
| Accounts staff | Often in-office but need quick, interrupt-driven actions | Receivables follow-up delayed until they're back at their screen |
| Dealers / customers | Outside the company entirely | No visibility at all into their own order/dispatch/invoice status — they call and ask |

## The opportunity

The backend is already a clean, tenant-scoped Laravel REST API with **token-based auth** (Sanctum bearer tokens, not cookies) — see [08_API_INTEGRATION_MAP.md](08_API_INTEGRATION_MAP.md). That means a mobile client for internal staff can be built **without backend rework** for auth or most business logic. The only genuinely new backend work is: photo/attachment upload for two actions, a push-notification pipeline, and a separate auth surface for the future dealer/customer portal. This keeps the project's real cost concentrated in mobile app development, not backend rearchitecture.

## What we are building

A single React Native + Expo app (TypeScript) with two entry flows:

1. **Staff App** (Milestone 1) — floor/production, dispatch, sales, accounts, role-gated by the existing `Role.permissions` model.
2. **Partner Portal** (Milestone 2) — dealers/customers, a thinner read-mostly experience behind new customer-scoped auth.

Full phase breakdown: [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md).

## Goals

- Cut the time between a production event happening (stage done, QC result, delivery) and it being recorded in PanelOS from "next time someone's at a desk" to real time.
- Give sales/accounts a way to act on alerts (overdue invoice, expiring quotation) the moment they get them, not hours later.
- Give dealers/customers self-serve visibility into their own orders, removing a chunk of the "what's the status of my order" phone-call volume.
- Do all of this without duplicating business logic — the mobile app is a new *client* of the existing system, not a parallel system.

## Non-goals (explicitly out of scope for this plan)

- Rebuilding the BOQ/quotation builder for mobile — it's a dense multi-section form that is genuinely worse on a phone; BOQ creation stays desktop-only.
- Multi-tenant UI (company switching, white-labeling) — the backend supports more companies later, but this app is built and shipped for Signature PUF only.
- Replacing the desktop app for admin/settings/master-data work — those stay desktop-only.
- A public marketing presence for the app (App Store optimization, etc.) — Staff App is internal distribution; Partner Portal's public-store question is an open decision, not a commitment (see [15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md)).

## Success criteria

Concrete, measurable targets are in [18_SUCCESS_METRICS_KPIS.md](18_SUCCESS_METRICS_KPIS.md). In one line: adoption by the floor/dispatch teams within 4 weeks of pilot, and a measurable drop in "status check" phone calls once the Partner Portal ships.

## Sequencing logic (why two milestones, not one release)

Investigation of the current backend (see [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md)) found:
- Internal staff auth, permissions, and ~95% of needed endpoints already exist — Staff App can start immediately.
- Dealer/customer auth **does not exist at all** today — `User` is internal-staff-only; `Customer` has no login. Building this properly (isolated guard, no cross-access to staff endpoints) is real backend design work that shouldn't be rushed to hit a combined ship date.

Shipping the Staff App first de-risks the harder Partner Portal work and gets value into the business faster.
