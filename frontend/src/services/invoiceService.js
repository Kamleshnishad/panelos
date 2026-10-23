import axios from 'axios'
import { openPdfFile, downloadFile } from './transfers.js'

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

  // PDF — generated on demand, shown with a progress card
  openPdf(id) {
    return openPdfFile(`/api/invoices/${id}/pdf`, { label: 'Invoice PDF', kind: 'invoice-pdf', filename: `invoice-${id}.pdf` })
  },
  downloadPdf(id, invoiceNo = 'invoice') {
    return downloadFile(`/api/invoices/${id}/pdf`, `invoice-${invoiceNo}.pdf`, { label: `Invoice ${invoiceNo}`, kind: 'invoice-pdf' })
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
  sendReminder(id)              { return api('post', `/api/invoices/${id}/send-payment-reminder`) },

  // e-Invoice (IRN / QR) + e-Way Bill
  einvoiceStatus(id)               { return api('get',  `/api/invoices/${id}/einvoice/status`) },
  generateIrn(id)                  { return api('post', `/api/invoices/${id}/einvoice/irn/generate`) },
  setIrnManual(id, data)           { return api('post', `/api/invoices/${id}/einvoice/irn/manual`, data) },
  cancelIrn(id, reason)            { return api('post', `/api/invoices/${id}/einvoice/irn/cancel`, { reason }) },
  generateEwayBill(id, data)       { return api('post', `/api/invoices/${id}/einvoice/eway/generate`, data) },
  setEwayBillManual(id, data)      { return api('post', `/api/invoices/${id}/einvoice/eway/manual`, data) },
  cancelEwayBill(id, reason)       { return api('post', `/api/invoices/${id}/einvoice/eway/cancel`, { reason }) },
}
