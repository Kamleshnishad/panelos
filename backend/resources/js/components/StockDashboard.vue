<template>
  <div class="stock-dashboard">
    <h2>Stock Management Dashboard</h2>

    <div v-if="loading" class="loading">Loading dashboard...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else class="dashboard-content">
      <div class="dashboard-cards">
        <div class="card total-value">
          <div class="card-label">Total Stock Value</div>
          <div class="card-value">${{ formatAmount(data.total_stock_value) }}</div>
        </div>

        <div class="card low-stock">
          <div class="card-label">Low Stock Items</div>
          <div class="card-value">{{ data.low_stock_items?.length || 0 }}</div>
          <div class="card-subtitle">Items below reorder level</div>
        </div>

        <div class="card expiring">
          <div class="card-label">Expiring Soon</div>
          <div class="card-value">{{ data.expiring_soon_chemicals?.length || 0 }}</div>
          <div class="card-subtitle">Chemicals (next 30 days)</div>
        </div>

        <div class="card pending-dispatch">
          <div class="card-label">Pending Dispatch</div>
          <div class="card-value">{{ data.pending_dispatch_batches || 0 }}</div>
          <div class="card-subtitle">Batches ready to ship</div>
        </div>
      </div>

      <div class="alerts-section">
        <h3>Active Alerts</h3>
        <div v-if="data.alerts" class="alert-summary">
          <div class="alert-stat">
            <span class="label">Total:</span>
            <span class="value">{{ data.alerts.total_active }}</span>
          </div>
          <div class="alert-stat low-stock">
            <span class="label">Low Stock:</span>
            <span class="value">{{ data.alerts.low_stock }}</span>
          </div>
          <div class="alert-stat expiring">
            <span class="label">Expiring:</span>
            <span class="value">{{ data.alerts.expiring_soon }}</span>
          </div>
          <div class="alert-stat out">
            <span class="label">Out of Stock:</span>
            <span class="value">{{ data.alerts.out_of_stock }}</span>
          </div>
        </div>
      </div>

      <div class="actions">
        <router-link to="/stock/coils" class="btn-primary">Coil Inventory</router-link>
        <router-link to="/stock/chemicals" class="btn-primary">Chemical Inventory</router-link>
        <router-link to="/stock/alerts" class="btn-secondary">View All Alerts</router-link>
        <router-link to="/dispatches" class="btn-secondary">Dispatches</router-link>
      </div>

      <div class="recent-transactions">
        <h3>Recent Transactions</h3>
        <table v-if="data.recent_transactions?.length" class="transactions-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Type</th>
              <th>Quantity</th>
              <th>By</th>
              <th>Time</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tx in data.recent_transactions" :key="tx.id">
              <td>{{ tx.item_name }}</td>
              <td><span :class="['badge', tx.type]">{{ tx.type }}</span></td>
              <td>{{ tx.quantity }} {{ tx.unit }}</td>
              <td>{{ tx.created_by }}</td>
              <td>{{ formatDate(tx.created_at) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else class="no-data">No recent transactions</div>
      </div>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'StockDashboard',
  data() {
    return {
      data: {},
      loading: true,
      error: null
    }
  },
  mounted() {
    this.fetchDashboard()
    this.interval = setInterval(this.fetchDashboard, 30000)
  },
  beforeUnmount() {
    clearInterval(this.interval)
  },
  methods: {
    async fetchDashboard() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getStockDashboard()
        this.data = response.data.data
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load dashboard'
      } finally {
        this.loading = false
      }
    },
    formatAmount(amount) {
      return parseFloat(amount || 0).toFixed(2)
    },
    formatDate(date) {
      return new Date(date).toLocaleString()
    }
  }
}
</script>

<style scoped>
.stock-dashboard {
  padding: 20px;
}

.loading,
.error {
  padding: 20px;
  text-align: center;
  font-size: 16px;
}

.error {
  color: #d32f2f;
  background-color: #ffebee;
  border-radius: 4px;
}

.dashboard-content {
  background-color: white;
  border-radius: 8px;
  padding: 20px;
}

.dashboard-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.card {
  padding: 20px;
  border-radius: 8px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.card.total-value {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.card.low-stock {
  background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.card.expiring {
  background: linear-gradient(135deg, #ffa500 0%, #ff6347 100%);
}

.card.pending-dispatch {
  background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.card-label {
  font-size: 12px;
  opacity: 0.9;
  margin-bottom: 8px;
}

.card-value {
  font-size: 28px;
  font-weight: bold;
  margin-bottom: 4px;
}

.card-subtitle {
  font-size: 11px;
  opacity: 0.8;
}

.alerts-section {
  margin-bottom: 30px;
  padding: 20px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.alert-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 15px;
  margin-top: 15px;
}

.alert-stat {
  padding: 12px;
  background-color: white;
  border-radius: 4px;
  border-left: 4px solid #667eea;
}

.alert-stat.low-stock {
  border-left-color: #f5576c;
}

.alert-stat.expiring {
  border-left-color: #ffa500;
}

.alert-stat.out {
  border-left-color: #d32f2f;
}

.alert-stat .label {
  font-size: 12px;
  color: #666;
  font-weight: 600;
}

.alert-stat .value {
  display: block;
  font-size: 20px;
  font-weight: bold;
  color: #333;
  margin-top: 4px;
}

.actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 30px;
}

.btn-primary,
.btn-secondary {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  display: inline-block;
}

.btn-primary {
  background-color: #667eea;
  color: white;
}

.btn-primary:hover {
  background-color: #5568d3;
}

.btn-secondary {
  background-color: #e0e0e0;
  color: #333;
}

.btn-secondary:hover {
  background-color: #bdbdbd;
}

.recent-transactions {
  margin-top: 30px;
}

.transactions-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 15px;
}

.transactions-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
  border-bottom: 2px solid #ddd;
}

.transactions-table td {
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

.badge.in {
  background-color: #d4edda;
  color: #155724;
}

.badge.out {
  background-color: #f8d7da;
  color: #721c24;
}

.badge.adjustment {
  background-color: #cfe2ff;
  color: #084298;
}

.no-data {
  padding: 20px;
  text-align: center;
  color: #999;
}
</style>
