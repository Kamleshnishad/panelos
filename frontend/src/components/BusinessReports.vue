<template>
  <div class="br-wrap">
    <div class="br-header">
      <h2>Reports</h2>
      <div class="br-tabs">
        <button :class="{ on: tab === 'dashboard' }" @click="tab = 'dashboard'">Dashboard</button>
        <button :class="{ on: tab === 'gst' }" @click="tab = 'gst'">GST Register</button>
        <button :class="{ on: tab === 'payments' }" @click="tab = 'payments'">Payments</button>
        <button :class="{ on: tab === 'statements' }" @click="tab = 'statements'">Statements</button>
        <button :class="{ on: tab === 'mis' }" @click="switchTab('mis')">MIS Report</button>
        <button :class="{ on: tab === 'recon' }" @click="switchTab('recon')">Reconciliation</button>
        <button :class="{ on: tab === 'tally' }" @click="switchTab('tally')">Tally Export</button>
        <button :class="{ on: tab === 'export' }" @click="tab = 'export'">Export</button>
      </div>
      <div class="period-picker">
        <select v-model="preset" aria-label="Period" @change="onPreset">
          <option v-for="p in PERIOD_PRESETS" :key="p.key" :value="p.key">{{ p.label }}</option>
          <option value="custom">Custom dates</option>
        </select>
        <label>From</label>
        <input v-model="from" type="date" @change="onDates" />
        <label>To</label>
        <input v-model="to" type="date" @change="onDates" />
        <button class="btn btn-ghost btn-sm" @click="onPeriodChange">↻</button>
      </div>
    </div>

    <div v-if="badPeriod" class="error-banner" role="alert">The end of the period is before its start.</div>
    <div v-if="error" class="error-banner">{{ error }}</div>

    <!-- ═══ DASHBOARD TAB ═══════════════════════════════════════════════ -->
    <template v-if="tab === 'dashboard'">
      <div class="headline-grid" v-if="pl && cf">
        <div class="hl-card click" role="button" tabindex="0" title="See the invoices behind this" @click="tab = 'gst'" @keyup.enter="tab = 'gst'">
          <div class="hl-val">₹ {{ fmtShort(pl.revenue.sales) }}</div>
          <div class="hl-lbl">Net Sales</div>
          <div v-if="cmp.sales" class="hl-delta" :class="cmp.sales.cls" :title="cmp.sales.title">{{ cmp.sales.text }}</div>
        </div>
        <div class="hl-card click" role="button" tabindex="0" title="See the invoices behind this" @click="tab = 'gst'" @keyup.enter="tab = 'gst'">
          <div class="hl-val">₹ {{ fmtShort(pl.revenue.tax_collected) }}</div>
          <div class="hl-lbl">GST Collected</div>
          <div v-if="cmp.tax" class="hl-delta" :class="cmp.tax.cls" :title="cmp.tax.title">{{ cmp.tax.text }}</div>
        </div>
        <div class="hl-card primary click" role="button" tabindex="0" title="See the invoices behind this" @click="tab = 'gst'" @keyup.enter="tab = 'gst'">
          <div class="hl-val">₹ {{ fmtShort(pl.revenue.gross_revenue) }}</div>
          <div class="hl-lbl">Gross Revenue</div>
          <div v-if="cmp.gross" class="hl-delta" :class="cmp.gross.cls" :title="cmp.gross.title">{{ cmp.gross.text }}</div>
        </div>
        <div class="hl-card green click" role="button" tabindex="0" title="See the payments behind this" @click="tab = 'payments'" @keyup.enter="tab = 'payments'">
          <div class="hl-val">₹ {{ fmtShort(cf.operating_activities.cash_collected) }}</div>
          <div class="hl-lbl">Cash Collected</div>
          <div v-if="cmp.cash" class="hl-delta" :class="cmp.cash.cls" :title="cmp.cash.title">{{ cmp.cash.text }}</div>
        </div>
        <div class="hl-card">
          <div class="hl-val">₹ {{ fmtShort(pl.average_invoice_value) }}</div>
          <div class="hl-lbl">Avg Invoice</div>
          <div v-if="cmp.avg" class="hl-delta" :class="cmp.avg.cls" :title="cmp.avg.title">{{ cmp.avg.text }}</div>
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
              <tr v-for="c in topCustomers" :key="c.customer_id" :class="{ 'click-row': c.customer_id > 0 }" :title="c.customer_id > 0 ? 'Open the statement of account' : ''" @click="openStatement(c)">
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
                <span class="mix-val">₹ {{ fmtShort(r.value) }}<template v-if="r.sqm !== null"> · {{ fmtNum(r.sqm) }} SQM</template></span>
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

    <!-- ═══ GST REGISTER, PAYMENTS, STATEMENTS (FR-010) ══════════════════ -->
    <template v-else-if="tab === 'gst'"><report-gst v-if="!badPeriod" :from="from" :to="to" /></template>
    <template v-else-if="tab === 'payments'"><report-payments v-if="!badPeriod" :from="from" :to="to" /></template>
    <template v-else-if="tab === 'statements'"><report-statements v-if="!badPeriod" :from="from" :to="to" :customer="stmtCustomer" /></template>

    <!-- ═══ MIS REPORT TAB ══════════════════════════════════════════════ -->
    <template v-else-if="tab === 'mis'">
      <div v-if="misLoading" class="loading-hint">Computing MIS…</div>
      <template v-else-if="mis">
        <p class="mis-note">The invoices raised in this period: what they come to, what has been paid against them so far, and what is still unpaid. For the money <em>received</em> in the period, see Cash Collected on the Dashboard tab.</p>

        <!-- Revenue summary cards -->
        <div class="headline-grid">
          <div class="hl-card primary click" role="button" tabindex="0" title="See the invoices" @click="tab = 'gst'" @keyup.enter="tab = 'gst'">
            <div class="hl-val">₹ {{ fmtShort(mis.revenue.invoiced) }}</div>
            <div class="hl-lbl">Total Invoiced</div>
          </div>
          <div class="hl-card green">
            <div class="hl-val">₹ {{ fmtShort(mis.revenue.collected) }}</div>
            <div class="hl-lbl">Paid so far</div>
          </div>
          <div class="hl-card click" :class="mis.revenue.outstanding > 0 ? 'amber' : ''" role="button" tabindex="0" title="Open Receivables" @click="emit('navigate', 'receivables')" @keyup.enter="emit('navigate', 'receivables')">
            <div class="hl-val" :class="mis.revenue.outstanding > 0 ? 'red' : ''">₹ {{ fmtShort(mis.revenue.outstanding) }}</div>
            <div class="hl-lbl">Still unpaid</div>
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
              <div class="kv"><span class="kv-l">SQM planned (completed runs)</span><span class="kv-v bold blue">{{ fmtNum(mis.production.sqm_produced) }} SQM</span></div>
              <p class="mis-note small">The planned area of the runs completed in the period. Actual output is not recorded yet.</p>
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
          <h3>📅 Still unpaid, by age (these invoices, as of today)</h3>
          <div class="aging-grid">
            <div class="aging-cell ok">
              <div class="aging-val">₹ {{ fmtShort(mis.aging.not_due) }}</div>
              <div class="aging-lbl">Not yet due</div>
            </div>
            <div class="aging-cell" :class="mis.aging['0_30'] > 0 ? 'warn' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['0_30']) }}</div>
              <div class="aging-lbl">1–30 days overdue</div>
            </div>
            <div class="aging-cell" :class="mis.aging['31_60'] > 0 ? 'warn' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['31_60']) }}</div>
              <div class="aging-lbl">31–60 days</div>
            </div>
            <div class="aging-cell" :class="mis.aging['61_90'] > 0 ? 'danger' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['61_90']) }}</div>
              <div class="aging-lbl">61–90 days</div>
            </div>
            <div class="aging-cell" :class="mis.aging['over_90'] > 0 ? 'danger' : 'ok'">
              <div class="aging-val">₹ {{ fmtShort(mis.aging['over_90']) }}</div>
              <div class="aging-lbl">Over 90 days</div>
            </div>
          </div>
        </div>

        <!-- Monthly trend -->
        <div class="card">
          <h3>📈 Month-wise Invoiced vs Collected</h3>
          <table class="rep-table">
            <thead><tr><th>Month</th><th class="text-right">Invoiced</th><th class="text-right">Paid so far</th><th class="text-right">Still unpaid</th><th class="text-right">Invoices</th></tr></thead>
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
            <span class="hint-sm">Revenue Leak counts only orders that are fully delivered but not (fully) invoiced: money left on the table.</span>
          </div>
          <p class="mis-note small recon-note">{{ recon.summary.not_delivered }} order(s) not yet delivered (work in progress, not counted) · {{ recon.summary.awaiting_invoice }} delivered in part with nothing invoiced yet.</p>
          <table class="rep-table">
            <thead><tr><th>Order</th><th>Customer</th><th class="text-right">Ordered</th><th class="text-right">Invoiced</th><th class="text-right">Gap</th><th>Flag</th></tr></thead>
            <tbody>
              <tr v-for="r in recon.rows" :key="r.order_id" :class="{ leak: r.flag === 'not_invoiced' || r.flag === 'under_invoiced' }">
                <td class="bold">{{ r.order_no }}</td>
                <td>{{ r.customer }}</td>
                <td class="text-right">₹ {{ fmtNum(r.ordered) }}</td>
                <td class="text-right">₹ {{ fmtNum(r.invoiced) }}</td>
                <td class="text-right" :class="{ red: r.flag === 'not_invoiced' || r.flag === 'under_invoiced' }">₹ {{ fmtNum(r.invoice_gap) }}</td>
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
            <div>Ensure your Tally company has the ledgers named below (set them to match your Tally), and a debtor ledger per customer.</div>
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
      <tally-ledger-settings />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import reportService from '../services/reportService.js'
