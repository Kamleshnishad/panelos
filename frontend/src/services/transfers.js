import { reactive } from 'vue'
import axios from 'axios'
import { toastInfo } from './ui.js'

// ── File transfers with visible progress ───────────────────────────────
// PDFs are generated on request and take several seconds. Until the first byte
// arrives the browser cannot report any progress, so the bar during that phase is an
// ESTIMATE: it eases towards ~88% over the time this kind of file usually takes
// (learned from earlier runs, kept in localStorage). Once bytes flow it follows the
// real download. It always jumps to 100% only when the file is actually in hand.

export const transfers = reactive([])
let _id = 0

const EST_KEY = 'transfer_estimates'
const PREPARING_CAP = 88   // % the estimate may reach before real bytes arrive

function readEstimates() {
  try { return JSON.parse(localStorage.getItem(EST_KEY)) || {} } catch { return {} }
}
function remember(kind, ms) {
  try {
    const all = readEstimates()
    all[kind] = Math.round(all[kind] ? (all[kind] + ms) / 2 : ms)
    localStorage.setItem(EST_KEY, JSON.stringify(all))
  } catch { /* storage unavailable — the default estimate still works */ }
}

function drop(id) {
  const i = transfers.findIndex(t => t.id === id)
  if (i !== -1) transfers.splice(i, 1)
}

export function dismissTransfer(id) { drop(id) }

/**
 * Run `task(onProgress)` with a progress card. `onProgress` is an axios
 * onDownloadProgress handler. Resolves/rejects exactly as `task` does.
 */
export async function runTransfer(label, task, { kind = label, estimateMs = 8000 } = {}) {
  const t = reactive({ id: ++_id, label, percent: 0, phase: 'preparing', failed: false })
  transfers.push(t)

  const est = readEstimates()[kind] || estimateMs
  const start = Date.now()
  const tick = setInterval(() => {
    if (t.phase !== 'preparing') return
    const eased = PREPARING_CAP * (1 - Math.exp((-3 * (Date.now() - start)) / est))
    t.percent = Math.max(t.percent, Math.min(PREPARING_CAP, eased))
  }, 120)

  const onProgress = (e) => {
    t.phase = 'downloading'
    // Chunked responses carry no total; hold near the end rather than guess.
    t.percent = Math.max(t.percent, e?.total ? PREPARING_CAP + (100 - PREPARING_CAP) * Math.min(1, e.loaded / e.total) : 94)
  }

  try {
    const result = await task(onProgress)
    t.percent = 100; t.phase = 'done'
    remember(kind, Date.now() - start)
    setTimeout(() => drop(t.id), 900)
    return result
  } catch (e) {
    t.failed = true; t.phase = 'error'
    setTimeout(() => drop(t.id), 5000)
    throw e
  } finally {
    clearInterval(tick)
  }
}

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

/** GET a file as a Blob with progress. On failure the error carries the server's message. */
export function fetchBlob(url, { label, kind, params, type = 'application/pdf', estimateMs } = {}) {
  return runTransfer(label || 'Preparing file', async (onProgress) => {
    try {
      const res = await axios({
        method: 'get', url, params, responseType: 'blob', headers: authHeaders(),
        onDownloadProgress: onProgress,
        silent: true,                      // the card is the indicator; keep the top bar for everything else
      })
      return new Blob([res.data], { type })
    } catch (err) {
      // An error body arrives as a Blob too — turn it back into the JSON callers expect.
      if (err?.response?.data instanceof Blob) {
        try {
          err.response.data = JSON.parse(await err.response.data.text())
          if (err.response.data?.message) err.message = err.response.data.message
        } catch { /* not JSON — leave as is */ }
      }
      throw err
    }
  }, { kind, estimateMs })
}

/** Save a Blob through a temporary link. */
export function saveBlob(blob, filename) {
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => window.URL.revokeObjectURL(url), 1000)
}

export async function downloadFile(url, filename, opts = {}) {
  saveBlob(await fetchBlob(url, opts), filename)
}

/**
 * Open a PDF in a new tab. The tab is opened straight away, while the click still
 * counts as a user gesture: the file takes seconds to generate, and browsers block
 * pop-ups opened more than ~5 s after the click — which silently swallowed it.
 * If the browser blocks even that, the file is downloaded instead.
 */
export async function openPdfFile(url, { filename = 'document.pdf', ...opts } = {}) {
  const tab = window.open('', '_blank')
  if (tab) {
    try {
      tab.document.title = opts.label || 'Preparing…'
      tab.document.body.style.cssText = 'font-family:system-ui,sans-serif;color:#667085;display:flex;align-items:center;justify-content:center;height:100vh;margin:0'
      tab.document.body.textContent = `${opts.label || 'Your document'} is being prepared…`
    } catch { /* cross-origin guard — harmless */ }
  }

  let blob
  try {
    blob = await fetchBlob(url, opts)
  } catch (e) {
    if (tab && !tab.closed) tab.close()
    throw e
  }

  if (tab && !tab.closed) {
    const objectUrl = window.URL.createObjectURL(blob)
    tab.location.href = objectUrl
    setTimeout(() => window.URL.revokeObjectURL(objectUrl), 60000)
  } else {
    saveBlob(blob, filename)
    toastInfo('Your browser blocked the new tab, so the file was downloaded instead.')
  }
}
