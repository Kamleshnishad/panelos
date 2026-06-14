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
  list(filters = {})            { return api('get',  '/api/invoices', null, filters) },
  get(id)                       { return api('get',  `/api/invoices/${id}`) },
  update(id, data)              { return api('put',  `/api/invoices/${id}`, data) },

  createFromDispatch(data)      { return api('post', '/api/invoices/from-dispatch', data) },
  createFromOrder(data)         { return api('post', '/api/invoices/from-order', data) },

  // Lifecycle
  send(id)                      { return api('post', `/api/invoices/${id}/send`) },
  accept(id)                    { return api('post', `/api/invoices/${id}/accept`) },
  markPaid(id)                  { return api('post', `/api/invoices/${id}/mark-paid`) },
  cancel(id)                    { return api('post', `/api/invoices/${id}/cancel`) },
  duplicate(id)                 { return api('post', `/api/invoices/${id}/duplicate`) },

  // PDF (blob — sends bearer token)
  async openPdf(id) {
    const res = await axios({ method: 'get', url: `/api/invoices/${id}/pdf`, responseType: 'blob', headers: authHeaders() })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 60000)
  },
  async downloadPdf(id, invoiceNo = 'invoice') {
    const res = await axios({ method: 'get', url: `/api/invoices/${id}/pdf`, responseType: 'blob', headers: authHeaders() })
    const url  = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `invoice-${invoiceNo}.pdf`
    document.body.appendChild(link); link.click(); link.remove()
    setTimeout(() => window.URL.revokeObjectURL(url), 1000)
  },

  // Sources for the create flow
  dispatches(filters = {})      { return api('get', '/api/dispatches', null, filters) },
  orders(filters = {})          { return api('get', '/api/orders', null, filters) },

  // Payments
  recordPayment(data)           { return api('post', '/api/payments/record', data) },
  paymentHistory(id)            { return api('get',  `/api/invoices/${id}/payments`) },
  paymentStatus(id)             { return api('get',  `/api/invoices/${id}/payment-status`) },

  // Accounts Receivable / aging
  accountsReceivable(params = {}) { return api('get', '/api/reports/accounts-receivable', null, params) },
  sendReminder(id)              { return api('post', `/api/invoices/${id}/payment-reminder`) },
}
