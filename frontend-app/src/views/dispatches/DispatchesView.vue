<template>
  <div>
    <div class="page-header"><div><div class="page-title">Dispatches</div><div class="page-subtitle">Manage goods dispatch & delivery challans</div></div></div>
    <div class="card">
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Dispatch #</th><th>Batch</th><th>Status</th><th>Dispatch Date</th><th>Actions</th></tr></thead>
          <tbody>
            <tr v-for="d in items" :key="d.id">
              <td><strong>{{ d.dispatch_no }}</strong></td>
              <td>{{ d.batch?.batch_no ?? '—' }}</td>
              <td><span :class="'badge badge-'+d.status">{{ d.status }}</span></td>
              <td>{{ fmtDate(d.dispatch_date) }}</td>
              <td style="display:flex;gap:6px">
                <button class="btn btn-sm btn-outline" @click="downloadChallan(d.id)">📄 Challan</button>
              </td>
            </tr>
            <tr v-if="!items.length"><td colspan="5"><div class="empty-state"><div class="icon">🚚</div><p>No dispatches yet</p></div></td></tr>
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
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const downloadChallan = async (id) => {
  const { data } = await api.get(`/dispatches/${id}/challan/pdf`, { responseType: 'blob' })
  const url = URL.createObjectURL(data); const a = document.createElement('a'); a.href = url; a.download = `challan_${id}.pdf`; a.click()
}
onMounted(async () => {
  try { const { data } = await api.get('/dispatches'); items.value = data.data?.data ?? data.data ?? [] }
  finally { loading.value = false }
})
</script>
