# 02 — Architecture

## Guiding principle

The mobile app is a **new client**, not a new system. It talks to the same `/api/v1/*` Laravel backend the Vue app uses ([03_API_ENDPOINTS.md](../03_API_ENDPOINTS.md), [08_API_INTEGRATION_MAP.md](08_API_INTEGRATION_MAP.md)). No business logic (GST calculation, BOQ pricing, stage sequencing rules) is duplicated on-device — the app renders state and submits actions; the server remains the single source of truth.

## Tech stack

| Layer | Choice | Rationale |
|---|---|---|
| Framework | React Native via **Expo** (managed workflow), TypeScript | Fastest path to camera/push/offline plugins with minimal native build config; JS/TS transfers from the existing Vue/JS frontend team |
| Navigation | React Navigation (`native-stack` + `bottom-tabs`) | De facto standard; supports role-based tab sets cleanly |
| Server state / caching | TanStack Query (React Query) | Request de-duplication, retry, background refetch, and cache invalidation map naturally onto a REST API that already returns `{success, data, meta}` envelopes |
| Client state | Zustand | Small, no-boilerplate store for session/auth/UI state that isn't server data |
| Forms | React Hook Form + Zod | Type-safe validation for the (few) mobile forms — QC checklist, payment recording, delivery confirmation |
| Auth token storage | `expo-secure-store` | Hardware-backed encrypted storage on both iOS (Keychain) and Android (Keystore) |
| Offline persistence | `expo-sqlite` (outbox table) + TanStack Query's persisted cache | See [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md) |
| Push notifications | `expo-notifications` + Firebase Cloud Messaging | See [11_PUSH_NOTIFICATIONS.md](11_PUSH_NOTIFICATIONS.md) |
| Camera / media | `expo-camera`, `expo-image-picker`, `expo-image-manipulator` (client-side compression) | QC photos, delivery proof photos |
| Signature capture | `react-native-signature-canvas` | Optional proof-of-delivery signature |
| Crash/error monitoring | Sentry (`sentry-expo`) | Same tool class as any production mobile app; catches JS and native crashes |
| Analytics | PostHog or Firebase Analytics (decide at Phase 4) | Feeds [18_SUCCESS_METRICS_KPIS.md](18_SUCCESS_METRICS_KPIS.md) |

## App structure (two entry flows, one codebase)

```
App
├── AuthGate (checks stored token → routes to one of:)
│   ├── StaffLoginScreen  → existing POST /auth/login (Sanctum)
│   └── PortalLoginScreen → new POST /portal/auth/login (Phase 6)
│
├── StaffNavigator (role-gated bottom tabs, built from Role.permissions)
│   ├── Dashboard
│   ├── Production  (floor worker / production manager)
│   ├── Dispatch    (dispatch executive)
│   ├── Sales        (sales manager)
│   ├── Accounts     (accounts staff)
│   └── Notifications
│
└── PortalNavigator (dealer/customer — read-mostly)
    ├── Orders
    ├── Dispatches
    ├── Invoices
    └── Profile
```

A single logged-in identity is either a **staff user** or a **portal user**, never both — the navigator shown is decided once at login and the two navigators share no screens, though they share the design system, API client, and UI component library.

## Folder structure (proposed)

```
mobile/
├── app.json / eas.json          # Expo config, build profiles
├── src/
│   ├── api/                     # typed API client, one module per backend resource
│   │   ├── client.ts             # axios instance, base URL per environment, auth header injection
│   │   ├── auth.ts
│   │   ├── production.ts
│   │   ├── dispatch.ts
│   │   ├── sales.ts
│   │   ├── accounts.ts
│   │   └── portal.ts
│   ├── features/                # one folder per module, mirrors 04-07 docs
│   │   ├── production/
│   │   ├── dispatch/
│   │   ├── sales-accounts/
│   │   └── portal/
│   ├── offline/                  # outbox queue, sync engine (see doc 10)
│   ├── notifications/             # push registration + handlers (see doc 11)
│   ├── navigation/
│   ├── design-system/             # tokens, shared components (see doc 13)
│   ├── auth/                      # session store, secure-store wrapper
│   └── shared/                    # formatting (₹, SQM), date utils, hooks
└── tests/
```

## Environments

| Environment | API base URL | Purpose |
|---|---|---|
| `dev` | local Laravel via ngrok/tunnel or LAN IP | Day-to-day development against a dev DB |
| `staging` | staging Laravel deployment | Internal QA/UAT, matches [DOCKER_SETUP.md](../DOCKER_SETUP.md) staging stack |
| `production` | live PanelOS API | Real Signature PUF data — gated behind internal-distribution builds until pilot sign-off |

Expo's `eas.json` build profiles map 1:1 to these three environments with environment-specific `API_BASE_URL` injected at build time — no runtime environment switching in production builds (reduces risk of a build accidentally pointing at the wrong backend).

## Data flow (typical write action, e.g. "Complete Stage")

1. User taps "Complete Stage" → form validated locally (Zod).
2. Action handed to the **offline queue** ([10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md)), which immediately optimistically updates the TanStack Query cache so the UI reflects "done" without waiting on network.
3. Queue attempts the real `POST /batches/{id}/stages/{stageId}/complete` call.
4. On success: server response reconciles the cache; queue entry cleared.
5. On failure (offline/timeout): entry stays queued, retried with backoff, surfaced in a small "pending sync" indicator; user can keep working.

## Why not a PWA instead

Briefly recorded for context (full comparison isn't needed since React Native was already decided): a PWA would have been cheaper to ship but weaker for camera-heavy offline workflows (QC photos, delivery proof) and push reliability on Android/iOS. Given photo capture and push are core to the Production and Dispatch modules, native modules win here.
