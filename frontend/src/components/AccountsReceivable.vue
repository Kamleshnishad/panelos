<template>
  <div class="ar-wrap">
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Accounts Receivable</h2>
        <span class="total-badge" v-if="summary">{{ summary.overdue_count }} overdue</span>
      </div>
      <div class="toolbar-right">
        <div class="date-group">
          <label>As of</label>
          <input v-model="asOf" type="date" @change="load" />
        </div>
        <button class="btn btn-ghost btn-sm" @click="load">↻ Refresh</button>
      </div>
    </div>

    <div v-if="actionMsg" class="success-banner">{{ actionMsg }}</div>
    <div v-if="error" class="error-banner">{{ error }}</div>

    <!-- Aging summary cards -->
    <div class="aging-bar" v-if="summary">
      <div class="aging-card total">
        <div class="aging-val">₹ {{ fmtNum(summary.total_ar) }}</div>
        <div class="aging-lbl">Total Receivable</div>
      </div>
      <div class="aging-card" :class="{ active: bucket === 'current' }" @click="setBucket('current')">
        <div class="aging-val">₹ {{ fmtNum(summary.current) }}</div>
        <div class="aging-lbl">Current (not due)</div>
      </div>
      <div class="aging-card amber" :class="{ active: bucket === '30' }" @click="setBucket('30')">
        <div class="aging-val">₹ {{ fmtNum(summary['30_days']) }}</div>
        <div class="aging-lbl">1–30 days</div>
      </div>
      <div class="aging-card orange" :class="{ active: bucket === '60' }" @click="setBucket('60')">
        <div class="aging-val">₹ {{ fmtNum(summary['60_days']) }}</div>
        <div class="aging-lbl">31–60 days</div>
      </div>
      <div class="aging-card red" :class="{ active: bucket === '90' }" @click="setBucket('90')">
        <div class="aging-val">₹ {{ fmtNum(summary['90_days']) }}</div>
        <div class="aging-lbl">61–90 days</div>
      </div>
      <div class="aging-card darkred" :class="{ active: bucket === '90plus' }" @click="setBucket('90plus')">
        <div class="aging-val">₹ {{ fmtNum(summary.over_90_days) }}</div>
        <div class="aging-lbl">90+ days</div>
      </div>
    </div>

    <!-- Filter hint -->
    <div class="filter-hint" v-if="bucket">
      Showing <strong>{{ bucketLabel }}</strong> bucket
      <button class="clear-bucket" @click="setBucket(null)">✕ clear</button>
    </div>

    <!-- Invoice table -->
    <div class="table-wrap">
      <div v-if="loading" class="loading-row">Loading receivables…</div>
      <table v-else>
        <thead>
          <tr>
            <th>Invoice No</th>
            <th>Customer</th>
            <th>Invoice Date</th>
            <th>Due Date</th>
            <th class="text-right">Total</th>
            <th class="text-right">Paid</th>
            <th class="text-right">Balance</th>
            <th class="text-center">Overdue</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="filteredRows.length === 0">
            <td colspan="9" class="empty-row">No outstanding receivables{{ bucket ? ' in this bucket' : '' }}.</td>
          </tr>
          <tr v-for="row in filteredRows" :key="row.invoice_id" :class="agingRowClass(row)">
            <td class="mono bold clickable" @click="$emit('view-invoice', row.invoice_id)">{{ row.invoice_no }}</td>
            <td>{{ row.customer_name }}</td>
            <td>{{ fmtDate(row.invoice_date) }}</td>
            <td :class="{ overdue: row.is_overdue }">{{ fmtDate(row.due_date) }}</td>
            <td class="text-right">{{ fmtNum(row.total_amount) }}</td>
            <td class="text-right muted">{{ fmtNum(row.paid_amount) }}</td>
            <td class="text-right bold red-text">{{ fmtNum(row.remaining_due) }}</td>
            <td class="text-center">
              <span v-if="row.days_overdue > 0" :class="['age-pill', agingClass(row.days_overdue)]">{{ row.days_overdue }}d</span>
              <span v-else class="muted">—</span>
            </td>
            <td class="text-center">
              <button
                class="btn-remind"
                :disabled="remindingId === row.invoice_id"
                @click="sendReminder(row)"
                title="Send payment reminder"
              >{{ remindingId === row.invoice_id ? '…' : 'Remind' }}</button>
            </td>
          </tr>
        </tbody>
        <tfoot v-if="filteredRows.length > 0">
          <tr class="foot-row">
            <td colspan="6" class="text-right bold">Total Balance Shown</td>
            <td class="text-right bold red-text">₹ {{ fmtNum(shownTotal) }}</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import invoiceService from '../services/invoiceService.js'

defineEmits(['view-invoice'])

const rows       = ref([])
const summary    = ref(null)
const loading    = ref(false)
const error      = ref(null)
const actionMsg  = ref(null)
const asOf       = ref(new Date().toISOString().slice(0, 10))
const bucket     = ref(null)
const remindingId = ref(null)

// Only show invoices with an outstanding balance
const outstandingRows = computed(() => rows.value.filter(r => Number(r.remaining_due) > 0))

