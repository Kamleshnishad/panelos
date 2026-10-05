# 18 — Success Metrics & KPIs

## Principle

Each metric ties back to the specific pain point it's meant to fix, from [00_EXECUTIVE_SUMMARY.md](00_EXECUTIVE_SUMMARY.md) — this isn't a generic mobile-app KPI list, it's specific to what this app is for.

## Staff App — adoption metrics (tracked from Phase 5.3 pilot onward)

| Metric | Target | How measured |
|---|---|---|
| Daily active users vs. eligible staff (per role) | ≥80% of assigned floor/dispatch staff using the app on a given shift within 4 weeks of pilot | Login/session events (Sentry or lightweight analytics, per [02_ARCHITECTURE.md](02_ARCHITECTURE.md)) |
| % of batches with stage data entered via mobile (vs. backfilled later on desktop) | ≥90% within 4 weeks | Compare `BatchStageLog` entries' recorded client/source if tracked, or entry timestamp proximity to actual event time as a proxy |
| % of dispatches with delivery proof (photo) attached | ≥95% once Phase 2.3 is live | Count of dispatches with an `attachments` row vs. total completed dispatches |

## Staff App — operational impact metrics

| Metric | Target | How measured |
|---|---|---|
| Time between a production stage actually finishing and it being recorded in PanelOS | Reduce from "hours (next desk visit)" to "minutes" | Compare stage `completed_at` vs. estimated actual completion time during the pilot's supervised sessions (qualitative baseline, since "actual" completion time isn't otherwise recorded today) |
| Time between physical delivery and it being recorded/invoiceable | Reduce from "same day, often hours later" to "at time of delivery" | Compare dispatch `completed_at` timestamp trend before/after rollout |
| Overdue-invoice reminder response time (time from invoice becoming overdue to a reminder being sent) | Meaningful reduction — exact target set after establishing a baseline in the first month of Phase 3 usage | `invoice.overdue_since` vs. `payment_reminder.sent_at` |

## Partner Portal — adoption & impact metrics (tracked from Phase 8.1 pilot onward)

| Metric | Target | How measured |
|---|---|---|
| % of invited dealers/customers who complete onboarding | ≥60% within 2 weeks of invitation (external adoption is naturally slower than internal staff mandate) | Portal login events |
| Reduction in "status check" phone calls/messages to Sales/Accounts | Directionally down — track qualitatively via Sales/Accounts self-report during Phase 8.1–8.2, formalize a number once a baseline exists | Manual log during pilot (there's no existing system-of-record for these calls today, so this starts as a lightweight manual count, not automated) |
| Portal session frequency per active dealer | Repeat usage (not just a one-time login) within the first month | Session events |

## Technical health metrics (ongoing, from Phase 5 onward)

| Metric | Target |
|---|---|
| Crash-free session rate | ≥99% |
| Offline sync success rate (queued actions that eventually sync vs. permanently fail) | ≥99.5% |
| API error rate attributable to mobile clients | Comparable to or better than the web app's existing baseline |
| App cold-start time on the P0 low-end Android device ([14_TESTING_QA_STRATEGY.md](14_TESTING_QA_STRATEGY.md)) | <3 seconds |

## Review cadence

Monthly review against this scorecard starting the month after each milestone's GA release (Phase 5.5 for Staff App, Phase 8.3 for Partner Portal), feeding the Phase 9 continuous-improvement backlog in [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md). If adoption metrics are significantly missed at the first review, that's the trigger to revisit R1 in [17_RISK_REGISTER.md](17_RISK_REGISTER.md) (adoption resistance) rather than assuming a technical fix will solve it.
