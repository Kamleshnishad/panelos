import { reactive } from 'vue'

// ── Toasts ──────────────────────────────────────────────────────────
let _id = 0
export const toasts = reactive([])

export function toast(message, type = 'info', timeout = 3500) {
  const id = ++_id
  toasts.push({ id, message, type })
  if (timeout) setTimeout(() => dismissToast(id), timeout)
  return id
}
export function dismissToast(id) {
  const i = toasts.findIndex(t => t.id === id)
  if (i !== -1) toasts.splice(i, 1)
}
export const toastSuccess = (m, t = 3500) => toast(m, 'success', t)
export const toastError   = (m, t = 6000) => toast(m, 'error', t)
export const toastInfo    = (m, t = 3500) => toast(m, 'info', t)

// ── Confirm dialog (promise-based) ──────────────────────────────────
export const confirmState = reactive({
  open: false, title: '', message: '', confirmLabel: 'Confirm', cancelLabel: 'Cancel', danger: false, _resolve: null,
})

export function confirmDialog({ message, title = 'Are you sure?', confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
  return new Promise((resolve) => {
    Object.assign(confirmState, { open: true, title, message, confirmLabel, cancelLabel, danger, _resolve: resolve })
  })
}
export function resolveConfirm(value) {
  const r = confirmState._resolve
  confirmState.open = false
  confirmState._resolve = null
  if (r) r(value)
}