const filteredRows = computed(() => {
  if (!bucket.value) return outstandingRows.value
  return outstandingRows.value.filter(r => {
    const d = r.days_overdue
    if (bucket.value === 'current') return d === 0
    if (bucket.value === '30')      return d > 0 && d <= 30
    if (bucket.value === '60')      return d > 30 && d <= 60
    if (bucket.value === '90')      return d > 60 && d <= 90
    if (bucket.value === '90plus')  return d > 90
    return true
  })
})

const shownTotal = computed(() => filteredRows.value.reduce((s, r) => s + Number(r.remaining_due || 0), 0))

const bucketLabel = computed(() => ({
  current: 'Current (not due)', '30': '1–30 days', '60': '31–60 days', '90': '61–90 days', '90plus': '90+ days',
}[bucket.value] ?? ''))

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await invoiceService.accountsReceivable({ as_of: asOf.value })
    const data = res?.data ?? {}
    summary.value = data.summary ?? null
    rows.value    = data.details ?? []
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load receivables.'
  } finally {
    loading.value = false
  }
}

function setBucket(b) { bucket.value = bucket.value === b ? null : b }

async function sendReminder(row) {
  remindingId.value = row.invoice_id
  actionMsg.value   = null
  error.value       = null
  try {
    await invoiceService.sendReminder(row.invoice_id)
    actionMsg.value = `Reminder issued for ${row.invoice_no} (₹ ${fmtNum(row.remaining_due)} due).`
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to send reminder.'
  } finally {
    remindingId.value = null
  }
}

function agingClass(d) {
  if (d <= 30) return 'amber'
  if (d <= 60) return 'orange'
  if (d <= 90) return 'red'
  return 'darkred'
}
function agingRowClass(row) {
  if (!row.is_overdue) return ''
  return 'overdue-row-' + agingClass(row.days_overdue)
}

function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
function fmtNum(n)  { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

onMounted(load)
</script>

<style scoped>
.ar-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.total-badge { background: #ffebee; color: #c62828; border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }
.toolbar-right { display: flex; align-items: flex-end; gap: 10px; }
.date-group { display: flex; flex-direction: column; gap: 3px; }
.date-group label { font-size: 10px; font-weight: 700; color: #999; text-transform: uppercase; }
.date-group input { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.error-banner   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }

/* Aging cards */
.aging-bar { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 16px; }
.aging-card { background: white; border: 2px solid #e0e0e0; border-radius: 10px; padding: 14px 12px; text-align: center; cursor: pointer; transition: all 0.15s; }
.aging-card:not(.total):hover { transform: translateY(-2px); box-shadow: 0 3px 10px rgba(0,0,0,0.08); }
.aging-card.total { cursor: default; background: var(--primary); border-color: var(--primary); }
.aging-card.total .aging-val { color: white; }
.aging-card.total .aging-lbl { color: var(--primary-bd); }
.aging-card.active { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.aging-card.amber.active   { border-color: #f57f17; box-shadow: 0 0 0 3px #fff8e1; }
.aging-card.orange.active  { border-color: #e65100; box-shadow: 0 0 0 3px #fff3e0; }
.aging-card.red.active     { border-color: #c62828; box-shadow: 0 0 0 3px #ffebee; }
.aging-card.darkred.active { border-color: #b71c1c; box-shadow: 0 0 0 3px #ffebee; }
.aging-val { font-size: 17px; font-weight: 800; color: var(--primary); }
.aging-card.amber .aging-val   { color: #f57f17; }
.aging-card.orange .aging-val  { color: #e65100; }
.aging-card.red .aging-val     { color: #c62828; }
.aging-card.darkred .aging-val { color: #b71c1c; }
.aging-lbl { font-size: 10px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 4px; }

.filter-hint { font-size: 13px; color: #666; margin-bottom: 10px; }
.clear-bucket { background: none; border: none; color: var(--primary); font-size: 12px; cursor: pointer; margin-left: 8px; }

.table-wrap { background: white; border: 1px solid #e0e0e0; border-radius: 10px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.mono { font-family: monospace; }
.bold { font-weight: 700; }
.muted { color: #999; }
.red-text { color: #c62828; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.clickable { cursor: pointer; color: var(--primary); }
.clickable:hover { text-decoration: underline; }
.overdue { color: #c62828; }
.empty-row { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }

.overdue-row-amber  td { background: #fffdf5; }
.overdue-row-orange td { background: #fff8f0; }
.overdue-row-red    td { background: #fff5f5; }
.overdue-row-darkred td { background: #fff0f0; }

.age-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.age-pill.amber   { background: #fff8e1; color: #f57f17; }
.age-pill.orange  { background: #fff3e0; color: #e65100; }
.age-pill.red     { background: #ffebee; color: #c62828; }
.age-pill.darkred { background: #ffcdd2; color: #b71c1c; }

.btn-remind { padding: 4px 12px; border: 1px solid var(--primary); background: var(--primary-tint); color: var(--primary); border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; }
.btn-remind:hover:not(:disabled) { background: var(--primary); color: white; }
.btn-remind:disabled { opacity: 0.5; cursor: not-allowed; }

.foot-row td { background: var(--surface-2); border-top: 2px solid var(--primary); padding: 10px 12px; }
</style>
