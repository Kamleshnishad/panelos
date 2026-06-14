<template>
  <div>
    <div class="page-header"><div><div class="page-title">Invoices</div><div class="page-subtitle">Manage billing & invoices</div></div></div>
    <div class="card">
      <div class="filters-bar">
        <select v-model="statusFilter" class="form-control" style="max-width:160px" @change="load">
          <option value="">All Status</option><option>draft</option><option>sent</option><option>accepted</option><option>paid</option><option>cancelled</option>
        </select>
        <button class="btn btn-primary" @click="load">🔄 Refresh</button>
      </div>
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Invoice #</th><th>Status</th><th>Subtotal</th><th>Due Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="inv in items" :key="inv.id">
              <td><a @click="$router.push('/invoices/'+inv.id)" style="cursor:pointer;color:var(--primary);font-weight:600">{{ inv.invoice_no }}</a></td>
              <td><span :class="'badge badge-'+inv.status">{{ inv.status }}</span></td>
              <td>₹{{ fmt(inv.subtotal) }}</td>
              <td>{{ fmtDate(inv.due_date) }}</td>
              <td style="display:flex;gap:6px">
                <button class="btn btn-sm btn-outline" @click="$router.push('/invoices/'+inv.id)">View</button>
                <button class="btn btn-sm btn-secondary" @click="downloadPdf(inv)">PDF</button>
              </td>
            </tr>
            <tr v-if="!items.length"><td colspan="5"><div class="empty-state"><div class="icon">🧾</div><p>No invoices yet</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
const items = ref([]); const loading = ref(true); const statusFilter = ref('')
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const load = async () => { loading.value = true; try { const { data } = await api.get('/invoices', { params: { status: statusFilter.value } }); items.value = data.data?.data ?? data.data ?? [] } finally { loading.value = false } }
const downloadPdf = async (inv) => {
  const { data } = await api.get(`/invoices/${inv.id}/pdf`, { responseType: 'blob' })
  const url = URL.createObjectURL(data); const a = document.createElement('a'); a.href = url; a.download = `invoice_${inv.invoice_no}.pdf`; a.click()
}
onMounted(load)
</script>
