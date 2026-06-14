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
  // Batches
  list(filters = {})        { return api('get',  '/api/batches',          null, filters) },
  get(id)                   { return api('get',  `/api/batches/${id}`) },
  update(id, data)          { return api('put',  `/api/batches/${id}`, data) },
  delete(id)                { return api('delete',`/api/batches/${id}`) },
  start(id)                 { return api('post', `/api/batches/${id}/start`) },
  complete(id, data)        { return api('post', `/api/batches/${id}/complete`, data) },

  // Create from order
  createFromOrder(orderId, data) { return api('post', `/api/orders/${orderId}/batches`, data) },

  // Stage timeline
  getTimeline(id)           { return api('get',  `/api/batches/${id}/timeline`) },
  getProgress(id)           { return api('get',  `/api/batches/${id}/progress`) },
  startStage(batchId, stageId, data)    { return api('post', `/api/batches/${batchId}/stages/${stageId}/start`,    data) },
  completeStage(batchId, stageId, data) { return api('post', `/api/batches/${batchId}/stages/${stageId}/complete`, data) },

  // QC
  createQc(id, data)        { return api('post', `/api/batches/${id}/qc`, data) },
  getQc(id)                 { return api('get',  `/api/batches/${id}/qc`) },
  approveQc(qcId, data)     { return api('post', `/api/quality-control/${qcId}/approve`, data) },
  qcStats(params = {})      { return api('get',  '/api/quality-control/statistics', null, params) },
  qcList(filters = {})      { return api('get',  '/api/quality-control', null, filters) },

  // Orders for batch-create dropdown
  orders(filters = {})      { return api('get',  '/api/orders', null, filters) },

  // Stages master list
  stages()                  { return api('get',  '/api/production-stages') },
}
