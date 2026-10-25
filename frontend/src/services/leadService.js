import axios from 'axios'
import { downloadFile } from './transfers.js'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() }).then(r => r.data)
}

// The products a lead can be about. One list, used by the lead form and by the assignment rules.
export const LEAD_APPLICATIONS = ['Wall', 'Roof', 'Cold Room', 'Partition', 'Clean Room', 'Ceiling', 'PEB Shade', 'Architectural']

export default {
  list(params = {})        { return api('get',  '/api/leads', null, params) },
  dashboard()              { return api('get',  '/api/leads/dashboard') },
  options()                { return api('get',  '/api/leads/options') },
  get(id)                  { return api('get',  `/api/leads/${id}`) },
  payloads(id)             { return api('get',  `/api/leads/${id}/payloads`) },
  create(data)             { return api('post', '/api/leads', data) },
  update(id, data)         { return api('put',  `/api/leads/${id}`, data) },
  changeStatus(id, data)   { return api('post', `/api/leads/${id}/status`, data) },
  addActivity(id, data)    { return api('post', `/api/leads/${id}/activities`, data) },
  convert(id, data = {})   { return api('post', `/api/leads/${id}/convert`, data) },
  states()                 { return api('get',  '/api/gst/states') },

  // Many leads at once, and merging two (LEADS_PLAN 5.8)
  bulk(ids, data)               { return api('post', '/api/leads/bulk', { ids, ...data }) },
  mergeCandidates(id, q = '')   { return api('get',  `/api/leads/${id}/merge-candidates`, null, q ? { q } : null) },
  mergeLead(keepId, fromId)     { return api('post', `/api/leads/${keepId}/merge`, { from_lead_id: fromId }) },

  // Files on a lead (LEADS_PLAN 5.7). Downloads go through transfers.js (they need the sign-in header).
  attachments(id)               { return api('get', `/api/leads/${id}/attachments`) },
  addAttachment(id, file, kind) {
    const form = new FormData()
    form.append('file', file)
    if (kind) form.append('kind', kind)
    return axios({ method: 'post', url: `/api/leads/${id}/attachments`, data: form, headers: authHeaders() }).then(r => r.data)
  },
  removeAttachment(id)          { return api('delete', `/api/leads/attachments/${id}`) },

  // What they want, and "Quote this lead" (LEADS_PLAN 5.4)
  requirement(id)               { return api('get', `/api/leads/${id}/requirement`) },
  saveRequirement(id, lines)    { return api('put', `/api/leads/${id}/requirement`, { lines }) },
  quoteLead(id, data = {})      { return api('post', `/api/leads/${id}/quote`, data) },

  // Contacting a lead (LEADS_PLAN 5.3)
  messageTemplates(id, channel) { return api('get', `/api/leads/${id}/message-templates`, null, { channel }) },
  whatsappLead(id, message)     { return api('post', `/api/leads/${id}/whatsapp`, { message }) },
  emailLead(id, subject, body)  { return api('post', `/api/leads/${id}/email`, { subject, body }) },

  // Next actions (LEADS_PLAN 5.2)
  myDay(params = {})         { return api('get',  '/api/leads/tasks', null, params) },
  tasks(leadId)              { return api('get',  `/api/leads/${leadId}/tasks`) },
  addTask(leadId, data)      { return api('post', `/api/leads/${leadId}/tasks`, data) },
  updateTask(id, data)       { return api('put',  `/api/leads/tasks/${id}`, data) },
  completeTask(id, data = {}) { return api('post', `/api/leads/tasks/${id}/complete`, data) },
  deleteTask(id)             { return api('delete', `/api/leads/tasks/${id}`) },

  // A person's own saved views of the list (LEADS_PLAN 5.1)
  views()                  { return api('get',  '/api/leads/views') },
  saveView(name, filters)  { return api('post', '/api/leads/views', { name, filters }) },
  deleteView(id)           { return api('delete', `/api/leads/views/${id}`) },

  // Spreadsheet in and out (LEADS_PLAN 4.2)
  importCheck(file, source) {
    const form = new FormData()
    form.append('file', file)
    if (source) form.append('source', source)
    return axios({ method: 'post', url: '/api/leads/import/preview', data: form, headers: authHeaders() }).then(r => r.data)
  },
  importCommit(id)         { return api('post', `/api/leads/import/${id}/commit`) },
  importUndo(id)           { return api('post', `/api/leads/import/${id}/undo`) },
  importLast()             { return api('get',  '/api/leads/import/last') },
  /** The list as the screen shows it (same status and search), as a CSV file. */
  exportList(params = {})  { return downloadFile('/api/leads/export', `leads_${new Date().toISOString().slice(0, 10)}.csv`, { label: 'Exporting leads', kind: 'leads-export', params, type: 'text/csv' }) },
  remove(id)               { return api('delete', `/api/leads/${id}`) },

  users()                  { return api('get',  '/api/users') },
}
