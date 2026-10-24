// Page addresses for the app shell (UX-01). A screen is /<section>, and a record on the four screens that open one
// is /<section>/<id>, e.g. /quotations/17. The server already answers every unknown path with index.html, so a
// refresh, a bookmark or a link pasted into a chat lands on the same screen. No router dependency: the shell keeps
// its section keys as the route names and only mirrors them into the address bar.

/** Sections whose records have their own address. */
export const DETAIL_SECTIONS = ['quotations', 'orders', 'batches', 'dispatches', 'invoices', 'customers']

export function buildPath(key, id = null) {
  if (key === 'dashboard') return '/'
  return id != null && DETAIL_SECTIONS.includes(key) ? `/${key}/${id}` : `/${key}`
}

/** @returns {{key: string, id: number|null} | null} null when the path is not a screen of this app */
export function parsePath(pathname, validKeys) {
  const parts = String(pathname || '/').split('/').filter(Boolean)
  if (parts.length === 0) return { key: 'dashboard', id: null }

  const [key, rawId, ...extra] = parts
  if (extra.length || !validKeys.includes(key)) return null
  if (rawId === undefined) return { key, id: null }
  if (!/^\d+$/.test(rawId)) return null
  // A screen without record pages (e.g. the /invoices/5?payment=success link Stripe returns to) still opens its list.
  if (!DETAIL_SECTIONS.includes(key)) return { key, id: null }

  return { key, id: Number(rawId) }
}
