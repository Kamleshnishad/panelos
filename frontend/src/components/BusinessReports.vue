<template>
  <div class="br-wrap">
    <div class="br-header">
      <h2>Reports</h2>
      <div class="br-tabs">
        <button :class="{ on: tab === 'dashboard' }" @click="tab = 'dashboard'">Dashboard</button>
        <button :class="{ on: tab === 'mis' }" @click="switchTab('mis')">MIS Report</button>
        <button :class="{ on: tab === 'recon' }" @click="switchTab('recon')">Reconciliation</button>
        <button :class="{ on: tab === 'tally' }" @click="switchTab('tally')">Tally Export</button>
        <button :class="{ on: tab === 'export' }" @click="tab = 'export'">Export</button>
      </div>
      <div class="period-picker">
        <label>From</label>
        <input v-model="from" type="date" @change="onPeriodChange" />
        <label>To</label>
        <input v-model="to" type="date" @change="onPeriodChange" />
        <button class="btn btn-ghost btn-sm" @click="onPeriodChange">↻</button>
      </div>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>

    <!-- ═══ DASHBOARD TAB ═══════════════════════════════════════════════ -->
    <template v-if="tab === 'dashboard'">
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
    </template>

    <!-- ═══ MIS REPORT TAB ══════════════════════════════════════════════ -->
    <template v-else-if="tab === 'mis'">
      <div v-if="misLoading" class="loading-hint">Computing MIS…</div>
      <template v-else-if="mis">

        <!-- Revenue summary cards -->
        <div class="headline-grid">
          <div class="hl-card primary">
            <div class="hl-val">₹ {{ fmtShort(mis.revenue.invoiced) }}</div>
            <div class="hl-lbl">Total Invoiced</div>
          </div>
          <div class="hl-card green">
            <div class="hl-val">₹ {{ fmtShort(mis.revenue.collected) }}</div>
            <div class="hl-lbl">Cash Collected</div>
          </div>
          <div class="hl-card" :class="mis.revenue.outstanding > 0 ? 'amber' : ''">
            <div class="hl-val" :class="mis.revenue.outstanding > 0 ? 'red' : ''">₹ {{ fmtShort(mis.revenue.outstanding) }}</div>
            <div class="hl-lbl">Outstanding</div>
          </div>
          <div class="hl-card">
            <div class="hl-val">{{ mis.revenue.collection_pct }}%</div>
            <div class="hl-lbl">Collection Rate</div>
          </div>
          <div class="hl-card">
            <div class="hl-val">{{ mis.orders.count }}</div>
            <div class="hl-lbl">Orders</div>
          </div>
        </div>

        <!-- Production + GST side by side -->
        <div class="report-cols">
          <div class="card">
            <h3>🏭 Production Summary</h3>
            <div class="kv-list">
              <div class="kv"><span class="kv-l">Completed Runs</span><span class="kv-v">{{ mis.production.runs }}</span></div>
              <div class="kv"><span class="kv-l">SQM Produced</span><span class="kv-v bold blue">{{ fmtNum(mis.production.sqm_produced) }} SQM</span></div>
            </div>
          </div>
          <div class="card">
            <h3>📋 GST Liability</h3>
            <div class="kv-list">
              <div class="kv"><span class="kv-l">CGST</span><span class="kv-v">₹ {{ fmtNum(mis.gst.cgst) }}</span></div>
              <div class="kv"><span class="kv-l">SGST</span><span class="kv-v">₹ {{ fmtNum(mis.gst.sgst) }}</span></div>
              <div class="kv"><span class="kv-l">IGST</span><span class="kv-v">₹ {{ fmtNum(mis.gst.igst) }}</span></div>
              <div class="kv total"><span class="kv-l">Total GST</span><span class="kv-v bold blue">₹ {{ fmtNum(mis.gst.total) }}</span></div>
            </div>
          </div>
        </div>

        <!-- Aging -->
        <div class="card">
          <h3>📅 Outstanding Aging (Overdue invoices)</h3>
          <div class="aging-grid">
            <div class="aging-cell" :class="mis.aging['0_30'] > 0 ? 'warn' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['0_30']) }}</div>
              <div class="aging-lbl">0–30 Days</div>
            </div>
            <div class="aging-cell" :class="mis.aging['31_60'] > 0 ? 'warn' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['31_60']) }}</div>
              <div class="aging-lbl">31–60 Days</div>
            </div>
            <div class="aging-cell" :class="mis.aging['61_90'] > 0 ? 'danger' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['61_90']) }}</div>
              <div class="aging-lbl">61–90 Days</div>
            </div>
            <div class="aging-cell" :class="mis.aging['over_90'] > 0 ? 'danger' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['over_90']) }}</div>
              <div class="aging-lbl">Over 90 Days</div>
            </div>
          </div>
        </div>

        <!-- Monthly trend -->
        <div class="card">
          <h3>📈 Month-wise Invoiced vs Collected</h3>
          <table class="rep-table">
            <thead><tr><th>Month</th><th class="text-right">Invoiced</th><th class="text-right">Collected</th><th class="text-right">Pending</th><th class="text-right">Invoices</th></tr></thead>
            <tbody>
              <tr v-for="m in mis.monthly" :key="m.month">
                <td class="bold">{{ m.month }}</td>
                <td class="text-right">₹ {{ fmtNum(m.invoiced) }}</td>
                <td class="text-right green-val">₹ {{ fmtNum(m.collected) }}</td>
                <td class="text-right" :class="(m.invoiced - m.collected) > 0 ? 'red' : ''">₹ {{ fmtNum(m.invoiced - m.collected) }}</td>
                <td class="text-right">{{ m.count }}</td>
              </tr>
              <tr v-if="!mis.monthly.length"><td colspan="5" class="empty-hint">No invoices in this period.</td></tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- ═══ RECONCILIATION TAB ══════════════════════════════════════════ -->
    <template v-else-if="tab === 'recon'">
      <div v-if="reconLoading" class="loading-hint">Computing reconciliation…</div>
      <template v-else-if="recon">
        <div class="headline-grid">
          <div class="hl-card"><div class="hl-val">{{ recon.summary.orders }}</div><div class="hl-lbl">Orders</div></div>
          <div class="hl-card"><div class="hl-val">₹ {{ fmtShort(recon.summary.total_ordered) }}</div><div class="hl-lbl">Ordered</div></div>
          <div class="hl-card"><div class="hl-val">₹ {{ fmtShort(recon.summary.total_invoiced) }}</div><div class="hl-lbl">Invoiced</div></div>
          <div class="hl-card" :class="recon.summary.revenue_leak > 0 ? 'amber' : ''">
            <div class="hl-val red">₹ {{ fmtShort(recon.summary.revenue_leak) }}</div><div class="hl-lbl">Revenue Leak</div>
          </div>
          <div class="hl-card"><div class="hl-val red">{{ recon.summary.not_invoiced }}</div><div class="hl-lbl">Not Invoiced</div></div>
        </div>
        <div class="card">
          <div class="card-head"><h3>Order → Invoice Reconciliation</h3>
            <span class="hint-sm">Orders delivered but not / under-invoiced = money left on the table.</span>
          </div>
          <table class="rep-table">
            <thead><tr><th>Order</th><th>Customer</th><th class="text-right">Ordered</th><th class="text-right">Invoiced</th><th class="text-right">Gap</th><th>Flag</th></tr></thead>
            <tbody>
              <tr v-for="r in recon.rows" :key="r.order_id" :class="{ leak: r.flag === 'not_invoiced' || r.flag === 'under_invoiced' }">
                <td class="bold">{{ r.order_no }}</td>
                <td>{{ r.customer }}</td>
                <td class="text-right">₹ {{ fmtNum(r.ordered) }}</td>
                <td class="text-right">₹ {{ fmtNum(r.invoiced) }}</td>
                <td class="text-right" :class="{ red: r.invoice_gap > 0 }">₹ {{ fmtNum(r.invoice_gap) }}</td>
                <td><span class="flag-chip" :class="r.flag">{{ flagLabel(r.flag) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- ═══ EXPORT TAB ══════════════════════════════════════════════════ -->
    <template v-else-if="tab === 'export'">
      <div class="card">
        <h3>📤 Export Data (CSV / Excel)</h3>
        <p class="card-hint">Download your data as CSV — opens directly in Excel. Date range (top) applies to quotations / orders / invoices.</p>
        <div class="export-grid">
          <button class="exp-btn" :disabled="exporting" @click="doExport('customers')">👥 Customers</button>
          <button class="exp-btn" :disabled="exporting" @click="doExport('quotations')">📄 Quotations</button>
          <button class="exp-btn" :disabled="exporting" @click="doExport('orders')">📦 Orders</button>
          <button class="exp-btn" :disabled="exporting" @click="doExport('invoices')">🧾 Invoices</button>
        </div>
        <div v-if="exportErr" class="error-banner" style="margin-top:12px">{{ exportErr }}</div>
      </div>
    </template>

    <!-- ═══ TALLY EXPORT TAB ════════════════════════════════════════════ -->
    <template v-else-if="tab === 'tally'">
      <div class="card tally-card">
        <h3>🧾 Tally Export — Sales Vouchers</h3>
        <p class="card-hint">Export invoices for this period in Tally-compatible format. Choose XML (direct import into TallyPrime/Tally ERP 9) or CSV (manual mapping).</p>

        <div class="tally-period">
          <div class="kv-list">
            <div class="kv"><span class="kv-l">Period</span><span class="kv-v bold">{{ from }} to {{ to }}</span></div>
          </div>
        </div>

        <div class="tally-how">
          <div class="tally-step">
            <div class="step-num">1</div>
            <div>Select the date range above (From / To) and click a download button.</div>
          </div>
          <div class="tally-step">
            <div class="step-num">2</div>
            <div><b>XML:</b> Open TallyPrime → Gateway → Import → Vouchers → choose the downloaded .xml file.</div>
          </div>
          <div class="tally-step">
            <div class="step-num">3</div>
            <div><b>CSV:</b> Open in Excel → map columns to your Tally ledgers manually, or use a Tally CSV import utility.</div>
          </div>
          <div class="tally-step">
            <div class="step-num">4</div>
            <div>Ensure your Tally company has ledgers: <b>Sales @ 18%</b>, <b>CGST</b>, <b>SGST</b>, <b>IGST</b>, and a debtor ledger per customer.</div>
          </div>
        </div>

        <div class="tally-btns">
          <button class="btn btn-xml" :disabled="tallyLoading === 'xml'" @click="downloadTally('xml')">
            {{ tallyLoading === 'xml' ? 'Downloading…' : '⬇ Download Tally XML' }}
          </button>
          <button class="btn btn-csv" :disabled="tallyLoading === 'csv'" @click="downloadTally('csv')">
            {{ tallyLoading === 'csv' ? 'Downloading…' : '⬇ Download CSV' }}
          </button>
        </div>
        <div v-if="tallyError" class="error-banner" style="margin-top:12px">{{ tallyError }}</div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import reportService from '../services/reportService.js'

const from = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10))
const to   = ref(new Date().toISOString().slice(0, 10))

