<template>
  <div class="dash-wrap">
    <div class="dash-header">
      <h2>Dashboard</h2>
      <button class="btn btn-ghost btn-sm" @click="load">↻ Refresh</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="loading" class="loading-state">Loading dashboard…</div>

    <template v-else-if="data">
      <!-- KPI cards -->
      <div class="kpi-grid">
        <div v-for="k in kpiCards" :key="k.key" class="kpi-card" @click="k.nav && $emit('navigate', k.nav)">
          <div class="kpi-top">
            <div class="kpi-icon" :class="k.tone" v-html="k.icon"></div>
            <svg v-if="k.nav" class="kpi-arrow" width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <div class="kpi-val mono">{{ k.value }}</div>
          <div class="kpi-lbl">{{ k.label }}</div>
          <div class="kpi-delta" :class="k.deltaTone">{{ k.delta }}</div>
        </div>
      </div>

      <template v-if="a">
        <!-- Business-performance headline tiles (FY) -->
        <div class="stat-strip">
          <div v-for="s in statTiles" :key="s.label" class="stat-tile">
            <div class="stat-val mono">{{ s.value }}</div>
            <div class="stat-lbl">{{ s.label }}</div>
            <div class="stat-hint">{{ s.hint }}</div>
          </div>
        </div>

        <div class="bi-section">Sales &amp; Revenue</div>
        <div class="bi-grid">
          <BiChart class="span-2" title="Revenue vs Collections" subtitle="Last 12 months · invoiced, collected and order bookings"
                   :labels="monthLabels" :fmt="money" :height="260"
                   :series="[{ name: 'Invoiced', data: a.trend.invoiced }, { name: 'Collected', data: a.trend.collected }, { name: 'Orders booked', data: a.trend.booked }]" />
          <BiChart title="Quotations vs Orders" subtitle="Monthly value quoted vs converted" type="bar"
                   :labels="monthLabels" :fmt="money" :height="260"
                   :series="[{ name: 'Quoted', data: a.trend.quoted }, { name: 'Booked', data: a.trend.booked }]" />
          <BiChart title="Top Customers" subtitle="Order value this FY" type="hbar" :fmt="money"
                   :labels="a.top_customers.map(c => c.name)" :series="[{ name: 'Order value', data: a.top_customers.map(c => c.total) }]" />
          <BiChart title="Revenue by State" subtitle="Order value this FY" type="donut" :fmt="money"
                   :labels="a.revenue_by_state.map(r => r.label)" :series="[{ name: 'Order value', data: a.revenue_by_state.map(r => r.value) }]" />
          <BiChart title="Quotation Outcomes" subtitle="Quotations created this FY, by value" type="donut" :fmt="money"
                   :labels="a.quotation_outcomes.map(r => r.label)" :series="[{ name: 'Value', data: a.quotation_outcomes.map(r => r.value) }]" />
        </div>

        <div class="bi-section">Leads &amp; Cash</div>
        <div class="bi-grid">
          <BiChart title="Lead Funnel" subtitle="All leads by stage" type="hbar"
                   :labels="a.lead_funnel.map(r => r.label)" :series="[{ name: 'Leads', data: a.lead_funnel.map(r => r.value) }]" />
          <BiChart title="Lead Sources" subtitle="Leads vs won this FY" type="bar"
                   :labels="a.lead_sources.map(r => r.label)"
                   :series="[{ name: 'Leads', data: a.lead_sources.map(r => r.value) }, { name: 'Won', data: a.lead_sources.map(r => r.won) }]" />
          <BiChart title="Collections by Method" subtitle="Payments received this FY" type="donut" :fmt="money"
                   :labels="a.payment_methods.map(r => r.label)" :series="[{ name: 'Collected', data: a.payment_methods.map(r => r.value) }]" />
          <BiChart title="Receivables Aging" subtitle="Outstanding by days overdue" type="bar" :fmt="money"
                   :labels="['Current', '1–30', '31–60', '61–90', '90+']"
                   :series="[{ name: 'Outstanding', data: ['current', '30_days', '60_days', '90_days', 'over_90_days'].map(k => Number(data.receivables?.[k] || 0)) }]" />
        </div>

        <div class="bi-section">Operations</div>
        <div class="bi-grid">
          <BiChart title="Production Output" subtitle="Quantity completed per month" type="bar"
                   :labels="monthLabels" :series="[{ name: 'Completed qty', data: a.trend.produced }]" />
          <BiChart title="Batch Status" subtitle="All production batches" type="hbar"
                   :labels="a.batch_status.map(r => r.label.replace('_', ' '))" :series="[{ name: 'Batches', data: a.batch_status.map(r => r.value) }]" />
          <BiChart title="Dispatch Status" subtitle="All dispatches" type="donut"
                   :labels="a.dispatch_status.map(r => r.label.replace('_', ' '))" :series="[{ name: 'Dispatches', data: a.dispatch_status.map(r => r.value) }]" />
        </div>
      </template>

      <div class="dash-cols">
        <!-- Left column -->
        <div class="dash-col">
          <!-- Alerts -->
          <div class="card">
            <h3>Alerts</h3>
            <div v-if="data.alerts.length === 0" class="empty-hint">All clear — no active alerts.</div>
            <div v-else class="alert-list">
              <div v-for="(a, i) in data.alerts" :key="i" :class="['alert-row', a.severity]">
                <span class="alert-icon">{{ alertIcon(a) }}</span>
                <span class="alert-msg">{{ a.message }}</span>
              </div>
            </div>
          </div>

        </div>

        <!-- Right column -->
        <div class="dash-col">
          <!-- Pipeline -->
          <div class="card">
            <h3>Order-to-Cash Pipeline</h3>
            <div class="pipeline">
              <div class="pipe-stage">
                <div class="pipe-title">Quotations</div>
                <div class="pipe-rows">
                  <span>Draft <b>{{ data.pipeline.quotations.draft }}</b></span>
                  <span>Sent <b>{{ data.pipeline.quotations.sent }}</b></span>
                  <span>Accepted <b>{{ data.pipeline.quotations.accepted }}</b></span>
                </div>
              </div>
              <div class="pipe-arrow">→</div>
              <div class="pipe-stage">
                <div class="pipe-title">Orders</div>
                <div class="pipe-rows">
                  <span>Pending <b>{{ data.pipeline.orders.pending }}</b></span>
                  <span>Producing <b>{{ data.pipeline.orders.in_production }}</b></span>
                  <span>Done <b>{{ data.pipeline.orders.completed }}</b></span>
                </div>
              </div>
              <div class="pipe-arrow">→</div>
              <div class="pipe-stage">
                <div class="pipe-title">Batches</div>
                <div class="pipe-rows">
                  <span>Running <b>{{ data.pipeline.batches.in_progress }}</b></span>
                  <span>QC <b>{{ data.pipeline.batches.qc_pending }}</b></span>
                  <span>Dispatched <b>{{ data.pipeline.batches.dispatched }}</b></span>
                </div>
              </div>
              <div class="pipe-arrow">→</div>
              <div class="pipe-stage">
                <div class="pipe-title">Invoices</div>
                <div class="pipe-rows">
                  <span>Sent <b>{{ data.pipeline.invoices.sent }}</b></span>
                  <span>Accepted <b>{{ data.pipeline.invoices.accepted }}</b></span>
                  <span>Paid <b>{{ data.pipeline.invoices.paid }}</b></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Recent activity -->
          <div class="card">
            <h3>Recent Activity</h3>
            <div v-if="data.recent_activity.length === 0" class="empty-hint">No recent activity.</div>
            <div v-else class="activity-list">
              <div v-for="(e, i) in data.recent_activity" :key="i" class="activity-row">
                <span :class="['act-type', e.type]">{{ e.type }}</span>
                <span class="act-ref mono">{{ e.ref }}</span>
                <span :class="['act-status', e.status]">{{ e.status }}</span>
                <span class="act-amt" v-if="e.amount">₹ {{ fmtShort(e.amount) }}</span>
                <span class="act-time">{{ fmtAgo(e.at) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import dashboardService from '../services/dashboardService.js'
import BiChart from './BiChart.vue'

defineEmits(['navigate'])

const data    = ref(null)
const loading = ref(false)
const error   = ref(null)

const I = {
  quote:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M6 2h9l5 5v15H6z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 2v6h6M9 13h6M9 17h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  order:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="m3 7 9-4 9 4-9 4-9-4Zm0 0v10l9 4 9-4V7" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
  factory: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 21V9l6 4V9l6 4V5l6 16H3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
  truck:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 6h11v9H3zM14 9h4l3 3v3h-7" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="17.5" r="1.8" stroke="currentColor" stroke-width="2"/></svg>',
  money:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>',
  coins:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="6" rx="8" ry="3" stroke="currentColor" stroke-width="2"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" stroke="currentColor" stroke-width="2"/></svg>',
}

const kpiCards = computed(() => {
  const k = data.value?.kpis ?? {}
  const yoy = k.collected_yoy_pct
  return [
    { key: 'q', nav: 'quotations', icon: I.quote,   tone: 'blue',   label: 'Open Quotations',
      value: k.open_quotations ?? 0,
      delta: (k.quotations_this_week ?? 0) > 0 ? `+${k.quotations_this_week} this week` : 'No new this week',
      deltaTone: (k.quotations_this_week ?? 0) > 0 ? 'up' : 'mut' },
    { key: 'o', nav: 'orders', icon: I.order,   tone: 'purple', label: 'Active Orders',
      value: k.active_orders ?? 0,
      delta: (k.orders_overdue ?? 0) > 0 ? `${k.orders_overdue} overdue` : 'On track',
      deltaTone: (k.orders_overdue ?? 0) > 0 ? 'down' : 'up' },
    { key: 'b', nav: 'batches', icon: I.factory, tone: 'teal',   label: 'In Production',
      value: k.batches_in_production ?? 0,
      delta: `${k.batches_on_schedule ?? 0} on schedule`, deltaTone: 'mut' },
    { key: 'd', nav: 'dispatches', icon: I.truck,   tone: 'amber',  label: 'Pending Dispatch',
      value: k.pending_dispatch ?? 0,
      delta: (k.dispatch_ready ?? 0) > 0 ? `${k.dispatch_ready} ready to ship` : 'None ready',
      deltaTone: (k.dispatch_ready ?? 0) > 0 ? 'up' : 'mut' },
    { key: 'r', nav: 'receivables', icon: I.money,   tone: 'red',    label: 'Outstanding',
      value: '₹' + fmtShort(k.outstanding_amount),
      delta: (k.overdue_invoice_count ?? 0) > 0 ? `${k.overdue_invoice_count} overdue` : 'All current',
      deltaTone: (k.overdue_invoice_count ?? 0) > 0 ? 'down' : 'up' },
    { key: 'c', nav: 'reports', icon: I.coins,   tone: 'green',  label: 'Collected (FY)',
      value: '₹' + fmtShort(k.collected_fy),
      delta: yoy === null || yoy === undefined ? 'This financial year' : `${yoy >= 0 ? '+' : ''}${yoy}% YoY`,
      deltaTone: yoy === null || yoy === undefined ? 'mut' : (yoy >= 0 ? 'up' : 'down') },
  ]
})

const a = computed(() => data.value?.analytics)
const monthLabels = computed(() => (a.value?.trend.months ?? []).map(m =>
  new Date(m + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'short', year: '2-digit' })))
const money = v => '₹' + fmtShort(v)
const pctOr = (v, suffix = '%') => v === null || v === undefined ? '—' : v + suffix

const statTiles = computed(() => {
  const h = a.value?.headline ?? {}
  return [
    { label: 'Invoiced (FY)',        value: money(h.invoiced_fy),          hint: 'Billed this financial year' },
    { label: 'Orders (FY)',          value: h.orders_fy ?? 0,              hint: 'Excluding cancelled' },
    { label: 'Avg Order Value',      value: money(h.avg_order_value),      hint: 'This financial year' },
    { label: 'Quote Win Rate',       value: pctOr(h.win_rate_pct),         hint: 'Accepted ÷ decided' },
    { label: 'Collection Efficiency', value: pctOr(h.collection_eff_pct),  hint: 'Collected ÷ invoiced (FY)' },
    { label: 'DSO',                  value: pctOr(h.dso_days, ' days'),    hint: 'Days sales outstanding' },
    { label: 'On-time Delivery',     value: pctOr(h.on_time_pct),          hint: 'Delivered by expected date' },
  ]
})

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await dashboardService.get()
    data.value = res?.data ?? res
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load dashboard.'
  } finally {
    loading.value = false
  }
}

