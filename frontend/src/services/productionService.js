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
  startRun(id)      { return api('post',   `/api/production/runs/${id}/start`) },
  completeRun(id)   { return api('post',   `/api/production/runs/${id}/complete`) },
  cancelRun(id)     { return api('delete', `/api/production/runs/${id}`) },
}
