import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() })
    .then(r => r.data)
}

export default {
  list(filters = {})          { return api('get',  '/api/dispatches', null, filters) },
  get(id)                     { return api('get',  `/api/dispatches/${id}`) },
  update(id, data)            { return api('put',  `/api/dispatches/${id}`, data) },
  cancel(id)                  { return api('delete', `/api/dispatches/${id}`) },

  // Create from batch
  createFromBatch(batchId, data) { return api('post', `/api/batches/${batchId}/dispatch`, data) },

  // Lifecycle
  allocate(id)                { return api('post', `/api/dispatches/${id}/allocate`) },
  complete(id, data)          { return api('post', `/api/dispatches/${id}/complete`, data) },

  // Challan
  getChallan(id)              { return api('get',  `/api/dispatches/${id}/challan`) },

  // Challan PDF — fetched as a blob so the bearer token is sent, then opened/downloaded
  async openChallanPdf(id) {
    const res = await axios({
      method: 'get',
      url: `/api/dispatches/${id}/challan/pdf`,
      responseType: 'blob',
      headers: authHeaders(),
    })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    // Revoke after a delay so the new tab can load it
    setTimeout(() => window.URL.revokeObjectURL(url), 60000)
  },

  async downloadChallanPdf(id, dispatchNo = 'challan') {
    const res = await axios({
      method: 'get',
      url: `/api/dispatches/${id}/challan/pdf`,
      params: { download: 1 },
      responseType: 'blob',
      headers: authHeaders(),
    })
    const url  = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `challan-${dispatchNo}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => window.URL.revokeObjectURL(url), 1000)
  },

  // Batches available for dispatch (qc_passed / completed)
  dispatchableBatches(filters = {}) { return api('get', '/api/batches', null, filters) },
}
