import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

export default {
  list()           { return api('get', '/api/document-templates') },
  apply(data)      { return api('put', '/api/document-templates', data) },

  // Returns an object URL for the preview PDF (fetched as blob so the Bearer
  // token is sent). On error, throws with a readable message.
  async preview(docType, key) {
    try {
      const res = await axios({
        method: 'get', url: '/api/document-templates/preview',
        params: { doc_type: docType, template: key },
        responseType: 'blob', headers: authHeaders(),
      })
      return window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    } catch (e) {
      let msg = 'Preview not available.'
      try { msg = JSON.parse(await e?.response?.data?.text())?.message ?? msg } catch { /* ignore */ }
      throw new Error(msg)
    }
  },
}
