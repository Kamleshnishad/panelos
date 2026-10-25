// Calendar dates as the person at the screen sees them (YYYY-MM-DD, local time).
//
// `new Date().toISOString().slice(0, 10)` is the UTC date. In India (UTC+5:30) that is yesterday until 05:30 every morning, and
// `new Date(year, month, 1).toISOString()` is the last day of the previous month, so a default "from" date was wrong all day (PB-075).
// Build the date from the local year, month and day instead.

const pad = (n) => String(n).padStart(2, '0')

/** A Date as YYYY-MM-DD in local time. */
export const isoLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

/** Today's date, local. */
export const todayLocal = (now = new Date()) => isoLocal(now)

/** The first day of the current month, local. */
export const monthStartLocal = (now = new Date()) => isoLocal(new Date(now.getFullYear(), now.getMonth(), 1))

/** The date `days` from today (negative = earlier), local. */
export const addDaysLocal = (days, now = new Date()) => isoLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate() + days))

// ── Periods for reports (FR-010) ──
// A financial year starts on the first of the company's `financial_year_start` month (Company settings; 4 = April, the Indian default) and runs twelve
// months; quarters are three months from there (April: Apr-Jun, Jul-Sep, Oct-Dec, Jan-Mar).

/** The calendar year in which the financial year containing `now` starts. */
export const fyStartYear = (now = new Date(), startMonth = 4) => (now.getMonth() >= startMonth - 1 ? now.getFullYear() : now.getFullYear() - 1)

/** "2026-27" for the financial year starting in 2026. */
export const fyLabel = (startYear) => `${startYear}-${String((startYear + 1) % 100).padStart(2, '0')}`

export const PERIOD_PRESETS = [
  { key: 'today', label: 'Today' },
  { key: 'month', label: 'This month' },
  { key: 'last_month', label: 'Last month' },
  { key: 'quarter', label: 'This quarter (to date)' },
  { key: 'last_quarter', label: 'Last quarter' },
  { key: 'fy', label: 'This financial year (to date)' },
  { key: 'last_fy', label: 'Last financial year' },
]

/** {from, to} (YYYY-MM-DD, both days included) for one of PERIOD_PRESETS; null for anything else (a custom range). */
export function periodPreset(key, now = new Date(), startMonth = 4) {
  const y = now.getFullYear()
  const m = now.getMonth()
  const fy = fyStartYear(now, startMonth)
  const idx = startMonth - 1
  const q = idx + Math.floor((((m - idx) % 12) + 12) % 12 / 3) * 3          // first month of this quarter, counted from January of the financial year's first calendar year
  const day = (yy, mm, dd) => isoLocal(new Date(yy, mm, dd))
  switch (key) {
    case 'today': return { from: todayLocal(now), to: todayLocal(now) }
    case 'month': return { from: day(y, m, 1), to: todayLocal(now) }
    case 'last_month': return { from: day(y, m - 1, 1), to: day(y, m, 0) }
    case 'quarter': return { from: day(fy, q, 1), to: todayLocal(now) }
    case 'last_quarter': return { from: day(fy, q - 3, 1), to: day(fy, q, 0) }
    case 'fy': return { from: day(fy, idx, 1), to: todayLocal(now) }
    case 'last_fy': return { from: day(fy - 1, idx, 1), to: day(fy, idx, 0) }
    default: return null
  }
}

/** The period of the same length that ends the day before `from`: what a figure is compared with. */
export function previousPeriod(from, to) {
  const f = new Date(`${from}T00:00:00`)
  const t = new Date(`${to}T00:00:00`)
  const days = Math.round((t - f) / 86400000) + 1
  return { from: isoLocal(new Date(f.getFullYear(), f.getMonth(), f.getDate() - days)), to: isoLocal(new Date(f.getFullYear(), f.getMonth(), f.getDate() - 1)) }
}
