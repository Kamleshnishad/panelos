# 15 — DevOps & Release Management

## Build & CI pipeline

- **Build service:** Expo Application Services (EAS Build) — handles native iOS/Android builds without requiring local Xcode/Android Studio setup for every developer, consistent with the Expo-managed-workflow choice in [02_ARCHITECTURE.md](02_ARCHITECTURE.md).
- **CI:** GitHub Actions (matches the existing repo being on GitHub per the root [README.md](../README.md)) — runs lint, type-check, unit/component/integration tests ([14_TESTING_QA_STRATEGY.md](14_TESTING_QA_STRATEGY.md)) on every PR against the `mobile/` directory.
- **EAS build profiles**, one per environment from [02_ARCHITECTURE.md](02_ARCHITECTURE.md):

| Profile | Trigger | API target | Distribution |
|---|---|---|---|
| `development` | Local/manual | `dev` | Dev client / Expo Go |
| `staging` | Merge to a `mobile-staging` branch | `staging` | TestFlight internal / Play internal testing |
| `production` | Tagged release | `production` | TestFlight external (pilot group) → Play internal/production track |

## Versioning

Semantic versioning (`MAJOR.MINOR.PATCH`) for the app, independent of the backend's own versioning. A backend-breaking-change (e.g. an endpoint contract change) must bump the app's minimum-supported-version check (see below) rather than assuming all installed app copies update instantly — mobile app updates are never instant like a web deploy.

## Minimum supported version / forced update

Because the backend evolves (new fields, endpoint changes) and mobile releases are not instant, add a lightweight version-gate: the app checks a `minimum_supported_version` value (served from `GET /dashboard` or a small dedicated config endpoint) on boot, and if the installed version is below it, blocks usage with an "Update required" screen linking to the store listing. This prevents an old app build from silently misbehaving against a backend that's moved on — a real risk once both sides are shipping independently.

## Distribution model

| App | Staff App | Partner Portal |
|---|---|---|
| v1 distribution | Internal only — TestFlight (Ad Hoc/internal) + Play Console internal testing track. No public App Store / Play Store listing needed since users are company staff, invited by device UDID/email. | **Open decision** (see [README.md](README.md)) — could start on the same internal track as the Staff App for the pilot (Phase 8.1), or go to public listing if the dealer base is large/dispersed enough that manual invitation doesn't scale |
| Why internal-only is fine for Staff App | Known, bounded user list (this company's own employees); avoids app-store review cycles slowing down iteration during active development | — |

**Recommendation to confirm with the business:** start the Partner Portal on the same internal-distribution model for the Phase 8.1 pilot (3–5 dealers), and only invest in public store listing + review-cycle overhead once GA scale is confirmed to need it — avoids paying that cost speculatively.

## Environments & secrets

- Firebase/FCM server credentials, EAS credentials, and any staging/production API keys live in EAS's encrypted secrets store, never committed to the repo — same discipline as the existing rule against committing `backend/.env` ([README.md:34](../README.md#L34)).
- App signing (iOS provisioning profiles, Android keystore) managed by EAS's credential service to avoid the classic "the one person with the keystore left the company" failure mode.

## Rollback strategy

- **OTA updates** (Expo Updates) for JS-only changes (bug fixes not touching native modules) can be pushed without a full app-store review cycle — useful for fast-following a P0/P1 bug found post-release.
- **Native changes** (new native module, permission change) require a full store submission and cannot be instantly rolled back — for these, roll forward with a fix rather than attempting a store rollback, which is slow/unreliable on both platforms.
- Every release is tagged in git and paired with a short changelog, mirroring the discipline already visible in this repo's commit history (e.g. `9dc6455`, `aee1444` style descriptive commits).

## Monitoring post-release

- **Crash-free session rate** via Sentry (see [02_ARCHITECTURE.md](02_ARCHITECTURE.md)) — this is one of the KPIs in [18_SUCCESS_METRICS_KPIS.md](18_SUCCESS_METRICS_KPIS.md).
- **API error rate from mobile clients specifically** — tag mobile requests with a distinguishing header/user-agent so backend logs/monitoring can separate "the web app is having an issue" from "the mobile app is having an issue" during triage.

## Backend deployment coordination

Because Phases 1, 2, 4, and 6 each have a backend-dependency component ([09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md)), backend changes for a given phase should deploy to staging **before** app development on that phase's dependent screens begins in earnest, and to production **before** that app version is submitted for release — sequence backend-then-app within each phase, not in parallel blind.