import { downloadFile } from '../services/transfers.js'
import { todayLocal, monthStartLocal, periodPreset, previousPeriod, PERIOD_PRESETS } from '../services/dates.js'
import companyService from '../services/companyService.js'
import ReportGst from './ReportGst.vue'
import ReportPayments from './ReportPayments.vue'
import ReportStatements from './ReportStatements.vue'
import TallyLedgerSettings from './TallyLedgerSettings.vue'

const emit = defineEmits(['navigate'])

const from = ref(monthStartLocal())     // local dates: the UTC date is yesterday until 05:30 IST and the 1st of the month came out as the 31st (PB-075)
const to   = ref(todayLocal())

const tab   = ref('dashboard')
const preset = ref('month')                 // which ready-made period the dates are (or 'custom')
const fyMonth = ref(4)                      // the company's financial-year start month (Company settings; 4 = April)
const stmtCustomer = ref(null)              // a customer opened from Top Customers
const prevPl = ref(null)
const prevCf = ref(null)
const badPeriod = computed(() => !!from.value && !!to.value && from.value > to.value)
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
    return
  }
  // The same length of time just before, for the "vs previous period" lines. A failure here only hides them.
  const before = previousPeriod(from.value, to.value)
  try {
    const [pp, pc] = await Promise.all([reportService.profitLoss({ from_date: before.from, to_date: before.to }), reportService.cashFlow({ from_date: before.from, to_date: before.to })])
    prevPl.value = pp?.data ?? null
    prevCf.value = pc?.data ?? null
  } catch { prevPl.value = null; prevCf.value = null }
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

