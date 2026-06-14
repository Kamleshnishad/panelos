<template>
  <div class="pr-wrap">
    <div class="pr-header">
      <div>
        <h2>Production Runs</h2>
        <p class="pr-sub">Grouped multi-order production. One run = same-spec orders together.</p>
      </div>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻ Refresh</button>
    </div>

    <div v-if="loading" class="pr-loading">Loading…</div>

    <template v-else>
      <p v-if="!runs.length" class="empty">
        No runs yet. Go to <a href="#" @click.prevent="$emit('go-planner')">Production Plan</a> and click "Create Run".
      </p>

      <div v-for="run in runs" :key="run.id" class="run-card" :class="run.status">
        <div class="run-head">
          <div class="rh-left">
            <span class="run-no mono">{{ run.run_no }}</span>
            <span class="status-badge" :class="run.status">{{ statusLabel(run.status) }}</span>
          </div>
          <div class="rh-actions">
            <button v-if="run.status === 'draft'" class="btn btn-primary sm" :disabled="busy === run.id" @click="act(run, 'start')">▶ Start</button>
            <button v-if="run.status === 'in_progress'" class="btn btn-primary sm" :disabled="busy === run.id" @click="act(run, 'complete')">✓ Complete</button>
            <button v-if="run.status === 'draft'" class="btn btn-danger sm" :disabled="busy === run.id" @click="act(run, 'cancel')">Cancel</button>
          </div>
        </div>

        <div v-if="run.label" class="run-spec">{{ run.label }}</div>

        <div class="run-stats">
          <span><b>{{ run.batches?.length || 0 }}</b> orders</span>
          <span><b>{{ fmt(run.planned_sqm) }}</b> SQM planned</span>
          <span v-if="run.started_at">Started {{ fmtDate(run.started_at) }}</span>
          <span v-if="run.completed_at">Completed {{ fmtDate(run.completed_at) }}</span>
        </div>

        <table class="ord-table">
          <thead>
            <tr><th>Order</th><th>Customer</th><th>Batch</th><th>Batch Status</th></tr>
          </thead>
          <tbody>
            <tr v-for="b in run.batches" :key="b.id" class="ord-row" tabindex="0" role="button"
                :aria-label="`Open order ${b.order?.order_no}`"
                @click="b.order && $emit('view-order', b.order.id)" @keyup.enter="b.order && $emit('view-order', b.order.id)">
              <td class="mono">{{ b.order?.order_no || '—' }}</td>
              <td>{{ b.order?.customer?.name || '—' }}</td>
              <td class="mono">{{ b.batch_no }}</td>
              <td><span class="batch-dot" :class="b.status">{{ b.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import productionService from '../services/productionService.js'
import { toastError, toastSuccess, confirmDialog } from '../services/ui.js'

defineEmits(['view-order', 'go-planner'])

const loading = ref(true)
const busy = ref(null)
const runs = ref([])

function fmt(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN') : '' }
function statusLabel(s) { return { draft: 'Draft', in_progress: 'In Progress', completed: 'Completed', cancelled: 'Cancelled' }[s] || s }

async function load() {
  loading.value = true
  try {
    const res = await productionService.listRuns()
    const list = res?.data ?? res ?? []
    // in_progress first, then draft, then completed
    const rank = { in_progress: 0, draft: 1, completed: 2, cancelled: 3 }
    runs.value = [...list].sort((a, b) => (rank[a.status] ?? 9) - (rank[b.status] ?? 9))
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Could not load runs.')
  } finally {
    loading.value = false
  }
}

async function act(run, action) {
  const labels = {
    start:    { t: 'Start run?', m: 'All child batches will move into production.', c: 'Start', done: 'started' },
    complete: { t: 'Complete run?', m: 'All batches will move to QC.', c: 'Complete', done: 'completed' },
    cancel:   { t: 'Cancel run?', m: 'Draft batches will be removed and orders returned to pending.', c: 'Cancel run', danger: true, done: 'cancelled' },
  }[action]
  const ok = await confirmDialog({ title: labels.t, message: labels.m, confirmLabel: labels.c, cancelLabel: 'No', danger: !!labels.danger })
  if (!ok) return
  busy.value = run.id
  try {
    if (action === 'start') await productionService.startRun(run.id)
    else if (action === 'complete') await productionService.completeRun(run.id)
    else await productionService.cancelRun(run.id)
    toastSuccess(`Run ${run.run_no} ${labels.done}.`)
    await load()
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Action failed.')
  } finally {
    busy.value = null
  }
}

onMounted(load)
defineExpose({ reload: load })
</script>

<style scoped>
.pr-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.pr-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; gap: 16px; }
.pr-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.pr-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.pr-loading { text-align: center; padding: 60px; color: #888; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 40px; }
.empty a { color: var(--primary); }

.run-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; margin-bottom: 12px; }
.run-card.in_progress { border-left: 4px solid var(--primary); }
.run-card.completed { opacity: 0.75; }

.run-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.rh-left { display: flex; align-items: center; gap: 10px; }
.run-no { font-size: 15px; font-weight: 800; color: var(--text); }
.status-badge { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.4px; }
.status-badge.draft { background: var(--surface-2); color: var(--text-2); }
.status-badge.in_progress { background: var(--primary-tint); color: var(--primary); }
.status-badge.completed { background: #e8f5e9; color: #2e7d32; }
.status-badge.cancelled { background: #ffebee; color: #c62828; }
.rh-actions { display: flex; gap: 8px; }

.run-spec { font-size: 12.5px; color: var(--text-2); margin: 10px 0 6px; font-weight: 600; }
.run-stats { display: flex; gap: 18px; font-size: 12px; color: var(--text-2); margin-bottom: 10px; flex-wrap: wrap; }
.run-stats b { color: var(--text); }

.ord-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ord-table th { text-align: left; background: var(--surface-2); color: var(--text-3); font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; padding: 5px 8px; border-bottom: 1px solid var(--border); }
.ord-row td { padding: 6px 8px; border-bottom: 1px solid var(--surface-2); cursor: pointer; }
.ord-row:hover td { background: var(--primary-tint); }
.ord-row:focus { outline: 2px solid var(--primary); outline-offset: -2px; }
.mono { font-variant-numeric: tabular-nums; }
.batch-dot { font-size: 11px; font-weight: 600; text-transform: capitalize; }

.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 5px 12px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-danger { background: #c62828; color: #fff; }

@media (max-width: 900px) { .pr-wrap { padding: 16px 16px 40px; } }
</style>
