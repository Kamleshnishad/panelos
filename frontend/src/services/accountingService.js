import { api } from './api'

export const invoiceService = {
  listInvoices(filters = {}) {
    return api.get('/invoices', { params: filters }).then(res => res.data.data)
  },

  getInvoice(id) {
    return api.get(`/invoices/${id}`).then(res => res.data.data)
  },

  createFromDispatch(dispatchId, data = {}) {
    return api.post('/invoices/from-dispatch', {
      dispatch_id: dispatchId,
      ...data
    }).then(res => res.data.data)
  },

  createFromOrder(orderId, data = {}) {
    return api.post('/invoices/from-order', {
      order_id: orderId,
      ...data
    }).then(res => res.data.data)
  },

  updateInvoice(id, data) {
    return api.put(`/invoices/${id}`, data).then(res => res.data.data)
  },

  addItem(invoiceId, itemData) {
    return api.post(`/invoices/${invoiceId}/items`, itemData).then(res => res.data.data)
  },

  sendInvoice(id) {
    return api.post(`/invoices/${id}/send`).then(res => res.data.data)
  },

  acceptInvoice(id) {
    return api.post(`/invoices/${id}/accept`).then(res => res.data.data)
  },

  markPaid(id) {
    return api.post(`/invoices/${id}/mark-paid`).then(res => res.data.data)
  },

  cancelInvoice(id) {
    return api.post(`/invoices/${id}/cancel`).then(res => res.data.data)
  },

  duplicateInvoice(id) {
    return api.post(`/invoices/${id}/duplicate`).then(res => res.data.data)
  },

  sendEmail(invoiceId) {
    return api.post(`/invoices/${invoiceId}/send-email`).then(res => res.data)
  },

  getEmailPreview(invoiceId, emailType = 'invoice_sent') {
    return api.get(`/invoices/${invoiceId}/email-preview/${emailType}`).then(res => res.data.data)
  },

  createCheckoutSession(invoiceId) {
    return api.post(`/invoices/${invoiceId}/payment/checkout-session`).then(res => res.data)
  },

  createPaymentIntent(invoiceId) {
    return api.post(`/invoices/${invoiceId}/payment/intent`).then(res => res.data)
  },

  confirmPaymentIntent(intentId, paymentMethodId = null) {
    return api.post('/payments/intent/confirm', {
      intent_id: intentId,
      payment_method_id: paymentMethodId
    }).then(res => res.data)
  },

  getPaymentLink(invoiceId) {
    return api.get(`/invoices/${invoiceId}/payment-link`).then(res => res.data)
  },

  scheduleReminder(invoiceId) {
    return api.post(`/invoices/${invoiceId}/schedule-reminder`).then(res => res.data)
  },

  getReminderStatus(invoiceId) {
    return api.get(`/invoices/${invoiceId}/reminder-status`).then(res => res.data)
  },

  sendManualReminder(invoiceId) {
    return api.post(`/invoices/${invoiceId}/send-reminder`).then(res => res.data)
  },

  getReminderStats() {
    return api.get('/reminders/stats').then(res => res.data)
  },

  sendPaymentReminderSms(invoiceId) {
    return api.post(`/invoices/${invoiceId}/send-sms-reminder`).then(res => res.data)
  },

  sendCustomSms(phoneNumber, message) {
    return api.post('/sms/send', {
      phone_number: phoneNumber,
      message: message
    }).then(res => res.data)
  },

  validatePhoneNumber(phoneNumber) {
    return api.post('/sms/validate', {
      phone_number: phoneNumber
    }).then(res => res.data)
  },

  getSmsLogs() {
    return api.get('/sms/logs').then(res => res.data)
  },

  getSmsStatus() {
    return api.get('/sms/status').then(res => res.data)
  },

  registerGstConfiguration(stateCode, gstin, registrationType) {
    return api.post('/gst/register', {
      state_code: stateCode,
      gstin: gstin,
      registration_type: registrationType
    }).then(res => res.data)
  },

  addHsnCode(code, description, category, gstRate, cessRate) {
    return api.post('/gst/hsn-code', {
      code: code,
      description: description,
      category: category,
      gst_rate: gstRate,
      cess_rate: cessRate
    }).then(res => res.data)
  },

  calculateGst(invoiceId, gstRate) {
    return api.post(`/invoices/${invoiceId}/calculate-gst`, {
      gst_rate: gstRate
    }).then(res => res.data)
  },

  getGstBreakdown(invoiceId) {
    return api.get(`/invoices/${invoiceId}/gst-breakdown`).then(res => res.data)
  },

  getGstConfigurations() {
    return api.get('/gst/configurations').then(res => res.data)
  },

  generateGstReport(startDate, endDate) {
    return api.get('/gst/report', {
      params: {
        start_date: startDate,
        end_date: endDate
      }
    }).then(res => res.data)
  },

  getGstCompliance() {
    return api.get('/gst/compliance').then(res => res.data)
  },

  validateGstin(gstin, stateCode) {
    return api.post('/gst/validate-gstin', {
      gstin: gstin,
      state_code: stateCode
    }).then(res => res.data)
  },

  getStatesList() {
    return api.get('/gst/states').then(res => res.data)
  },

  generateMlForecast(panelTypeId, horizonDays = 30) {
    return api.post('/forecasts/ml', {
      panel_type_id: panelTypeId,
      horizon_days: horizonDays,
    }).then(res => res.data)
  },

  compareModels(panelTypeId, days = 90) {
    return api.post('/forecasts/ml/compare-models', {
      panel_type_id: panelTypeId,
      days,
    }).then(res => res.data)
  },

  getAnomalyDetection(panelTypeId, days = 90) {
    return api.get('/forecasts/ml/anomalies', {
      params: { panel_type_id: panelTypeId, days },
    }).then(res => res.data)
  },

  recordActualDemand(panelTypeId, forecastId, actualQuantity) {
    return api.post('/forecasts/ml/record-actual', {
      panel_type_id: panelTypeId,
      forecast_id: forecastId,
      actual_quantity: actualQuantity,
    }).then(res => res.data)
  },

  getMlModelPerformance() {
    return api.get('/forecasts/ml/performance').then(res => res.data)
  }
}

