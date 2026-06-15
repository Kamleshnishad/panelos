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
  impersonate(id)             { return api('post', `/api/admin/companies/${id}/impersonate`) },
  expiring(days = 7)          { return api('get',  '/api/admin/expiring', null, { days }) },
  revenue()                   { return api('get',  '/api/admin/revenue') },
  funnel(days = 30)           { return api('get',  '/api/admin/funnel', null, { days }) },
  async downloadInvoice(paymentId, invoiceNo) {
    const res = await axios.get(`/api/admin/payments/${paymentId}/invoice`, { headers: authHeaders(), responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const a = document.createElement('a')
    a.href = url; a.download = `${invoiceNo || 'invoice'}.pdf`; a.click()
    window.URL.revokeObjectURL(url)
  },
  platformAdmins()            { return api('get',  '/api/admin/platform-admins') },
  createPlatformAdmin(data)   { return api('post', '/api/admin/platform-admins', data) },
}