const tab   = ref('dashboard')
const error = ref(null)

// Dashboard
const trend        = ref(null)
const topCustomers = ref([])
const mix          = ref(null)
const pl           = ref(null)
const cf           = ref(null)

// MIS
const mis       = ref(null)
const misLoading = ref(false)

// Reconciliation
const recon = ref(null)
const reconLoading = ref(false)

// Export
const exporting = ref(false)
const exportErr = ref(null)

// Tally
const tallyLoading = ref(null)
const tallyError   = ref(null)

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

const maxBar = computed(() => {
  if (!trend.value) return 1
  const vals = trend.value.series.flatMap(m => [m.invoiced, m.collected])
  return Math.max(...vals, 1)
})
function barH(v) { return Math.max(2, Math.round((Number(v) / maxBar.value) * 140)) }
function fmtNum(n)   { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtShort(n) {
  const v = Number(n || 0)
  if (v >= 10000000) return (v / 10000000).toFixed(2) + ' Cr'
  if (v >= 100000)   return (v / 100000).toFixed(2) + ' L'
  if (v >= 1000)     return (v / 1000).toFixed(1) + ' K'
  return v.toFixed(0)
}

async function loadDashboard() {
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

async function loadMis() {
  misLoading.value = true; error.value = null
  try {
    const r = await reportService.mis({ from: from.value, to: to.value })
    mis.value = r?.data ?? null
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load MIS.'
  } finally { misLoading.value = false }
}

async function loadRecon() {
  reconLoading.value = true; error.value = null
  try {
    const r = await reportService.reconciliation({ from: from.value, to: to.value })
    recon.value = r?.data ?? null
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load reconciliation.'
  } finally { reconLoading.value = false }
}

async function switchTab(t) {
  tab.value = t
  if (t === 'mis' && !mis.value) await loadMis()
  if (t === 'recon' && !recon.value) await loadRecon()
}

function onPeriodChange() {
  mis.value = null; recon.value = null
  if (tab.value === 'dashboard') loadDashboard()
  else if (tab.value === 'mis') loadMis()
  else if (tab.value === 'recon') loadRecon()
}

function flagLabel(f) {
  return { ok: 'OK', not_invoiced: 'Not Invoiced', under_invoiced: 'Under-invoiced', over_invoiced: 'Over-invoiced' }[f] || f
}

async function doExport(type) {
  exporting.value = true; exportErr.value = null
  try {
    const res = await axios.get(`/api/export/${type}`, {
      params: { from: from.value, to: to.value }, headers: authHeaders(), responseType: 'blob',
    })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }))
    const a = document.createElement('a')
    a.href = url; a.download = `${type}_${to.value}.csv`; a.click()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    exportErr.value = e?.response?.data?.message ?? `Failed to export ${type}.`
  } finally { exporting.value = false }
}