export const paymentService = {
  recordPayment(invoiceId, paymentData) {
    return api.post('/payments/record', {
      invoice_id: invoiceId,
      ...paymentData
    }).then(res => res.data.data)
  },

  getPaymentHistory(invoiceId) {
    return api.get(`/invoices/${invoiceId}/payments`).then(res => res.data.data)
  },

  getPaymentStatus(invoiceId) {
    return api.get(`/invoices/${invoiceId}/payment-status`).then(res => res.data.data)
  },

  issueReminder(invoiceId) {
    return api.post(`/invoices/${invoiceId}/payment-reminder`).then(res => res.data.data)
  },

  writeOff(invoiceId, amount, reason) {
    return api.post(`/invoices/${invoiceId}/write-off`, {
      amount,
      reason
    }).then(res => res.data.data)
  },

  reconcilePayment(invoiceId, paidAmount, referenceNo) {
    return api.post('/payments/reconcile', {
      invoice_id: invoiceId,
      paid_amount: paidAmount,
      reference_no: referenceNo
    }).then(res => res.data.data)
  },

  getUnpaidInvoices(page = 1) {
    return api.get('/payments/unpaid', { params: { page } }).then(res => res.data.data)
  },

  sendPaymentReminder(invoiceId) {
    return api.post(`/invoices/${invoiceId}/send-payment-reminder`).then(res => res.data)
  },

  sendPaymentConfirmation(invoiceId) {
    return api.post(`/invoices/${invoiceId}/send-payment-confirmation`).then(res => res.data)
  }
}

export const reportingService = {
  getAccountingDashboard() {
    return api.get('/reports/accounting-dashboard').then(res => res.data.data)
  },

  getProfitLossStatement(filters = {}) {
    return api.get('/reports/profit-loss', { params: filters }).then(res => res.data.data)
  },

  getBalanceSheet(filters = {}) {
    return api.get('/reports/balance-sheet', { params: filters }).then(res => res.data.data)
  },

  getCashFlowStatement(filters = {}) {
    return api.get('/reports/cash-flow', { params: filters }).then(res => res.data.data)
  },

  getAccountsReceivable(filters = {}) {
    return api.get('/reports/accounts-receivable', { params: filters }).then(res => res.data.data)
  },

  getSalesReport(filters = {}) {
    return api.get('/reports/sales', { params: filters }).then(res => res.data.data)
  },

  getTaxReport(filters = {}) {
    return api.get('/reports/tax', { params: filters }).then(res => res.data.data)
  },

  reconcileInvoices() {
    return api.get('/reports/reconcile').then(res => res.data.data)
  }
}

export const taxService = {
  getConfiguration() {
    return api.get('/tax-config').then(res => res.data.data).catch(() => null)
  },

  updateConfiguration(configData) {
    return api.put('/tax-config', configData).then(res => res.data.data)
  },

  validateGSTNumber(gstNumber) {
    return api.post('/tax-validate', { gst_number: gstNumber }).then(res => res.data.data)
  }
}
