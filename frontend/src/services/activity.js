import { reactive } from 'vue'

// Global "something is loading" signal for the top progress bar. Counts requests
// in flight on any axios instance it is installed on. Requests that finish
// within SHOW_AFTER_MS never show anything (no flicker on fast pages), and once
// shown the bar stays at least MIN_VISIBLE_MS so it can actually be seen.
// Background polling opts out with `{ silent: true }` in the axios config.

export const activity = reactive({ busy: false })

const SHOW_AFTER_MS = 300
const MIN_VISIBLE_MS = 500

let inflight = 0
let showTimer = null
let shownAt = 0

function begin() {
  inflight++
  if (!activity.busy && !showTimer) {
    showTimer = setTimeout(() => {
      showTimer = null
      if (inflight > 0) { activity.busy = true; shownAt = Date.now() }
    }, SHOW_AFTER_MS)
  }
}

function end() {
  inflight = Math.max(0, inflight - 1)
  if (inflight > 0) return
  clearTimeout(showTimer); showTimer = null
  if (activity.busy) {
    setTimeout(() => { if (inflight === 0) activity.busy = false }, Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt)))
  }
}

export function trackActivity(instance) {
  instance.interceptors.request.use((config) => {
    if (!config.silent) { config.__tracked = true; begin() }
    return config
  })
  instance.interceptors.response.use(
    (res) => { if (res.config?.__tracked) end(); return res },
    (err) => { if (err?.config?.__tracked) end(); return Promise.reject(err) },
  )
}
