<template>
  <div class="order-list">
    <div class="header">
      <h1>Orders</h1>
      <div class="filters">
        <input v-model="filters.search" type="text" placeholder="Search order no..." class="search-input">
        <select v-model="filters.status" class="filter-select">
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="in_production">In Production</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">Loading orders...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <table v-else class="orders-table">
      <thead>
        <tr>
          <th @click="sort('order_no')">Order No {{ sortBy === 'order_no' ? (sortOrder === 'asc' ? '↑' : '↓') : '' }}</th>
          <th @click="sort('customer_id')">Customer {{ sortBy === 'customer_id' ? (sortOrder === 'asc' ? '↑' : '↓') : '' }}</th>
          <th>Status</th>
          <th @click="sort('total_amount')">Amount {{ sortBy === 'total_amount' ? (sortOrder === 'asc' ? '↑' : '↓') : '' }}</th>
          <th @click="sort('created_at')">Created {{ sortBy === 'created_at' ? (sortOrder === 'asc' ? '↑' : '↓') : '' }}</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="order in orders" :key="order.id">
          <td><strong>{{ order.order_no }}</strong></td>
          <td>{{ order.customer.name }}</td>
          <td><span :class="['status', order.status]">{{ formatStatus(order.status) }}</span></td>
          <td>${{ formatAmount(order.total_amount) }}</td>
          <td>{{ formatDate(order.created_at) }}</td>
          <td>
            <button @click="viewOrder(order.id)" class="btn-small">View</button>
            <button @click="createBatch(order.id)" class="btn-small" v-if="order.status === 'pending'">Create Batch</button>
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
  name: 'OrderList',
  data() {
    return {
      orders: [],
      loading: true,
      error: null,
      filters: {
        search: '',
        status: ''
      },
      sortBy: 'created_at',
      sortOrder: 'desc',
      pagination: null
    }
  },
  mounted() {
    this.fetchOrders()
  },
  methods: {
    async fetchOrders() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getOrders({
          search: this.filters.search,
          status: this.filters.status,
          sort_by: this.sortBy,
          sort_order: this.sortOrder,
          page: this.currentPage || 1
        })
        this.orders = response.data.data
        this.pagination = response.data.meta.pagination
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load orders'
      } finally {
        this.loading = false
      }
    },
    sort(field) {
      if (this.sortBy === field) {
        this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc'
      } else {
        this.sortBy = field
        this.sortOrder = 'asc'
      }
      this.currentPage = 1
      this.fetchOrders()
    },
    viewOrder(orderId) {
      this.$router.push(`/orders/${orderId}`)
    },
    createBatch(orderId) {
      this.$router.push(`/orders/${orderId}/create-batch`)
    },
    previousPage() {
      if (this.pagination.current_page > 1) {
        this.currentPage = this.pagination.current_page - 1
        this.fetchOrders()
      }
    },
    nextPage() {
      if (this.pagination.current_page < Math.ceil(this.pagination.total / this.pagination.per_page)) {
        this.currentPage = this.pagination.current_page + 1
        this.fetchOrders()
      }
    },
    formatStatus(status) {
      return status.replace('_', ' ').toUpperCase()
    },
    formatAmount(amount) {
      return parseFloat(amount).toFixed(2)
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString()
    }
  },
  watch: {
    'filters.search': 'fetchOrders',
    'filters.status': 'fetchOrders'
  }
}
</script>

<style scoped>
.order-list {
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

.search-input,
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

.orders-table {
  width: 100%;
  border-collapse: collapse;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.orders-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  cursor: pointer;
  user-select: none;
}

.orders-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
}

.orders-table tbody tr:hover {
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
