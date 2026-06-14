import api from './api'

export default {
  // Orders
  getOrders(params = {}) {
    return api.get('/orders', { params })
  },

  getOrder(id) {
    return api.get(`/orders/${id}`)
  },

  updateOrder(id, data) {
    return api.put(`/orders/${id}`, data)
  },

  // Production Batches
  getBatches(params = {}) {
    return api.get('/batches', { params })
  },

  createBatch(orderId, data = {}) {
    return api.post(`/orders/${orderId}/batches`, data)
  },

  getBatch(id) {
    return api.get(`/batches/${id}`)
  },

  updateBatch(id, data) {
    return api.put(`/batches/${id}`, data)
  },

  deleteBatch(id) {
    return api.delete(`/batches/${id}`)
  },

  getBatchesByOrder(orderId, params = {}) {
    return api.get(`/orders/${orderId}/batches`, { params })
  },

  startProduction(batchId) {
    return api.post(`/batches/${batchId}/start`)
  },

  completeBatch(batchId, data = {}) {
    return api.post(`/batches/${batchId}/complete`, data)
  },

  // Production Stages
  getStages(params = {}) {
    return api.get('/production-stages', { params })
  },

  createStage(data) {
    return api.post('/production-stages', data)
  },

  getStage(id) {
    return api.get(`/production-stages/${id}`)
  },

  updateStage(id, data) {
    return api.put(`/production-stages/${id}`, data)
  },

  deleteStage(id) {
    return api.delete(`/production-stages/${id}`)
  },

  // Batch Stage Logs (Workflow)
  getBatchTimeline(batchId) {
    return api.get(`/batches/${batchId}/timeline`)
  },

  getBatchProgress(batchId) {
    return api.get(`/batches/${batchId}/progress`)
  },

  startStage(batchId, stageId, data = {}) {
    return api.post(`/batches/${batchId}/stages/${stageId}/start`, data)
  },

  completeStage(batchId, stageId, data = {}) {
    return api.post(`/batches/${batchId}/stages/${stageId}/complete`, data)
  },

  // Quality Control
  getQCEntries(params = {}) {
    return api.get('/quality-control', { params })
  },

  createQC(batchId, data) {
    return api.post(`/batches/${batchId}/qc`, data)
  },

  getQCForBatch(batchId) {
    return api.get(`/batches/${batchId}/qc`)
  },

  getQCEntry(id) {
    return api.get(`/quality-control/${id}`)
  },

  approveQC(id, data = {}) {
    return api.post(`/quality-control/${id}/approve`, data)
  },

  getQCStatistics(params = {}) {
    return api.get('/quality-control/statistics', { params })
  },

  // Cutting Schedule
  calculateCuttingSchedule(batchId) {
    return api.post(`/batches/${batchId}/calculate-cutting-schedule`)
  },

  getCuttingInstructions(batchId) {
    return api.get(`/batches/${batchId}/cutting-schedule`)
  },

  getCuttingScheduleJson(batchId) {
    return api.get(`/batches/${batchId}/cutting-schedule/json`)
  },

  // Stock Management
  getCoilInventory(params = {}) {
    return api.get('/stock/coils', { params })
  },

  getCoilDetail(id) {
    return api.get(`/stock/coils/${id}`)
  },

  addCoilStock(id, data) {
    return api.post(`/stock/coils/${id}/add`, data)
  },

  removeCoilStock(id, data) {
    return api.post(`/stock/coils/${id}/remove`, data)
  },

  adjustCoilStock(id, data) {
    return api.post(`/stock/coils/${id}/adjust`, data)
  },

  getChemicalInventory(params = {}) {
    return api.get('/stock/chemicals', { params })
  },

  getChemicalDetail(id) {
    return api.get(`/stock/chemicals/${id}`)
  },

  addChemicalStock(id, data) {
    return api.post(`/stock/chemicals/${id}/add`, data)
  },

  removeChemicalStock(id, data) {
    return api.post(`/stock/chemicals/${id}/remove`, data)
  },

  adjustChemicalStock(id, data) {
    return api.post(`/stock/chemicals/${id}/adjust`, data)
  },

  getStockTransactions(params = {}) {
    return api.get('/stock/transactions', { params })
  },

  getStockTransaction(id) {
    return api.get(`/stock/transactions/${id}`)
  },

  getStockAlerts(params = {}) {
    return api.get('/stock/alerts', { params })
  },

  resolveStockAlert(id) {
    return api.post(`/stock/alerts/${id}/resolve`)
  },

  getStockDashboard() {
    return api.get('/stock/dashboard')
  },

  getInventoryReport() {
    return api.get('/stock/report')
  },

  // Dispatch Management
  getDispatches(params = {}) {
    return api.get('/dispatches', { params })
  },

  createDispatch(batchId, data) {
    return api.post(`/batches/${batchId}/dispatch`, data)
  },

  getDispatch(id) {
    return api.get(`/dispatches/${id}`)
  },

  updateDispatch(id, data) {
    return api.put(`/dispatches/${id}`, data)
  },

  cancelDispatch(id) {
    return api.delete(`/dispatches/${id}`)
  },

  allocateDispatchStock(id) {
    return api.post(`/dispatches/${id}/allocate`)
  },

  completeDispatch(id, data) {
    return api.post(`/dispatches/${id}/complete`, data)
  },

  getChallan(id) {
    return api.get(`/dispatches/${id}/challan`)
  },

  getChallanPdf(id) {
    return api.get(`/dispatches/${id}/challan/pdf`)
  },

  getDispatchesByBatch(batchId) {
    return api.get(`/batches/${batchId}/dispatches`)
  }
}
