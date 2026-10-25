// The same rule as the menu: company admins can do everything, everyone else needs the permission key.
export function can(key) {
  let u = null
  try { u = JSON.parse(localStorage.getItem('user') || 'null') } catch { /* no user stored */ }
  if (!u) return false
  if (u.is_admin || u.is_company_admin || u.is_super_admin) return true
  const p = u.permissions || []
  return p.includes('*') || p.includes(key)
}
