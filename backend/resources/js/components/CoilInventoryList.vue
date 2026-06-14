<template>
  <div class="coil-inventory">
    <div class="header">
      <h1>Coil Inventory</h1>
      <div class="filters">
        <input v-model="search" type="text" placeholder="Search coil..." class="search-input">
        <label class="checkbox">
          <input v-model="showLowStock" type="checkbox">
          Low Stock Only
        </label>
      </div>
    </div>

    <div v-if="loading" class="loading">Loading inventory...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <table v-else class="inventory-table">
      <thead>
        <tr>
          <th>Coil Type</th>
          <th>Quantity in Stock</th>
          <th>Reorder Level</th>
          <th>Available (w/o allocation)</th>
          <th>Status</th>
          <th>Last Update</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="stock in coils" :key="stock.id" :class="{ 'low-stock-row': stock.is_low_stock }">
          <td><strong>{{ stock.coil?.name }}</strong></td>
          <td>{{ formatQuantity(stock.quantity_in_stock) }}</td>
          <td>{{ formatQuantity(stock.reorder_level) }}</td>
          <td>{{ formatQuantity(getAvailable(stock)) }}</td>
          <td>
            <span v-if="stock.is_low_stock" class="badge danger">LOW STOCK</span>
            <span v-else class="badge success">OK</span>
          </td>
          <td>{{ formatDate(stock.last_stock_in || stock.created_at) }}</td>
          <td>
            <button @click="viewDetail(stock.id)" class="btn-small">View</button>
            <button @click="openAddModal(stock.id)" class="btn-small primary">Add</button>
            <button @click="openRemoveModal(stock.id)" class="btn-small danger">Remove</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="!loading && pagination" class="pagination">
      <button @click="previousPage" :disabled="pagination.current_page === 1">← Previous</button>
      <span>Page {{ pagination.current_page }} of {{ Math.ceil(pagination.total / pagination.per_page) }}</span>
      <button @click="nextPage" :disabled="pagination.current_page >= Math.ceil(pagination.total / pagination.per_page)">Next →</button>
    </div>

    <!-- Add Stock Modal -->
    <div v-if="showAddModal" class="modal-overlay" @click="showAddModal = false">
      <div class="modal" @click.stop>
        <h3>Add Stock</h3>
        <div class="form-group">
          <label>Quantity</label>
          <input v-model.number="addForm.quantity" type="number" min="0.01" step="0.01" placeholder="0.00">
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea v-model="addForm.notes" placeholder="Reason for stock addition..."></textarea>
        </div>
        <div class="modal-actions">
          <button @click="submitAdd" class="btn-primary" :disabled="submitting">{{ submitting ? 'Adding...' : 'Add' }}</button>
          <button @click="showAddModal = false" class="btn-secondary">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Remove Stock Modal -->
    <div v-if="showRemoveModal" class="modal-overlay" @click="showRemoveModal = false">
      <div class="modal" @click.stop>
        <h3>Remove Stock</h3>
        <div class="form-group">
          <label>Quantity</label>
          <input v-model.number="removeForm.quantity" type="number" min="0.01" step="0.01" placeholder="0.00">
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea v-model="removeForm.notes" placeholder="Reason for stock removal..."></textarea>
        </div>
        <div class="modal-actions">
          <button @click="submitRemove" class="btn-primary" :disabled="submitting">{{ submitting ? 'Removing...' : 'Remove' }}</button>
          <button @click="showRemoveModal = false" class="btn-secondary">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'CoilInventoryList',
  data() {
    return {
      coils: [],
      loading: true,
      error: null,
      search: '',
      showLowStock: false,
      showAddModal: false,
      showRemoveModal: false,
      submitting: false,
      activeStockId: null,
      addForm: { quantity: null, notes: '' },
      removeForm: { quantity: null, notes: '' },
      pagination: null,
      currentPage: 1
    }
  },
  mounted() {
    this.fetchInventory()
  },
  methods: {
    async fetchInventory() {
      try {
        this.loading = true
        this.error = null
        const params = {
          page: this.currentPage,
          low_stock: this.showLowStock ? 1 : 0,
          search: this.search || undefined
        }
        const response = await productionService.getCoilInventory(params)
        this.coils = response.data.data
        this.pagination = response.data.meta?.pagination
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load inventory'
      } finally {
        this.loading = false
      }
    },
    viewDetail(stockId) {
      this.$router.push(`/stock/coils/${stockId}`)
    },
    openAddModal(stockId) {
      this.activeStockId = stockId
      this.addForm = { quantity: null, notes: '' }
      this.showAddModal = true
    },
    openRemoveModal(stockId) {
      this.activeStockId = stockId
      this.removeForm = { quantity: null, notes: '' }
      this.showRemoveModal = true
    },
    async submitAdd() {
      if (!this.addForm.quantity) {
        alert('Please enter a quantity')
        return
      }
      try {
        this.submitting = true
        await productionService.addCoilStock(this.activeStockId, this.addForm)
        this.showAddModal = false
        this.fetchInventory()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to add stock'
      } finally {
        this.submitting = false
      }
    },
    async submitRemove() {
      if (!this.removeForm.quantity) {
        alert('Please enter a quantity')
        return
      }
      try {
        this.submitting = true
        await productionService.removeCoilStock(this.activeStockId, this.removeForm)
        this.showRemoveModal = false
        this.fetchInventory()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to remove stock'
      } finally {
        this.submitting = false
      }
    },
    formatQuantity(qty) {
      return parseFloat(qty || 0).toFixed(2)
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString()
    },
    getAvailable(stock) {
      return stock.quantity_in_stock
    },
    previousPage() {
      if (this.currentPage > 1) {
        this.currentPage--
        this.fetchInventory()
      }
    },
    nextPage() {
      if (this.currentPage < Math.ceil(this.pagination.total / this.pagination.per_page)) {
        this.currentPage++
        this.fetchInventory()
      }
    }
  },
  watch: {
    search() {
      this.currentPage = 1
      this.fetchInventory()
    },
    showLowStock() {
      this.currentPage = 1
      this.fetchInventory()
    }
  }
}
</script>

<style scoped>
.coil-inventory {
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

.search-input {
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.checkbox {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

.checkbox input {
  cursor: pointer;
}

.loading,
.error {
  padding: 20px;
  text-align: center;
}

.error {
  color: #d32f2f;
  background-color: #ffebee;
  border-radius: 4px;
}

.inventory-table {
  width: 100%;
  border-collapse: collapse;
  background-color: white;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.inventory-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
}

.inventory-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
}

.inventory-table tr.low-stock-row {
  background-color: #fff3cd;
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

.badge.danger {
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

.btn-small.primary {
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

.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal {
  background-color: white;
  border-radius: 8px;
  padding: 20px;
  max-width: 400px;
  width: 90%;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.modal h3 {
  margin-top: 0;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 6px;
  font-size: 14px;
}

.form-group input,
.form-group textarea {
  width: 100%;
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.form-group textarea {
  resize: vertical;
  min-height: 80px;
}

.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 20px;
}

.btn-primary,
.btn-secondary {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background-color: #1565c0;
}

.btn-primary:disabled {
  background-color: #ccc;
}

.btn-secondary {
  background-color: #e0e0e0;
  color: #333;
}

.btn-secondary:hover {
  background-color: #bdbdbd;
}
</style>
