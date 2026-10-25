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
