/**
 * Turn a failed request into one sentence a person can act on.
 *
 * Forms used to join EVERY value of the response body, which for a server error
 * produced "false • Something went wrong… • SERVER_ERROR • ab12cd34 • [object
 * Object]", and showed only a bare "Save failed." when the body was empty — which
 * is exactly what a crashed server returns, so the one case that most needed an
 * explanation got none.
 */
export function errorText(e, fallback = 'Save failed.') {
  const status = e?.response?.status
  const d = e?.response?.data

  if (d && typeof d === 'object') {
    // Field errors from validation: show those, and only those.
    if (d.errors && typeof d.errors === 'object') {
      const parts = Object.values(d.errors).flat().filter((x) => typeof x === 'string')
      if (parts.length) return parts.join(' • ')
    }
    if (typeof d.message === 'string' && d.message) {
      // A reference lets support find the exact log line.
      return d.reference ? `${d.message} (ref ${d.reference})` : d.message
    }
  }

  // No usable body at all.
  if (!e?.response) return 'Could not reach the server. Check your connection and try again.'
  if (status >= 500) {
    return `The server hit a problem saving this (error ${status}). Please try again — if it keeps happening, contact support.`
  }
  return fallback
}
