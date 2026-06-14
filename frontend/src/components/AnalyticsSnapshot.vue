<template>
  <div class="analytics-snapshot">
    <div class="header">
      <h2>Daily Analytics Snapshot</h2>
      <div class="snapshot-controls">
        <input v-model="selectedDate" type="date" class="date-input" />
        <button @click="fetchSnapshot" class="btn-primary">Load</button>
        <button @click="createNewSnapshot" class="btn-secondary">Create New</button>
      </div>
    </div>

    <div v-if="snapshot" class="snapshot-data">
      <div class="snapshot-grid">
        <div class="metric-box">
          <div class="metric-title">Total Invoices</div>
          <div class="metric-large">{{ snapshot.total_invoices }}</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Total Revenue</div>
          <div class="metric-large">${{ formatNumber(snapshot.total_revenue) }}</div>
          <div class="metric-sub">Avg: ${{ formatNumber(snapshot.average_invoice_value) }}</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Quantity Sold</div>
          <div class="metric-large">{{ snapshot.total_quantity_sold }}</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Inventory Value</div>
          <div class="metric-large">${{ formatNumber(snapshot.total_inventory_value) }}</div>
          <div class="metric-sub">{{ snapshot.total_stock_units }} units</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Accounts Receivable</div>
          <div class="metric-large">${{ formatNumber(snapshot.accounts_receivable) }}</div>
          <div class="metric-sub">{{ snapshot.invoices_overdue }} overdue</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Tax Collected</div>
          <div class="metric-large">${{ formatNumber(snapshot.tax_collected) }}</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Active Customers</div>
          <div class="metric-large">{{ snapshot.active_customers }}</div>
        </div>

        <div class="metric-box">
          <div class="metric-title">Top Panel Type</div>
          <div class="metric-large">{{ snapshot.topPanelType?.type || 'N/A' }}</div>
        </div>
      </div>

      <div class="performance-section">
        <h3>Performance Status</h3>
        <div :class="['performance-badge', `status-${snapshot.performance_status}`]">
          {{ snapshot.performance_status.toUpperCase() }}
        </div>
        <p class="performance-note">
          <template v-if="snapshot.performance_status === 'excellent'">
            Strong financial performance with low AR and healthy inventory levels.
          </template>
          <template v-else-if="snapshot.performance_status === 'good'">
            Good overall performance. Monitor AR and inventory levels.
          </template>
          <template v-else-if="snapshot.performance_status === 'average'">
            Acceptable performance. Consider reviewing AR and payment follow-ups.
          </template>
          <template v-else>
            Needs improvement. Address AR and inventory management.
          </template>
        </p>
      </div>

      <div class="snapshot-date">
        Snapshot Date: {{ formatDate(snapshot.snapshot_date) }}
      </div>
    </div>

    <div v-else class="empty-state">
      <p>No snapshot data available. Select a date or create a new snapshot.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { analyticsService } from '@/services/api'

const snapshot = ref(null)
const selectedDate = ref(new Date().toISOString().split('T')[0])

const fetchSnapshot = async () => {
  try {
    snapshot.value = await analyticsService.getSnapshot({
      date: selectedDate.value
    })
  } catch (error) {
    console.error('Failed to fetch snapshot:', error)
    snapshot.value = null
  }
}

const createNewSnapshot = async () => {
  try {
    snapshot.value = await analyticsService.createSnapshot()
    selectedDate.value = new Date().toISOString().split('T')[0]
  } catch (error) {
    console.error('Failed to create snapshot:', error)
  }
}

const formatNumber = (num) => {
  return num ? num.toFixed(2) : '0.00'
}

const formatDate = (date) => new Date(date).toLocaleDateString()

onMounted(() => {
  fetchSnapshot()
})
</script>

<style scoped>
.analytics-snapshot {
  padding: 20px;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
  flex-wrap: wrap;
  gap: 20px;
}

.header h2 {
  margin: 0;
}

.snapshot-controls {
  display: flex;
  gap: 10px;
  align-items: center;
}

.date-input {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.btn-primary,
.btn-secondary {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
}

.btn-secondary {
  background-color: #f5f5f5;
  color: #333;
}

.snapshot-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
  margin-bottom: 30px;
}

.metric-box {
  background: white;
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 20px;
  text-align: center;
}

.metric-title {
  font-size: 12px;
  color: #666;
  text-transform: uppercase;
  margin-bottom: 10px;
  letter-spacing: 1px;
}

.metric-large {
  font-size: 32px;
  font-weight: bold;
  color: #1976d2;
  margin-bottom: 5px;
}

.metric-sub {
  font-size: 12px;
  color: #999;
}

.performance-section {
  background: white;
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 20px;
  margin-bottom: 20px;
}

.performance-section h3 {
  margin: 0 0 15px 0;
}

.performance-badge {
  display: inline-block;
  padding: 10px 20px;
  border-radius: 4px;
  font-weight: bold;
  font-size: 16px;
  margin-bottom: 15px;
}

.status-excellent {
  background-color: #e8f5e9;
  color: #388e3c;
}

.status-good {
  background-color: var(--primary-tint);
  color: #1976d2;
}

.status-average {
  background-color: #fff3e0;
  color: #f57c00;
}

.status-poor {
  background-color: #ffebee;
  color: #d32f2f;
}

.performance-note {
  margin: 0;
  font-size: 14px;
  color: #666;
  line-height: 1.5;
}

.snapshot-date {
  text-align: center;
  color: #999;
  font-size: 12px;
  margin-top: 20px;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #999;
}

@media (max-width: 768px) {
  .header {
    flex-direction: column;
    align-items: flex-start;
  }

  .snapshot-controls {
    width: 100%;
    flex-wrap: wrap;
  }

  .snapshot-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
