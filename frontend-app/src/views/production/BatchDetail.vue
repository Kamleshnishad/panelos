<template>
  <div>
    <div class="page-header">
      <div><button class="btn btn-outline btn-sm" @click="$router.back()">← Back</button><div class="page-title" style="margin-top:8px">Batch {{ batch?.batch_no }}</div></div>
      <div style="display:flex;gap:8px" v-if="batch">
        <button v-if="batch.status==='pending'" class="btn btn-primary" @click="doAction('start')">Start Production</button>
        <button v-if="batch.status==='in_progress'" class="btn btn-success" @click="doAction('complete')">Complete Batch</button>
      </div>
    </div>
    <div v-if="loading" class="loading"><div class="spinner"></div></div>
    <template v-else-if="batch">
      <div class="card">
        <div class="card-header"><div class="card-title">Batch Details</div><span :class="'badge badge-'+batch.status">{{ batch.status }}</span></div>
        <div class="form-row-3">
          <div class="form-group"><label class="form-label">Panel Type</label><div>{{ batch.panel_type?.type }}</div></div>
          <div class="form-group"><label class="form-label">Quantity</label><div>{{ batch.quantity }}</div></div>
          <div class="form-group"><label class="form-label">Start Date</label><div>{{ fmtDate(batch.start_date) }}</div></div>
        </div>
      </div>
      <div class="card" v-if="timeline.length">
        <div class="card-header"><div class="card-title">Production Timeline</div></div>
        <div v-for="log in timeline" :key="log.id" style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0">
          <div style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0">{{ log.stage?.order ?? '•' }}</div>
          <div>
            <div style="font-weight:600">{{ log.stage?.name }}</div>
            <div class="text-muted" style="font-size:12px">{{ log.status }} • {{ fmtDate(log.started_at) }}</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
const route = useRoute()
const batch = ref(null); const timeline = ref([]); const loading = ref(true)
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const doAction = async (act) => { await api.post(`/batches/${route.params.id}/${act}`); load() }
const load = async () => {
  loading.value = true
  try {
    const [b, t] = await Promise.all([api.get(`/batches/${route.params.id}`), api.get(`/batches/${route.params.id}/timeline`).catch(() => ({ data: { data: [] } }))])
    batch.value = b.data.data; timeline.value = t.data?.data ?? []
  } finally { loading.value = false }
}
onMounted(load)
</script>
