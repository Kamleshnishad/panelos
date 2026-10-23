import { api } from './api.js'
import { openPdfFile } from './transfers.js'

export default {
  list()           { return api.get('/document-templates').then(r => r.data) },
  apply(data)      { return api.put('/document-templates', data).then(r => r.data) },

  // Opens the preview PDF in a new tab, with a progress card.
  openPreview(docType, key) {
    return openPdfFile('/api/document-templates/preview', {
      label: 'Template preview', kind: 'template-preview', filename: 'template-preview.pdf',
      params: { doc_type: docType, template: key },
    })
  },
}
