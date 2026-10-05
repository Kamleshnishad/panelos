# 14 — Testing & QA Strategy

## Test pyramid

| Layer | Tooling | What it covers |
|---|---|---|
| Unit tests | Jest + React Native Testing Library | Pure logic — formatters (₹/SQM), offline outbox state machine, permission-gating logic |
| Component tests | React Native Testing Library | Individual screens/components render correctly per state (loading/empty/error/data) |
| Integration tests | Jest + mocked API layer (MSW) | Full screen flows against a mocked backend — e.g. "complete a stage" end to end within the app |
| End-to-end tests | Maestro (preferred over Detox for Expo compatibility and lower setup friction) | Critical paths on a real/simulated device: login → complete a batch stage → QC submit; login → mark dispatch delivered with photo |
| Backend contract tests | Existing Laravel test suite (`backend/tests`) | Every new/changed endpoint from [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) gets PHPUnit coverage as part of that backend work, not bolted on later |

## Device matrix

Given the persona split in [03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md), testing priority is **not** "latest flagship phone" — it's whatever the factory/field teams will actually carry.

| Priority | Device class | Why |
|---|---|---|
| P0 | Mid/low-end Android (e.g. a ~2-year-old budget Android, Android 11+) | Most likely actual floor/dispatch device; performance and offline behavior must hold up here, not just on a dev's flagship |
| P0 | Recent iPhone (for Sales/Accounts, who more likely have personal iPhones) | |
| P1 | Tablet form factor (Android) | Mounted shop-floor tablet scenario |
| P2 | Older iOS (iOS 15/16 minimum supported version — confirm final floor at Phase 0.2) | |

Performance testing (cold start time, list scroll on a batch list with realistic record counts, camera capture → compress → queue latency) is run specifically on the P0 low-end Android device, not just simulators — simulators mask real-world jank.

## UAT plan (per module, tied to the roadmap's own QA sub-phases)

Each module phase in [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) ends with a dedicated UAT sub-phase (1.6, 2.5, 3.6) where an actual person in that role uses the feature under light supervision, not a developer pretending to be the persona. Structure:

1. **Script:** a realistic task list ("start and complete all stages for today's first batch," "confirm delivery for your next stop with a photo") rather than a feature checklist — tests the real workflow, not isolated buttons.
2. **Observation, not instruction:** watch where the user hesitates or does something unexpected; that's a UX signal even if the "correct" action was technically available.
3. **Data integrity check:** after the session, verify on the web app that everything recorded on mobile is correct, complete, and attributed to the right user/timestamp.
4. **Sign-off:** the role's actual supervisor (Production Manager for floor workers, etc.) confirms the module is usable for daily work before it moves to the next roadmap phase.

## Regression checklist (run before every release build)

- Login/logout/session-expiry for both Staff and Portal flows.
- Offline queue: at least the two P0 scenarios from [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md)'s test matrix.
- Push notification delivery + deep link for at least one event per category.
- Role-based navigation: each of the 5 staff roles + portal role sees exactly the tabs/actions in the permission matrix ([03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md)), nothing more.
- Guard isolation spot-check: a portal token still cannot reach a staff endpoint (automated, but re-verified manually before major releases as a belt-and-suspenders check).

## Bug severity definitions (for the "no P0/P1 open for 5 days" exit criteria in Phase 5.3)

| Severity | Definition | Example |
|---|---|---|
| P0 | Data loss/corruption, security issue, or app unusable for a core workflow | A queued stage-completion silently disappears instead of syncing or failing visibly |
| P1 | Core workflow broken but has a workaround, or affects one role entirely | QC photo attach crashes the app, but QC can still be submitted without a photo |
| P2 | Non-core feature broken, or cosmetic issue with functional impact | Aging tile filter doesn't reset properly |
| P3 | Cosmetic only | Misaligned padding |

## Tooling/CI integration

Test suite runs in CI (see [15_DEVOPS_RELEASE_MANAGEMENT.md](15_DEVOPS_RELEASE_MANAGEMENT.md)) on every PR — unit/component/integration tests block merge on failure; E2E (Maestro) runs on a schedule and before release builds rather than on every commit, since device-farm E2E runs are slower and costlier than the rest of the pyramid.
