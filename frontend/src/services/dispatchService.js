import axios from 'axios'
import { openPdfFile, downloadFile } from './transfers.js'

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

  // What is left to send for a batch (per order line and size row)
  plan(batchId)               { return api('get', `/api/batches/${batchId}/dispatch-plan`) },

  // Lifecycle
  allocate(id)                { return api('post', `/api/dispatches/${id}/allocate`) },
  complete(id, data)          { return api('post', `/api/dispatches/${id}/complete`, data) },

  // Challan
  getChallan(id)              { return api('get',  `/api/dispatches/${id}/challan`) },

  // Challan PDF — generated on demand, shown with a progress card
  openChallanPdf(id) {
    return openPdfFile(`/api/dispatches/${id}/challan/pdf`, { label: 'Delivery challan', kind: 'challan-pdf', filename: `challan-${id}.pdf` })
  },

  downloadChallanPdf(id, dispatchNo = 'challan') {
    return downloadFile(`/api/dispatches/${id}/challan/pdf`, `challan-${dispatchNo}.pdf`, { label: `Challan ${dispatchNo}`, kind: 'challan-pdf', params: { download: 1 } })
  },

  // Batches available for dispatch (qc_passed / completed)
  dispatchableBatches(filters = {}) { return api('get', '/api/batches', null, filters) },
}
