<template>
  <div class="quotation-list">
    <div class="header">
      <h1>Quotations</h1>
      <router-link to="/quotations/create" class="btn btn-primary">
        + New Quotation
      </router-link>
    </div>

    <div class="filters">
      <input
        v-model="filters.search"
        type="text"
        placeholder="Search by quotation number..."
        @keyup="loadQuotations()"
      />
      <select v-model="filters.status" @change="loadQuotations()">
        <option value="">All Statuses</option>
        <option value="draft">Draft</option>
        <option value="sent">Sent</option>
        <option value="accepted">Accepted</option>
        <option value="rejected">Rejected</option>
      </select>
    </div>

    <div v-if="loading" class="loading">Loading quotations...</div>

    <div v-if="error" class="error">{{ error }}</div>

    <table v-if="quotations.length > 0" class="quotations-table">
      <thead>
        <tr>
          <th>Quotation #</th>
          <th>Customer</th>
          <th>Total Amount</th>
          <th>Status</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="quotation in quotations" :key="quotation.id">
          <td>
            <router-link :to="`/quotations/${quotation.id}`">
              {{ quotation.quotation_no }}
            </router-link>
          </td>
          <td>{{ quotation.customer.name }}</td>
          <td>₹ {{ formatCurrency(quotation.total_amount) }}</td>
          <td>
            <span :class="`badge badge-${quotation.status}`">
              {{ quotation.status.toUpperCase() }}
            </span>
          </td>
          <td>{{ formatDate(quotation.created_at) }}</td>
          <td class="actions">
            <router-link :to="`/quotations/${quotation.id}`" class="btn btn-sm btn-info">
              View
            </router-link>
            <button
              v-if="quotation.status === 'draft'"
              @click="editQuotation(quotation.id)"
              class="btn btn-sm btn-warning"
            >
              Edit
            </button>
            <button
              v-if="quotation.status === 'draft'"
              @click="sendQuotation(quotation.id)"
              class="btn btn-sm btn-success"
            >
              Send
            </button>
            <button
              @click="downloadPdf(quotation.id)"
              class="btn btn-sm btn-secondary"
            >
              PDF
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-else class="no-data">
      <p>No quotations found. <router-link to="/quotations/create">Create one</router-link></p>
    </div>

    <div v-if="pagination.last_page > 1" class="pagination">
      <button
        v-if="pagination.current_page > 1"
        @click="changePage(pagination.current_page - 1)"
        class="btn"
      >
        Previous
      </button>
      <span class="page-info">
        Page {{ pagination.current_page }} of {{ pagination.last_page }}
      </span>
      <button
        v-if="pagination.current_page < pagination.last_page"
        @click="changePage(pagination.current_page + 1)"
        class="btn"
      >
        Next
      </button>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import quotationService from '../services/quotationService'

export default {
  name: 'QuotationList',
  setup() {
    const quotations = ref([])
    const loading = ref(false)
    const error = ref(null)
    const pagination = ref({
      current_page: 1,
      last_page: 1,
    })
    const filters = ref({
      search: '',
      status: '',
      page: 1,
    })

    const loadQuotations = async () => {
      loading.value = true
      error.value = null
      try {
        const response = await quotationService.list({
          search: filters.value.search,
          status: filters.value.status,
          page: filters.value.page,
        })
        quotations.value = response.data.data
        pagination.value = response.data.meta.pagination
      } catch (err) {
        error.value = 'Failed to load quotations'
        console.error(err)
      } finally {
        loading.value = false
      }
    }

    const changePage = (page) => {
      filters.value.page = page
      loadQuotations()
    }

    const sendQuotation = async (id) => {
      if (!confirm('Send this quotation to customer?')) return
      try {
        await quotationService.send(id)
        loadQuotations()
      } catch (err) {
        error.value = 'Failed to send quotation'
      }
    }

    const downloadPdf = async (id) => {
      try {
        const response = await quotationService.downloadPdf(id)
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `quotation-${id}.pdf`)
        link.click()
      } catch (err) {
        error.value = 'Failed to download PDF'
      }
    }

    const editQuotation = (id) => {
      // Navigate to edit page
      window.location.href = `/quotations/${id}/edit`
    }

    const formatCurrency = (value) => {
      return parseFloat(value).toFixed(2)
    }

    const formatDate = (date) => {
      return new Date(date).toLocaleDateString()
    }

    onMounted(loadQuotations)

    return {
      quotations,
      loading,
      error,
      pagination,
      filters,
      loadQuotations,
      changePage,
      sendQuotation,
      downloadPdf,
      editQuotation,
      formatCurrency,
      formatDate,
    }
  },
}
</script>

<style scoped>
.quotation-list {
  padding: 20px;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
}

.filters {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
}

.filters input,
.filters select {
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.quotations-table {
  width: 100%;
  border-collapse: collapse;
}

.quotations-table th,
.quotations-table td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}

.quotations-table thead {
  background-color: #f5f5f5;
  font-weight: bold;
}

.quotations-table tbody tr:hover {
  background-color: #f9f9f9;
}

.actions {
  display: flex;
  gap: 5px;
}

.btn {
  padding: 6px 12px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
  text-decoration: none;
  display: inline-block;
}

.btn-primary {
  background-color: #007bff;
  color: white;
}

.btn-info {
  background-color: #17a2b8;
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

.btn-secondary {
  background-color: #6c757d;
  color: white;
}

.btn-sm {
  padding: 4px 8px;
  font-size: 11px;
}

.badge {
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: bold;
}

.badge-draft {
  background-color: #e9ecef;
  color: #333;
}

.badge-sent {
  background-color: #d1ecf1;
  color: #0c5460;
}

.badge-accepted {
  background-color: #d4edda;
  color: #155724;
}

.badge-rejected {
  background-color: #f8d7da;
  color: #721c24;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
}

.page-info {
  margin: 0 10px;
}

.loading,
.error,
.no-data {
  padding: 20px;
  text-align: center;
  font-size: 14px;
}

.error {
  color: #dc3545;
  background-color: #f8d7da;
  border: 1px solid #f5c6cb;
  border-radius: 4px;
}

.no-data {
  color: #666;
}
</style>
