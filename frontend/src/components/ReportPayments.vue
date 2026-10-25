<template>
  <div class="rk-pay">
    <p v-if="error" class="rk-error" role="alert">{{ error }}</p>
    <div v-if="loading" class="rk-loading">Loading payments…</div>
    <template v-else-if="data">
      <div class="rk-cards" aria-label="Payment totals">
        <div class="rk-card green"><div class="v">₹ {{ fmt(data.totals.received) }}</div><div class="l">Money received</div></div>
        <div class="rk-card"><div class="v">{{ data.totals.payments }}</div><div class="l">Payments</div></div>
        <div class="rk-card" :class="{ red: data.totals.written_off > 0 }"><div class="v">₹ {{ fmt(data.totals.written_off) }}</div><div class="l">Written off</div></div>
      </div>

      <div class="rk-box">
        <div class="rk-head">
          <h3>Payments received</h3>
          <div class="rk-actions"><button class="rk-btn primary" type="button" :disabled="busy" @click="download">⬇ Download (Excel / CSV)</button></div>
        </div>
        <p class="rk-note">Every payment that came in on a day in this period (first and last day included), newest first. A write-off is listed but is not money received.</p>
        <div v-if="data.by_method.length" class="rk-chips" aria-label="By method">
          <span v-for="m in data.by_method" :key="m.method" class="rk-chip">{{ label(m.method) }}: ₹ {{ fmt(m.amount) }} ({{ m.payments }})</span>
        </div>
        <div class="rk-table-wrap">
          <table class="rk-table" aria-label="Payments">
            <thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th>Reference</th><th class="r">Amount</th><th>Entered by</th></tr></thead>
            <tbody>
              <tr v-for="p in data.payments" :key="p.payment_id" :class="{ off: p.is_write_off }">
                <td>{{ day(p.date) }}</td><td><b>{{ p.invoice_no || '—' }}</b></td><td>{{ p.customer_name }}</td>
                <td>{{ p.is_write_off ? 'Written off' : label(p.method) }}</td><td>{{ p.reference || '—' }}</td>
                <td class="r">{{ fmt(p.amount) }}</td><td>{{ p.entered_by || '—' }}</td>
              </tr>
              <tr v-if="!data.payments.length"><td colspan="7" class="rk-empty">No payments in this period.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import reportService from '../services/reportService.js'
import { downloadFile } from '../services/transfers.js'
import './report-kit.css'

const props = defineProps({ from: { type: String, required: true }, to: { type: String, required: true } })

const data = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
let seq = 0

const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
const label = (m) => String(m || '').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

async function load() {
  const mine = ++seq
  loading.value = true; error.value = ''
  try {
    const res = await reportService.paymentsRegister({ from: props.from, to: props.to })
    if (mine === seq) data.value = res?.data ?? null
  } catch (e) {
    if (mine === seq) { data.value = null; error.value = e?.response?.data?.message ?? 'Could not load the payments.' }
  } finally { if (mine === seq) loading.value = false }
}

async function download() {
  busy.value = true; error.value = ''
  try {
    await downloadFile('/api/reports/payments/csv', `payments_${props.from}_${props.to}.csv`, { label: 'Payments received', kind: 'payments-csv', type: 'text/csv', estimateMs: 2000, params: { from: props.from, to: props.to } })
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Could not download the file.'
  } finally { busy.value = false }
}

watch(() => [props.from, props.to], load)
onMounted(load)
</script>

<style scoped>
tr.off td { color: #999; font-style: italic; }
</style>