function alertIcon(a) {
  return { low_stock: '⚠', expiring: '⏰', expired: '❌', overdue: '💸', quote_expired: '📅' }[a.type] ?? 'ℹ'
}

function fmtShort(n) {
  const v = Number(n || 0)
  if (v >= 10000000) return (v / 10000000).toFixed(2) + ' Cr'
  if (v >= 100000)   return (v / 100000).toFixed(2) + ' L'
  if (v >= 1000)     return (v / 1000).toFixed(1) + ' K'
  return v.toFixed(0)
}
function fmtAgo(d) {
  if (!d) return ''
  const diff = (Date.now() - new Date(d).getTime()) / 1000
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago'
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago'
  return Math.floor(diff / 86400) + 'd ago'
}

onMounted(load)
</script>

<style scoped>
.dash-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.dash-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.dash-header h2 { margin: 0; font-size: 17px; color: var(--ink); letter-spacing: -0.02em; font-weight: 800; }

.error-banner { background: var(--danger-bg); border: 1px solid var(--danger-bd); color: var(--danger); padding: 10px 16px; border-radius: var(--r-sm); font-size: 13px; margin-bottom: 12px; }
.loading-state { text-align: center; padding: 60px; color: var(--text-3); }

/* KPI grid */
.kpi-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 13px; margin-bottom: 16px; }
.kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 16px 16px 14px; cursor: pointer; box-shadow: var(--shadow-xs); transition: transform var(--t), box-shadow var(--t), border-color var(--t); }
.kpi-card:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); border-color: var(--primary-bd); }
.kpi-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-icon.blue   { background: var(--primary-tint); color: var(--primary); }
.kpi-icon.purple { background: var(--purple-bg);    color: var(--purple); }
.kpi-icon.teal   { background: var(--teal-bg);      color: var(--teal); }
.kpi-icon.amber  { background: var(--warning-bg);   color: var(--warning); }
.kpi-icon.red    { background: var(--danger-bg);    color: var(--danger); }
.kpi-icon.green  { background: var(--success-bg);   color: var(--success); }
.kpi-arrow { color: #cbd3e0; transition: color var(--t-fast); }
.kpi-card:hover .kpi-arrow { color: var(--primary); }
.kpi-val { font-size: 21px; font-weight: 700; color: var(--ink); line-height: 1.05; letter-spacing: -0.02em; }
.kpi-lbl { font-size: 12px; color: var(--text-2); font-weight: 500; margin-top: 4px; }
.kpi-delta { font-size: 11.5px; font-weight: 600; margin-top: 8px; }
.kpi-delta.up   { color: var(--success); }
.kpi-delta.down { color: var(--danger); }
.kpi-delta.mut  { color: var(--text-3); }

/* BI section */
.stat-strip { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 13px; margin-bottom: 8px; }
.stat-tile { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 14px 16px; box-shadow: var(--shadow-xs); }
.stat-val { font-size: 19px; font-weight: 700; color: var(--ink); letter-spacing: -0.02em; }
.stat-lbl { font-size: 12px; color: var(--text-2); font-weight: 600; margin-top: 4px; }
.stat-hint { font-size: 11px; color: var(--text-3); margin-top: 2px; }
.bi-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-3); margin: 18px 0 10px; }
.bi-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 4px; }
.bi-grid .span-2 { grid-column: span 2; }
.bi-grid + .dash-cols, .bi-grid ~ .dash-cols { margin-top: 18px; }

