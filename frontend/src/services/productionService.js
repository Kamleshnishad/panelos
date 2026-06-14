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
  // Advisory production plan: { alerts, groups, summary }
  planning() { return api('get', '/api/production/planning') },

  // Production runs (multi-order grouped production)
  createRun(data)   { return api('post',   '/api/production/runs', data) },
  listRuns(filters = {}) { return api('get', '/api/production/runs', null, filters) },
  getRun(id)        { return api('get',    `/api/production/runs/${id}`) },
  runMaterialRequirement(id) { return api('get', `/api/production/runs/${id}/material-requirement`) },
  runMaterialUsage(id)       { return api('get', `/api/production/runs/${id}/material-usage`) },
  wastageReport(params = {}) { return api('get', '/api/production/wastage-report', null, params) },
  startRun(id, force = false) { return api('post', `/api/production/runs/${id}/start`, { force }) },
  completeRun(id, actuals = []) { return api('post', `/api/production/runs/${id}/complete`, { actuals }) },
  cancelRun(id)     { return api('delete', `/api/production/runs/${id}`) },
}