/** The preset whose dates these are, else 'custom'. */
function presetFor(f, t) {
  const hit = PERIOD_PRESETS.find((p) => { const r = periodPreset(p.key, new Date(), fyMonth.value); return r && r.from === f && r.to === t })
  return hit ? hit.key : 'custom'
}
function onPreset() {
  const r = periodPreset(preset.value, new Date(), fyMonth.value)
  if (!r) return                            // "Custom dates": keep what is typed
  from.value = r.from; to.value = r.to
  onPeriodChange()
}
function onDates() { preset.value = presetFor(from.value, to.value); onPeriodChange() }

/** "▲ 12.5% vs previous period" for a figure against the same length of time before. Null when there is nothing to compare. */
function compare(now, before) {
  if (before === undefined || before === null) return null
  const n = Number(now || 0); const b = Number(before || 0)
  const title = `Previous period: ₹ ${fmtNum(b)}`
  if (b === 0) return n > 0 ? { text: 'new this period', cls: 'up', title } : null
  const pct = ((n - b) / b) * 100
  if (Math.abs(pct) < 0.05) return { text: 'same as previous period', cls: 'flat', title }
  return { text: `${pct > 0 ? '▲' : '▼'} ${Math.abs(pct).toFixed(1)}% vs previous period`, cls: pct > 0 ? 'up' : 'down', title }
}
const cmp = computed(() => ({
  sales: pl.value && prevPl.value ? compare(pl.value.revenue.sales, prevPl.value.revenue.sales) : null,
  tax:   pl.value && prevPl.value ? compare(pl.value.revenue.tax_collected, prevPl.value.revenue.tax_collected) : null,
  gross: pl.value && prevPl.value ? compare(pl.value.revenue.gross_revenue, prevPl.value.revenue.gross_revenue) : null,
  avg:   pl.value && prevPl.value ? compare(pl.value.average_invoice_value, prevPl.value.average_invoice_value) : null,
  cash:  cf.value && prevCf.value ? compare(cf.value.operating_activities.cash_collected, prevCf.value.operating_activities.cash_collected) : null,
}))

