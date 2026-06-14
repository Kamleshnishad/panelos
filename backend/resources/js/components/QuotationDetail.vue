<template>
  <div class="quotation-detail">
    <div v-if="loading" class="loading">Loading quotation...</div>
    <div v-if="error" class="error">{{ error }}</div>

    <div v-if="quotation && !loading" class="detail-container">
      <div class="header">
        <div>
          <h1>{{ quotation.quotation_no }}</h1>
          <p class="customer">{{ quotation.customer.name }}</p>
        </div>
        <div class="status" :class="`status-${quotation.status}`">
          {{ quotation.status.toUpperCase() }}
        </div>
      </div>

      <div class="quotation-meta">
        <div class="meta-item">
          <label>Date</label>
          <p>{{ formatDate(quotation.quoted_on) }}</p>
        </div>
        <div class="meta-item">
          <label>Valid Until</label>
          <p>{{ formatDate(quotation.valid_until) }}</p>
        </div>
        <div class="meta-item">
          <label>Created</label>
          <p>{{ formatDate(quotation.created_at) }}</p>
        </div>
        <div v-if="quotation.sent_at" class="meta-item">
          <label>Sent</label>
          <p>{{ formatDate(quotation.sent_at) }}</p>
        </div>
      </div>

      <div class="customer-info">
        <h3>Customer Information</h3>
        <p><strong>{{ quotation.customer.name }}</strong></p>
        <p>{{ quotation.customer.address_line1 }}</p>
        <p>{{ quotation.customer.city }}, {{ quotation.customer.state }} {{ quotation.customer.pincode }}</p>
        <p>Phone: {{ quotation.customer.phone }}</p>
        <p>Email: {{ quotation.customer.email }}</p>
      </div>

      <div class="items-section">
        <h3>Items</h3>
        <table v-if="quotation.items.length > 0" class="items-table">
          <thead>
            <tr>
              <th>Panel Type</th>
              <th class="text-right">Quantity</th>
              <th class="text-right">Unit Price</th>
              <th class="text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in quotation.items" :key="item.id">
              <td>{{ item.panel_type.name }}</td>
              <td class="text-right">{{ item.quantity }}</td>
              <td class="text-right">₹ {{ formatCurrency(item.unit_price) }}</td>
              <td class="text-right">₹ {{ formatCurrency(item.amount) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="no-items">No items</p>
      </div>

      <div class="totals">
        <div class="total-row">
          <label>Subtotal:</label>
          <span>₹ {{ formatCurrency(quotation.subtotal) }}</span>
        </div>
        <div class="total-row">
          <label>Tax (18%):</label>
          <span>₹ {{ formatCurrency(quotation.tax_amount) }}</span>
        </div>
        <div class="total-row grand-total">
          <label>TOTAL:</label>
          <span>₹ {{ formatCurrency(quotation.total_amount) }}</span>
        </div>
      </div>

      <div v-if="quotation.notes" class="notes">
        <h4>Notes</h4>
        <p>{{ quotation.notes }}</p>
      </div>

      <div class="actions">
        <router-link to="/quotations" class="btn btn-secondary">Back to List</router-link>
        <button
          v-if="quotation.status === 'draft'"
          @click="editQuotation()"
          class="btn btn-warning"
        >
          Edit
        </button>
        <button
          v-if="quotation.status === 'draft'"
          @click="sendQuotation()"
          class="btn btn-success"
        >
          Send
        </button>
        <button
          v-if="quotation.status === 'sent'"
          @click="acceptQuotation()"
          class="btn btn-success"
        >
          Accept
        </button>
        <button
          v-if="quotation.status === 'draft' || quotation.status === 'sent'"
          @click="rejectQuotation()"
          class="btn btn-danger"
        >
          Reject
        </button>
        <button @click="downloadPdf()" class="btn btn-info">Download PDF</button>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import quotationService from '../services/quotationService'

export default {
  name: 'QuotationDetail',
  setup(_, { emit }) {
    const route = useRoute()
    const quotation = ref(null)
    const loading = ref(false)
    const error = ref(null)

    const loadQuotation = async () => {
      loading.value = true
      try {
        const response = await quotationService.get(route.params.id)
        quotation.value = response.data.data
      } catch (err) {
        error.value = 'Failed to load quotation'
        console.error(err)
      } finally {
        loading.value = false
      }
    }

    const sendQuotation = async () => {
      if (!confirm('Send this quotation?')) return
      try {
        await quotationService.send(quotation.value.id)
        loadQuotation()
      } catch (err) {
        error.value = 'Failed to send quotation'
      }
    }

    const acceptQuotation = async () => {
      if (!confirm('Accept this quotation?')) return
      try {
        await quotationService.accept(quotation.value.id)
        loadQuotation()
      } catch (err) {
        error.value = 'Failed to accept quotation'
      }
    }

    const rejectQuotation = async () => {
      if (!confirm('Reject this quotation?')) return
      try {
        await quotationService.reject(quotation.value.id)
        loadQuotation()
      } catch (err) {
        error.value = 'Failed to reject quotation'
      }
    }

    const downloadPdf = async () => {
      try {
        const response = await quotationService.downloadPdf(quotation.value.id)
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `quotation-${quotation.value.quotation_no}.pdf`)
        link.click()
      } catch (err) {
        error.value = 'Failed to download PDF'
      }
    }

    const editQuotation = () => {
      window.location.href = `/quotations/${quotation.value.id}/edit`
    }

    const formatDate = (date) => {
      return new Date(date).toLocaleDateString()
    }

    const formatCurrency = (value) => {
      return parseFloat(value).toFixed(2)
    }

    onMounted(loadQuotation)

    return {
      quotation,
      loading,
      error,
      sendQuotation,
      acceptQuotation,
      rejectQuotation,
      downloadPdf,
      editQuotation,
      formatDate,
      formatCurrency,
    }
  },
}
</script>

<style scoped>
.quotation-detail {
  padding: 20px;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 30px;
  padding-bottom: 20px;
  border-bottom: 2px solid #eee;
}

.header h1 {
  margin: 0;
  font-size: 28px;
}

.customer {
  margin: 5px 0 0 0;
  color: #666;
}

.status {
  padding: 10px 20px;
  border-radius: 4px;
  font-weight: bold;
  font-size: 14px;
}

.status-draft {
  background-color: #e9ecef;
  color: #333;
}

.status-sent {
  background-color: #d1ecf1;
  color: #0c5460;
}

.status-accepted {
  background-color: #d4edda;
  color: #155724;
}

.status-rejected {
  background-color: #f8d7da;
  color: #721c24;
}

.quotation-meta {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
  padding: 20px;
  background-color: #f8f9fa;
  border-radius: 4px;
}

.meta-item label {
  font-weight: bold;
  font-size: 12px;
  color: #555;
  text-transform: uppercase;
}

.meta-item p {
  margin: 5px 0 0 0;
  font-size: 14px;
}

.customer-info {
  margin-bottom: 30px;
  padding: 20px;
  background-color: #f8f9fa;
  border-radius: 4px;
}

.customer-info h3 {
  margin: 0 0 15px 0;
  color: #333;
}

.customer-info p {
  margin: 5px 0;
  font-size: 14px;
}

.items-section {
  margin-bottom: 30px;
}

.items-section h3 {
  margin: 0 0 15px 0;
}

.items-table {
  width: 100%;
  border-collapse: collapse;
}

.items-table th,
.items-table td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}

