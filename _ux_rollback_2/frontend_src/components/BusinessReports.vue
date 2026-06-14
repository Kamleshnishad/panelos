<template>
  <div class="br-wrap">
    <div class="br-header">
      <h2>Business Reports</h2>
      <div class="period-picker">
        <label>From</label>
        <input v-model="from" type="date" @change="loadAll" />
        <label>To</label>
        <input v-model="to" type="date" @change="loadAll" />
        <button class="btn btn-ghost btn-sm" @click="loadAll">↻</button>
      </div>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>

    <!-- Headline KPIs from P&L + cash flow -->
    <div class="headline-grid" v-if="pl && cf">
      <div class="hl-card">
        <div class="hl-val">₹ {{ fmtShort(pl.revenue.sales) }}</div>
        <div class="hl-lbl">Net Sales</div>
      </div>
      <div class="hl-card">
        <div class="hl-val">₹ {{ fmtShort(pl.revenue.tax_collected) }}</div>
        <div class="hl-lbl">GST Collected</div>
      </div>
      <div class="hl-card primary">
        <div class="hl-val">₹ {{ fmtShort(pl.revenue.gross_revenue) }}</div>
        <div class="hl-lbl">Gross Revenue</div>
      </div>
      <div class="hl-card green">
        <div class="hl-val">₹ {{ fmtShort(cf.operating_activities.cash_collected) }}</div>
        <div class="hl-lbl">Cash Collected</div>
      </div>
      <div class="hl-card">
        <div class="hl-val">₹ {{ fmtShort(pl.average_invoice_value) }}</div>
        <div class="hl-lbl">Avg Invoice</div>
      </div>
    </div>

    <!-- Monthly revenue trend (CSS bar chart) -->
    <div class="card">
      <div class="card-head">
        <h3>Monthly Revenue Trend</h3>
        <div class="legend">
          <span><i class="sw invoiced"></i> Invoiced</span>
          <span><i class="sw collected"></i> Collected</span>
        </div>
      </div>
      <div v-if="trend" class="chart">
        <div v-for="m in trend.series" :key="m.key" class="chart-col">
          <div class="bars">
            <div class="bar invoiced" :style="{ height: barH(m.invoiced) + 'px' }" :title="'Invoiced: ₹' + fmtNum(m.invoiced)"></div>
            <div class="bar collected" :style="{ height: barH(m.collected) + 'px' }" :title="'Collected: ₹' + fmtNum(m.collected)"></div>
          </div>
          <div class="chart-lbl">{{ m.month.split(' ')[0] }}</div>
        </div>
      </div>
      <div v-else class="loading-hint">Loading…</div>
    </div>

    <div class="report-cols">
      <!-- Top customers -->
      <div class="card">
        <h3>Top Customers</h3>
        <table class="rep-table" v-if="topCustomers.length">
          <thead><tr><th>Customer</th><th class="text-right">Invoiced</th><th class="text-right">Outstanding</th></tr></thead>
          <tbody>
            <tr v-for="c in topCustomers" :key="c.customer_id">
              <td class="bold">{{ c.customer_name }}</td>
              <td class="text-right">₹ {{ fmtShort(c.invoiced) }}</td>
              <td class="text-right" :class="{ red: c.outstanding > 0 }">₹ {{ fmtShort(c.outstanding) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty-hint">No customer sales in this period.</div>
      </div>

      <!-- Panel type mix -->
      <div class="card">
        <div class="card-head">
          <h3>Panel Type Mix</h3>
          <span class="mix-total" v-if="mix">{{ fmtNum(mix.total_sqm) }} SQM</span>
        </div>
        <div v-if="mix && mix.rows.length" class="mix-list">
          <div v-for="r in mix.rows" :key="r.panel_type" class="mix-row">
            <div class="mix-info">
              <span class="mix-name">{{ r.panel_type }}</span>
              <span class="mix-val">₹ {{ fmtShort(r.value) }} · {{ fmtNum(r.sqm) }} SQM</span>
            </div>
            <div class="mix-bar-track">
              <div class="mix-bar-fill" :style="{ width: r.value_pct + '%' }"></div>
              <span class="mix-pct">{{ r.value_pct }}%</span>
            </div>
          </div>
        </div>
        <div v-else class="empty-hint">No panel sales in this period.</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import reportService from '../services/reportService.js'

const from = ref(new Date(Date.now() - 365 * 86400000).toISOString().slice(0, 10))
const to   = ref(new Date().toISOString().slice(0, 10))

const trend         = ref(null)
const topCustomers  = ref([])
const mix           = ref(null)
const pl            = ref(null)
const cf            = ref(null)
const error         = ref(null)

const maxBar = computed(() => {
  if (!trend.value) return 1
  const vals = trend.value.series.flatMap(m => [m.invoiced, m.collected])
  return Math.max(...vals, 1)
})

function barH(v) {
  const max = maxBar.value
  return Math.max(2, Math.round((Number(v) / max) * 140))
}

async function loadAll() {
  error.value = null
  const params = { from_date: from.value, to_date: to.value }
  try {
    const [t, tc, m, p, c] = await Promise.all([
      reportService.revenueTrend({ months: 12 }),
      reportService.topCustomers({ ...params, limit: 10 }),
      reportService.panelTypeMix(params),
      reportService.profitLoss(params),
      reportService.cashFlow(params),
    ])
    trend.value        = t?.data ?? null
    topCustomers.value = tc?.data ?? []
    mix.value          = m?.data ?? null
    pl.value           = p?.data ?? null
    cf.value           = c?.data ?? null
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load reports.'
  }
}

function fmtNum(n)   { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtShort(n) {
  const v = Number(n || 0)
  if (v >= 10000000) return (v / 10000000).toFixed(2) + ' Cr'
  if (v >= 100000)   return (v / 100000).toFixed(2) + ' L'
  if (v >= 1000)     return (v / 1000).toFixed(1) + ' K'
  return v.toFixed(0)
}

onMounted(loadAll)
</script>

<style scoped>
.br-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.br-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
.br-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.period-picker { display: flex; align-items: center; gap: 8px; }
.period-picker label { font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; }
.period-picker input { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }

/* Headline KPIs */
.headline-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 18px; }
.hl-card { background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 16px; text-align: center; }
.hl-card.primary { background: var(--primary); border-color: var(--primary); }
.hl-card.primary .hl-val { color: white; }
.hl-card.primary .hl-lbl { color: var(--primary-bd); }
.hl-card.green .hl-val { color: #2e7d32; }
.hl-val { font-size: 20px; font-weight: 800; color: var(--primary); }
.hl-lbl { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 3px; }

.card { background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.card h3 { margin: 0 0 14px; font-size: 14px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }
.card-head h3 { margin: 0; border: none; padding: 0; }

.legend { display: flex; gap: 14px; font-size: 12px; color: #666; }
.legend .sw { display: inline-block; width: 11px; height: 11px; border-radius: 2px; margin-right: 4px; }
.sw.invoiced { background: var(--primary); } .sw.collected { background: #43a047; }

/* CSS bar chart */
.chart { display: flex; align-items: flex-end; gap: 8px; height: 170px; padding-top: 10px; }
.chart-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; }
.bars { display: flex; align-items: flex-end; gap: 3px; height: 145px; }
.bar { width: 14px; border-radius: 3px 3px 0 0; transition: height 0.4s; }
.bar.invoiced { background: var(--primary); }
.bar.collected { background: #43a047; }
.chart-lbl { font-size: 10px; color: #888; }
.loading-hint { color: #aaa; text-align: center; padding: 30px; }

.report-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.rep-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rep-table th { background: var(--primary-tint); color: #333; padding: 7px 10px; text-align: left; font-size: 11px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.rep-table td { padding: 8px 10px; border: 1px solid #e0e0e0; }
.bold { font-weight: 700; }
.text-right { text-align: right; }
.red { color: #c62828; }

.mix-total { font-size: 12px; font-weight: 700; color: var(--primary); }
.mix-list { display: flex; flex-direction: column; gap: 12px; }
.mix-row { }
.mix-info { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 13px; }
.mix-name { font-weight: 700; color: #333; }
.mix-val { color: #888; font-size: 12px; }
.mix-bar-track { position: relative; height: 18px; background: #f0f0f0; border-radius: 9px; overflow: hidden; }
.mix-bar-fill { height: 100%; background: linear-gradient(90deg, var(--primary), var(--primary-hover)); border-radius: 9px; transition: width 0.5s; }
.mix-pct { position: absolute; right: 8px; top: 0; line-height: 18px; font-size: 10px; font-weight: 700; color: #444; }

.empty-hint { color: #aaa; font-style: italic; font-size: 13px; padding: 16px 0; text-align: center; }
.btn { padding: 6px 12px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

@media (max-width: 900px) {
  .headline-grid { grid-template-columns: repeat(2, 1fr); }
  .report-cols { grid-template-columns: 1fr; }
}
</style>
