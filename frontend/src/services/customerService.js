import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  list(params = {}) { return api('get', '/api/customers', null, params) },
  get(id)           { return api('get', `/api/customers/${id}`) },
  profile(id)       { return api('get', `/api/customers/${id}/profile`) },
}
