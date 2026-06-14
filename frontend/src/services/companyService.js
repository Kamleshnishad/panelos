import axios from 'axios'

function authHeaders(extra = {}) {
  const token = localStorage.getItem('token')
  return { ...(token ? { Authorization: `Bearer ${token}` } : {}), ...extra }
}

export default {
  get() {
    return axios({ method: 'get', url: '/api/company', headers: authHeaders() }).then(r => r.data)
  },
  update(data) {
    return axios({ method: 'put', url: '/api/company', data, headers: authHeaders() }).then(r => r.data)
  },
  uploadLogo(file) {
    const form = new FormData()
    form.append('logo', file)
    return axios({
      method: 'post',
      url: '/api/company/logo',
      data: form,
      headers: authHeaders({ 'Content-Type': 'multipart/form-data' }),
    }).then(r => r.data)
  },
}
