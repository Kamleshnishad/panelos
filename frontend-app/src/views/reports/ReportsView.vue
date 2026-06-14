<template>
  <div>
    <div class="page-header"><div><div class="page-title">Financial Reports</div></div></div>

    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
      <button v-for="r in reportTypes" :key="r.key" :class="['btn btn-sm', activeReport===r.key?'btn-primary':'btn-outline']" @click="loadReport(r.key)">{{ r.label }}</button>
    </div>

    <div class="filters-bar" v-if="activeReport">
      <input v-model="filters.start_date" type="date" class="form-control" style="max-width:160px" />
      <input v-model="filters.end_date" type="date" class="form-control" style="max-width:160px" />
      <button class="btn btn-primary" @click="loadReport(activeReport)">Apply</button>
    </div>

    <div v-if="loading" class="loading"><div class="spinner"></div></div>

    <div v-else-if="report" class="card">
      <div class="card-header"><div class="card-title">{{ reportTypes.find(r=>r.key===activeReport)?.label }}</div></div>

      <!-- Dashboard tiles -->
      <div v-if="activeReport==='dashboard'" class="kpi-grid">
        <div class="kpi-card blue"><div class="kpi-label">Total Invoices</div><div class="kpi-value">{{ report.total_invoices ?? '—' }}</div></div>
        <div class="kpi-card green"><div class="kpi-label">Total Revenue</div><div class="kpi-value">₹{{ fmt(report.total_revenue) }}</div></div>
        <div class="kpi-card orange"><div class="kpi-label">Outstanding</div><div class="kpi-value">₹{{ fmt(report.outstanding_amount) }}</div></div>
        <div class="kpi-card red"><div class="kpi-label">Overdue Count</div><div class="kpi-value">{{ report.overdue_count ?? '—' }}</div></div>
      </div>

      <!-- Generic table for other reports -->
      <div v-else>
        <pre style="font-size:12px;background:#f9f9f9;padding:16px;border-radius:4px;overflow-x:auto">{{ JSON.stringify(report, null, 2) }}</pre>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api from '@/services/api'
const report = ref(null); const loading = ref(false); const activeReport = ref('')
const filters = ref({ start_date: '', end_date: '' })
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const reportTypes = [
  { key: 'dashboard', label: '📊 Accounting Dashboard', path: '/reports/accounting-dashboard' },
  { key: 'profit-loss', label: '📈 Profit & Loss', path: '/reports/profit-loss' },
  { key: 'balance-sheet', label: '⚖️ Balance Sheet', path: '/reports/balance-sheet' },
  { key: 'cash-flow', label: '💵 Cash Flow', path: '/reports/cash-flow' },
  { key: 'accounts-receivable', label: '📋 Accounts Receivable', path: '/reports/accounts-receivable' },
  { key: 'sales', label: '🛒 Sales Report', path: '/reports/sales' },
  { key: 'tax', label: '🏛️ Tax Report', path: '/reports/tax' },
]
const loadReport = async (key) => {
  activeReport.value = key; loading.value = true; report.value = null
  try {
    const r = reportTypes.find(r => r.key === key)
    const { data } = await api.get(r.path, { params: filters.value })
    report.value = data.data
  } catch { report.value = { error: 'No data available yet' } }
  finally { loading.value = false }
}
</script>
