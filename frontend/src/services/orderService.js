import axios from 'axios'

const BASE = '/api/orders'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() })
    .then(r => r.data)
}

export default {
  list(filters = {}) {
    return api('get', BASE, null, filters)
  },

  get(id) {
    return api('get', `${BASE}/${id}`)
  },

  update(id, data) {
    return api('put', `${BASE}/${id}`, data)
  },
}
