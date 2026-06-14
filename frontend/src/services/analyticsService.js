import { api } from './api'

export const analyticsService = {
  // Forecasting endpoints
  generateInventoryForecast(params = {}) {
    return api.post('/forecasts/inventory', params).then(res => res.data.data)
  },

  generateDemandForecast(params = {}) {
    return api.post('/forecasts/demand', params).then(res => res.data.data)
  },

  getDemandForecast(params = {}) {
    return api.get('/forecasts/demand', { params }).then(res => res.data.data)
  },

  getUpcomingReorders(params = {}) {
    return api.get('/forecasts/reorders', { params }).then(res => res.data.data)
  },

  // Analytics endpoints
  recordSalesMetric(params = {}) {
    return api.post('/analytics/metrics/sales', params).then(res => res.data.data)
  },

  generateTrendAnalysis(params = {}) {
    return api.post('/analytics/trends', params).then(res => res.data.data)
  },

  getTrendAnalysis(params = {}) {
    return api.get('/analytics/trends', { params }).then(res => res.data.data)
  },

  createSnapshot() {
    return api.post('/analytics/snapshot').then(res => res.data.data)
  },

  getSnapshot(params = {}) {
    return api.get('/analytics/snapshot', { params }).then(res => res.data.data)
  }
}
