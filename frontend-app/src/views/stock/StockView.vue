<template>
  <div>
    <div class="page-header"><div><div class="page-title">Stock Management</div><div class="page-subtitle">Coil & chemical inventory</div></div></div>

    <div class="kpi-grid" v-if="dashboard">
      <div class="kpi-card blue"><div class="kpi-label">Coil Stock Items</div><div class="kpi-value">{{ dashboard.coil_count ?? '—' }}</div></div>
      <div class="kpi-card orange"><div class="kpi-label">Low Stock Alerts</div><div class="kpi-value">{{ dashboard.alert_count ?? '—' }}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Chemical Items</div><div class="kpi-value">{{ dashboard.chemical_count ?? '—' }}</div></div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Coil Inventory</div>
        <div style="display:flex;gap:8px">
          <button :class="['btn btn-sm', tab==='coils'?'btn-primary':'btn-outline']" @click="tab='coils';loadCoils()">Coils</button>
          <button :class="['btn btn-sm', tab==='chemicals'?'btn-primary':'btn-outline']" @click="tab='chemicals';loadChemicals()">Chemicals</button>
          <button :class="['btn btn-sm', tab==='alerts'?'btn-danger':'btn-outline']" @click="tab='alerts';loadAlerts()">Alerts</button>
        </div>
      </div>
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table v-if="tab==='coils'">
          <thead><tr><th>ID</th><th>Type</th><th>In Stock</th><th>Reorder Level</th><th>Last Updated</th></tr></thead>
          <tbody>
            <tr v-for="c in items" :key="c.id">
              <td>{{ c.id }}</td>
              <td>{{ c.panel_type?.type ?? c.coil_id }}</td>
              <td :style="c.quantity_in_stock <= c.reorder_level ? 'color:var(--danger);font-weight:600':''">{{ c.quantity_in_stock }}</td>
              <td>{{ c.reorder_level }}</td>
              <td>{{ fmtDate(c.updated_at) }}</td>
            </tr>
            <tr v-if="!items.length"><td colspan="5"><div class="empty-state"><div class="icon">📦</div><p>No stock records</p></div></td></tr>
          </tbody>
        </table>
        <table v-else-if="tab==='chemicals'">
          <thead><tr><th>ID</th><th>Qty in Stock</th><th>Unit</th><th>Expiry</th></tr></thead>
          <tbody>
            <tr v-for="c in items" :key="c.id"><td>{{ c.id }}</td><td>{{ c.quantity_in_stock }}</td><td>{{ c.unit }}</td><td>{{ fmtDate(c.expiry_date) }}</td></tr>
            <tr v-if="!items.length"><td colspan="4"><div class="empty-state"><div class="icon">🧪</div><p>No chemicals</p></div></td></tr>
          </tbody>
        </table>
        <table v-else>
          <thead><tr><th>Type</th><th>Current Qty</th><th>Reorder Level</th><th>Alert Type</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="a in items" :key="a.id">
              <td>{{ a.item_type }}</td>
              <td>{{ a.current_quantity }}</td>
              <td>{{ a.reorder_level }}</td>
              <td>{{ a.alert_type }}</td>
              <td><span :class="'badge badge-'+(a.status==='active'?'high':'paid')">{{ a.status }}</span></td>
            </tr>
            <tr v-if="!items.length"><td colspan="5"><div class="empty-state"><div class="icon">✅</div><p>No active alerts</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
const items = ref([]); const loading = ref(true); const tab = ref('coils'); const dashboard = ref(null)
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const loadCoils = async () => { loading.value = true; try { const { data } = await api.get('/stock/coils'); items.value = data.data?.data ?? data.data ?? [] } finally { loading.value = false } }
const loadChemicals = async () => { loading.value = true; try { const { data } = await api.get('/stock/chemicals'); items.value = data.data?.data ?? data.data ?? [] } finally { loading.value = false } }
const loadAlerts = async () => { loading.value = true; try { const { data } = await api.get('/stock/alerts'); items.value = data.data?.data ?? data.data ?? [] } finally { loading.value = false } }
onMounted(async () => {
  try { const { data } = await api.get('/stock/dashboard'); dashboard.value = data.data } catch {}
  await loadCoils()
})
</script>
