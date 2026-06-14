<template>
  <div class="pr-wrap">
    <div class="pr-header">
      <div>
        <h2>Production Runs</h2>
        <p class="pr-sub">Grouped multi-order production. One run = same-spec orders together.</p>
      </div>
      <div class="hdr-actions">
        <button class="btn btn-ghost" @click="toggleWastage">📊 Wastage Report</button>
        <button class="btn btn-ghost" :disabled="loading" @click="load">↻ Refresh</button>
      </div>
    </div>

    <!-- Wastage report -->
    <div v-if="wastageOpen" class="wastage-panel">
      <div v-if="wastageLoading" class="mat-loading">Loading wastage report…</div>
      <template v-else-if="wastage">
        <div class="mat-head"><strong>Material Wastage (actual vs standard)</strong>
          <span class="wr-count">{{ wastage.count }} usage record(s)</span></div>
        <table class="mat-table" v-if="wastage.lines.length">
          <thead><tr><th>Material</th><th class="r">Standard</th><th class="r">Actual</th><th class="r">Wastage</th><th class="r">%</th></tr></thead>
          <tbody>
            <tr v-for="(l,i) in wastage.lines" :key="i">
              <td>{{ l.material }}</td>
              <td class="r mono">{{ fmt(l.standard) }} {{ l.unit }}</td>
              <td class="r mono">{{ fmt(l.actual) }} {{ l.unit }}</td>
              <td class="r mono" :class="l.wastage > 0 ? 'neg' : 'pos'">{{ fmt(l.wastage) }} {{ l.unit }}</td>
              <td class="r mono" :class="l.wastage_pct > 0 ? 'neg' : 'pos'">{{ l.wastage_pct }}%</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="mat-empty">No completed runs with recorded actuals yet.</p>
      </template>
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
            <button class="btn btn-ghost sm" @click="toggleMaterial(run)">📦 Material</button>
            <button v-if="run.status === 'draft'" class="btn btn-primary sm" :disabled="busy === run.id" @click="act(run, 'start')">▶ Start</button>
            <button v-if="run.status === 'in_progress'" class="btn btn-primary sm" :disabled="busy === run.id" @click="openComplete(run)">✓ Complete</button>
            <button v-if="run.status === 'draft'" class="btn btn-danger sm" :disabled="busy === run.id" @click="act(run, 'cancel')">Cancel</button>
          </div>
        </div>

        <!-- Material requirement (advisory) -->
        <div v-if="matOpen === run.id" class="mat-panel">
          <div v-if="matLoading" class="mat-loading">Computing material requirement…</div>
          <template v-else-if="matReq[run.id]">
            <div class="mat-head">
              <strong>Raw Material Requirement</strong>
              <span class="mat-overall" :class="matReq[run.id].all_ok ? 'ok' : 'short'">
                {{ matReq[run.id].all_ok ? '✓ Stock sufficient' : '⚠ Stock short' }}
              </span>
            </div>
            <table class="mat-table">
              <thead><tr><th>Material</th><th class="r">Required</th><th class="r">Available</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="(l, i) in matReq[run.id].lines" :key="i" :class="{ 'row-short': !l.ok }">
                  <td>{{ l.label }}</td>
                  <td class="r mono">{{ fmt(l.required) }} {{ l.unit }}</td>
                  <td class="r mono">{{ fmt(l.available) }} {{ l.unit }}</td>
                  <td><span class="mat-badge" :class="l.ok ? 'ok' : 'short'">{{ l.ok ? 'OK' : 'short ' + fmt(l.short_by) }}</span></td>
                </tr>
                <tr v-if="!matReq[run.id].lines.length"><td colspan="4" class="mat-empty">No computable material (panel specs incomplete).</td></tr>
              </tbody>
            </table>
            <div v-if="!matReq[run.id].all_ok" class="mat-po-actions">
              <button class="btn btn-primary sm" :disabled="poBusy === run.id" @click="createShortagePo(run)">
                {{ poBusy === run.id ? 'Creating…' : '🛒 Create draft PO for shortage' }}
              </button>
              <span class="mat-po-hint">Generates a purchase order for the short quantities — review &amp; assign a supplier in Procurement.</span>
            </div>
            <p class="mat-note" v-for="(n, i) in matReq[run.id].notes" :key="'n'+i">• {{ n }}</p>
          </template>
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

    <!-- Complete run modal: record actual material consumed -->
    <div v-if="completeRunObj" class="modal-overlay" @click.self="completeRunObj = null">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Complete Run {{ completeRunObj.run_no }}</h3>
          <button class="btn-close" @click="completeRunObj = null">✕</button>
        </div>
        <p class="modal-hint">Enter actual material consumed (default = issued). Difference adjusts stock and records wastage.</p>
        <div v-if="completeLoading" class="mat-loading">Loading…</div>
        <table v-else-if="completeUsages.length" class="mat-table">
          <thead><tr><th>Material</th><th class="r">Issued</th><th class="r">Actual</th></tr></thead>
          <tbody>
            <tr v-for="u in completeUsages" :key="u.id">
              <td>{{ u.material_name }}</td>
              <td class="r mono">{{ fmt(u.issued_qty) }} {{ u.unit }}</td>
              <td class="r"><input v-model.number="u._actual" type="number" min="0" step="0.01" class="act-input" /> {{ u.unit }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="mat-empty">No material was issued for this run.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="completeRunObj = null">Cancel</button>
          <button class="btn btn-primary" :disabled="completing" @click="doComplete">{{ completing ? 'Completing…' : '✓ Complete Run' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import productionService from '../services/productionService.js'
import procurementService from '../services/procurementService.js'
import { toastError, toastSuccess, confirmDialog } from '../services/ui.js'

defineEmits(['view-order', 'go-planner'])

const loading = ref(true)
const busy = ref(null)
const runs = ref([])
const matOpen = ref(null)
const matLoading = ref(false)
const matReq = ref({})
const poBusy = ref(null)

const wastageOpen = ref(false)
const wastageLoading = ref(false)
const wastage = ref(null)

const completeRunObj = ref(null)
const completeUsages = ref([])
const completeLoading = ref(false)
const completing = ref(false)

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

async function toggleMaterial(run) {
  if (matOpen.value === run.id) { matOpen.value = null; return }
  matOpen.value = run.id
  if (matReq.value[run.id]) return   // cached
  matLoading.value = true
  try {
    const res = await productionService.runMaterialRequirement(run.id)
    matReq.value = { ...matReq.value, [run.id]: res?.data ?? res }
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Could not compute material requirement.')
    matOpen.value = null
  } finally {
    matLoading.value = false
  }
}

async function createShortagePo(run) {
  poBusy.value = run.id
  try {
    const res = await productionService.runPoSuggestion(run.id)
    const sug = res?.data ?? res ?? {}
    const items = sug.items ?? []
    const unresolved = sug.unresolved ?? []
    if (!items.length) {
      toastError(unresolved.length
        ? `No PO created — no stock item exists yet for: ${unresolved.map(u => u.label).join(', ')}. Create the stock item first.`
        : 'Nothing short to order.')
      return
    }
    const po = await procurementService.createPO({
      notes: `Auto-generated for material shortage — run ${run.run_no}`,
      items,
    })
    const poNo = po?.data?.po_no ?? po?.po_no ?? ''
    let msg = `Draft PO ${poNo} created for ${items.length} item(s). Assign a supplier in Procurement.`
    if (unresolved.length) msg += ` (${unresolved.length} item(s) skipped — no stock item yet.)`
    toastSuccess(msg)
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Could not create PO.')
  } finally {
    poBusy.value = null
  }
}

async function toggleWastage() {
  if (wastageOpen.value) { wastageOpen.value = false; return }
  wastageOpen.value = true
  wastageLoading.value = true
  try {
    const res = await productionService.wastageReport()
    wastage.value = res?.data ?? res
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Could not load wastage report.')
    wastageOpen.value = false
  } finally { wastageLoading.value = false }
}

async function openComplete(run) {
  completeRunObj.value = run
  completeUsages.value = []
  completeLoading.value = true
  try {
    const res = await productionService.runMaterialUsage(run.id)
    const rows = res?.data ?? res ?? []
    completeUsages.value = rows.map(u => ({ ...u, _actual: Number(u.issued_qty) }))
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Could not load material usage.')
  } finally { completeLoading.value = false }
}

async function doComplete() {
  const run = completeRunObj.value
  if (!run) return
  completing.value = true
  try {
    const actuals = completeUsages.value.map(u => ({ id: u.id, actual_qty: u._actual }))
    await productionService.completeRun(run.id, actuals)
    toastSuccess(`Run ${run.run_no} completed.`)
    completeRunObj.value = null
    matReq.value = { ...matReq.value, [run.id]: undefined }
    if (wastageOpen.value) {
      try { wastage.value = (await productionService.wastageReport())?.data ?? wastage.value } catch { /* ignore */ }
    }
    await load()
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Could not complete run.')
  } finally { completing.value = false }
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
    if (action === 'start') {
      try {
        await productionService.startRun(run.id, false)
      } catch (err) {
        // Stock short → offer override
        const msg = err?.response?.data?.message ?? ''
        if (/insufficient stock/i.test(msg)) {
          busy.value = null
          const force = await confirmDialog({
            title: 'Stock is short',
            message: msg + '\n\nStart anyway (override)? Available stock will be consumed.',
            confirmLabel: 'Start anyway',
            cancelLabel: 'Cancel',
            danger: true,
          })
          if (!force) return
          busy.value = run.id
          await productionService.startRun(run.id, true)
        } else { throw err }
      }
    } else if (action === 'complete') {
      await productionService.completeRun(run.id)
    } else {
      await productionService.cancelRun(run.id)
    }
    toastSuccess(`Run ${run.run_no} ${labels.done}.`)
    matReq.value = { ...matReq.value, [run.id]: undefined }   // invalidate cached requirement
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

/* Material requirement panel */
.mat-panel { margin-top: 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; }
.mat-loading { font-size: 12px; color: var(--text-3); padding: 6px; }
.mat-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; }
.mat-overall { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 10px; }
.mat-overall.ok { background: #e8f5e9; color: #2e7d32; }
.mat-overall.short { background: #fff5f5; color: #c62828; }
.mat-table { width: 100%; border-collapse: collapse; font-size: 12px; background: var(--surface); border-radius: 6px; overflow: hidden; }
.mat-table th { text-align: left; background: var(--primary-tint); color: #333; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; padding: 5px 8px; }
.mat-table th.r, .mat-table td.r { text-align: right; }
.mat-table td { padding: 6px 8px; border-bottom: 1px solid var(--surface-2); }
.mat-table tr.row-short td { background: #fff7f7; }
.mat-badge { font-size: 10px; font-weight: 700; padding: 1px 8px; border-radius: 8px; }
.mat-badge.ok { background: #e8f5e9; color: #2e7d32; }
.mat-badge.short { background: #ffebee; color: #c62828; }
.mat-empty { text-align: center; color: #aaa; font-style: italic; }
.mat-note { font-size: 10.5px; color: var(--text-3); margin: 6px 0 0; line-height: 1.4; }
.mat-po-actions { display: flex; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
.mat-po-hint { font-size: 11px; color: var(--text-3); }
.mono { font-variant-numeric: tabular-nums; }

.hdr-actions { display: flex; gap: 8px; }
.wastage-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
.wr-count { font-size: 11px; color: var(--text-3); }
.neg { color: #c62828; } .pos { color: #2e7d32; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 540px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.modal-hint { font-size: 12px; color: var(--text-2); margin-bottom: 12px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
.act-input { width: 90px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; text-align: right; }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }

@media (max-width: 900px) { .pr-wrap { padding: 16px 16px 40px; } }
</style>