async function downloadTally(type) {
  tallyLoading.value = type; tallyError.value = null
  try {
    const res = await axios.get(`/api/reports/tally/${type}`, {
      params: { from: from.value, to: to.value },
      headers: authHeaders(),
      responseType: 'blob',
    })
    const ext      = type === 'xml' ? 'xml' : 'csv'
    const mime     = type === 'xml' ? 'application/xml' : 'text/csv'
    const filename = `tally_sales_${from.value}_${to.value}.${ext}`
    const url      = window.URL.createObjectURL(new Blob([res.data], { type: mime }))
    const a        = document.createElement('a')
    a.href = url; a.download = filename; a.click()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    tallyError.value = e?.response?.data?.message ?? `Failed to download ${type.toUpperCase()}.`
  } finally { tallyLoading.value = null }
}

onMounted(loadDashboard)
</script>

<style scoped>
.br-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.br-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
.br-header h2 { margin: 0; font-size: 22px; color: var(--primary); }

.br-tabs { display: flex; gap: 4px; background: #eef1f5; padding: 4px; border-radius: 8px; }
.br-tabs button { border: 0; background: transparent; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; color: #555; cursor: pointer; }
.br-tabs button.on { background: #fff; color: var(--primary); box-shadow: 0 1px 3px rgba(0,0,0,.12); }

.period-picker { display: flex; align-items: center; gap: 8px; }
.period-picker label { font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; }
.period-picker input { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.loading-hint { color: #aaa; text-align: center; padding: 40px; font-size: 13px; }

/* KPI cards */
.headline-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 18px; }
.hl-card { background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 16px; text-align: center; }
.hl-card.primary { background: var(--primary); border-color: var(--primary); }
.hl-card.primary .hl-val { color: white; }
.hl-card.primary .hl-lbl { color: rgba(255,255,255,.7); }
.hl-card.green .hl-val { color: #2e7d32; }
.hl-card.amber { border-color: #ffe082; }
.hl-val { font-size: 20px; font-weight: 800; color: var(--primary); }
.hl-lbl { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 3px; }

.card { background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.card h3 { margin: 0 0 14px; font-size: 14px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }
.card-head h3 { margin: 0; border: none; padding: 0; }
.card-hint { font-size: 12px; color: #888; margin-bottom: 16px; }

.legend { display: flex; gap: 14px; font-size: 12px; color: #666; }
.legend .sw { display: inline-block; width: 11px; height: 11px; border-radius: 2px; margin-right: 4px; }
.sw.invoiced { background: var(--primary); } .sw.collected { background: #43a047; }
.chart { display: flex; align-items: flex-end; gap: 8px; height: 170px; padding-top: 10px; }
.chart-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; }
.bars { display: flex; align-items: flex-end; gap: 3px; height: 145px; }
.bar { width: 14px; border-radius: 3px 3px 0 0; transition: height 0.4s; }
.bar.invoiced { background: var(--primary); }
.bar.collected { background: #43a047; }
.chart-lbl { font-size: 10px; color: #888; }

.report-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.rep-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rep-table th { background: var(--primary-tint); color: #333; padding: 7px 10px; text-align: left; font-size: 11px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.rep-table td { padding: 8px 10px; border: 1px solid #e0e0e0; }
.rep-table tr.leak td { background: #fff7f7; }
.bold { font-weight: 700; }
.hint-sm { font-size: 11px; color: #888; font-weight: 400; }
.flag-chip { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 8px; text-transform: uppercase; }
.flag-chip.ok { background: #e8f5e9; color: #2e7d32; }
.flag-chip.not_invoiced { background: #ffebee; color: #c62828; }
.flag-chip.under_invoiced { background: #fff8e1; color: #b5740a; }
.flag-chip.over_invoiced { background: #eef1fe; color: #2B50E0; }
.export-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.exp-btn { padding: 18px 12px; border: 1px solid var(--primary-bd); background: var(--primary-tint); color: var(--primary); border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
.exp-btn:hover { background: var(--primary); color: #fff; }
.exp-btn:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width: 700px) { .export-grid { grid-template-columns: 1fr 1fr; } }
.text-right { text-align: right; }
.red { color: #c62828; }
.green-val { color: #2e7d32; }
.blue { color: var(--primary); }

/* MIS specific */
.kv-list { display: flex; flex-direction: column; gap: 8px; }
.kv { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.kv:last-child { border-bottom: none; }
.kv.total { border-top: 2px solid #e0e0e0; padding-top: 10px; font-size: 14px; }
.kv-l { color: #667085; }
.kv-v { font-weight: 600; }

.aging-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.aging-cell { border-radius: 10px; padding: 14px; text-align: center; }
.aging-cell.ok     { background: #e8f5e9; border: 1px solid #a5d6a7; }
.aging-cell.warn   { background: #fff8e1; border: 1px solid #ffe082; }
.aging-cell.danger { background: #ffebee; border: 1px solid #ef9a9a; }
.aging-val { font-size: 18px; font-weight: 800; color: #15181E; }
.aging-lbl { font-size: 11px; color: #667085; font-weight: 700; text-transform: uppercase; margin-top: 4px; }
.aging-cell.ok     .aging-val { color: #2e7d32; }
.aging-cell.warn   .aging-val { color: #b5740a; }
.aging-cell.danger .aging-val { color: #c62828; }

.mix-total { font-size: 12px; font-weight: 700; color: var(--primary); }
.mix-list { display: flex; flex-direction: column; gap: 12px; }
.mix-info { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 13px; }
.mix-name { font-weight: 700; color: #333; }
.mix-val { color: #888; font-size: 12px; }
.mix-bar-track { position: relative; height: 18px; background: #f0f0f0; border-radius: 9px; overflow: hidden; }
.mix-bar-fill { height: 100%; background: linear-gradient(90deg, var(--primary), var(--primary-hover)); border-radius: 9px; transition: width 0.5s; }
.mix-pct { position: absolute; right: 8px; top: 0; line-height: 18px; font-size: 10px; font-weight: 700; color: #444; }
.empty-hint { color: #aaa; font-style: italic; font-size: 13px; padding: 16px 0; text-align: center; }

/* Tally */
.tally-card .card-hint { margin-bottom: 14px; }
.tally-period { background: #f8faff; border: 1px solid #dce6f8; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
.tally-how { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
.tally-step { display: flex; align-items: flex-start; gap: 12px; font-size: 13px; color: #444; }
.step-num { width: 24px; height: 24px; background: var(--primary); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; }
.tally-btns { display: flex; gap: 12px; }
.btn-xml { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; padding: 10px 22px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
.btn-xml:disabled { opacity: .5; cursor: not-allowed; }
.btn-csv { background: #fff8e1; color: #b5740a; border: 1px solid #ffe082; padding: 10px 22px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
.btn-csv:disabled { opacity: .5; cursor: not-allowed; }

.btn { padding: 6px 12px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

@media (max-width: 900px) {
  .headline-grid { grid-template-columns: repeat(2, 1fr); }
  .report-cols { grid-template-columns: 1fr; }
  .aging-grid { grid-template-columns: repeat(2, 1fr); }
  .br-header { flex-direction: column; align-items: flex-start; }
}
</style>
