import { api } from './api.js'

export default {
  list()           { return api.get('/document-templates').then(r => r.data) },
  apply(data)      { return api.put('/document-templates', data).then(r => r.data) },

  // Returns an object URL for the preview PDF (fetched as blob so the Bearer
  // token is sent). On error, throws with a readable message.
  async preview(docType, key) {
    try {
      const res = await api.get('/document-templates/preview', {
        params: { doc_type: docType, template: key },
        responseType: 'blob',
      })
      return window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    } catch (e) {
      let msg = 'Preview not available.'
      try { msg = JSON.parse(await e?.response?.data?.text())?.message ?? msg } catch { /* ignore */ }
      throw new Error(msg)
    }
  },
}
