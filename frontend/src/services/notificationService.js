import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null) {
  return axios({ method, url, data, headers: authHeaders() }).then(r => r.data)
}

export default {
  get()           { return api('get',  '/api/settings/notifications') },
  save(data)      { return api('put',  '/api/settings/notifications', data) },
  test(channel, phone) { return api('post', '/api/settings/notifications/test', { channel, phone }) },
  logs()          { return api('get',  '/api/settings/notifications/logs') },
}
