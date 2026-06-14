<template>
  <div class="pp-wrap">
    <div class="pp-header">
      <div>
        <h2>Production Planner</h2>
        <p class="pp-sub">Same-spec jobs ek saath chalao — kam changeover, kam material waste.</p>
      </div>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻ Refresh</button>
    </div>

    <!-- Summary strip -->
    <div v-if="!loading && plan" class="pp-summary">
      <div class="sum-cell"><label>Pending Orders</label><span>{{ plan.summary.pending_orders }}</span></div>
      <div class="sum-cell"><label>Run Groups</label><span>{{ plan.summary.run_groups }}</span></div>
      <div class="sum-cell"><label>Total SQM</label><span>{{ fmt(plan.summary.total_sqm) }}</span></div>
      <div class="sum-cell"><label>Min. Changeovers</label><span>{{ plan.summary.changeovers }}</span></div>
      <div class="sum-cell alert" :class="{ none: plan.summary.alert_count === 0 }">
        <label>Action Alerts</label><span>{{ plan.summary.alert_count }}</span>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="pp-loading">Plan ban raha hai…</div>

    <template v-else-if="plan">
      <!-- ALERTS: "is job ko pehle lo" -->
      <section v-if="plan.alerts.length" class="pp-alerts">
        <div v-for="(a, i) in plan.alerts" :key="i" class="alert-card" :class="a.severity">
          <div class="alert-icon">🔔</div>
          <div class="alert-body">
            <div class="alert-title">{{ a.title }}
              <span class="alert-tag" :class="a.type">{{ tagLabel(a.type) }}</span>
            </div>
            <div class="alert-msg">{{ a.message }}</div>
          </div>
          <div class="alert-metric">{{ fmt(a.total_sqm) }}<small>SQM</small></div>
        </div>
      </section>
      <div v-else class="pp-noalert">✓ Koi urgent action nahi — sequence neeche recommended hai.</div>

      <!-- RECOMMENDED RUN SEQUENCE -->
      <section class="pp-runs">
        <h3>Recommended Run Sequence</h3>
        <p v-if="!plan.groups.length" class="empty">Koi pending order production ke liye nahi hai.</p>

        <div v-for="g in plan.groups" :key="g.signature" class="run-card" :class="{ running: g.matches_running, overdue: g.is_overdue }">
          <div class="run-rail">
            <span class="run-no">{{ g.run_order }}</span>
          </div>
          <div class="run-main">
            <div class="run-top">
              <div class="run-label">{{ g.label }}</div>
              <div class="run-badges">
                <span v-if="g.matches_running" class="rb rb-run">🔁 Line par chal raha</span>
                <span v-if="g.order_count > 1" class="rb rb-merge">⛓ {{ g.order_count }} orders merge</span>
                <span v-if="g.is_overdue" class="rb rb-over">⚠ Overdue</span>
                <span v-else-if="g.due_soon" class="rb rb-soon">⏱ Due {{ g.days_to_due }}d</span>
                <button class="btn-run" :disabled="creating === g.signature" @click="createRun(g)">
                  {{ creating === g.signature ? 'Ban raha…' : '▶ Create Run' }}
                </button>
              </div>
            </div>

            <div class="run-meta">
              <span><b>{{ fmt(g.total_sqm) }}</b> SQM</span>
              <span><b>{{ g.total_nos }}</b> panels</span>
              <span v-if="g.dl_count">{{ g.dl_count }} DL <small>(doubled-length)</small></span>
              <span class="rm-app">{{ g.application }} · {{ g.thickness }}mm · {{ g.core_type }}</span>
            </div>

            <table class="ord-table">
              <thead>
                <tr><th>Order</th><th>Customer</th><th class="r">SQM</th><th class="r">Panels</th><th>Due</th></tr>
              </thead>
              <tbody>
                <tr v-for="o in g.orders" :key="o.order_id" class="ord-row" tabindex="0" role="button"
                    :aria-label="`Open order ${o.order_no}`"
                    @click="$emit('view-order', o.order_id)" @keyup.enter="$emit('view-order', o.order_id)">
                  <td class="mono">{{ o.order_no }}</td>
                  <td>{{ o.customer_name }}</td>
                  <td class="r mono">{{ fmt(o.sqm) }}</td>
                  <td class="r mono">{{ o.nos }}</td>
                  <td :class="dueClass(o.due_date)">{{ o.due_date || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <p class="pp-foot">Phase 1: yeh sirf recommendation hai. Aap orders open kar ke production batch banao.
        (Phase 2 mein ek hi run multiple orders ka seedha yahin se banega.)</p>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import productionService from '../services/productionService.js'
import { toastError, toastSuccess, confirmDialog } from '../services/ui.js'

const emit = defineEmits(['view-order', 'view-runs'])

const loading = ref(true)
const plan = ref(null)
const creating = ref(null)

function fmt(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

function tagLabel(t) {
  return { running_match: 'Pehle lo', merge_pending: 'Merge', overdue: 'Overdue' }[t] || t
}
function dueClass(d) {
  if (!d) return ''
  const days = Math.ceil((new Date(d) - new Date().setHours(0,0,0,0)) / 86400000)
  if (days < 0) return 'due-over'
  if (days <= 3) return 'due-soon'
  return ''
}

async function load() {
  loading.value = true
  try {
    const res = await productionService.planning()
    plan.value = res?.data ?? res
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Plan load nahi hua.')
  } finally {
    loading.value = false
  }
}

async function createRun(g) {
  const orderIds = g.orders.map(o => o.order_id)
  const ok = await confirmDialog({
    title: 'Production Run banayein?',
    message: `${g.order_count} order(s) ka ek hi run banega (${fmt(g.total_sqm)} SQM). Ye orders production mein chale jaayenge.`,
    confirmLabel: 'Haan, Run banao',
    cancelLabel: 'Cancel',
  })
  if (!ok) return
  creating.value = g.signature
  try {
    const res = await productionService.createRun({
      order_ids: orderIds,
      signature: g.signature,
      label: g.label,
    })
    const run = res?.data ?? res
    toastSuccess(`Run ${run.run_no} ban gaya — ${g.order_count} order(s).`)
    emit('view-runs')
    await load()
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Run nahi bana.')
  } finally {
    creating.value = null
  }
}

onMounted(load)
defineExpose({ reload: load })
</script>

<style scoped>
.pp-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }

.pp-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; gap: 16px; }
.pp-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.pp-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }

.pp-summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 20px; }
.sum-cell { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; display: flex; flex-direction: column; gap: 3px; }
.sum-cell label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-3); font-weight: 700; }
.sum-cell span { font-size: 22px; font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; }
.sum-cell.alert span { color: #c62828; }
.sum-cell.alert.none span { color: #2e7d32; }

.pp-loading { text-align: center; padding: 60px; color: #888; }

/* Alerts */
.pp-alerts { display: flex; flex-direction: column; gap: 10px; margin-bottom: 22px; }
.alert-card { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-radius: 10px; border: 1px solid; }
.alert-card.high { background: #fff5f5; border-color: #f3b4b4; }
.alert-card.medium { background: #fff8e1; border-color: #ffe082; }
.alert-icon { font-size: 22px; flex-shrink: 0; }
.alert-body { flex: 1; min-width: 0; }
.alert-title { font-size: 14px; font-weight: 700; color: #15181E; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.alert-msg { font-size: 12.5px; color: var(--text-2); margin-top: 3px; line-height: 1.5; }
.alert-tag { font-size: 10px; font-weight: 700; padding: 1px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.4px; }
.alert-tag.running_match { background: #c62828; color: #fff; }
.alert-tag.merge_pending { background: var(--primary); color: #fff; }
.alert-tag.overdue { background: #e65100; color: #fff; }
.alert-metric { font-size: 20px; font-weight: 800; color: var(--primary); text-align: right; font-variant-numeric: tabular-nums; }
.alert-metric small { display: block; font-size: 9px; color: var(--text-3); font-weight: 700; }

.pp-noalert { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 22px; }

/* Runs */
.pp-runs h3 { font-size: 15px; color: var(--primary); margin: 0 0 12px; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 30px; }

.run-card { display: flex; gap: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; overflow: hidden; }
.run-card.running { border-color: #f3b4b4; box-shadow: 0 0 0 1px #f3b4b4; }
.run-card.overdue { border-left: 4px solid #e65100; }
.run-rail { width: 52px; flex-shrink: 0; background: var(--surface-2); display: flex; align-items: center; justify-content: center; border-right: 1px solid var(--border); }
.run-no { width: 30px; height: 30px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.run-main { flex: 1; padding: 14px 18px; min-width: 0; }

.run-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
.run-label { font-size: 13px; font-weight: 700; color: #15181E; line-height: 1.45; }
.run-badges { display: flex; gap: 6px; flex-wrap: wrap; flex-shrink: 0; }
.rb { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
.rb-run { background: #ffebee; color: #c62828; }
.rb-merge { background: var(--primary-tint); color: var(--primary); }
.rb-over { background: #fff3e0; color: #e65100; }
.rb-soon { background: #fff8e1; color: #b5740a; }
.btn-run { background: var(--primary); color: #fff; border: none; border-radius: 8px; padding: 4px 12px; font-size: 11px; font-weight: 700; cursor: pointer; }
.btn-run:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-run:hover:not(:disabled) { background: var(--primary-hover, #2140C0); }

.run-meta { display: flex; gap: 18px; margin: 8px 0 10px; font-size: 12px; color: var(--text-2); flex-wrap: wrap; }
.run-meta b { color: var(--text); }
.rm-app { margin-left: auto; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }

.ord-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ord-table th { text-align: left; background: var(--surface-2); color: var(--text-3); font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; padding: 5px 8px; border-bottom: 1px solid var(--border); }
.ord-table th.r, .ord-table td.r { text-align: right; }
.ord-row td { padding: 6px 8px; border-bottom: 1px solid var(--surface-2); cursor: pointer; }
.ord-row:hover td { background: var(--primary-tint); }
.ord-row:focus { outline: 2px solid var(--primary); outline-offset: -2px; }
.mono { font-variant-numeric: tabular-nums; }
.due-over { color: #c62828; font-weight: 700; }
.due-soon { color: #b5740a; font-weight: 600; }

.pp-foot { margin-top: 16px; font-size: 11.5px; color: var(--text-3); font-style: italic; line-height: 1.5; }

.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }

@media (max-width: 900px) {
  .pp-summary { grid-template-columns: repeat(2, 1fr); }
  .pp-wrap { padding: 16px 16px 40px; }
}
</style>
