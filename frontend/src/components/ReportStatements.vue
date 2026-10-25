<template>
  <div class="rk-stmt">
    <div class="rk-box">
      <div class="rk-head"><h3>Customer statement</h3></div>
      <p class="rk-note">Pick a customer to see what was billed to them and what they paid in this period, with a running balance, and print it or send it as a PDF.</p>
      <div class="rk-field">
        <label for="st-search">Customer</label>
        <input id="st-search" v-model="search" type="search" placeholder="Start typing a name, phone or e-mail…" autocomplete="off" @input="onSearch" />
        <ul v-if="matches.length" class="st-matches" role="listbox" aria-label="Customers found">
          <li v-for="c in matches" :key="c.id" role="option" tabindex="0" @click="choose(c)" @keyup.enter="choose(c)">{{ c.name }}<small v-if="c.phone"> · {{ c.phone }}</small></li>
        </ul>
        <small v-if="searched && !matches.length && !picked">No customer matches that.</small>
      </div>
    </div>

    <p v-if="error" class="rk-error" role="alert">{{ error }}</p>
    <div v-if="loading" class="rk-loading">Preparing the statement…</div>

    <template v-else-if="data">
      <div class="rk-cards" aria-label="Statement totals">
        <div class="rk-card"><div class="v">₹ {{ fmt(data.opening_balance) }}</div><div class="l">Opening balance</div></div>
        <div class="rk-card"><div class="v">₹ {{ fmt(data.total_debit) }}</div><div class="l">Billed</div></div>
        <div class="rk-card green"><div class="v">₹ {{ fmt(data.total_credit) }}</div><div class="l">Received</div></div>
        <div class="rk-card" :class="{ red: data.closing_balance > 0 }"><div class="v">₹ {{ fmt(data.closing_balance) }}</div><div class="l">Closing balance</div></div>
      </div>

      <div class="rk-box">
        <div class="rk-head">
          <h3>{{ data.customer.name }}<small v-if="data.customer.gstin"> · GSTIN {{ data.customer.gstin }}</small></h3>
          <div class="rk-actions"><button class="rk-btn primary" type="button" :disabled="busy" @click="pdf">⬇ Statement (PDF)</button></div>
        </div>
        <div class="rk-table-wrap">
          <table class="rk-table" aria-label="Statement lines">
            <thead><tr><th>Date</th><th>Details</th><th>Reference</th><th class="r">Billed</th><th class="r">Received</th><th class="r">Balance</th></tr></thead>
            <tbody>
              <tr class="total"><td colspan="5">Opening balance</td><td class="r">{{ fmt(data.opening_balance) }}</td></tr>
              <tr v-for="(l, i) in data.lines" :key="i">
                <td>{{ day(l.date) }}</td><td>{{ l.description }}</td><td>{{ l.reference || '—' }}</td>
                <td class="r">{{ l.debit > 0 ? fmt(l.debit) : '' }}</td><td class="r">{{ l.credit > 0 ? fmt(l.credit) : '' }}</td><td class="r">{{ fmt(l.balance) }}</td>
              </tr>
              <tr v-if="!data.lines.length"><td colspan="6" class="rk-empty">No invoices or payments in this period.</td></tr>
              <tr class="total"><td colspan="5">Closing balance</td><td class="r">{{ fmt(data.closing_balance) }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="data.outstanding.length" class="rk-box">
        <div class="rk-head"><h3>Still unpaid on {{ day(data.period.to) }}</h3></div>
        <div class="rk-table-wrap">
          <table class="rk-table" aria-label="Unpaid invoices">
            <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th class="r">Days overdue</th></tr></thead>
            <tbody>
              <tr v-for="o in data.outstanding" :key="o.invoice_no">
                <td><b>{{ o.invoice_no }}</b></td><td>{{ day(o.invoice_date) }}</td><td>{{ o.due_date ? day(o.due_date) : '—' }}</td>
                <td class="r">{{ fmt(o.total) }}</td><td class="r">{{ fmt(o.paid) }}</td><td class="r">{{ fmt(o.balance) }}</td>
                <td class="r" :class="{ late: o.days_overdue > 0 }">{{ o.days_overdue > 0 ? o.days_overdue : 'not due' }}</td>
              </tr>
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

// `customer` ({ id, name }) opens that customer straight away: a click on a name in Top Customers.
const props = defineProps({ from: { type: String, required: true }, to: { type: String, required: true }, customer: { type: Object, default: null } })

const search = ref('')
const matches = ref([])
const searched = ref(false)
const picked = ref(null)
const data = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
let seq = 0
let timer = null

const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })

function onSearch() {
  picked.value = null
  clearTimeout(timer)
  const q = search.value.trim()
  if (q.length < 2) { matches.value = []; searched.value = false; return }
  timer = setTimeout(async () => {
    try {
      const res = await reportService.customers(q)
      matches.value = (res?.data?.data ?? res?.data ?? []).slice(0, 15)
    } catch (e) {
      matches.value = []
      error.value = e?.response?.data?.message ?? 'Could not search customers.'
    }
    searched.value = true
  }, 250)
}

function choose(c) {
  picked.value = c
  search.value = c.name
  matches.value = []
  load()
}

async function load() {
  if (!picked.value) return
  const mine = ++seq
  loading.value = true; error.value = ''
  try {
    const res = await reportService.customerStatement(picked.value.id, { from: props.from, to: props.to })
    if (mine === seq) data.value = res?.data ?? null
  } catch (e) {
    if (mine === seq) { data.value = null; error.value = e?.response?.data?.message ?? 'Could not prepare the statement.' }
  } finally { if (mine === seq) loading.value = false }
}

async function pdf() {
  if (!picked.value) return
  busy.value = true; error.value = ''
  try {
    await downloadFile(`/api/reports/customer-statement/${picked.value.id}/pdf`, `statement_${picked.value.name}_${props.to}.pdf`, {
      label: 'Customer statement', kind: 'statement-pdf', type: 'application/pdf', estimateMs: 3000, params: { from: props.from, to: props.to },
    })
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Could not download the statement.'
  } finally { busy.value = false }
}

function openGiven() { if (props.customer?.id) { picked.value = props.customer; search.value = props.customer.name; load() } }

watch(() => [props.from, props.to], load)
watch(() => props.customer?.id, openGiven)
onMounted(openGiven)
</script>

<style scoped>
.st-matches { list-style: none; margin: 4px 0 0; padding: 0; max-width: 420px; border: 1px solid #ddd; border-radius: 8px; background: #fff; max-height: 260px; overflow-y: auto; }
.st-matches li { padding: 8px 12px; font-size: 13px; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.st-matches li:last-child { border-bottom: 0; }
.st-matches li:hover, .st-matches li:focus { background: var(--primary-tint); outline: none; }
.st-matches small { color: #888; }
.late { color: #c62828; font-weight: 700; }
</style>
