<template>
  <div>
    <div class="page-header"><div><div class="page-title">Production Batches</div><div class="page-subtitle">Track manufacturing batches</div></div></div>
    <div class="card">
      <div class="filters-bar">
        <select v-model="statusFilter" class="form-control" style="max-width:160px" @change="load">
          <option value="">All Status</option><option>pending</option><option>in_progress</option><option>completed</option>
        </select>
        <button class="btn btn-primary" @click="load">🔄 Refresh</button>
      </div>
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Batch #</th><th>Panel Type</th><th>Quantity</th><th>Status</th><th>Start Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="b in items" :key="b.id">
              <td><strong>{{ b.batch_no }}</strong></td>
              <td>{{ b.panel_type?.type ?? '—' }}</td>
              <td>{{ b.quantity }}</td>
              <td><span :class="'badge badge-'+b.status">{{ b.status }}</span></td>
              <td>{{ fmtDate(b.start_date) }}</td>
              <td><button class="btn btn-sm btn-outline" @click="$router.push('/production/'+b.id)">View</button></td>
            </tr>
            <tr v-if="!items.length"><td colspan="6"><div class="empty-state"><div class="icon">⚙️</div><p>No batches yet</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
const items = ref([])
const loading = ref(true)
const statusFilter = ref('')
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const load = async () => {
  loading.value = true
  try { const { data } = await api.get('/batches', { params: { status: statusFilter.value } }); items.value = data.data?.data ?? data.data ?? [] }
  finally { loading.value = false }
}
onMounted(load)
</script>
