import axios from 'axios'

const BASE = '/api/quotations'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function api(method, url, data = null, params = null) {
  return axios({ method, url, data, params, headers: authHeaders() })
    .then(r => r.data)
}

// One-shot in-memory cache for company info (cleared on page reload)
let _companyCache = null

export default {
  // List with filters: { status, customer_id, from_date, to_date, search, sort_by, sort_order, page, per_page }
  list(filters = {}) {
    return api('get', BASE, null, filters)
  },

  get(id) {
    return api('get', `${BASE}/${id}`)
  },

  create(payload) {
    return api('post', BASE, payload)
  },

  update(id, payload) {
    return api('put', `${BASE}/${id}`, payload)
  },

  delete(id) {
    return api('delete', `${BASE}/${id}`)
  },

  send(id) {
    return api('post', `${BASE}/${id}/send`)
  },

  // Convert a BOQ into a priced draft quotation
  convert(id) {
    return api('post', `${BASE}/${id}/convert`)
  },

  // Inline rate entry from the detail page: rates = [{ id, rate_per_sqm }]
  saveRates(id, rates) {
    return api('post', `${BASE}/${id}/rates`, { rates })
  },

  accept(id) {
    return api('post', `${BASE}/${id}/accept`)
  },

  reject(id) {
    return api('post', `${BASE}/${id}/reject`)
  },

  revise(id) {
    return api('post', `${BASE}/${id}/revise`)
  },

  createOrder(id, opts = {}) {
    return api('post', `${BASE}/${id}/create-order`, opts)
  },

  // PDF — fetched as a blob so the Bearer token is sent (Sanctum ignores ?token=)
  async openPdf(id) {
    const res = await axios({ method: 'get', url: `${BASE}/${id}/pdf`, responseType: 'blob', headers: authHeaders() })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 60000)
  },
  async downloadPdf(id, quotationNo = 'quotation') {
    const res = await axios({ method: 'get', url: `${BASE}/${id}/pdf`, responseType: 'blob', headers: authHeaders() })
    const url  = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `quotation-${quotationNo}.pdf`
    document.body.appendChild(link); link.click(); link.remove()
    setTimeout(() => window.URL.revokeObjectURL(url), 1000)
  },

  // Worker copy — BOQ cutting sheet only (no rates)
  async openBoqPdf(id) {
    const res = await axios({ method: 'get', url: `${BASE}/${id}/boq-pdf`, responseType: 'blob', headers: authHeaders() })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 60000)
  },
  async downloadBoqPdf(id, quotationNo = 'boq') {
    const res = await axios({ method: 'get', url: `${BASE}/${id}/boq-pdf`, params: { download: 1 }, responseType: 'blob', headers: authHeaders() })
    const url  = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `boq-cutting-sheet-${quotationNo}.pdf`
    document.body.appendChild(link); link.click(); link.remove()
    setTimeout(() => window.URL.revokeObjectURL(url), 1000)
  },

  // Returns { data: { rate } }
  getSuggestedRate(params) {
    return api('post', `${BASE}/suggested-rate`, params)
  },

  // Helpers
  customers(search = '') {
    return api('get', '/api/customers', null, { search, per_page: 100 })
  },

  createCustomer(payload) {
    return api('post', '/api/customers', payload)
  },

  panelTypes() {
    return api('get', '/api/panel-types')
  },

  accessories() {
    return api('get', '/api/accessories')
  },

  duplicate(id) {
    return api('post', `${BASE}/${id}/duplicate`)
  },

  expire(id) {
    return api('post', `${BASE}/${id}/expire`)
  },

  // Returns company state code (2-char alpha, e.g. 'GJ') for IGST detection
  async getCompanyStateCode() {
    if (_companyCache) return _companyCache
    try {
      const res = await api('get', '/api/auth/me')
      const stateCode = res?.data?.company?.state_code ?? null
      _companyCache = stateCode
      return stateCode
    } catch {
      return null
    }
  },

  clearCompanyCache() {
    _companyCache = null
  },
}
