<template>
  <div class="report-viewer">
    <div class="report-header">
      <h2>{{ reportTitle }}</h2>
      <div class="report-controls">
        <input
          v-if="reportType !== 'accounting-dashboard'"
          v-model="filters.from_date"
          type="date"
          class="date-input"
        />
        <input
          v-if="reportType !== 'accounting-dashboard'"
          v-model="filters.to_date"
          type="date"
          class="date-input"
        />
        <button @click="fetchReport" class="btn-primary">Generate</button>
        <button @click="exportReport" class="btn-secondary">Export</button>
      </div>
    </div>

    <div class="report-content" v-if="reportData">
      <div v-if="reportType === 'profit-loss'" class="pl-report">
        <table class="report-table">
          <tr>
            <td class="label">Sales Revenue</td>
            <td class="value">${{ formatNumber(reportData.revenue.sales) }}</td>
          </tr>
          <tr>
            <td class="label">Tax Collected</td>
            <td class="value">${{ formatNumber(reportData.revenue.tax_collected) }}</td>
          </tr>
          <tr class="total-row">
            <td class="label">Gross Revenue</td>
            <td class="value">${{ formatNumber(reportData.revenue.gross_revenue) }}</td>
          </tr>
          <tr>
            <td class="label">Number of Invoices</td>
            <td class="value">{{ reportData.invoice_count }}</td>
          </tr>
          <tr>
            <td class="label">Average Invoice Value</td>
            <td class="value">${{ formatNumber(reportData.average_invoice_value) }}</td>
          </tr>
        </table>
      </div>

      <div v-else-if="reportType === 'balance-sheet'" class="bs-report">
        <div class="bs-section">
          <h3>Assets</h3>
          <table class="report-table">
            <tr>
              <td>Accounts Receivable</td>
              <td>${{ formatNumber(reportData.assets.accounts_receivable) }}</td>
            </tr>
            <tr>
              <td>Cash Collected</td>
              <td>${{ formatNumber(reportData.assets.cash_collected) }}</td>
            </tr>
            <tr class="subtotal">
              <td>Total Assets</td>
              <td>${{ formatNumber(reportData.total_assets) }}</td>
            </tr>
          </table>
        </div>

        <div class="bs-section">
          <h3>Liabilities & Equity</h3>
          <table class="report-table">
            <tr>
              <td>Tax Payable</td>
              <td>${{ formatNumber(reportData.liabilities.tax_payable) }}</td>
            </tr>
            <tr>
              <td>Retained Earnings</td>
              <td>${{ formatNumber(reportData.equity.retained_earnings) }}</td>
            </tr>
            <tr class="subtotal">
              <td>Total Liabilities & Equity</td>
              <td>${{ formatNumber(reportData.total_liabilities_and_equity) }}</td>
            </tr>
          </table>
        </div>
      </div>

      <div v-else-if="reportType === 'accounts-receivable'" class="ar-report">
        <div class="ar-summary">
          <h3>Aging Summary</h3>
          <table class="report-table">
            <tr>
              <td>Current (0-30 days)</td>
              <td>${{ formatNumber(reportData.summary.current) }}</td>
            </tr>
            <tr>
              <td>30-60 Days</td>
              <td>${{ formatNumber(reportData.summary['30_days']) }}</td>
            </tr>
            <tr>
              <td>60-90 Days</td>
              <td>${{{ formatNumber(reportData.summary['60_days']) }}</td>
            </tr>
            <tr>
              <td>Over 90 Days</td>
              <td>${{{ formatNumber(reportData.summary.over_90_days) }}</td>
            </tr>
            <tr class="total-row">
              <td>Total AR</td>
              <td>${{{ formatNumber(reportData.summary.total_ar) }}</td>
            </tr>
          </table>
        </div>

        <div class="ar-details" v-if="reportData.details.length">
          <h3>Invoice Details</h3>
          <table class="detail-table">
            <thead>
              <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Remaining</th>
                <th>Days Overdue</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in reportData.details" :key="inv.invoice_id">
                <td>{{ inv.invoice_no }}</td>
                <td>{{ inv.customer_name }}</td>
                <td>${{ formatNumber(inv.total_amount) }}</td>
                <td>${{{ formatNumber(inv.remaining_due) }}</td>
                <td :class="{ overdue: inv.is_overdue }">{{ inv.days_overdue }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-else-if="reportType === 'sales'" class="sales-report">
        <div class="sales-summary">
          <h3>Sales Summary</h3>
          <table class="report-table">
            <tr>
              <td>Total Sales</td>
              <td>${{ formatNumber(reportData.summary.total_sales) }}</td>
            </tr>
            <tr>
              <td>Total Tax</td>
              <td>${{{ formatNumber(reportData.summary.total_tax) }}</td>
            </tr>
            <tr class="total-row">
              <td>Total Value</td>
              <td>${{{ formatNumber(reportData.summary.total_value) }}</td>
            </tr>
            <tr>
              <td>Invoice Count</td>
              <td>{{ reportData.summary.invoice_count }}</td>
            </tr>
          </table>
        </div>

        <div class="sales-by-type" v-if="reportData.by_panel_type">
          <h3>Sales by Panel Type</h3>
          <table class="detail-table">
            <thead>
              <tr>
                <th>Panel Type</th>
                <th>Quantity</th>
                <th>Sales Value</th>
                <th>Count</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(data, type) in reportData.by_panel_type" :key="type">
                <td>{{ type }}</td>
                <td>{{ data.quantity }}</td>
                <td>${{{ formatNumber(data.value) }}</td>
                <td>{{ data.count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-else class="empty-state">
      <p>Select date range and click "Generate" to view report</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { reportingService } from '@/services/api'

const route = useRoute()
const reportType = ref(route.params.type || 'profit-loss')
const reportData = ref(null)
const filters = ref({
  from_date: new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().split('T')[0],
  to_date: new Date().toISOString().split('T')[0]
})

const reportTitle = computed(() => {
  const titles = {
    'profit-loss': 'Profit & Loss Statement',
    'balance-sheet': 'Balance Sheet',
    'accounts-receivable': 'Accounts Receivable Aging',
    'sales': 'Sales Report',
    'cash-flow': 'Cash Flow Statement',
    'tax': 'Tax Report'
  }
  return titles[reportType.value] || 'Report'
})

const fetchReport = async () => {
  try {
    let response
    switch (reportType.value) {
      case 'profit-loss':
        response = await reportingService.getProfitLossStatement(filters.value)
        break
      case 'balance-sheet':
        response = await reportingService.getBalanceSheet()
        break
      case 'accounts-receivable':
        response = await reportingService.getAccountsReceivable(filters.value)
        break
      case 'sales':
        response = await reportingService.getSalesReport(filters.value)
        break
      case 'cash-flow':
        response = await reportingService.getCashFlowStatement(filters.value)
        break
      case 'tax':
        response = await reportingService.getTaxReport(filters.value)
        break
    }
    reportData.value = response
  } catch (error) {
    console.error('Failed to fetch report:', error)
  }
}

const exportReport = () => {
  const dataStr = JSON.stringify(reportData.value, null, 2)
  const dataBlob = new Blob([dataStr], { type: 'application/json' })
  const url = URL.createObjectURL(dataBlob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${reportType.value}-${new Date().toISOString().split('T')[0]}.json`
  link.click()
}

const formatNumber = (num) => num?.toFixed(2) || '0.00'

onMounted(() => {
  if (reportType.value === 'accounting-dashboard') {
    fetchReport()
  }
})
</script>

<style scoped>
.report-viewer {
  padding: 20px;
}

.report-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
  padding: 20px;
  background: white;
  border-radius: 4px;
  border: 1px solid #ddd;
}

.report-controls {
  display: flex;
  gap: 10px;
  align-items: center;
}

.date-input,
.btn-primary,
.btn-secondary {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
  border: none;
  cursor: pointer;
}

.btn-secondary {
  background-color: #f5f5f5;
  cursor: pointer;
}

.report-content {
  background: white;
  padding: 20px;
  border-radius: 4px;
  border: 1px solid #ddd;
}

.report-table {
  width: 100%;
  border-collapse: collapse;
}

.report-table tr {
  border-bottom: 1px solid #e0e0e0;
}

.report-table .label {
  font-weight: 600;
  padding: 10px;
  text-align: left;
}

.report-table .value {
  text-align: right;
  padding: 10px;
  font-weight: 600;
}

.report-table .total-row {
  border-top: 2px solid #333;
  border-bottom: none;
  font-weight: bold;
}

.report-table .subtotal {
  background-color: #f9f9f9;
  font-weight: 600;
}

.bs-section {
  margin-bottom: 30px;
}

.bs-section h3 {
  margin-bottom: 15px;
  border-bottom: 2px solid #1976d2;
  padding-bottom: 10px;
}

.ar-summary {
  margin-bottom: 30px;
}

.ar-details {
  margin-top: 30px;
}

.detail-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 15px;
}

.detail-table th,
.detail-table td {
  padding: 10px;
  text-align: left;
  border-bottom: 1px solid #e0e0e0;
}

.detail-table th {
  background-color: #f5f5f5;
  font-weight: 600;
}

.detail-table .overdue {
  color: #d32f2f;
  font-weight: bold;
}

.sales-summary {
  margin-bottom: 30px;
}

.sales-by-type {
  margin-top: 30px;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #999;
}
</style>
