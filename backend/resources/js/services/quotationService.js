import axios from 'axios'

const API_BASE = '/api'

const quotationService = {
  /**
   * Get list of quotations
   */
  list(params = {}) {
    return axios.get(`${API_BASE}/quotations`, { params })
  },

  /**
   * Create new quotation
   */
  create(data) {
    return axios.post(`${API_BASE}/quotations`, data)
  },

  /**
   * Get quotation details
   */
  get(id) {
    return axios.get(`${API_BASE}/quotations/${id}`)
  },

  /**
   * Update quotation
   */
  update(id, data) {
    return axios.put(`${API_BASE}/quotations/${id}`, data)
  },

  /**
   * Delete quotation
   */
  delete(id) {
    return axios.delete(`${API_BASE}/quotations/${id}`)
  },

  /**
   * Send quotation
   */
  send(id) {
    return axios.post(`${API_BASE}/quotations/${id}/send`)
  },

  /**
   * Accept quotation
   */
  accept(id) {
    return axios.post(`${API_BASE}/quotations/${id}/accept`)
  },

  /**
   * Reject quotation
   */
  reject(id) {
    return axios.post(`${API_BASE}/quotations/${id}/reject`)
  },

  /**
   * Download quotation as PDF
   */
  downloadPdf(id) {
    return axios.get(`${API_BASE}/quotations/${id}/pdf`, {
      responseType: 'blob',
    })
  },

  /**
   * Add accessory to quotation
   */
  addAccessory(quotationId, data) {
    return axios.post(`${API_BASE}/quotations/${quotationId}/accessories`, data)
  },

  /**
   * Remove accessory from quotation
   */
  removeAccessory(quotationId, accessoryId) {
    return axios.delete(`${API_BASE}/quotations/${quotationId}/accessories/${accessoryId}`)
  },
}

export default quotationService
