<template>
  <div class="dispatch-detail">
    <div class="detail-header">
      <h2>Dispatch {{ dispatch?.dispatch_no }}</h2>
      <router-link to="/dispatches" class="btn-back">← Back</router-link>
    </div>

    <div v-if="loading" class="loading">Loading dispatch details...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else-if="dispatch" class="detail-content">
      <div class="dispatch-summary">
        <div class="summary-grid">
          <div class="summary-item">
            <span class="label">Status:</span>
            <span :class="['status', dispatch.status]">{{ formatStatus(dispatch.status) }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Batch Reference:</span>
            <router-link :to="`/batches/${dispatch.batch_id}`" class="value-link">{{ dispatch.batch?.batch_no }}</router-link>
          </div>
          <div class="summary-item">
            <span class="label">Dispatch Date:</span>
            <span class="value">{{ formatDate(dispatch.dispatch_date) }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Expected Delivery:</span>
            <span class="value">{{ formatDate(dispatch.expected_delivery_date) }}</span>
          </div>
        </div>
      </div>

      <div class="dispatch-actions">
        <button
          v-if="dispatch.status === 'pending'"
          @click="allocateStock"
          class="btn-primary"
          :disabled="actionLoading">
          {{ actionLoading ? 'Allocating...' : 'Allocate Stock' }}
        </button>
        <button
          v-if="dispatch.status === 'pending' && isFullyAllocated"
          @click="completeDispatch"
          class="btn-success"
          :disabled="actionLoading">
          {{ actionLoading ? 'Completing...' : 'Complete Dispatch' }}
        </button>
        <button
          v-if="dispatch.status === 'pending'"
          @click="cancelDispatch"
          class="btn-danger"
          :disabled="actionLoading">
          {{ actionLoading ? 'Cancelling...' : 'Cancel Dispatch' }}
        </button>
      </div>

      <div class="dispatch-items">
        <h3>Items to Dispatch</h3>
        <table class="items-table">
          <thead>
            <tr>
              <th>Panel Type</th>
              <th>Quantity</th>
              <th>Unit Price</th>
              <th>Total Amount</th>
              <th>Allocated</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in dispatch.items" :key="item.id">
              <td>{{ item.panel_type?.name }}</td>
              <td>{{ item.quantity }}</td>
              <td>${{ formatAmount(item.unit_price) }}</td>
              <td>${{ formatAmount(item.amount) }}</td>
              <td>
                <span v-if="isItemAllocated(item.id)" class="badge success">✓</span>
                <span v-else class="badge warning">✗</span>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="dispatch-totals">
          <div class="total-row">
            <span class="label">Total Items:</span>
            <span class="value">{{ dispatch.total_items }}</span>
          </div>
          <div class="total-row grand-total">
            <span class="label">Total Amount:</span>
            <span class="value">${{ formatAmount(dispatch.total_amount) }}</span>
          </div>
        </div>
      </div>

      <div class="dispatch-info">
        <h3>Dispatch Information</h3>
        <div class="info-grid">
          <div class="info-item">
            <span class="label">Customer Address:</span>
            <span class="value">{{ dispatch.customer_address || '—' }}</span>
          </div>
          <div class="info-item">
            <span class="label">Tracking Number:</span>
            <span class="value">{{ dispatch.tracking_number || '—' }}</span>
          </div>
          <div class="info-item">
            <span class="label">Notes:</span>
            <span class="value">{{ dispatch.notes || '—' }}</span>
          </div>
        </div>
      </div>

      <div class="challan-section">
        <h3>Challan</h3>
        <button @click="viewChallan" class="btn-secondary">📄 View Challan</button>
        <button @click="downloadChallan" class="btn-secondary">📥 Download PDF</button>
      </div>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'DispatchDetail',
  props: {
    dispatchId: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      dispatch: null,
      loading: true,
      error: null,
      actionLoading: false
    }
  },
  computed: {
    isFullyAllocated() {
      if (!this.dispatch?.allocations) return false
      return this.dispatch.allocations.filter(a => a.status === 'allocated').length === this.dispatch.items?.length
    }
  },
  mounted() {
    this.fetchDispatchDetail()
  },
  methods: {
    async fetchDispatchDetail() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getDispatch(this.dispatchId)
        this.dispatch = response.data.data
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load dispatch details'
      } finally {
        this.loading = false
      }
    },
    async allocateStock() {
      try {
        this.actionLoading = true
        this.error = null
        await productionService.allocateDispatchStock(this.dispatchId)
        this.fetchDispatchDetail()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to allocate stock'
      } finally {
        this.actionLoading = false
      }
    },
    async completeDispatch() {
      try {
        this.actionLoading = true
        this.error = null
        await productionService.completeDispatch(this.dispatchId, {})
        this.fetchDispatchDetail()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to complete dispatch'
      } finally {
        this.actionLoading = false
      }
    },
    async cancelDispatch() {
      if (!confirm('Are you sure you want to cancel this dispatch?')) {
        return
      }
      try {
        this.actionLoading = true
        this.error = null
        await productionService.cancelDispatch(this.dispatchId)
        this.$router.push('/dispatches')
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to cancel dispatch'
        this.actionLoading = false
      }
    },
    async viewChallan() {
      try {
        const response = await productionService.getChallan(this.dispatchId)
        this.$router.push(`/dispatches/${this.dispatchId}/challan`)
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load challan'
      }
    },
    async downloadChallan() {
      try {
        const response = await productionService.getChallanPdf(this.dispatchId)
        // Implement PDF download
        alert('Challan PDF download ready')
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to download challan'
      }
    },
    isItemAllocated(itemId) {
      return this.dispatch?.allocations?.some(a => a.id === itemId && a.status === 'allocated')
    },
    formatStatus(status) {
      return status.replace('_', ' ').toUpperCase()
    },
    formatAmount(amount) {
      return parseFloat(amount || 0).toFixed(2)
    },
    formatDate(date) {
      return date ? new Date(date).toLocaleDateString() : '—'
    }
  }
}
</script>

