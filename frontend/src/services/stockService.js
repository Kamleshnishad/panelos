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
  // Coil stock
  getCoils(params = {})            { return api('get',  '/api/stock/coils',           null, params) },
  getCoil(id)                      { return api('get',  `/api/stock/coils/${id}`) },
  addCoil(id, data)                { return api('post', `/api/stock/coils/${id}/add`,    data) },
  removeCoil(id, data)             { return api('post', `/api/stock/coils/${id}/remove`, data) },
  adjustCoil(id, data)             { return api('post', `/api/stock/coils/${id}/adjust`, data) },
  updateCoilReorder(id, level)     { return api('post', `/api/stock/coils/${id}/reorder`, { reorder_level: level }) },

  // Chemical stock
  getChemicals(params = {})        { return api('get',  '/api/stock/chemicals',              null, params) },
  getChemical(id)                  { return api('get',  `/api/stock/chemicals/${id}`) },
  createChemical(data)             { return api('post', '/api/stock/chemicals',              data) },
  addChemical(id, data)            { return api('post', `/api/stock/chemicals/${id}/add`,    data) },
  removeChemical(id, data)         { return api('post', `/api/stock/chemicals/${id}/remove`, data) },
  adjustChemical(id, data)         { return api('post', `/api/stock/chemicals/${id}/adjust`, data) },
  updateChemicalReorder(id, level) { return api('post', `/api/stock/chemicals/${id}/reorder`, { reorder_level: level }) },

  // Transactions
  getTransactions(params = {})     { return api('get',  '/api/stock/transactions', null, params) },

  // Alerts
  getAlerts(params = {})           { return api('get',  '/api/stock/alerts',            null, params) },
  resolveAlert(id)                 { return api('post', `/api/stock/alerts/${id}/resolve`) },

  // Dashboard
  getDashboard()                   { return api('get',  '/api/stock/dashboard') },
}
