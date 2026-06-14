import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  suppliers()              { return api('get',  '/api/suppliers') },
  createSupplier(data)     { return api('post', '/api/suppliers', data) },
  purchasable()            { return api('get',  '/api/procurement/purchasable') },
  valuation()              { return api('get',  '/api/procurement/valuation') },

  listPOs(params = {})     { return api('get',  '/api/purchase-orders', null, params) },
  getPO(id)                { return api('get',  `/api/purchase-orders/${id}`) },
  createPO(data)           { return api('post', '/api/purchase-orders', data) },
  receivePO(id, receipts)  { return api('post', `/api/purchase-orders/${id}/receive`, { receipts }) },
  cancelPO(id)             { return api('post', `/api/purchase-orders/${id}/cancel`) },
}
