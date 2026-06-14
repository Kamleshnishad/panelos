<template>
  <div class="cp-wrap">
    <button class="btn btn-ghost back" @click="$emit('back')">← All Customers</button>

    <div v-if="loading" class="cp-loading">Loading profile…</div>

    <template v-else-if="p">
      <!-- Header -->
      <div class="cp-head">
        <div class="avatar">{{ initials }}</div>
        <div class="head-main">
          <div class="head-top">
            <h2>{{ c.name }}</h2>
            <span class="seg-badge" :class="segClass">{{ p.repeat.segment }}</span>
            <span class="type-badge">{{ c.type || 'customer' }}</span>
          </div>
          <div class="chips">
            <span v-if="c.city || c.state" class="chip">📍 {{ [c.city, c.state].filter(Boolean).join(', ') }}</span>
            <span v-if="c.gstin" class="chip">GSTIN {{ c.gstin }}</span>
            <span v-if="c.phone" class="chip">📞 {{ c.phone }}</span>
            <span v-if="c.email" class="chip">✉ {{ c.email }}</span>
            <span v-if="c.contact_person" class="chip">👤 {{ c.contact_person }}</span>
          </div>
        </div>
      </div>

      <!-- Hero KPIs -->
      <div class="kpi-row">
        <div class="kpi hero">
          <label>Total Business</label><span>₹ {{ fmt(p.kpis.total_business) }}</span>
          <small>{{ fmt(p.kpis.total_sqm) }} SQM lifetime</small>
        </div>
        <div class="kpi"><label>Orders</label><span>{{ p.kpis.order_count }}</span><small>{{ p.kpis.quote_count }} quotations</small></div>
        <div class="kpi"><label>Avg Order Value</label><span>₹ {{ fmt(p.kpis.avg_order_value) }}</span></div>
        <div class="kpi"><label>Outstanding</label><span :class="{ red: p.kpis.outstanding > 0 }">₹ {{ fmt(p.kpis.outstanding) }}</span><small>₹ {{ fmt(p.kpis.invoiced) }} invoiced</small></div>
        <div class="kpi accent">
          <label>Repeat Frequency</label>
          <span v-if="p.repeat.avg_gap_days">every ~{{ p.repeat.avg_gap_days }}d</span>
          <span v-else>{{ p.repeat.is_repeat ? 'repeat' : 'first order' }}</span>
          <small>{{ p.repeat.orders_per_year }} / year</small>
        </div>
      </div>

      <!-- RFM + monthly sparkline -->
      <div class="cp-mid">
        <div class="rfm-card">
          <div class="rfm-cell"><label>Recency</label><b>{{ p.repeat.recency_days != null ? p.repeat.recency_days + 'd ago' : '—' }}</b></div>
          <div class="rfm-cell"><label>Frequency</label><b>{{ p.repeat.frequency }} orders</b></div>
          <div class="rfm-cell"><label>Monetary</label><b>₹ {{ fmt(p.repeat.monetary) }}</b></div>
          <div class="rfm-seg" :class="segClass">{{ p.repeat.segment }}</div>
        </div>
        <div class="spark-card">
          <label>Orders — last 12 months</label>
          <div class="spark">
            <div v-for="(m, i) in p.monthly_orders" :key="i" class="spark-col">
              <div class="spark-bar" :style="{ height: barH(m.count) }" :title="m.count + ' orders'"></div>
              <span class="spark-lbl">{{ m.label }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="cp-tabs">
        <button v-for="t in tabs" :key="t.key" :class="['cp-tab', { on: tab === t.key }]" @click="tab = t.key">
          {{ t.label }}<span v-if="t.count != null" class="tab-n">{{ t.count }}</span>
        </button>
      </div>

      <!-- Overview -->
      <div v-if="tab === 'overview'" class="tab-body grid2">
        <div class="card">
          <h4>Top Panels Ordered</h4>
          <div v-if="!p.top_products.length" class="muted">No orders yet.</div>
          <div v-for="tp in p.top_products" :key="tp.name" class="tp-row">
            <span class="tp-name">{{ tp.name }}</span>
            <span class="tp-meta">{{ tp.times }}× · {{ fmt(tp.sqm) }} SQM</span>
          </div>
        </div>
        <div class="card">
          <h4>Recent Orders</h4>
          <div v-if="!p.orders.length" class="muted">No orders yet.</div>
          <div v-for="o in p.orders.slice(0, 6)" :key="o.id" class="mini-row">
            <span class="mono">{{ o.no }}</span><span>{{ o.date }}</span>
            <span class="status-dot" :class="o.status">{{ o.status }}</span>
            <span class="r bold">₹ {{ fmt(o.total) }}</span>
          </div>
        </div>
      </div>

      <!-- Quotations -->
      <div v-else-if="tab === 'quotations'" class="tab-body">
        <table class="rel-table"><thead><tr><th>Quotation</th><th>Date</th><th>Status</th><th class="r">Total</th></tr></thead>
          <tbody>
            <tr v-for="q in p.quotations" :key="q.id" class="rel-row" @click="$emit('open-quotation', q.id)">
              <td class="mono">{{ q.no }}</td><td>{{ q.date || '—' }}</td>
              <td><span class="status-dot" :class="q.status">{{ q.status }}</span></td>
              <td class="r bold">₹ {{ fmt(q.total) }}</td>
            </tr>
            <tr v-if="!p.quotations.length"><td colspan="4" class="muted">No quotations.</td></tr>
          </tbody></table>
      </div>

      <!-- Orders -->
      <div v-else-if="tab === 'orders'" class="tab-body">
        <table class="rel-table"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="r">SQM</th><th class="r">Total</th></tr></thead>
          <tbody>
            <tr v-for="o in p.orders" :key="o.id">
              <td class="mono">{{ o.no }}</td><td>{{ o.date || '—' }}</td>
              <td><span class="status-dot" :class="o.status">{{ o.status }}</span></td>
              <td class="r mono">{{ fmt(o.sqm) }}</td><td class="r bold">₹ {{ fmt(o.total) }}</td>
            </tr>
            <tr v-if="!p.orders.length"><td colspan="5" class="muted">No orders.</td></tr>
          </tbody></table>
      </div>

      <!-- Invoices -->
      <div v-else-if="tab === 'invoices'" class="tab-body">
        <table class="rel-table"><thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="r">Total</th></tr></thead>
          <tbody>
            <tr v-for="i in p.invoices" :key="i.id">
              <td class="mono">{{ i.no }}</td><td>{{ i.date || '—' }}</td>
              <td><span class="status-dot" :class="i.status">{{ i.status }}</span></td>
              <td class="r bold">₹ {{ fmt(i.total) }}</td>
            </tr>
            <tr v-if="!p.invoices.length"><td colspan="4" class="muted">No invoices.</td></tr>
          </tbody></table>
      </div>

      <!-- Dispatches -->
      <div v-else-if="tab === 'dispatches'" class="tab-body">
        <table class="rel-table"><thead><tr><th>Dispatch</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="d in p.dispatches" :key="d.id">
              <td class="mono">{{ d.no }}</td><td>{{ d.date || '—' }}</td>
              <td><span class="status-dot" :class="d.status">{{ d.status }}</span></td>
            </tr>
            <tr v-if="!p.dispatches.length"><td colspan="3" class="muted">No dispatches.</td></tr>
          </tbody></table>
      </div>

      <!-- Leads -->
      <div v-else-if="tab === 'leads'" class="tab-body">
        <table class="rel-table"><thead><tr><th>Lead</th><th>Source</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="l in p.leads" :key="l.id">
              <td class="mono">{{ l.no }}</td><td>{{ l.source }}</td>
              <td><span class="status-dot" :class="l.status">{{ l.status }}</span></td><td>{{ l.date || '—' }}</td>
            </tr>
            <tr v-if="!p.leads.length"><td colspan="4" class="muted">No leads.</td></tr>
          </tbody></table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import customerService from '../services/customerService.js'
import { toastError } from '../services/ui.js'

const props = defineProps({ customerId: { type: Number, required: true } })
defineEmits(['back', 'open-quotation', 'view-orders'])

const p = ref(null)
const loading = ref(false)
const tab = ref('overview')

const c = computed(() => p.value?.customer || {})
const initials = computed(() => (c.value.name || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase())
const segClass = computed(() => 'seg-' + (p.value?.repeat?.segment || 'New').toLowerCase().replace(/[^a-z]/g, ''))
const tabs = computed(() => [
  { key: 'overview', label: 'Overview' },
  { key: 'quotations', label: 'Quotations', count: p.value?.quotations.length },
  { key: 'orders', label: 'Orders', count: p.value?.orders.length },
  { key: 'invoices', label: 'Invoices', count: p.value?.invoices.length },
  { key: 'dispatches', label: 'Dispatches', count: p.value?.dispatches.length },
  { key: 'leads', label: 'Leads', count: p.value?.leads.length },
])

const maxMonthly = computed(() => Math.max(1, ...(p.value?.monthly_orders || []).map(m => m.count)))
function barH(n) { return Math.max(4, Math.round((n / maxMonthly.value) * 46)) + 'px' }
function fmt(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }

async function load() {
  loading.value = true
  try {
    const res = await customerService.profile(props.customerId)
    p.value = res?.data ?? res
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not load profile.') }
  finally { loading.value = false }
}
onMounted(load)
</script>

<style scoped>
.cp-wrap { padding: 20px 32px 48px; max-width: 1600px; margin: 0 auto; }
.back { margin-bottom: 14px; }
.cp-loading { text-align: center; padding: 60px; color: #888; }

.cp-head { display: flex; gap: 18px; align-items: center; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 18px 22px; margin-bottom: 16px; }
.avatar { width: 60px; height: 60px; border-radius: 14px; background: linear-gradient(135deg, var(--primary), var(--primary-hover, #2140C0)); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; flex-shrink: 0; }
.head-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.head-top h2 { margin: 0; font-size: 22px; color: var(--ink); }
.type-badge { font-size: 11px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 2px 9px; text-transform: capitalize; color: var(--text-2); }
.chips { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.chip { font-size: 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 16px; padding: 3px 11px; color: var(--text-2); }

.seg-badge { font-size: 11px; font-weight: 800; padding: 3px 12px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
.seg-champion { background: #fff7e0; color: #b58100; border: 1px solid #ffe39a; }
.seg-loyal { background: var(--primary-tint); color: var(--primary); }
.seg-new { background: #e0f2f1; color: #00897b; }
.seg-atrisk { background: #fff3e0; color: #e65100; }
.seg-dormant { background: #ffebee; color: #c62828; }
.seg-prospect { background: var(--surface-2); color: var(--text-3); }

.kpi-row { display: grid; grid-template-columns: 1.3fr 1fr 1fr 1.2fr 1.2fr; gap: 12px; margin-bottom: 14px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; }
.kpi label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-3); font-weight: 700; }
.kpi span { font-size: 23px; font-weight: 800; color: var(--ink); font-variant-numeric: tabular-nums; }
.kpi small { display: block; font-size: 11px; color: var(--text-3); margin-top: 2px; }
.kpi .red { color: #c62828; }
.kpi.hero { background: linear-gradient(135deg, var(--primary), var(--primary-hover, #2140C0)); border: none; }
.kpi.hero label, .kpi.hero small { color: rgba(255,255,255,0.8); }
.kpi.hero span { color: #fff; }
.kpi.accent { background: var(--primary-tint); border-color: var(--primary-bd, #c5cae9); }
.kpi.accent span { color: var(--primary); }

.cp-mid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 12px; margin-bottom: 16px; }
.rfm-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; display: flex; align-items: center; gap: 24px; }
.rfm-cell label { display: block; font-size: 10px; text-transform: uppercase; color: var(--text-3); font-weight: 700; }
.rfm-cell b { font-size: 16px; color: var(--ink); }
.rfm-seg { margin-left: auto; font-size: 13px; font-weight: 800; padding: 6px 16px; border-radius: 12px; text-transform: uppercase; }
.spark-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; }
.spark-card label { font-size: 10px; text-transform: uppercase; color: var(--text-3); font-weight: 700; }
.spark { display: flex; align-items: flex-end; gap: 5px; height: 56px; margin-top: 8px; }
.spark-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.spark-bar { width: 100%; background: var(--primary); border-radius: 3px 3px 0 0; min-height: 4px; }
.spark-lbl { font-size: 8px; color: var(--text-3); }

.cp-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--border); margin-bottom: 16px; flex-wrap: wrap; }
.cp-tab { padding: 9px 16px; border: none; background: none; font-size: 13px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; }
.cp-tab.on { color: var(--primary); border-bottom-color: var(--primary); }
.tab-n { margin-left: 6px; font-size: 11px; background: var(--surface-2); border-radius: 8px; padding: 0 6px; }

.tab-body.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; }
.card h4 { margin: 0 0 12px; font-size: 13px; color: var(--primary); }
.tp-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--surface-2); font-size: 13px; }
.tp-name { font-weight: 600; } .tp-meta { color: var(--text-3); font-size: 12px; }
.mini-row { display: grid; grid-template-columns: 1fr 90px 90px 1fr; gap: 8px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--surface-2); font-size: 12.5px; }

.rel-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.rel-table th { background: var(--surface-2); color: var(--text-2); padding: 8px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; }
.rel-table td { padding: 9px 12px; border-bottom: 1px solid var(--surface-2); }
.rel-row { cursor: pointer; } .rel-row:hover td { background: var(--primary-tint); }
.mono { font-variant-numeric: tabular-nums; } .bold { font-weight: 700; } .r { text-align: right; } .muted { color: #aaa; font-style: italic; text-align: center; padding: 16px; }

.status-dot { font-size: 11px; font-weight: 700; text-transform: capitalize; padding: 2px 8px; border-radius: 8px; background: var(--surface-2); color: var(--text-2); }
.status-dot.draft { background: var(--surface-2); color: var(--text-2); }
.status-dot.sent, .status-dot.contacted { background: var(--primary-tint); color: var(--primary); }
.status-dot.accepted, .status-dot.paid, .status-dot.completed, .status-dot.won, .status-dot.qc_passed { background: #e8f5e9; color: #2e7d32; }
.status-dot.rejected, .status-dot.cancelled, .status-dot.lost, .status-dot.overdue { background: #ffebee; color: #c62828; }
.status-dot.in_production, .status-dot.pending, .status-dot.qualified { background: #fff8e1; color: #b5740a; }

.btn { padding: 8px 14px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }

@media (max-width: 1000px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .cp-mid, .tab-body.grid2 { grid-template-columns: 1fr; } }
</style>
