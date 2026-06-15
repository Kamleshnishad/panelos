import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  overview()                  { return api('get',  '/api/admin/overview') },
  companies(params = {})       { return api('get',  '/api/admin/companies', null, params) },
  company(id)                 { return api('get',  `/api/admin/companies/${id}`) },
  activate(id, plan, months)  { return api('post', `/api/admin/companies/${id}/activate`, { plan, months }) },
  extendTrial(id, days)       { return api('post', `/api/admin/companies/${id}/extend-trial`, { days }) },
  setActive(id, isActive)     { return api('post', `/api/admin/companies/${id}/set-active`, { is_active: isActive }) },
}
