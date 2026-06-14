<template>
  <div class="order-detail">
    <div class="detail-header">
      <h2>Order {{ order?.order_no }}</h2>
      <router-link to="/orders" class="btn-back">← Back</router-link>
    </div>

    <div v-if="loading" class="loading">Loading order details...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else-if="order" class="detail-content">
      <div class="order-summary">
        <div class="summary-grid">
          <div class="summary-item">
            <span class="label">Customer:</span>
            <span class="value">{{ order.customer.name }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Status:</span>
            <span :class="['status', order.status]">{{ formatStatus(order.status) }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Created:</span>
            <span class="value">{{ formatDate(order.created_at) }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Total Amount:</span>
            <span class="value">${{ formatAmount(order.total_amount) }}</span>
          </div>
        </div>
      </div>

      <div class="order-items">
        <h3>Order Items</h3>
        <table class="items-table">
          <thead>
            <tr>
              <th>Panel Type</th>
              <th>Quantity</th>
              <th>Unit Price</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in order.items" :key="item.id">
              <td>{{ item.panel_type }}</td>
              <td>{{ item.quantity }}</td>
              <td>${{ formatAmount(item.unit_price) }}</td>
              <td>${{ formatAmount(item.amount) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="order-totals">
          <div class="total-row">
            <span class="label">Subtotal:</span>
            <span class="value">${{ formatAmount(order.subtotal) }}</span>
          </div>
          <div class="total-row">
            <span class="label">Tax:</span>
            <span class="value">${{ formatAmount(order.tax) }}</span>
          </div>
          <div class="total-row grand-total">
            <span class="label">Total:</span>
            <span class="value">${{ formatAmount(order.total_amount) }}</span>
          </div>
        </div>
      </div>

      <div v-if="order.status === 'pending'" class="create-batch-section">
        <button @click="navigateCreateBatch" class="btn-primary">+ Create Batch</button>
      </div>

      <div v-if="batches.length > 0" class="batches-section">
        <h3>Associated Batches</h3>
        <table class="batches-table">
          <thead>
            <tr>
              <th>Batch No</th>
              <th>Status</th>
              <th>Planned Qty</th>
              <th>Completed Qty</th>
              <th>Progress</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="batch in batches" :key="batch.id">
              <td><strong>{{ batch.batch_no }}</strong></td>
              <td><span :class="['status', batch.status]">{{ formatStatus(batch.status) }}</span></td>
              <td>{{ batch.planned_quantity }}</td>
              <td>{{ batch.completed_quantity }}</td>
              <td>
                <div class="progress-bar">
                  <div class="progress-fill" :style="{ width: getProgressPercent(batch) + '%' }"></div>
                </div>
              </td>
              <td>
                <button @click="viewBatch(batch.id)" class="btn-small">View</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="no-batches">
        <p>No batches created yet for this order.</p>
      </div>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'OrderDetail',
  props: {
    orderId: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      order: null,
      batches: [],
      loading: true,
      error: null
    }
  },
  mounted() {
    this.fetchOrderDetail()
  },
  methods: {
    async fetchOrderDetail() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getOrder(this.orderId)
        this.order = response.data.data
        await this.fetchBatches()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load order details'
      } finally {
        this.loading = false
      }
    },
    async fetchBatches() {
      try {
        const response = await productionService.getBatchesByOrder(this.orderId)
        this.batches = response.data.data || []
      } catch (err) {
        this.batches = []
      }
    },
    navigateCreateBatch() {
      this.$router.push(`/orders/${this.orderId}/create-batch`)
    },
    viewBatch(batchId) {
      this.$router.push(`/batches/${batchId}`)
    },
    formatStatus(status) {
      return status.replace('_', ' ').toUpperCase()
    },
    formatAmount(amount) {
      return parseFloat(amount).toFixed(2)
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString()
    },
    getProgressPercent(batch) {
      if (batch.planned_quantity === 0) return 0
      return (batch.completed_quantity / batch.planned_quantity) * 100
    }
  }
}
</script>

<style scoped>
.order-detail {
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

.btn-back:hover {
  background-color: #bdbdbd;
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
}

.detail-content {
  background-color: white;
  border-radius: 8px;
  padding: 20px;
}

.order-summary {
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

.status.in_production {
  background-color: #cce5ff;
  color: #004085;
}

.status.completed {
  background-color: #d4edda;
  color: #155724;
}

.status.cancelled {
  background-color: #f8d7da;
  color: #721c24;
}

.order-items {
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

.order-totals {
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

.create-batch-section {
  margin: 20px 0;
  text-align: center;
}

.batches-section {
  margin-top: 30px;
}

.batches-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}

.batches-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
  border-bottom: 2px solid #ddd;
}

.batches-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
  font-size: 13px;
}

.progress-bar {
  width: 100%;
  height: 20px;
  background-color: #e0e0e0;
  border-radius: 4px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background-color: #4caf50;
  transition: width 0.3s;
}

.btn-primary,
.btn-small {
  padding: 10px 20px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.btn-small {
  padding: 6px 12px;
  font-size: 12px;
}

.btn-primary:hover,
.btn-small:hover {
  background-color: #1565c0;
}

.no-batches {
  padding: 20px;
  text-align: center;
  color: #666;
  font-size: 14px;
  background-color: #f9f9f9;
  border-radius: 4px;
}
</style>
