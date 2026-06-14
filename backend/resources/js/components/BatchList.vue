<template>
  <div class="batch-list">
    <div class="header">
      <h1>Production Batches</h1>
      <div class="filters">
        <select v-model="filters.status" class="filter-select">
          <option value="">All Status</option>
          <option value="draft">Draft</option>
          <option value="in_progress">In Progress</option>
          <option value="qc_pending">QC Pending</option>
          <option value="qc_passed">QC Passed</option>
          <option value="qc_failed">QC Failed</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">Loading batches...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <table v-else class="batches-table">
      <thead>
        <tr>
          <th>Batch No</th>
          <th>Order</th>
          <th>Status</th>
          <th>Quantity</th>
          <th>Progress</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="batch in batches" :key="batch.id">
          <td><strong>{{ batch.batch_no }}</strong></td>
          <td>{{ batch.order?.order_no }}</td>
          <td><span :class="['status', batch.status]">{{ formatStatus(batch.status) }}</span></td>
          <td>{{ batch.planned_quantity }} / {{ batch.completed_quantity }}</td>
          <td>
            <div class="progress-bar">
              <div class="progress-fill" :style="{ width: getProgressPercent(batch) + '%' }"></div>
            </div>
          </td>
          <td>
            <button @click="viewBatch(batch.id)" class="btn-small">View</button>
            <button @click="startProduction(batch.id)" class="btn-small" v-if="batch.status === 'draft'">Start</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="!loading && pagination" class="pagination">
      <button @click="previousPage" :disabled="pagination.current_page === 1">← Previous</button>
      <span>Page {{ pagination.current_page }}</span>
      <button @click="nextPage" :disabled="pagination.current_page >= Math.ceil(pagination.total / pagination.per_page)">Next →</button>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'BatchList',
  data() {
    return {
      batches: [],
      loading: true,
      error: null,
      filters: {
        status: ''
      },
      pagination: null,
      currentPage: 1
    }
  },
  mounted() {
    this.fetchBatches()
  },
  methods: {
    async fetchBatches() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getBatches({
          status: this.filters.status,
          page: this.currentPage
        })
        this.batches = response.data.data
        this.pagination = response.data.meta.pagination
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load batches'
      } finally {
        this.loading = false
      }
    },
    viewBatch(batchId) {
      this.$router.push(`/batches/${batchId}`)
    },
    async startProduction(batchId) {
      try {
        await productionService.startProduction(batchId)
        this.fetchBatches()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to start production'
      }
    },
    previousPage() {
      if (this.currentPage > 1) {
        this.currentPage--
        this.fetchBatches()
      }
    },
    nextPage() {
      if (this.currentPage < Math.ceil(this.pagination.total / this.pagination.per_page)) {
        this.currentPage++
        this.fetchBatches()
      }
    },
    formatStatus(status) {
      return status.replace('_', ' ').toUpperCase()
    },
    getProgressPercent(batch) {
      if (batch.planned_quantity === 0) return 0
      return (batch.completed_quantity / batch.planned_quantity) * 100
    }
  },
  watch: {
    'filters.status': function() {
      this.currentPage = 1
      this.fetchBatches()
    }
  }
}
</script>

<style scoped>
.batch-list {
  padding: 20px;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.filters {
  display: flex;
  gap: 10px;
}

.filter-select {
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
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

.batches-table {
  width: 100%;
  border-collapse: collapse;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.batches-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
}

.batches-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
}

.batches-table tbody tr:hover {
  background-color: #f9f9f9;
}

.status {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
}

.status.draft {
  background-color: #e0e0e0;
  color: #333;
}

.status.in_progress {
  background-color: #cce5ff;
  color: #004085;
}

.status.qc_pending {
  background-color: #fff3cd;
  color: #856404;
}

.status.qc_passed {
  background-color: #d4edda;
  color: #155724;
}

.status.qc_failed {
  background-color: #f8d7da;
  color: #721c24;
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

.btn-small {
  padding: 6px 12px;
  margin-right: 5px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
}

.btn-small:hover {
  background-color: #1565c0;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
}

.pagination button {
  padding: 8px 12px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.pagination button:disabled {
  background-color: #ccc;
  cursor: not-allowed;
}
</style>
