# 13 — Design System & UI Guidelines

## Principle: adapt the existing brand language, don't reinvent it

The web app already has a defined visual language ([SYSTEM_OVERVIEW.md §3](../SYSTEM_OVERVIEW.md#L38-L48)). The mobile app should feel like the same product, not a separate brand — users (especially Sales/Accounts staff who use both) shouldn't have to re-learn color meanings.

## Color tokens (carried over from web)

| Token | Value | Meaning (unchanged from web) |
|---|---|---|
| Primary | `#1a237e` (deep indigo) | Headers, primary buttons, KPI values |
| Secondary | `#3949ab` | Secondary actions |
| Success | `#2e7d32` | Paid / in-stock / QC passed / delivered |
| Danger | `#c62828` | Overdue / out-of-stock / QC failed |
| Warning | `#f57f17` / `#e65100` | Approaching threshold, expiring soon |
| Info/accent | `#4527a0` | Accepted status, chemical-related |

**Mobile-specific additions needed:** a dark-mode variant of each token (the web app doesn't need this per its design brief, but a factory-floor tablet or an outdoor dispatch screen benefits from dark mode for glare/battery reasons) — define at Phase 0.3, don't retrofit later.

## Typography

System font stack (San Francisco on iOS, Roboto on Android) rather than importing the web's font — respects each platform's native reading experience, which matters more on mobile than brand-font consistency. Numeric/currency fields use tabular figures (monospace-style number alignment) so amount columns line up, matching the intent (if not the literal font) of the web's "monospace for document numbers" rule ([SYSTEM_OVERVIEW.md:46](../SYSTEM_OVERVIEW.md#L46)).

## Indian currency & unit formatting

Reuse the exact same formatting logic the web app uses — ₹ with Indian digit grouping (`₹ 2,31,320.40`) and K/L/Cr short forms for large figures, SQM units where relevant. This should be a **shared utility ported directly from the Vue frontend's formatting helpers** (find and port, don't reimplement from scratch — check `frontend/src/` for the existing formatter before writing a new one).

## Component library approach

- **Base components built in-house** (buttons, cards, status pills, list rows, form fields) rather than adopting a full pre-built UI kit (e.g. React Native Paper) wholesale — the app's visual identity is simple enough (per the web's own "plain tables/cards" starting point, [SYSTEM_OVERVIEW.md:187](../SYSTEM_OVERVIEW.md#L187)) that a small custom set is lower long-term maintenance than fighting a heavy third-party kit's theming system.
- **Status pills** are a first-class component — every module uses them identically (batch status, dispatch status, invoice status, QC pass/fail) — build once, reuse everywhere, exactly mirroring the "status is always a colored pill badge" rule from the web design brief ([SYSTEM_OVERVIEW.md:83](../SYSTEM_OVERVIEW.md#L83)).

## Accessibility & field-usability (mobile-specific, beyond what the web needs)

This is where mobile guidelines genuinely diverge from the desktop app, because of who's using it and where ([03_ROLES_AND_PERSONAS.md](03_ROLES_AND_PERSONAS.md)):

- **Touch targets ≥48dp** everywhere a Floor Worker or Dispatch Executive interacts — gloved/wet hands need real margin for error, not the tighter desktop-mouse-precision spacing the web app can use.
- **High contrast** status colors — factory floor and outdoor lighting both wash out low-contrast UI; validate the color tokens above against WCAG AA contrast ratios specifically for outdoor/bright conditions, not just standard screen contrast.
- **Minimize typing** on the Production/Dispatch modules — checkboxes, toggles, and pre-set options over free-text wherever the underlying data allows it (e.g. QC checklist items are toggles, not text fields).
- **Large, unambiguous primary actions** — one clear "Start Stage" / "Complete Stage" / "Mark Delivered" button per screen state, not a menu of options a distracted or rushed user could mis-tap.
- **Offline/pending-sync indicator** is a persistent, glanceable UI element (small badge, not a modal that blocks work) — per [10_OFFLINE_SYNC_STRATEGY.md](10_OFFLINE_SYNC_STRATEGY.md), users need to trust the app is tracking their actions even without signal.

## Sales/Accounts/Portal modules — different tone

These modules are used by people at a normal desk-or-phone comfort level, more like a typical consumer-grade business app — standard touch targets are fine, more information density is acceptable, and the Partner Portal in particular ([07_PARTNER_PORTAL_MODULE.md](07_PARTNER_PORTAL_MODULE.md)) should look closer to a polished consumer app (since it represents the company to outside dealers/customers) than a utilitarian internal tool.

## Empty, loading, and error states

Every list/detail screen defines all four states explicitly during implementation (not an afterthought): loading skeleton, empty state with a clear explanation ("No batches in production today" not just a blank screen), error state with retry, and offline-stale state ("Showing data from [time] — pull to refresh"). This matches the web app's own stated UX pattern of "success/error banners after every action" ([SYSTEM_OVERVIEW.md:179](../SYSTEM_OVERVIEW.md#L179)) extended to cover connectivity-aware states the web app doesn't need to worry about as much.

## Deliverable for Phase 0.3

A living component reference screen inside the dev build (not a separate design tool) showing every base component, both themes, so any developer adding a new screen in Phases 1–7 pulls from a real, current source of truth rather than guessing at spacing/color from memory.
