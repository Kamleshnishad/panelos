<template>
  <div class="financial-dashboard">
    <h2>Financial Dashboard</h2>

    <div class="dashboard-grid" v-if="dashboard">
      <div class="card total-revenue">
        <div class="card-label">Total Revenue (YTD)</div>
        <div class="card-value">${{ formatNumber(dashboard.summary.total_revenue_ytd) }}</div>
        <div class="card-change">Year-to-date</div>
      </div>

      <div class="card accounts-receivable">
        <div class="card-label">Accounts Receivable</div>
        <div class="card-value">${{ formatNumber(dashboard.summary.total_accounts_receivable) }}</div>
        <div class="card-change">{{ dashboard.summary.overdue_count }} overdue</div>
      </div>

      <div class="card overdue">
        <div class="card-label">Overdue Amount</div>
        <div class="card-value">${{ formatNumber(dashboard.summary.total_overdue) }}</div>
        <div class="card-change">Over 60 days</div>
      </div>

      <div class="card cash-collected">
        <div class="card-label">Cash Collected (MTD)</div>
        <div class="card-value">${{{ formatNumber(dashboard.summary.cash_collected_mtd) }}</div>
        <div class="card-change">Month-to-date</div>
      </div>
    </div>

    <div class="report-grid">
      <div class="report-card">
        <h3>Profit & Loss</h3>
        <div v-if="dashboard?.pl_statement" class="report-content">
          <div class="report-row">
            <span>Sales Revenue</span>
            <span>${{ formatNumber(dashboard.pl_statement.revenue.sales) }}</span>
          </div>
          <div class="report-row">
            <span>Tax Collected</span>
            <span>${{ formatNumber(dashboard.pl_statement.revenue.tax_collected) }}</span>
          </div>
          <div class="report-row total">
            <span>Gross Revenue</span>
            <span>${{ formatNumber(dashboard.pl_statement.revenue.gross_revenue) }}</span>
          </div>
          <button @click="viewPLStatement" class="btn-view">View Details</button>
        </div>
      </div>

      <div class="report-card">
        <h3>Accounts Receivable Aging</h3>
        <div v-if="dashboard?.ar_aging" class="report-content">
          <div class="report-row">
            <span>Current</span>
            <span>${{ formatNumber(dashboard.ar_aging.current) }}</span>
          </div>
          <div class="report-row">
            <span>30-60 Days</span>
            <span>${{ formatNumber(dashboard.ar_aging['30_days']) }}</span>
          </div>
          <div class="report-row">
            <span>60-90 Days</span>
            <span>${{ formatNumber(dashboard.ar_aging['60_days']) }}</span>
          </div>
          <div class="report-row">
            <span>Over 90 Days</span>
            <span>${{ formatNumber(dashboard.ar_aging.over_90_days) }}</span>
          </div>
          <button @click="viewARAging" class="btn-view">View Details</button>
        </div>
      </div>

      <div class="report-card">
        <h3>Balance Sheet</h3>
        <div v-if="dashboard?.balance_sheet" class="report-content">
          <div class="report-row">
            <span>Total Assets</span>
            <span>${{ formatNumber(dashboard.balance_sheet.total_assets) }}</span>
          </div>
          <div class="report-row">
            <span>Total Liabilities</span>
            <span>${{ formatNumber(dashboard.balance_sheet.total_liabilities_and_equity) }}</span>
          </div>
          <div class="report-row total">
            <span>Equity</span>
            <span>${{ formatNumber(dashboard.balance_sheet.equity.retained_earnings) }}</span>
          </div>
          <button @click="viewBalanceSheet" class="btn-view">View Details</button>
        </div>
      </div>

      <div class="report-card">
        <h3>Recent Invoices</h3>
        <div v-if="dashboard?.recent_invoices?.length" class="report-content">
          <div v-for="inv in dashboard.recent_invoices" :key="inv.invoice_no" class="invoice-row">
            <div class="invoice-no">{{ inv.invoice_no }}</div>
            <div class="invoice-amount">${{ formatNumber(inv.amount) }}</div>
            <div :class="['invoice-status', `status-${inv.status}`]">{{ inv.status }}</div>
          </div>
          <button @click="viewAllInvoices" class="btn-view">View All</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { reportingService } from '@/services/api'

const router = useRouter()
const dashboard = ref(null)

const fetchDashboard = async () => {
  try {
    dashboard.value = await reportingService.getAccountingDashboard()
  } catch (error) {
    console.error('Failed to fetch dashboard:', error)
  }
}

const viewPLStatement = () => {
  router.push('/reports/profit-loss')
}

const viewARAging = () => {
  router.push('/reports/accounts-receivable')
}

const viewBalanceSheet = () => {
  router.push('/reports/balance-sheet')
}

const viewAllInvoices = () => {
  router.push('/invoices')
}

const formatNumber = (num) => num?.toFixed(2) || '0.00'

onMounted(() => {
  fetchDashboard()
})
</script>

<style scoped>
.financial-dashboard {
  padding: 20px;
}

.financial-dashboard h2 {
  margin-bottom: 20px;
}

.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 15px;
  margin-bottom: 30px;
}

.card {
  background: white;
  padding: 20px;
  border-radius: 4px;
  border: 1px solid #ddd;
}

.card-label {
  font-size: 12px;
  color: #666;
  text-transform: uppercase;
  margin-bottom: 10px;
}

.card-value {
  font-size: 24px;
  font-weight: bold;
  color: #333;
  margin-bottom: 5px;
}

.card-change {
  font-size: 12px;
  color: #999;
}

.total-revenue {
  border-left: 4px solid #1976d2;
}

.accounts-receivable {
  border-left: 4px solid #ff9800;
}

.overdue {
  border-left: 4px solid #d32f2f;
}

.cash-collected {
  border-left: 4px solid #388e3c;
}

.report-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.report-card {
  background: white;
  padding: 20px;
  border-radius: 4px;
  border: 1px solid #ddd;
}

.report-card h3 {
  margin-bottom: 15px;
  font-size: 16px;
}

.report-content {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.report-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #f0f0f0;
  font-size: 14px;
}

.report-row.total {
  border-bottom: 2px solid #333;
  font-weight: bold;
  padding-bottom: 10px;
}

.report-row:last-of-type {
  border-bottom: none;
}

.invoice-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid #f0f0f0;
  font-size: 14px;
}

.invoice-no {
  flex: 1;
  font-weight: 600;
}

.invoice-amount {
  flex: 1;
  text-align: right;
}

.invoice-status {
  flex: 1;
  text-align: right;
  font-size: 12px;
  padding: 2px 4px;
  border-radius: 3px;
}

.status-draft {
  background-color: #e0e0e0;
  color: #333;
}

.status-sent {
  background-color: var(--primary-tint);
  color: #1976d2;
}

.status-paid {
  background-color: #e8f5e9;
  color: #388e3c;
}

.btn-view {
  background-color: #f5f5f5;
  border: 1px solid #ddd;
  padding: 8px 12px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
  margin-top: 10px;
}

.btn-view:hover {
  background-color: #e0e0e0;
}

@media (max-width: 1024px) {
  .dashboard-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .report-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .dashboard-grid {
    grid-template-columns: 1fr;
  }
}
</style>