.dash-cols { display: grid; grid-template-columns: 1fr 1.4fr; gap: 16px; align-items: start; }
.dash-col { display: flex; flex-direction: column; gap: 16px; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 20px 22px; box-shadow: var(--shadow-xs); }
.card h3 { margin: 0 0 14px; font-size: 14px; color: var(--text); font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
.empty-hint { color: var(--text-3); font-style: italic; font-size: 13px; padding: 8px 0; }

/* Alerts */
.alert-list { display: flex; flex-direction: column; gap: 8px; }
.alert-row { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; font-size: 13px; }
.alert-row.warning { background: #fff8f0; border: 1px solid #ffe0b2; color: #e65100; }
.alert-row.danger  { background: #fff5f5; border: 1px solid #ffcdd2; color: #c62828; }
.alert-row.info    { background: #f0f7ff; border: 1px solid #bbdefb; color: var(--primary); }
.alert-icon { font-size: 15px; }

/* Pipeline */
.pipeline { display: flex; align-items: stretch; gap: 4px; overflow-x: auto; }
.pipe-stage { flex: 1; min-width: 120px; background: var(--surface-2); border: 1px solid #e0e4f5; border-radius: 8px; padding: 10px; }
.pipe-title { font-size: 11px; font-weight: 700; color: var(--primary); text-transform: uppercase; margin-bottom: 6px; }
.pipe-rows { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: #666; }
.pipe-rows b { color: #222; float: right; }
.pipe-rows span { display: flex; justify-content: space-between; }
.pipe-arrow { display: flex; align-items: center; color: var(--primary-bd); font-size: 18px; }

/* Activity */
.activity-list { display: flex; flex-direction: column; }
.activity-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f5f5f5; font-size: 12px; }
.activity-row:last-child { border-bottom: none; }
.act-type { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 2px 7px; border-radius: 8px; width: 70px; text-align: center; }
.act-type.quotation { background: var(--primary-tint); color: var(--primary); }
.act-type.order     { background: #fff8e1; color: #f57f17; }
.act-type.dispatch  { background: #e0f2f1; color: #00695c; }
.act-type.invoice   { background: #ede7f6; color: #4527a0; }
.act-ref { font-weight: 700; color: #333; flex: 1; }
.mono { font-family: monospace; }
.act-status { font-size: 10px; color: #888; text-transform: uppercase; }
.act-amt { font-weight: 700; color: var(--primary); }
.act-time { color: #aaa; width: 60px; text-align: right; }

.btn { padding: 6px 12px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-sm { padding: 5px 11px; font-size: 12px; }
.btn-link { background: none; border: none; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; padding: 4px 0; text-align: left; }
.btn-link:hover { text-decoration: underline; }

@media (max-width: 900px) {
  .kpi-grid { grid-template-columns: repeat(3, 1fr); }
  .dash-cols { grid-template-columns: 1fr; }
}
@media (max-width: 1280px) {
  .stat-strip { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .bi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 700px) {
  .stat-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .bi-grid { grid-template-columns: 1fr; }
  .bi-grid .span-2 { grid-column: auto; }
}
</style>
