# 16 — Team & Resourcing Estimate

> These are planning-stage estimates to support a scoping conversation, not commitments — refine once actual team availability is known (this is one of the open items flagged in [README.md](README.md)).

## Roles needed

| Role | Involvement | Why |
|---|---|---|
| React Native developer (lead) | Full-time, whole project | Owns app architecture, all module implementation |
| React Native developer (second, optional) | Full-time, Phases 1–3 only | Parallelizes Production/Dispatch against Sales/Accounts — see timeline compression in [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) |
| Laravel/backend developer | Part-time, concentrated in Phases 0, 1, 2, 4, 6 | Owns everything in [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) — could be the same person who built the existing backend, given deep context already exists |
| Designer (UI, part-time) | Phase 0.3 concentrated, light touch-ups through later phases | Mobile design system port, per [13_DESIGN_SYSTEM_UI_GUIDELINES.md](13_DESIGN_SYSTEM_UI_GUIDELINES.md) — could be scoped as a short engagement rather than a dedicated hire, since the web app's design language already exists to adapt from |
| QA (part-time / shared with UAT coordination) | Concentrated at each phase's QA sub-phase | Could be the same Production/Dispatch/Sales supervisors doing UAT rather than a dedicated QA hire, given the company's likely size |
| Product owner / business sponsor | Light, ongoing | Sign-off gates in the roadmap (0.1, 5.4, 6.1, 8.1) — this is the role Kamlesh already occupies for the web app |

**This does not require a large new team.** Given the backend is already largely built and the app is a client of it, a lean team (1 RN developer + part-time backend support + light design/QA involvement) can execute the whole roadmap — the 2-developer parallelization is a nice-to-have for speed, not a requirement.

## Effort roll-up by phase (from [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md))

| Phase | App effort | Backend effort |
|---|---|---|
| 0 — Foundation | 4 weeks | 0 |
| 1 — Production | 5 weeks | ~0.5 week (photo endpoint, shared with Phase 2) |
| 2 — Dispatch | 4 weeks | ~0.5 week (shared with Phase 1) |
| 3 — Sales & Accounts | 5 weeks | 0 |
| 4 — Notifications & Dashboard | 2.5 weeks | 1 week |
| 5 — Hardening & Release | 4 weeks + 2 weeks observation | light (fixes as found) |
| **Staff App subtotal** | **~24.5 weeks + 2 obs.** | **~2 weeks** |
| 6 — Portal backend | 0 | 3.5 weeks |
| 7 — Portal app | 2.5–3.5 weeks | light |
| 8 — Portal release | 2.5 weeks + 2 weeks observation | light |
| **Portal subtotal** | **~5–6 weeks + 2 obs.** | **~3.5 weeks** |

(Figures here are the app/backend split underlying the elapsed-time totals already shown in [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md)'s timeline summary; use that document for elapsed-calendar-time planning, this table for effort/cost planning.)

## Cost framing (order-of-magnitude only, not a quote)

This document intentionally does not attach rupee/dollar figures — that depends entirely on whether this is built in-house (existing team's time, effectively sunk salary cost) versus contracted out (market rate for an RN developer varies widely by engagement model). What's stable regardless of who builds it: the **~30 person-weeks of app effort + ~5.5 person-weeks of backend effort** total across both milestones is the real unit to plan a budget against, however it gets staffed.

## Recommended sequencing for a lean team (1 RN dev + part-time backend)

1. Backend dev builds the Phase 1/2 photo endpoint and Phase 4 push pipeline slightly ahead of when the RN dev needs them (per the coordination note in [15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md)) so the app developer is never blocked waiting.
2. RN dev works Phases 0→1→2→3→4→5 in order as written — no parallelization needed, just don't let Phase 1.5/2.3 (photo features) start before the backend endpoint exists in staging.
3. After Staff App ships and stabilizes (post Phase 5.4 observation window), the same backend dev picks up Phase 6 while the RN dev either starts Phase 7 UI against a mocked portal API in parallel, or takes a break between milestones — team's call based on appetite for the portal work immediately vs. after a pause.