.items-table thead {
  background-color: #f5f5f5;
}

.text-right {
  text-align: right;
}

.no-items {
  color: #999;
}

.totals {
  width: 50%;
  margin-left: auto;
  margin-bottom: 30px;
  padding: 20px;
  background-color: #f8f9fa;
  border-radius: 4px;
}

.total-row {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid #ddd;
}

.total-row label {
  font-weight: bold;
}

.total-row.grand-total {
  background-color: #007bff;
  color: white;
  padding: 15px;
  border: none;
  margin-top: 10px;
}

.notes {
  margin-bottom: 30px;
  padding: 20px;
  background-color: #f8f9fa;
  border-left: 4px solid #007bff;
  border-radius: 4px;
}

.notes h4 {
  margin: 0 0 10px 0;
}

.notes p {
  margin: 0;
  color: #666;
}

.actions {
  display: flex;
  gap: 10px;
  padding-top: 20px;
  border-top: 1px solid #eee;
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  text-decoration: none;
  display: inline-block;
}

.btn-secondary {
  background-color: #6c757d;
  color: white;
}

.btn-warning {
  background-color: #ffc107;
  color: black;
}

.btn-success {
  background-color: #28a745;
  color: white;
}

.btn-danger {
  background-color: #dc3545;
  color: white;
}

.btn-info {
  background-color: #17a2b8;
  color: white;
}

.loading,
.error {
  padding: 20px;
  text-align: center;
}

.error {
  color: #dc3545;
  background-color: #f8d7da;
  border: 1px solid #f5c6cb;
  border-radius: 4px;
}
</style>
