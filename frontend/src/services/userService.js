import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  list()                  { return api('get',  '/api/users') },
  roles()                 { return api('get',  '/api/roles') },
  create(data)            { return api('post', '/api/users', data) },
  update(id, data)        { return api('put',  `/api/users/${id}`, data) },
  resetPassword(id, data) { return api('post', `/api/users/${id}/reset-password`, data) },
}
