import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

function uploadImage(url, file) {
  const form = new FormData()
  form.append('image', file)
  return axios({ method: 'post', url, data: form, headers: { ...authHeaders(), 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
}

export default {
  uploadPanelTypeImage(id, file) { return uploadImage(`/api/panel-types/${id}/image`, file) },
  uploadAccessoryImage(id, file) { return uploadImage(`/api/accessories/${id}/image`, file) },

  // Panel types — index returns { success, data: [...] }
  panelTypes(params = {})        { return api('get',    '/api/panel-types', null, params) },
  createPanelType(data)          { return api('post',   '/api/panel-types', data) },
  updatePanelType(id, data)      { return api('put',    `/api/panel-types/${id}`, data) },
  deletePanelType(id)            { return api('delete', `/api/panel-types/${id}`) },

  // Accessories — index is paginated { data: [...], meta }
  accessories(params = {})       { return api('get',    '/api/accessories', null, params) },
  createAccessory(data)          { return api('post',   '/api/accessories', data) },
  updateAccessory(id, data)      { return api('put',    `/api/accessories/${id}`, data) },
  deleteAccessory(id)            { return api('delete', `/api/accessories/${id}`) },

  // Production stages — index returns { data: [...] }
  stages(params = {})            { return api('get',    '/api/production-stages', null, params) },
  createStage(data)              { return api('post',   '/api/production-stages', data) },
  updateStage(id, data)          { return api('put',    `/api/production-stages/${id}`, data) },
  deleteStage(id)                { return api('delete', `/api/production-stages/${id}`) },
}
