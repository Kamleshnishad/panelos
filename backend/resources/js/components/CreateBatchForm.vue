<template>
  <div class="create-batch-form">
    <div class="form-header">
      <h2>Create Production Batch</h2>
      <router-link to="/orders" class="btn-back">← Back</router-link>
    </div>

    <div v-if="loadingOrder" class="loading">Loading order details...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else-if="order" class="form-container">
      <div class="order-info">
        <h3>Order {{ order.order_no }}</h3>
        <p>Customer: <strong>{{ order.customer.name }}</strong></p>
        <p>Total Items: <strong>{{ order.items.length }}</strong></p>
      </div>

      <form @submit.prevent="submitForm">
        <div class="form-group">
          <label for="plannedQuantity">Planned Quantity *</label>
          <input
            id="plannedQuantity"
            v-model.number="form.plannedQuantity"
            type="number"
            min="1"
            required
            placeholder="Enter quantity to produce">
          <small v-if="order.items.length > 0">
            Order contains {{ order.items.reduce((sum, item) => sum + item.quantity, 0) }} items total
          </small>
        </div>

        <div class="form-group">
          <label for="notes">Notes</label>
          <textarea
            id="notes"
            v-model="form.notes"
            placeholder="Enter any additional notes for this batch..."></textarea>
        </div>

        <div class="form-group">
          <label>
            <input v-model="form.calculateSchedule" type="checkbox">
            Calculate Cutting Schedule Automatically
          </label>
          <small>If unchecked, you can calculate the schedule later from the batch detail page</small>
        </div>

        <div v-if="formError" class="error">{{ formError }}</div>
        <div v-if="success" class="success">{{ success }}</div>

        <div class="form-actions">
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? 'Creating Batch...' : 'Create Batch' }}
          </button>
          <router-link to="/orders" class="btn-secondary">Cancel</router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'CreateBatchForm',
  props: {
    orderId: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      order: null,
      form: {
        plannedQuantity: null,
        notes: '',
        calculateSchedule: true
      },
      loadingOrder: true,
      submitting: false,
      error: null,
      formError: null,
      success: null
    }
  },
  mounted() {
    this.fetchOrder()
  },
  methods: {
    async fetchOrder() {
      try {
        this.loadingOrder = true
        this.error = null
        const response = await productionService.getOrder(this.orderId)
        this.order = response.data.data
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load order'
      } finally {
        this.loadingOrder = false
      }
    },
    async submitForm() {
      try {
        this.formError = null
        this.success = null
        this.submitting = true

        if (!this.form.plannedQuantity || this.form.plannedQuantity < 1) {
          this.formError = 'Planned quantity must be at least 1'
          return
        }

        const data = {
          planned_quantity: this.form.plannedQuantity,
          notes: this.form.notes
        }

        const response = await productionService.createBatch(this.orderId, data)
        const batchId = response.data.data.id

        if (this.form.calculateSchedule) {
          await productionService.calculateCuttingSchedule(batchId)
        }

        this.success = 'Batch created successfully!'
        setTimeout(() => {
          this.$router.push(`/batches/${batchId}`)
        }, 1000)
      } catch (err) {
        this.formError = err.response?.data?.message || 'Failed to create batch'
      } finally {
        this.submitting = false
      }
    }
  }
}
</script>

<style scoped>
.create-batch-form {
  padding: 20px;
  background-color: #f9f9f9;
  min-height: 100vh;
}

.form-header {
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
  border: 1px solid #ef5350;
}

.form-container {
  background-color: white;
  border-radius: 8px;
  padding: 30px;
  max-width: 600px;
  margin: 0 auto;
}

.order-info {
  margin-bottom: 30px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
  border-left: 4px solid #1976d2;
}

.order-info h3 {
  margin: 0 0 10px 0;
  color: #333;
}

.order-info p {
  margin: 8px 0;
  font-size: 14px;
  color: #666;
}

form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

label {
  font-weight: 600;
  margin-bottom: 8px;
  color: #333;
  font-size: 14px;
}

input[type="number"],
textarea {
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
  font-family: inherit;
}

input[type="number"]:focus,
textarea:focus {
  outline: none;
  border-color: #1976d2;
  box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

textarea {
  resize: vertical;
  min-height: 100px;
}

small {
  font-size: 12px;
  color: #666;
  margin-top: 4px;
}

input[type="checkbox"] {
  margin-right: 8px;
  cursor: pointer;
}

input[type="checkbox"] + label {
  display: flex;
  align-items: center;
  font-weight: normal;
  margin-bottom: 0;
}

.form-group:has(input[type="checkbox"]) {
  flex-direction: row;
  align-items: center;
}

.success {
  padding: 12px;
  background-color: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #4caf50;
  border-radius: 4px;
  text-align: center;
  font-size: 14px;
}

.form-actions {
  display: flex;
  gap: 10px;
  justify-content: center;
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
  text-decoration: none;
  text-align: center;
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
  cursor: not-allowed;
}

.btn-secondary {
  background-color: #e0e0e0;
  color: #333;
}

.btn-secondary:hover {
  background-color: #bdbdbd;
}
</style>
