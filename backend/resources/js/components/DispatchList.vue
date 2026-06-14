<template>
  <div class="dispatch-list">
    <div class="header">
      <h1>Dispatches</h1>
      <div class="filters">
        <select v-model="filters.status" class="filter-select">
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="in_transit">In Transit</option>
          <option value="delivered">Delivered</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">Loading dispatches...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <table v-else class="dispatches-table">
      <thead>
        <tr>
          <th>Dispatch No</th>
          <th>Batch Reference</th>
          <th>Status</th>
          <th>Items</th>
          <th>Dispatch Date</th>
          <th>Expected Delivery</th>
          <th>Tracking</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="dispatch in dispatches" :key="dispatch.id">
          <td><strong>{{ dispatch.dispatch_no }}</strong></td>
          <td>{{ dispatch.batch?.batch_no }}</td>
          <td><span :class="['status', dispatch.status]">{{ formatStatus(dispatch.status) }}</span></td>
          <td>{{ dispatch.total_items }}</td>
          <td>{{ formatDate(dispatch.dispatch_date) }}</td>
          <td>{{ formatDate(dispatch.expected_delivery_date) }}</td>
          <td>{{ dispatch.tracking_number || '—' }}</td>
          <td>
            <button @click="viewDispatch(dispatch.id)" class="btn-small">View</button>
            <button
              v-if="dispatch.status === 'pending'"
              @click="completeDispatch(dispatch.id)"
              class="btn-small success">
              Complete
            </button>
            <button
              v-if="dispatch.status === 'pending'"
              @click="cancelDispatch(dispatch.id)"
              class="btn-small danger">
              Cancel
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="!loading && pagination" class="pagination">
      <button @click="previousPage" :disabled="pagination.current_page === 1">← Previous</button>
      <span>Page {{ pagination.current_page }} of {{ Math.ceil(pagination.total / pagination.per_page) }}</span>
      <button @click="nextPage" :disabled="pagination.current_page >= Math.ceil(pagination.total / pagination.per_page)">Next →</button>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'DispatchList',
  data() {
    return {
      dispatches: [],
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
    this.fetchDispatches()
  },
  methods: {
    async fetchDispatches() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getDispatches({
          status: this.filters.status,
          page: this.currentPage
        })
        this.dispatches = response.data.data
        this.pagination = response.data.meta.pagination
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load dispatches'
      } finally {
        this.loading = false
      }
    },
    viewDispatch(dispatchId) {
      this.$router.push(`/dispatches/${dispatchId}`)
    },
    async completeDispatch(dispatchId) {
      try {
        await productionService.completeDispatch(dispatchId, {})
        this.fetchDispatches()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to complete dispatch'
      }
    },
    async cancelDispatch(dispatchId) {
      if (!confirm('Are you sure you want to cancel this dispatch?')) {
        return
      }
      try {
        await productionService.cancelDispatch(dispatchId)
        this.fetchDispatches()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to cancel dispatch'
      }
    },
    previousPage() {
      if (this.currentPage > 1) {
        this.currentPage--
        this.fetchDispatches()
      }
    },
    nextPage() {
      if (this.currentPage < Math.ceil(this.pagination.total / this.pagination.per_page)) {
        this.currentPage++
        this.fetchDispatches()
      }
    },
    formatStatus(status) {
      return status.replace('_', ' ').toUpperCase()
    },
    formatDate(date) {
      return date ? new Date(date).toLocaleDateString() : '—'
    }
  },
  watch: {
    'filters.status': function() {
      this.currentPage = 1
      this.fetchDispatches()
    }
  }
}
</script>

<style scoped>
.dispatch-list {
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

.dispatches-table {
  width: 100%;
  border-collapse: collapse;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.dispatches-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
}

.dispatches-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
  font-size: 13px;
}

.dispatches-table tbody tr:hover {
  background-color: #f9f9f9;
}

.status {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
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

.status.cancelled {
  background-color: #f8d7da;
  color: #721c24;
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

.btn-small.success {
  background-color: #4caf50;
}

.btn-small.danger {
  background-color: #d32f2f;
}

.btn-small:hover {
  opacity: 0.9;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
  padding: 20px;
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
