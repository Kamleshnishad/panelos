# 12 — Security & Compliance

## Threat model summary

The mobile app introduces three new attack surfaces beyond the existing web app: (1) tokens living on physical devices that can be lost or stolen, (2) a new external-facing auth surface (Partner Portal) with no prior track record, (3) photo/document data now flowing from unmanaged personal devices. Each is addressed below.

## Token & session security

- **Storage:** Sanctum bearer tokens stored via `expo-secure-store`, which uses iOS Keychain / Android Keystore — hardware-backed, not plain `AsyncStorage`, which is unencrypted and unsuitable for auth tokens.
- **Transport:** HTTPS only, enforced at the API client level (reject any non-TLS base URL in staging/production build profiles).
- **Expiry:** existing 12-hour Sanctum token expiry (per commit `9dc6455`) applies unchanged; the app implements the same sliding-refresh pattern as the web `AppShell` (refresh on resume + periodically) rather than inventing a different session model for mobile.
- **Remote revocation / lost device:** since tokens already expire within 12 hours, a lost device is self-limiting, but for company-owned floor/dispatch devices, add a "revoke all sessions for this user" action to the existing Users & Roles admin screen (small addition, reuses the existing `users.reset-password`-style pattern) so IT/admin can immediately kill access on a reported loss rather than waiting out the expiry window.
- **Shared devices:** floor-worker/dispatch tablets may be shared across a shift — explicit logout must fully clear the secure-store token and any locally cached data belonging to that user (not just navigate to the login screen while leaving cached API responses in memory/disk).

## Guard isolation (Partner Portal)

This is the single most important security property of the whole plan, covered in depth in [09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §3: a portal (`customer` guard) token must be **structurally incapable** of authenticating against any staff (`User`) endpoint, not merely blocked by an application-level check that could be missed on a new route. Enforced via a separate Laravel auth guard bound to a separate model, so the type system itself prevents the mistake.

Required before Phase 6 is considered complete:
- Automated test suite asserting every `/api/v1/*` staff route rejects a valid portal token (401/403).
- Automated test suite asserting a portal token for Customer A cannot retrieve Customer B's data via any `/portal/*` route, including sequential-ID guessing.
- These tests run in CI on every backend change touching the portal routes, not just once at ship time.

## Data classification & what leaves the server

| Data | Sensitivity | Mobile handling |
|---|---|---|
| GST/financial figures (invoices, payments, receivables) | High — financial/business-sensitive | Never cached to plaintext unencrypted local storage beyond the normal encrypted app sandbox; no export/share of raw financial data beyond the existing PDF share flow |
| Customer contact details | Medium — PII | Same handling as the existing web app already applies (no new exposure introduced) |
| Production/QC photos | Medium — operational, not personal | Stored via the new `attachments` mechanism ([09_BACKEND_CHANGES_REQUIRED.md](09_BACKEND_CHANGES_REQUIRED.md) §1), access-controlled the same as any other company record |
| Delivery proof photos/signatures | Medium — may contain a person's likeness/signature | Treated as company operational records tied to the dispatch, retained per the company's existing document retention practice (not a new policy invented for this feature) |

## Regulatory context

Signature PUF operates in India; the **Digital Personal Data Protection Act (DPDP), 2023** governs personal data handling. The mobile app doesn't introduce new categories of personal data collection beyond what the web app already handles (customer contact info, user accounts) except device push tokens and, for the Partner Portal, dealer/customer phone numbers used for OTP if that flow is chosen. Recommendation: confirm with whoever owns compliance for the business (likely outside the scope of this engineering plan) that the existing privacy notice/consent language, if any, is updated to mention the mobile app and push notifications before Partner Portal GA — flagged here as a to-do, not resolved in this document.

## Upload validation (relates to §1 in backend doc)

- Server-side mime-type and size enforcement on every photo/attachment upload endpoint, independent of client-side compression (client-side limits are a UX courtesy, not a security control).
- Strip EXIF/location metadata from uploaded photos server-side unless location is intentionally wanted (e.g. a future "delivery GPS confirmation" feature, not in scope now) — avoids inadvertently storing a device's GPS coordinates embedded in a QC photo.

## App-level hardening

- Certificate pinning: optional, evaluate at Phase 5.2 — likely unnecessary given this is an internal-distribution app talking to a known backend, but worth a quick cost/benefit check rather than skipping the conversation entirely.
- Jailbreak/root detection: not recommended for v1 — adds complexity for a low-value signal on an internal-distribution app; revisit only if the Partner Portal's broader distribution changes the risk calculus.
- Crash reporting (Sentry, per [02_ARCHITECTURE.md](02_ARCHITECTURE.md)): ensure no auth tokens or full customer financial records are captured in crash payloads — scrub sensitive fields from Sentry breadcrumbs/context.

## Security review checkpoint

Phase 5.2 (Staff App) and the isolation testing in Phase 6.4 (Portal) are the two hard checkpoints in [01_PHASE_ROADMAP.md](01_PHASE_ROADMAP.md) where this document's checklist is run end-to-end before release — not a one-time audit at the very end of the whole project.
