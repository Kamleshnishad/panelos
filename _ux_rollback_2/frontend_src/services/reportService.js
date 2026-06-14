import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function api(method, url, params = null) {
  return axios({ method, url, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  revenueTrend(params = {})  { return api('get', '/api/reports/revenue-trend', params) },
  topCustomers(params = {})  { return api('get', '/api/reports/top-customers', params) },
  panelTypeMix(params = {})  { return api('get', '/api/reports/panel-type-mix', params) },
  profitLoss(params = {})    { return api('get', '/api/reports/profit-loss', params) },
  cashFlow(params = {})      { return api('get', '/api/reports/cash-flow', params) },
  salesReport(params = {})   { return api('get', '/api/reports/sales', params) },
}