<style scoped>
.dispatch-detail {
  padding: 20px;
  background-color: #f9f9f9;
  min-height: 100vh;
}

.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
}

.btn-back {
  padding: 8px 16px;
  background-color: #e0e0e0;
  color: #333;
  text-decoration: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.loading,
.error {
  padding: 20px;
  text-align: center;
  font-size: 16px;
  background-color: white;
  border-radius: 8px;
}

.error {
  color: #d32f2f;
  background-color: #ffebee;
  border: 1px solid #ef5350;
}

.detail-content {
  background-color: white;
  border-radius: 8px;
  padding: 20px;
}

.dispatch-summary {
  margin-bottom: 30px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
}

.summary-item {
  display: flex;
  flex-direction: column;
}

.label {
  font-size: 12px;
  color: #666;
  font-weight: 600;
  margin-bottom: 4px;
}

.value {
  font-size: 16px;
  color: #333;
  font-weight: 500;
}

.value-link {
  font-size: 16px;
  color: #1976d2;
  font-weight: 500;
  text-decoration: none;
}

.status {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  width: fit-content;
}

.status.pending {
  background-color: #fff3cd;
  color: #856404;
}

.status.in_transit {
  background-color: #cce5ff;
  color: #004085;
}

.status.delivered {
  background-color: #d4edda;
  color: #155724;
}

.dispatch-actions {
  display: flex;
  gap: 10px;
  margin-bottom: 30px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.btn-primary,
.btn-success,
.btn-danger,
.btn-secondary {
  padding: 10px 20px;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.btn-primary {
  background-color: #1976d2;
}

.btn-success {
  background-color: #4caf50;
}

.btn-danger {
  background-color: #d32f2f;
}

.btn-secondary {
  background-color: #757575;
}

.btn-primary:hover:not(:disabled),
.btn-success:hover:not(:disabled),
.btn-danger:hover:not(:disabled),
.btn-secondary:hover:not(:disabled) {
  opacity: 0.9;
}

.btn-primary:disabled,
.btn-success:disabled,
.btn-danger:disabled {
  background-color: #ccc;
  cursor: not-allowed;
}

.dispatch-items {
  margin-bottom: 30px;
}

.items-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 20px;
}

.items-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
  border-bottom: 2px solid #ddd;
}

.items-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
  font-size: 13px;
}

.badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.badge.success {
  background-color: #d4edda;
  color: #155724;
}

.badge.warning {
  background-color: #fff3cd;
  color: #856404;
}

.dispatch-totals {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
  max-width: 300px;
  margin-left: auto;
}

.total-row {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  font-size: 14px;
}

.total-row.grand-total {
  border-top: 2px solid #ddd;
  padding-top: 10px;
  font-weight: bold;
  font-size: 16px;
}

.dispatch-info {
  margin-bottom: 30px;
  padding: 20px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  margin-top: 15px;
}

.info-item {
  display: flex;
  flex-direction: column;
}

.challan-section {
  padding: 20px;
  background-color: #e3f2fd;
  border-radius: 4px;
  text-align: center;
}

.challan-section button {
  margin: 10px 10px 0 0;
}
</style>
