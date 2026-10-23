/**
 * When should the app renew the session quietly, warn, or give up?
 *
 * Pure on purpose — no Vue, no DOM, no timers — so the rules can be tested in
 * isolation. This is the part that must never mis-fire: warn too eagerly and it
 * is noise people learn to ignore; stay silent and they lose unsaved work.
 *
 * The server's token lives 12 hours from the moment it was issued. A refresh
 * issues a new one, so an ACTIVE user never gets near the end. The point of the
 * warning is the people who are not active: the tab left open over lunch, a
 * laptop that slept, a refresh that failed because the network blipped.
 */

/** Start renewing quietly once this little time is left. */
export const RENEW_WITHIN_SECONDS = 30 * 60

/** Warn once this little time is left and we could not renew quietly. */
export const WARN_WITHIN_SECONDS = 5 * 60

/** Someone who did anything in the last 15 minutes counts as present. */
export const ACTIVE_WITHIN_SECONDS = 15 * 60

/**
 * @param {object}  s
 * @param {number|null} s.secondsLeft  Seconds until the token dies; null if unknown
 *                                     (a session that began before expiry was tracked).
 * @param {number}  s.idleSeconds      Seconds since the last click / key / scroll.
 * @param {boolean} [s.renewFailed]    A quiet renewal was tried recently and failed.
 * @returns {'none'|'renew'|'warn'|'expired'}
 */
export function decideSessionAction({ secondsLeft, idleSeconds, renewFailed = false }) {
  // Unknown expiry: renew once, which tells us what it is.
  if (secondsLeft === null || secondsLeft === undefined) return 'renew'

  if (secondsLeft <= 0) return 'expired'

  // A present user whose last renewal worked is renewed without being bothered.
  const canRenewQuietly = idleSeconds <= ACTIVE_WITHIN_SECONDS && !renewFailed

  // Final stretch: only interrupt if we could not sort it out ourselves.
  if (secondsLeft <= WARN_WITHIN_SECONDS) return canRenewQuietly ? 'renew' : 'warn'

  if (secondsLeft <= RENEW_WITHIN_SECONDS && canRenewQuietly) return 'renew'

  return 'none'
}

/** 7:05 style countdown. */
export function formatCountdown(seconds) {
  const s = Math.max(0, Math.floor(seconds))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}
