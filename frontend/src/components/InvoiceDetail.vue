<template>
  <div class="id-wrap" v-if="invoice">
    <!-- Toolbar -->
    <div class="id-toolbar">
      <button class="btn btn-ghost" @click="$emit('back')">← Back to Invoices</button>
      <div class="id-title">
        <span class="inv-no">{{ invoice.invoice_no }}</span>
        <span :class="['status-badge', invoice.status]">{{ invoice.status }}</span>
        <span v-if="isOverdue" class="overdue-tag">Overdue</span>
      </div>
      <div class="action-bar">
        <button class="btn btn-pdf" :disabled="pdfLoading" @click="openPdf">{{ pdfLoading ? 'Opening…' : '📄 PDF' }}</button>
        <button class="btn btn-ghost" :disabled="pdfLoading" @click="downloadPdf" title="Download" aria-label="Download invoice PDF">⬇</button>
        <button v-if="invoice.status === 'draft'" class="btn btn-send"   :disabled="actionLoading" @click="action('send')">Send</button>
        <button v-if="['draft','sent'].includes(invoice.status)" class="btn btn-accept" :disabled="actionLoading" @click="action('accept')">Accept</button>
        <button v-if="canRecordPayment" class="btn btn-record" :disabled="actionLoading" @click="openPaymentModal">+ Record Payment</button>
        <button v-if="['sent','accepted'].includes(invoice.status)" class="btn btn-paid" :disabled="actionLoading" @click="action('mark-paid')">Mark Paid</button>
        <button class="btn btn-dup" :disabled="actionLoading" @click="action('duplicate')">Duplicate</button>
        <button v-if="['draft','sent'].includes(invoice.status)" class="btn btn-cancel" :disabled="actionLoading" @click="showCancel = true">Cancel</button>
      </div>
    </div>

    <div v-if="actionError"   class="error-banner">{{ actionError }}</div>
    <div v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</div>

    <!-- Info grid -->
    <div class="info-grid card">
      <div class="info-cell">
        <div class="info-label">Customer</div>
        <div class="info-value bold">{{ customer?.name ?? '—' }}</div>
        <div class="info-sub" v-if="customer?.gstin">GSTIN: {{ customer.gstin }}</div>
        <div class="info-sub">{{ customer?.city }}{{ customer?.state ? ', ' + customer.state : '' }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Source</div>
        <div class="info-value mono">{{ invoice.dispatch?.dispatch_no ?? invoice.order?.order_no ?? '—' }}</div>
        <div class="info-sub">{{ invoice.dispatch_id ? 'From Dispatch' : invoice.order_id ? 'From Order' : 'Manual' }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Dates</div>
        <div class="info-value">Invoice: {{ fmtDate(invoice.invoice_date) }}</div>
        <div class="info-sub" :class="{ overdue: isOverdue }">Due: {{ fmtDate(invoice.due_date) }}</div>
        <div class="info-sub" v-if="invoice.paid_date">Paid: {{ fmtDate(invoice.paid_date) }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Payment</div>
        <div class="info-value bold" :class="{ pos: remainingDue > 0 }">₹ {{ fmtNum(remainingDue) }} due</div>
        <div class="info-sub">of ₹ {{ fmtNum(invoice.total_amount) }} total</div>
      </div>
    </div>

    <!-- Editable (draft only) -->
    <div class="card edit-card" v-if="invoice.status === 'draft'">
      <div class="edit-row">
        <div class="form-group"><label>Due Date</label><input v-model="editForm.due_date" type="date" /></div>
        <div class="form-group flex-1"><label>Notes</label><input v-model="editForm.notes" placeholder="Invoice notes…" /></div>
        <div class="form-group flex-1"><label>Terms</label><input v-model="editForm.terms" placeholder="Payment terms…" /></div>
        <button class="btn btn-save" :disabled="saving" @click="saveEdits">{{ saving ? 'Saving…' : 'Save' }}</button>
      </div>
    </div>

    <!-- Items -->
    <div class="card">
      <h3>Invoice Items</h3>
      <table class="items-table">
        <thead>
          <tr>
            <th>Panel Type</th>
            <th class="text-right">Qty (SQM)</th>
            <th class="text-right">Rate (₹)</th>
            <th class="text-right">Amount (₹)</th>
            <th class="text-right">Tax %</th>
            <th class="text-right">Tax (₹)</th>
            <th class="text-right">Total (₹)</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in invoice.items" :key="item.id">
            <td class="bold">{{ item.panel_type?.name ?? 'Panel #' + item.panel_type_id }}</td>
            <td class="text-right">{{ fmtNum(item.quantity) }}</td>
            <td class="text-right">{{ fmtNum(item.unit_price) }}</td>
            <td class="text-right">{{ fmtNum(item.amount) }}</td>
            <td class="text-right">{{ Number(item.tax_rate || 0) }}%</td>
            <td class="text-right">{{ fmtNum(item.tax_amount) }}</td>
            <td class="text-right bold blue">{{ fmtNum(item.total_with_tax) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Totals -->
    <div class="totals-card card">
      <h3>Summary</h3>
      <div class="totals-grid">
        <div class="t-row"><span>Subtotal</span><span>₹ {{ fmtNum(invoice.subtotal) }}</span></div>
        <div class="t-row"><span>Tax (GST)</span><span>₹ {{ fmtNum(invoice.tax_amount) }}</span></div>
        <div class="t-row grand"><span>GRAND TOTAL</span><span>₹ {{ fmtNum(invoice.total_amount) }}</span></div>
        <div class="t-row"><span>Paid</span><span>₹ {{ fmtNum(paidAmount) }}</span></div>
        <div class="t-row balance"><span>Balance Due</span><span>₹ {{ fmtNum(remainingDue) }}</span></div>
      </div>
    </div>

    <!-- Payment history (read-only; recording is Phase 5B) -->
    <div class="card" v-if="invoice.payments?.length">
      <h3>Payment History</h3>
      <table class="items-table">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="text-right">Amount (₹)</th></tr></thead>
        <tbody>
          <tr v-for="p in invoice.payments" :key="p.id">
            <td>{{ fmtDate(p.transaction_date) }}</td>
            <td>{{ p.payment_method }}</td>
            <td class="muted">{{ p.reference_no ?? '—' }}</td>
            <td class="text-right bold">{{ fmtNum(p.amount) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Notes / Terms -->
    <div class="card" v-if="invoice.notes || invoice.terms">
      <div v-if="invoice.notes"><span class="lbl">Notes:</span> {{ invoice.notes }}</div>
      <div v-if="invoice.terms" style="margin-top:6px"><span class="lbl">Terms:</span> {{ invoice.terms }}</div>
    </div>

    <!-- e-Invoice / e-Way Bill panel -->
    <div class="card ei-card">
      <div class="ei-head">
        <div class="ei-title">e-Invoice &amp; e-Way Bill
          <span class="ei-sub">GST Compliance — IRN &amp; e-Way Bill</span>
        </div>
        <button class="btn btn-ghost sm" @click="loadEiStatus">↻ Refresh</button>
      </div>

      <div v-if="eiLoading" class="ei-loading">Loading…</div>
      <template v-else-if="eiStatus">
        <!-- IRN section -->
        <div class="ei-section">
          <div class="ei-sec-label">IRN (Invoice Reference Number)
            <span :class="['ei-badge', eiStatus.irn_status]">{{ eiStatus.irn_status }}</span>
          </div>
          <template v-if="eiStatus.irn_status === 'generated'">
            <div class="ei-mono">{{ eiStatus.irn }}</div>
            <div class="ei-meta" v-if="eiStatus.irn_ack_no">Ack: {{ eiStatus.irn_ack_no }}{{ eiStatus.irn_ack_date ? '  |  ' + fmtDate(eiStatus.irn_ack_date) : '' }}</div>
            <button class="btn btn-danger sm" style="margin-top:6px" @click="cancelIrn">Cancel IRN</button>
          </template>
          <template v-else-if="eiStatus.irn_status !== 'cancelled'">
            <div v-if="eiStatus.gsp_enabled" class="ei-note">GSP connected — click to auto-generate.</div>
            <div v-else class="ei-note">GSP not configured — enter IRN manually from the <a href="https://einvoice1.gst.gov.in" target="_blank">GST portal</a>.</div>
            <div class="ei-form">
              <input v-model="irnForm.irn" placeholder="64-char IRN" class="ei-input wide" />
              <input v-model="irnForm.irn_ack_no" placeholder="Ack Number" class="ei-input" />
              <input v-model="irnForm.irn_ack_date" type="date" class="ei-input" />
              <textarea v-model="irnForm.irn_qr" placeholder="QR string or data:image/png;base64,..." rows="2" class="ei-textarea"></textarea>
              <div class="ei-actions">
                <button v-if="eiStatus.gsp_enabled" class="btn btn-primary sm" :disabled="eiBusy" @click="generateIrn">{{ eiBusy ? '…' : 'Auto-generate IRN' }}</button>
                <button class="btn btn-accept sm" :disabled="eiBusy || !irnForm.irn || irnForm.irn.length !== 64" @click="saveIrnManual">{{ eiBusy ? '…' : 'Save IRN' }}</button>
              </div>
            </div>
          </template>
          <div v-else class="ei-note" style="color:#c62828">IRN cancelled{{ eiStatus.irn_cancel_reason ? ': ' + eiStatus.irn_cancel_reason : '' }}</div>
        </div>

        <!-- e-Way Bill section -->
        <div class="ei-section" style="margin-top:12px">
          <div class="ei-sec-label">e-Way Bill
            <span :class="['ei-badge', eiStatus.eway_bill_status]">{{ eiStatus.eway_bill_status }}</span>
          </div>
          <template v-if="eiStatus.eway_bill_status === 'active'">
            <div class="ei-meta bold">{{ eiStatus.eway_bill_no }}</div>
            <div class="ei-meta" v-if="eiStatus.eway_bill_expiry">Expires: {{ fmtDate(eiStatus.eway_bill_expiry) }}</div>
            <div class="ei-meta">{{ eiStatus.eway_vehicle_no }} &nbsp;|&nbsp; Mode: {{ eiStatus.eway_transport_mode }}</div>
            <button class="btn btn-danger sm" style="margin-top:6px" @click="cancelEwayBill">Cancel e-Way Bill</button>
          </template>
          <template v-else-if="eiStatus.eway_bill_status !== 'cancelled'">
            <div class="ei-note">Enter e-Way Bill details manually or auto-generate via GSP.</div>
            <div class="ei-form">
              <input v-model="ewayForm.eway_bill_no" placeholder="12-digit e-Way Bill No" class="ei-input" />
              <input v-model="ewayForm.eway_bill_expiry" type="datetime-local" class="ei-input" />
              <input v-model="ewayForm.eway_vehicle_no" placeholder="Vehicle No (e.g. GJ01AB1234)" class="ei-input" />
              <select v-model="ewayForm.eway_transport_mode" class="ei-input">
                <option value="road">Road</option><option value="rail">Rail</option>
                <option value="air">Air</option><option value="ship">Ship</option>
              </select>
              <input v-model.number="ewayForm.eway_distance_km" type="number" placeholder="Distance (km)" class="ei-input" />
              <input v-model="ewayForm.eway_transporter_id" placeholder="Transporter GSTIN/ID" class="ei-input" />
              <div class="ei-actions">
                <button class="btn btn-accept sm" :disabled="eiBusy || !ewayForm.eway_bill_no" @click="saveEwayManual">{{ eiBusy ? '…' : 'Save e-Way Bill' }}</button>
              </div>
            </div>
          </template>
          <div v-else class="ei-note" style="color:#c62828">e-Way Bill cancelled.</div>
        </div>
      </template>
      <div v-else class="ei-note ei-loading">Click ↻ to load status.</div>
      <div v-if="eiError" class="error-banner" style="margin-top:8px">{{ eiError }}</div>
    </div>

    <!-- Record Payment modal -->
    <div v-if="showPayment" class="modal-overlay" @click.self="showPayment = false">
      <div class="modal-box pay-modal">
        <div class="modal-header">
          <h3>Record Payment</h3>
          <button class="btn-close" aria-label="Close" @click="showPayment = false">✕</button>
        </div>

        <div class="pay-summary">
          <div class="pay-sum-row"><span>Invoice Total</span><span class="bold">₹ {{ fmtNum(invoice.total_amount) }}</span></div>
          <div class="pay-sum-row"><span>Already Paid</span><span>₹ {{ fmtNum(paidAmount) }}</span></div>
          <div class="pay-sum-row balance"><span>Balance Due</span><span>₹ {{ fmtNum(remainingDue) }}</span></div>
        </div>

        <div class="form-group">
          <label>Amount (₹) <span class="req">*</span></label>
          <div class="amount-row">
            <input v-model.number="payForm.amount" type="number" min="0.01" :max="remainingDue" step="0.01" placeholder="0.00" />
            <button type="button" class="btn-fill" @click="payForm.amount = Number(remainingDue)">Full</button>
          </div>
          <span class="hint" v-if="payForm.amount > remainingDue">Amount exceeds balance due.</span>
        </div>

        <div class="form-row">
          <div class="form-group flex-1">
            <label>Payment Method</label>
            <select v-model="payForm.payment_method">
              <option value="bank_transfer">Bank Transfer / NEFT / RTGS</option>
              <option value="cheque">Cheque</option>
              <option value="upi">UPI</option>
              <option value="cash">Cash</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group flex-1">
            <label>Reference No</label>
            <input v-model="payForm.reference_no" placeholder="UTR / cheque / txn ref" />
          </div>
        </div>

        <div class="form-group">
          <label>Payment Date <span class="req">*</span></label>
          <input v-model="payForm.transaction_date" type="date" :max="todayIso()" />
          <span class="hint" v-if="payForm.transaction_date > todayIso()">Date cannot be in the future.</span>
        </div>

        <div v-if="payError" class="error-msg">{{ payError }}</div>

        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showPayment = false">Cancel</button>
          <button
            class="btn btn-record"
            :disabled="paySaving || !payForm.amount || payForm.amount <= 0 || payForm.amount > remainingDue || !payForm.transaction_date || payForm.transaction_date > todayIso()"
            @click="recordPayment"
          >
            {{ paySaving ? 'Recording…' : 'Record ₹ ' + fmtNum(payForm.amount || 0) }}
          </button>
        </div>
      </div>
    </div>

    <!-- Cancel modal -->
    <div v-if="showCancel" class="modal-overlay" @click.self="showCancel = false">
      <div class="modal-box">
        <h3>Cancel Invoice?</h3>
        <p>This marks <strong>{{ invoice.invoice_no }}</strong> as cancelled.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showCancel = false">Back</button>
          <button class="btn btn-danger" :disabled="actionLoading" @click="action('cancel')">Cancel Invoice</button>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="loading" class="loading-state">Loading invoice…</div>
  <div v-else-if="loadError" class="error-banner">{{ loadError }}</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import invoiceService from '../services/invoiceService.js'

const props = defineProps({ invoiceId: { type: Number, required: true } })
const emit  = defineEmits(['back', 'view'])

const invoice    = ref(null)
const loading    = ref(false)
const loadError  = ref(null)
const saving     = ref(false)
const pdfLoading = ref(false)
const actionLoading = ref(false)
const actionError   = ref(null)

// e-Invoice / e-Way Bill
const eiStatus  = ref(null)
const eiLoading = ref(false)
const eiBusy    = ref(false)
const eiError   = ref(null)
const irnForm   = reactive({ irn: '', irn_ack_no: '', irn_ack_date: '', irn_qr: '' })
const ewayForm  = reactive({ eway_bill_no: '', eway_bill_expiry: '', eway_vehicle_no: '', eway_transport_mode: 'road', eway_distance_km: null, eway_transporter_id: '' })

async function loadEiStatus() {
  eiLoading.value = true; eiError.value = null
  try {
    const r = await invoiceService.einvoiceStatus(props.invoiceId)
    eiStatus.value = r?.data ?? r
  } catch (e) { eiError.value = e?.response?.data?.message ?? 'Could not load e-Invoice status.' }
  finally { eiLoading.value = false }
}
async function generateIrn() {
  eiBusy.value = true; eiError.value = null
  try {
    const r = await invoiceService.generateIrn(props.invoiceId)
    eiStatus.value = r?.data ?? r
  } catch (e) { eiError.value = e?.response?.data?.message ?? 'IRN generation failed.' }
  finally { eiBusy.value = false }
}
async function saveIrnManual() {
  eiBusy.value = true; eiError.value = null
  try {
    const r = await invoiceService.setIrnManual(props.invoiceId, { irn: irnForm.irn, irn_ack_no: irnForm.irn_ack_no || null, irn_ack_date: irnForm.irn_ack_date || null, irn_qr: irnForm.irn_qr || null })
    eiStatus.value = r?.data ?? r
    Object.assign(irnForm, { irn: '', irn_ack_no: '', irn_ack_date: '', irn_qr: '' })
  } catch (e) { eiError.value = e?.response?.data?.message ?? 'Could not save IRN.' }
  finally { eiBusy.value = false }
}
async function cancelIrn() {
  const reason = prompt('Reason for cancellation?'); if (!reason) return
  eiBusy.value = true; eiError.value = null
  try { const r = await invoiceService.cancelIrn(props.invoiceId, reason); eiStatus.value = r?.data ?? r }
  catch (e) { eiError.value = e?.response?.data?.message ?? 'Cancel failed.' }
  finally { eiBusy.value = false }
}
async function saveEwayManual() {
  eiBusy.value = true; eiError.value = null
  try {
    const payload = { eway_bill_no: ewayForm.eway_bill_no, eway_bill_expiry: ewayForm.eway_bill_expiry || null, eway_vehicle_no: ewayForm.eway_vehicle_no || null, eway_transport_mode: ewayForm.eway_transport_mode, eway_distance_km: ewayForm.eway_distance_km || null, eway_transporter_id: ewayForm.eway_transporter_id || null }
    const r = await invoiceService.setEwayBillManual(props.invoiceId, payload)
    eiStatus.value = r?.data ?? r
    Object.assign(ewayForm, { eway_bill_no: '', eway_bill_expiry: '', eway_vehicle_no: '', eway_transport_mode: 'road', eway_distance_km: null, eway_transporter_id: '' })
  } catch (e) { eiError.value = e?.response?.data?.message ?? 'Could not save e-Way Bill.' }
  finally { eiBusy.value = false }
}
async function cancelEwayBill() {
  const reason = prompt('Reason?') ?? 'Cancelled'
  eiBusy.value = true; eiError.value = null
  try { const r = await invoiceService.cancelEwayBill(props.invoiceId, reason); eiStatus.value = r?.data ?? r }
  catch (e) { eiError.value = e?.response?.data?.message ?? 'Cancel failed.' }
  finally { eiBusy.value = false }
}
const actionSuccess = ref(null)
const showCancel    = ref(false)

// Payment recording
const showPayment = ref(false)
const paySaving   = ref(false)
const payError    = ref(null)
const payForm     = reactive({ amount: null, payment_method: 'bank_transfer', reference_no: '', transaction_date: '' })
const todayIso    = () => new Date().toISOString().slice(0, 10)

const editForm = reactive({ due_date: '', notes: '', terms: '' })

const customer    = computed(() => invoice.value?.dispatch?.batch?.order?.customer ?? invoice.value?.order?.customer ?? null)
const paidAmount  = computed(() => (invoice.value?.payments ?? []).reduce((s, p) => s + Number(p.amount || 0), 0))
const remainingDue = computed(() => Number(invoice.value?.total_amount || 0) - paidAmount.value)
const isOverdue   = computed(() => {
  if (!invoice.value || ['paid','cancelled'].includes(invoice.value.status)) return false
  return invoice.value.due_date && new Date(invoice.value.due_date) < new Date() && remainingDue.value > 0
})
const canRecordPayment = computed(() =>
  invoice.value && ['sent','accepted'].includes(invoice.value.status) && remainingDue.value > 0
)

async function load() {
  loading.value = true; loadError.value = null
  try {
    const res = await invoiceService.get(props.invoiceId)
    invoice.value = res?.data ?? res
    editForm.due_date = invoice.value.due_date?.slice(0, 10) ?? ''
    editForm.notes    = invoice.value.notes ?? ''
    editForm.terms    = invoice.value.terms ?? ''
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? 'Failed to load invoice.'
  } finally { loading.value = false }
}

async function saveEdits() {
  saving.value = true; actionError.value = null
  try {
    await invoiceService.update(props.invoiceId, {
      due_date: editForm.due_date || null,
      notes:    editForm.notes || null,
      terms:    editForm.terms || null,
    })
    actionSuccess.value = 'Invoice updated.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to update.'
  } finally { saving.value = false }
}

async function action(type) {
  actionLoading.value = true; actionError.value = null; actionSuccess.value = null; showCancel.value = false
  try {
    if (type === 'send')           { await invoiceService.send(props.invoiceId);     actionSuccess.value = 'Invoice sent.' }
    else if (type === 'accept')    { await invoiceService.accept(props.invoiceId);   actionSuccess.value = 'Invoice accepted.' }
    else if (type === 'mark-paid') { await invoiceService.markPaid(props.invoiceId); actionSuccess.value = 'Invoice marked paid.' }
    else if (type === 'cancel')    { await invoiceService.cancel(props.invoiceId);   actionSuccess.value = 'Invoice cancelled.' }
    else if (type === 'duplicate') { const r = await invoiceService.duplicate(props.invoiceId); emit('view', r?.data?.id ?? r?.id); return }
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? `Failed to ${type}.`
  } finally { actionLoading.value = false }
}

function openPaymentModal() {
  payForm.amount           = Number(remainingDue.value)
  payForm.payment_method   = 'bank_transfer'
  payForm.reference_no     = ''
  payForm.transaction_date = todayIso()
  payError.value           = null
  showPayment.value        = true
}

async function recordPayment() {
  // Stricter guards: amount must be positive AND within balance; date must be set
  // and not in the future. Backend can still validate, but failing early gives
  // the user immediate feedback instead of a 422 round-trip.
  if (!payForm.amount || payForm.amount <= 0) { payError.value = 'Amount must be greater than zero.'; return }
  if (payForm.amount > remainingDue.value)    { payError.value = 'Amount exceeds balance due.';      return }
  if (!payForm.transaction_date)              { payError.value = 'Payment date is required.';        return }
  if (payForm.transaction_date > todayIso())  { payError.value = 'Payment date cannot be in the future.'; return }
  paySaving.value = true
  payError.value  = null
  try {
    await invoiceService.recordPayment({
      invoice_id:       props.invoiceId,
      amount:           payForm.amount,
      payment_method:   payForm.payment_method,
      reference_no:     payForm.reference_no || null,
      transaction_date: payForm.transaction_date,
    })
    showPayment.value   = false
    actionSuccess.value = `Payment of ₹ ${fmtNum(payForm.amount)} recorded.`
    await load()
  } catch (e) {
    payError.value = e?.response?.data?.message ?? 'Failed to record payment.'
  } finally {
    paySaving.value = false
  }
}

async function openPdf() {
  pdfLoading.value = true; actionError.value = null
  try { await invoiceService.openPdf(props.invoiceId) }
  catch { actionError.value = 'Failed to open PDF.' }
  finally { pdfLoading.value = false }
}
async function downloadPdf() {
  pdfLoading.value = true; actionError.value = null
  try { await invoiceService.downloadPdf(props.invoiceId, invoice.value?.invoice_no) }
  catch { actionError.value = 'Failed to download PDF.' }
  finally { pdfLoading.value = false }
}

function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
function fmtNum(n)  { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

onMounted(async () => { await load(); loadEiStatus() })
</script>

<style scoped>
.id-wrap { font-family: inherit; max-width: 1000px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
.id-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.id-title { display: flex; align-items: center; gap: 8px; flex: 1; }
.inv-no { font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace; letter-spacing: 1px; }
.action-bar { display: flex; gap: 6px; flex-wrap: wrap; }

.error-banner   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; }

.card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px 24px; }
.card h3 { margin: 0 0 14px; font-size: 15px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }

.info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.info-cell { padding: 4px; }
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 4px; }
.info-value { font-size: 14px; color: #222; }
.info-value.bold { font-weight: 700; }
.info-sub { font-size: 11px; color: #888; margin-top: 2px; }
.mono { font-family: monospace; }
.bold { font-weight: 700; }
.pos { color: #c62828; }
.overdue { color: #c62828 !important; }
.overdue-tag { font-size: 10px; font-weight: 700; background: #ffebee; color: #c62828; border-radius: 8px; padding: 2px 7px; }

.edit-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; }
.form-group input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.flex-1 { flex: 1; min-width: 160px; }

.items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.items-table th { background: var(--primary-tint); color: #333; padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.items-table td { padding: 8px 10px; border: 1px solid #e0e0e0; }
.text-right { text-align: right; }
.blue { color: var(--primary); }
.muted { color: #999; }

.totals-card { max-width: 420px; margin-left: auto; }
.totals-grid { display: flex; flex-direction: column; }
.t-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.t-row.grand { background: var(--primary); color: white; font-size: 15px; font-weight: 700; padding: 10px 8px; border-radius: 6px; margin: 6px 0; }
.t-row.balance { color: #c62828; font-weight: 700; }

.lbl { font-weight: 700; color: #888; text-transform: uppercase; font-size: 11px; margin-right: 6px; }

.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.draft     { background: var(--primary-tint); color: var(--primary); }
.status-badge.sent      { background: #fff8e1; color: #f57f17; }
.status-badge.accepted  { background: #ede7f6; color: #4527a0; }
.status-badge.paid      { background: #e8f5e9; color: #2e7d32; }
.status-badge.cancelled { background: #fafafa; color: #aaa; border: 1px solid #e0e0e0; }

.btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-pdf { background: #fce4ec; color: #c62828; }
.btn-send { background: #e0f2f1; color: #00695c; }
.btn-accept { background: #ede7f6; color: #4527a0; }
.btn-paid { background: #e8f5e9; color: #2e7d32; }
.btn-record { background: #2e7d32; color: white; }
.btn-dup { background: #e0f7fa; color: #006064; }
.btn-cancel { background: #ffebee; color: #c62828; }
.btn-save { background: var(--primary); color: white; }
.btn-danger { background: #c62828; color: white; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.loading-state { text-align: center; padding: 60px; color: #888; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box { background: white; border-radius: 10px; padding: 26px 30px; min-width: 360px; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.modal-box h3 { margin: 0 0 10px; color: var(--primary); }
.modal-box p { color: #555; font-size: 14px; margin-bottom: 14px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }

/* Payment modal */
.pay-modal { min-width: 440px; max-width: 480px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-header h3 { margin: 0; }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.btn-close:hover { color: #333; background: #f0f0f0; }

.pay-summary { background: var(--surface-2); border: 1px solid var(--primary-bd); border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
.pay-sum-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 13px; color: #555; }
.pay-sum-row.balance { border-top: 1px solid var(--primary-bd); margin-top: 5px; padding-top: 7px; font-weight: 700; color: #c62828; font-size: 14px; }

.pay-modal .form-group { margin-bottom: 14px; }
.pay-modal .form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.pay-modal .form-group input, .pay-modal .form-group select { width: 100%; box-sizing: border-box; padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.pay-modal .form-group input:focus, .pay-modal .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.pay-modal .form-row { display: flex; gap: 12px; }
.amount-row { display: flex; gap: 8px; align-items: stretch; }
.amount-row input { flex: 1; }
.btn-fill { padding: 0 14px; border: 1px solid var(--primary); background: var(--primary-tint); color: var(--primary); border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; }
.req { color: #c62828; }
.hint { font-size: 11px; color: #c62828; margin-top: 3px; display: block; }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }

/* e-Invoice panel */
.ei-card { padding: 16px 20px; }
.ei-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ei-title { font-size: 14px; font-weight: 700; color: var(--primary); }
.ei-sub { font-size: 11px; font-weight: 400; color: var(--text-2); margin-left: 8px; }
.ei-loading { font-size: 12px; color: #888; padding: 8px 0; }
.ei-section { background: #f8faff; border: 1px solid #dce6f8; border-radius: 7px; padding: 10px 14px; }
.ei-sec-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #5b6472; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.ei-badge { font-size: 9px; font-weight: 800; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; }
.ei-badge.none { background: #f0f0f0; color: #888; }
.ei-badge.generated { background: #e8f5e9; color: #2e7d32; }
.ei-badge.cancelled { background: #ffebee; color: #c62828; }
.ei-badge.active { background: #e8f5e9; color: #2e7d32; }
.ei-badge.expired { background: #fff8e1; color: #b5740a; }
.ei-mono { font-family: monospace; font-size: 10px; color: #333; word-break: break-all; background: #fff; padding: 4px 8px; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 4px; }
.ei-meta { font-size: 11px; color: #555; margin-bottom: 2px; }
.ei-note { font-size: 11.5px; color: #667085; margin-bottom: 8px; }
.ei-note a { color: var(--primary); }
.ei-form { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.ei-input { padding: 6px 9px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; min-width: 160px; }
.ei-input.wide { min-width: 320px; flex: 1; }
.ei-textarea { width: 100%; padding: 6px 9px; border: 1px solid #ddd; border-radius: 5px; font-size: 11px; font-family: monospace; resize: vertical; }
.ei-actions { width: 100%; display: flex; gap: 8px; margin-top: 4px; }
</style>
