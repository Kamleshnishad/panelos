<template>
  <div class="rk-gst">
    <p v-if="error" class="rk-error" role="alert">{{ error }}</p>
    <div v-if="loading" class="rk-loading">Preparing the register…</div>
    <template v-else-if="data">
      <p v-for="w in data.warnings" :key="w" class="rk-warn" role="alert">⚠ {{ w }}</p>

      <div class="rk-cards" aria-label="GST register totals">
        <div class="rk-card"><div class="v">{{ data.totals.invoices }}</div><div class="l">Invoices</div></div>
        <div class="rk-card"><div class="v">{{ data.totals.b2b_invoices }} / {{ data.totals.b2c_invoices }}</div><div class="l">B2B / B2C</div></div>
        <div class="rk-card"><div class="v">₹ {{ fmt(data.totals.taxable_value) }}</div><div class="l">Taxable value</div></div>
        <div class="rk-card"><div class="v">₹ {{ fmt(data.totals.cgst) }}</div><div class="l">CGST</div></div>
        <div class="rk-card"><div class="v">₹ {{ fmt(data.totals.sgst) }}</div><div class="l">SGST</div></div>
        <div class="rk-card"><div class="v">₹ {{ fmt(data.totals.igst) }}</div><div class="l">IGST</div></div>
        <div class="rk-card green"><div class="v">₹ {{ fmt(data.totals.total_tax) }}</div><div class="l">Total GST</div></div>
      </div>

      <div class="rk-box">
        <div class="rk-head">
          <h3>Sales register (one line per invoice)</h3>
          <div class="rk-actions">
            <button class="rk-btn primary" type="button" :disabled="busy" @click="download('invoices')">⬇ Invoices (Excel / CSV)</button>
            <button class="rk-btn" type="button" :disabled="busy" @click="download('hsn')">⬇ HSN summary (CSV)</button>
          </div>
        </div>
        <p class="rk-note">Invoices dated in this period that are not draft or cancelled, oldest first, as the sales return (GSTR-1) wants them. A customer with a valid GSTIN is B2B. Amounts are the ones on the invoices.</p>
        <div class="rk-table-wrap">
          <table class="rk-table" aria-label="Invoices">
            <thead><tr><th>Invoice</th><th>Date</th><th>Type</th><th>Customer</th><th>GSTIN</th><th>Place of supply</th><th class="r">Value</th><th class="r">Rate</th><th class="r">Taxable</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">IGST</th></tr></thead>
            <tbody>
              <tr v-for="r in data.invoices" :key="r.invoice_id">
                <td><b>{{ r.invoice_no }}</b></td><td>{{ day(r.invoice_date) }}</td><td :class="r.type === 'B2B' ? 'b2b' : 'b2c'">{{ r.type }}</td>
                <td>{{ r.customer_name }}</td><td>{{ r.customer_gstin || '—' }}</td><td>{{ r.place_of_supply || '—' }}</td>
                <td class="r">{{ fmt(r.invoice_value) }}</td><td class="r">{{ r.tax_rate }}%</td><td class="r">{{ fmt(r.taxable_value) }}</td>
                <td class="r">{{ fmt(r.cgst) }}</td><td class="r">{{ fmt(r.sgst) }}</td><td class="r">{{ fmt(r.igst) }}</td>
              </tr>
              <tr v-if="!data.invoices.length"><td colspan="12" class="rk-empty">No invoices in this period.</td></tr>
              <tr v-else class="total"><td colspan="6">Total</td><td class="r">{{ fmt(data.totals.invoice_value) }}</td><td></td><td class="r">{{ fmt(data.totals.taxable_value) }}</td><td class="r">{{ fmt(data.totals.cgst) }}</td><td class="r">{{ fmt(data.totals.sgst) }}</td><td class="r">{{ fmt(data.totals.igst) }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rk-box">
        <div class="rk-head"><h3>By GST rate</h3></div>
        <div class="rk-table-wrap">
          <table class="rk-table" aria-label="By rate">
            <thead><tr><th>Rate</th><th class="r">Invoices</th><th class="r">Taxable</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">IGST</th></tr></thead>
            <tbody>
              <tr v-for="r in data.by_rate" :key="r.tax_rate"><td><b>{{ r.tax_rate }}%</b></td><td class="r">{{ r.invoices }}</td><td class="r">{{ fmt(r.taxable_value) }}</td><td class="r">{{ fmt(r.cgst) }}</td><td class="r">{{ fmt(r.sgst) }}</td><td class="r">{{ fmt(r.igst) }}</td></tr>
              <tr v-if="!data.by_rate.length"><td colspan="6" class="rk-empty">Nothing to show.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rk-box">
        <div class="rk-head"><h3>HSN summary</h3></div>
        <div class="rk-table-wrap">
          <table class="rk-table" aria-label="HSN summary">
            <thead><tr><th>HSN</th><th>Description</th><th>Unit</th><th class="r">Quantity</th><th class="r">Rate</th><th class="r">Taxable</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">IGST</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in data.hsn" :key="i"><td><b>{{ r.hsn }}</b></td><td>{{ r.description }}</td><td>{{ r.uqc }}</td><td class="r">{{ fmt(r.quantity) }}</td><td class="r">{{ r.tax_rate }}%</td><td class="r">{{ fmt(r.taxable_value) }}</td><td class="r">{{ fmt(r.cgst) }}</td><td class="r">{{ fmt(r.sgst) }}</td><td class="r">{{ fmt(r.igst) }}</td></tr>
              <tr v-if="!data.hsn.length"><td colspan="9" class="rk-empty">Nothing to show.</td></tr>
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
let seq = 0                                              // an older answer must not overwrite a newer one

const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })

async function load() {
  const mine = ++seq
  loading.value = true; error.value = ''
  try {
    const res = await reportService.gstRegister({ from: props.from, to: props.to })
    if (mine === seq) data.value = res?.data ?? null
  } catch (e) {
    if (mine === seq) { data.value = null; error.value = e?.response?.data?.message ?? 'Could not prepare the GST register.' }
  } finally { if (mine === seq) loading.value = false }
}

async function download(part) {
  busy.value = true; error.value = ''
  try {
    await downloadFile('/api/reports/gst-register/csv', `gst_register_${part}_${props.from}_${props.to}.csv`, {
      label: part === 'hsn' ? 'GST HSN summary' : 'GST sales register', kind: `gst-${part}`, type: 'text/csv', estimateMs: 3000, params: { from: props.from, to: props.to, part },
    })
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Could not download the file.'
  } finally { busy.value = false }
}

watch(() => [props.from, props.to], load)
onMounted(load)
</script>
