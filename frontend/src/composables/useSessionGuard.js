import { ref, onMounted, onBeforeUnmount } from 'vue'
import authService from '../services/authService.js'
import { decideSessionAction } from './sessionPolicy.js'

/**
 * Keeps the session alive for people who are working, and warns the ones who
 * are not before it ends — replacing "refresh on every page load plus every 6h".
 *
 * That old approach had two faults:
 *  - it refreshed on EVERY load, and refresh invalidated the old token, so the
 *    screen you opened (already fetching) or a second tab could be signed out
 *    mid-request. Measured on production: 2 of 40 simulated loads.
 *  - it was a setInterval, which a sleeping laptop or a background tab does not
 *    run, so the session could simply lapse with no notice at all.
 *
 * This renews only when time is genuinely running low, from the token's own
 * remaining life (see sessionPolicy for the rules), and re-checks the moment the
 * tab becomes visible again.
 *
 * @param {{ onExpired: () => void }} opts
 */
export function useSessionGuard({ onExpired }) {
  const warning = ref(false)       // show the "about to expire" banner
  const secondsLeft = ref(null)    // for the countdown in it
  const renewing = ref(false)
  const failed = ref(false)        // the last renewal attempt could not reach the server

  const RETRY_AFTER_FAILURE_MS = 60 * 1000

  // However a renewal ends, never start another within this long. Belt and braces
  // against a loop if a response ever came back without a usable expiry.
  const MIN_GAP_BETWEEN_RENEWALS_MS = 20 * 1000

  let lastActivity = Date.now()
  let renewFailedAt = 0
  let lastRenewAt = 0
  let expiredFired = false
  let timer = null

  // Anything a person does counts. Passive + capture so it never interferes.
  const ACTIVITY = ['pointerdown', 'keydown', 'scroll', 'touchstart']
  const markActive = () => { lastActivity = Date.now() }

  // Support sessions started from the platform admin are deliberately short and
  // are not refreshed: refreshing would swap the restricted impersonation token
  // for an ordinary full one.
  const isImpersonating = () => !!localStorage.getItem('impersonating')

  async function renew({ force = false } = {}) {
    if (renewing.value) return false
    if (!force && Date.now() - lastRenewAt < MIN_GAP_BETWEEN_RENEWALS_MS) return false
    lastRenewAt = Date.now()
    renewing.value = true
    const data = await authService.refreshToken()
    renewing.value = false
    renewFailedAt = data ? 0 : Date.now()
    failed.value = !data
    if (data) warning.value = false
    tick()
    return !!data
  }

  function tick() {
    if (isImpersonating() || !authService.isLoggedIn()) {
      warning.value = false
      return
    }

    secondsLeft.value = authService.secondsLeft()

    const action = decideSessionAction({
      secondsLeft: secondsLeft.value,
      idleSeconds: (Date.now() - lastActivity) / 1000,
      // After a failure, back off for a minute rather than hammering the server.
      renewFailed: renewFailedAt > 0 && Date.now() - renewFailedAt < RETRY_AFTER_FAILURE_MS,
    })

    if (action === 'renew') { renew(); return }
    if (action === 'warn') { warning.value = true; return }
    if (action === 'expired') {
      warning.value = false
      if (!expiredFired) { expiredFired = true; onExpired() }
      return
    }
    warning.value = false
  }

  /** The "Stay signed in" button. Pressing it also counts as being present. */
  function extend() {
    markActive()
    renewFailedAt = 0
    return renew({ force: true })   // the user asked; skip the spacing rule
  }

  const onVisible = () => { if (document.visibilityState === 'visible') tick() }

  onMounted(() => {
    ACTIVITY.forEach((e) => window.addEventListener(e, markActive, { passive: true, capture: true }))
    document.addEventListener('visibilitychange', onVisible)
    tick()
    // Once a second: cheap (a localStorage read and a comparison) and it keeps the
    // countdown honest. The renew back-off above protects the server.
    timer = setInterval(tick, 1000)
  })

  onBeforeUnmount(() => {
    ACTIVITY.forEach((e) => window.removeEventListener(e, markActive, { capture: true }))
    document.removeEventListener('visibilitychange', onVisible)
    if (timer) clearInterval(timer)
  })

  return { warning, secondsLeft, renewing, failed, extend }
}
