<template>
  <div>
    <div class="page-header">
      <div>
        <div class="page-title">Welcome back, {{ auth.userName }} 👋</div>
        <div class="page-subtitle">Here's what's happening today</div>
      </div>
    </div>

    <div v-if="loading" class="loading"><div class="spinner"></div></div>

    <template v-else>
      <div class="kpi-grid">
        <div class="kpi-card blue">
          <div class="kpi-label">Total Invoices</div>
          <div class="kpi-value">{{ stats.total_invoices ?? '—' }}</div>
          <div class="kpi-sub">All time</div>
        </div>
        <div class="kpi-card green">
          <div class="kpi-label">Revenue Collected</div>
          <div class="kpi-value">₹{{ fmt(stats.total_revenue) }}</div>
          <div class="kpi-sub">Paid invoices</div>
        </div>
        <div class="kpi-card orange">
          <div class="kpi-label">Outstanding</div>
          <div class="kpi-value">₹{{ fmt(stats.outstanding_amount) }}</div>
          <div class="kpi-sub">Unpaid invoices</div>
        </div>
        <div class="kpi-card red">
          <div class="kpi-label">Overdue</div>
          <div class="kpi-value">{{ stats.overdue_count ?? '—' }}</div>
          <div class="kpi-sub">Past due date</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
          <div class="card-header"><div class="card-title">Recent Invoices</div><router-link to="/invoices" class="btn btn-sm btn-outline">View All</router-link></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Invoice #</th><th>Status</th><th>Amount</th></tr></thead>
              <tbody>
                <tr v-for="inv in recentInvoices" :key="inv.id" @click="$router.push('/invoices/'+inv.id)" style="cursor:pointer">
                  <td>{{ inv.invoice_no }}</td>
                  <td><span :class="'badge badge-'+inv.status">{{ inv.status }}</span></td>
                  <td>₹{{ fmt(inv.subtotal) }}</td>
                </tr>
                <tr v-if="!recentInvoices.length"><td colspan="3" class="text-muted" style="text-align:center;padding:20px">No invoices yet</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><div class="card-title">Quick Actions</div></div>
          <div style="display:grid;gap:10px">
            <router-link to="/quotations" class="btn btn-outline" style="justify-content:flex-start">📋 New Quotation</router-link>
            <router-link to="/invoices" class="btn btn-outline" style="justify-content:flex-start">🧾 Create Invoice</router-link>
            <router-link to="/production" class="btn btn-outline" style="justify-content:flex-start">⚙️ View Production</router-link>
            <router-link to="/stock" class="btn btn-outline" style="justify-content:flex-start">📦 Check Stock</router-link>
            <router-link to="/reports" class="btn btn-outline" style="justify-content:flex-start">📈 Financial Reports</router-link>
            <router-link to="/forecasting" class="btn btn-outline" style="justify-content:flex-start">🤖 ML Forecast</router-link>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const auth = useAuthStore()
const loading = ref(true)
const stats = ref({})
const recentInvoices = ref([])

const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'

onMounted(async () => {
  try {
    const [dash, inv] = await Promise.all([
      api.get('/reports/accounting-dashboard').catch(() => ({ data: { data: {} } })),
      api.get('/invoices', { params: { per_page: 5 } }).catch(() => ({ data: { data: [] } }))
    ])
    stats.value = dash.data?.data ?? {}
    const invData = inv.data?.data
    recentInvoices.value = Array.isArray(invData) ? invData : (invData?.data ?? [])
  } finally {
    loading.value = false
  }
})
</script>
