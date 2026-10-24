import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  list(params = {})        { return api('get',  '/api/leads', null, params) },
  dashboard()              { return api('get',  '/api/leads/dashboard') },
  options()                { return api('get',  '/api/leads/options') },
  get(id)                  { return api('get',  `/api/leads/${id}`) },
  payloads(id)             { return api('get',  `/api/leads/${id}/payloads`) },
  create(data)             { return api('post', '/api/leads', data) },
  update(id, data)         { return api('put',  `/api/leads/${id}`, data) },
  changeStatus(id, data)   { return api('post', `/api/leads/${id}/status`, data) },
  addActivity(id, data)    { return api('post', `/api/leads/${id}/activities`, data) },
  convert(id, data = {})   { return api('post', `/api/leads/${id}/convert`, data) },
  states()                 { return api('get',  '/api/gst/states') },
  remove(id)               { return api('delete', `/api/leads/${id}`) },

  users()                  { return api('get',  '/api/users') },
}