function openStatement(c) {
  if (!(c.customer_id > 0)) return
  stmtCustomer.value = { id: c.customer_id, name: c.customer_name }
  tab.value = 'statements'
}

function onPeriodChange() {
  if (badPeriod.value) return
  mis.value = null; recon.value = null
  if (tab.value === 'dashboard') loadDashboard()
  else if (tab.value === 'mis') loadMis()
  else if (tab.value === 'recon') loadRecon()
}

function flagLabel(f) {
  return { ok: 'OK', not_invoiced: 'Not Invoiced', under_invoiced: 'Under-invoiced', over_invoiced: 'Over-invoiced', not_delivered: 'Not delivered yet', part_delivered: 'Part delivered', awaiting_invoice: 'Awaiting invoice' }[f] || f
}

async function doExport(type) {
  exporting.value = true; exportErr.value = null
  try {
    await downloadFile(`/api/export/${type}`, `${type}_${to.value}.csv`, {
      label: `Export ${type}`, kind: `export-${type}`, type: 'text/csv', estimateMs: 3000,
      params: { from: from.value, to: to.value },
    })
  } catch (e) {
    exportErr.value = e?.response?.data?.message ?? `Failed to export ${type}.`
  } finally { exporting.value = false }
}

async function downloadTally(type) {
  tallyLoading.value = type; tallyError.value = null
  try {
    const ext = type === 'xml' ? 'xml' : 'csv'
    await downloadFile(`/api/reports/tally/${type}`, `tally_sales_${from.value}_${to.value}.${ext}`, {
      label: `Tally ${type.toUpperCase()} export`, kind: `tally-${type}`, estimateMs: 3000,
      type: type === 'xml' ? 'application/xml' : 'text/csv',
      params: { from: from.value, to: to.value },
    })
  } catch (e) {
    tallyError.value = e?.response?.data?.message ?? `Failed to download ${type.toUpperCase()}.`
  } finally { tallyLoading.value = null }
}

onMounted(async () => {
  loadDashboard()
  try {
    const c = (await companyService.get())?.data ?? {}
    if (c.financial_year_start) { fyMonth.value = Number(c.financial_year_start); preset.value = presetFor(from.value, to.value) }
  } catch { /* the Indian default (April) stays */ }
})
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
.hl-card.click { cursor: pointer; }
.hl-card.click:hover { border-color: var(--primary); box-shadow: 0 1px 6px rgba(0,0,0,.08); }
.hl-delta { font-size: 11px; font-weight: 600; margin-top: 5px; color: #888; }
.hl-delta.up { color: #2e7d32; } .hl-delta.down { color: #c62828; }
.hl-card.primary .hl-delta { color: rgba(255,255,255,.9); }
.click-row { cursor: pointer; }
.click-row:hover td { background: var(--primary-tint); }
.period-picker select { padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff; }
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
.flag-chip.not_delivered { background: #f0f2f5; color: #667085; }
.flag-chip.part_delivered { background: #eef1fe; color: #2B50E0; }
.flag-chip.awaiting_invoice { background: #fff8e1; color: #b5740a; }
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

.aging-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.mis-note { font-size: 12.5px; color: #667085; margin: 0 0 14px; line-height: 1.5; }
.mis-note.small { font-size: 11.5px; margin: 8px 0 0; }
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
