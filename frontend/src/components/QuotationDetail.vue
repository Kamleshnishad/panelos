<template>
  <div class="qd-wrap" v-if="quotation">
    <!-- Toolbar -->
    <div class="qd-toolbar">
      <button class="btn btn-ghost" @click="$emit('back')">← Back to List</button>
      <div class="qd-title">
        <span class="qno">{{ quotation.quotation_no }}</span>
        <span :class="['status-badge', quotation.status]">{{ quotation.status }}</span>
        <span v-if="quotation.rates_pending" class="rates-pending-badge" title="Sales must enter rates">Rates Pending</span>
        <span v-if="quotation.revision_number > 1" class="rev-badge">Rev {{ quotation.revision_number }}</span>
      </div>
      <div class="action-bar">
        <!-- Document actions -->
        <button class="btn btn-ghost" :disabled="pdfLoading" @click="openPdf">{{ pdfLoading ? 'Opening…' : 'PDF' }}</button>
        <button class="btn btn-ghost" :disabled="pdfLoading" aria-label="Download full quotation PDF" title="Download full quotation PDF" @click="downloadPdf">⬇</button>
        <button class="btn btn-ghost" :disabled="boqLoading" title="BOQ cutting sheet for workers (no rates)" @click="openBoq">{{ boqLoading ? 'Opening…' : '🔧 BOQ Sheet' }}</button>
        <span class="ab-sep"></span>
        <!-- Contextual primary action -->
        <button v-if="quotation.status === 'draft'" class="btn btn-edit" @click="$emit('edit', quotation.id)">Edit</button>
        <button v-if="quotation.status === 'draft'" class="btn btn-primary" :disabled="actionLoading || quotation.rates_pending" :title="quotation.rates_pending ? 'Enter rates before sending' : 'Send to customer'" @click="action('send')">Send</button>
        <button v-if="quotation.status === 'sent'"  class="btn btn-primary" :disabled="actionLoading" @click="action('accept')">Accept</button>
        <button v-if="quotation.status === 'accepted'" class="btn btn-primary" :disabled="actionLoading" @click="action('create-order')">Create Order</button>
        <!-- Secondary actions -->
        <button v-if="['draft','sent'].includes(quotation.status)" class="btn btn-ghost" :disabled="actionLoading" @click="action('reject')">Reject</button>
        <button v-if="['sent','accepted'].includes(quotation.status)" class="btn btn-ghost" :disabled="actionLoading" @click="action('revise')">Revise</button>
        <button class="btn btn-ghost" :disabled="actionLoading" title="Copy as new draft" @click="action('duplicate')">Duplicate</button>
        <!-- Destructive (pushed right) -->
        <span class="ab-spacer"></span>
        <button v-if="['draft','sent'].includes(quotation.status)" class="btn btn-ghost" :disabled="actionLoading" @click="action('expire')">Expire</button>
        <button v-if="quotation.status === 'draft'" class="btn btn-danger" :disabled="actionLoading" @click="confirmDelete">Delete</button>
      </div>
    </div>

    <div v-if="quotation.rates_pending && quotation.status === 'draft'" class="pending-banner">
      ⚠ This BOQ is awaiting <strong>rates from the Sales team</strong>. Click <strong>Edit</strong> to enter rates — the quotation can be sent once all panel rows are priced.
    </div>

    <!-- Header info bar -->
    <div class="info-grid card">
      <div class="info-cell">
        <div class="info-label">Customer</div>
        <div class="info-value bold">{{ quotation.customer?.name }}</div>
        <div class="info-sub">{{ quotation.customer?.city }}, {{ quotation.customer?.state }}</div>
        <div class="info-sub" v-if="quotation.customer?.gstin">GSTIN: {{ quotation.customer.gstin }}</div>
      </div>
      <div class="info-cell" v-if="quotation.project_name">
        <div class="info-label">Project</div>
        <div class="info-value bold">{{ quotation.project_name }}</div>
        <div class="info-sub">{{ quotation.project_location }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Dates</div>
        <div class="info-value">Date: {{ fmtDate(quotation.quoted_on) }}</div>
        <div class="info-sub" :class="{ expired: isExpired(quotation.valid_until) }">Valid until: {{ fmtDate(quotation.valid_until) }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Quality / Grade</div>
        <div class="info-value">{{ quotation.quality_grade }}</div>
        <div class="info-sub">GST: {{ quotation.is_inter_state ? 'IGST (Inter-state)' : 'CGST+SGST' }}</div>
      </div>
    </div>

    <!-- Revision history -->
    <div class="card" v-if="quotation.revisions?.length > 0 || quotation.parent">
      <h3>Revision History</h3>
      <div class="rev-chain">
        <div v-if="quotation.parent" class="rev-item">
          <span class="rev-link" @click="$emit('view', quotation.parent.id)">{{ quotation.parent.quotation_no }}</span>
          <span :class="['status-badge-sm', quotation.parent.status]">{{ quotation.parent.status }}</span>
          <span class="rev-arrow">→</span>
          <strong>Current (Rev {{ quotation.revision_number }})</strong>
        </div>
        <div v-for="rev in quotation.revisions" :key="rev.id" class="rev-item">
          <span class="rev-arrow">→</span>
          <span class="rev-link" @click="$emit('view', rev.id)">{{ rev.quotation_no }}</span>
          <span :class="['status-badge-sm', rev.status]">{{ rev.status }}</span>
        </div>
      </div>
    </div>

    <!-- Panel items -->
    <div class="card">
      <h3>Panel Specification & BOQ</h3>

      <div v-for="(item, ii) in quotation.items" :key="item.id" class="item-block">
        <div class="item-header">
          <span class="item-num">{{ ii + 1 }}</span>
          <div class="item-spec">
            <div class="spec-title">{{ item.panel_type?.name }}</div>
            <div class="spec-detail">
              {{ item.thickness }}mm &nbsp;|&nbsp;
              {{ item.density_type }} {{ item.density_kgm3 }} kg/m³ &nbsp;|&nbsp;
              TOP: {{ item.top_skin_thickness }}mm {{ item.top_skin_material }} {{ item.top_color }} ({{ item.top_surface }}) &nbsp;|&nbsp;
              BTM: {{ item.bottom_skin_thickness }}mm {{ item.bottom_skin_material }} {{ item.bottom_color }} &nbsp;|&nbsp;
              HSN: {{ item.hsn_code }}
              <span v-if="item.guard_film"> | Guard Film</span>
              <span v-if="item.cello_tap"> | Cello Tap</span>
            </div>
          </div>
          <div class="item-total">
            <div class="item-total-sqm">{{ Number(item.total_sqm).toFixed(2) }} SQM</div>
            <div class="item-total-amt">₹ {{ fmtNum(itemAmt(item)) }}</div>
          </div>
        </div>

        <!-- Size sub-table -->
        <table class="size-table" v-if="item.sizes?.length > 0">
          <thead>
            <tr><th>Length (mm)</th><th>Width (mm)</th><th>NOS</th><th>SQM</th><th class="text-right">Rate (₹/SQM)</th><th class="text-right">Amount (₹)</th></tr>
          </thead>
          <tbody>
            <tr v-for="sz in item.sizes" :key="sz.id">
              <td class="bold">{{ sz.length_mm }}
                <span v-if="sz.length_mm < 2000" class="warn-tag">⚠ DL</span>
              </td>
              <td>{{ sz.width_mm }}</td>
              <td class="bold">{{ sz.nos }}</td>
              <td class="bold">{{ Number(sz.sqm).toFixed(3) }}</td>
              <td class="text-right">
                <input v-if="canEditRates" v-model.number="sz.rate_per_sqm" type="number" min="0" step="5" class="rate-input" />
                <span v-else>{{ fmtNum(sz.rate_per_sqm) }}</span>
              </td>
              <td class="text-right bold blue">{{ fmtNum(lineAmt(sz)) }}</td>
            </tr>
            <tr class="size-total-row">
              <td colspan="2" class="text-right bold">Total</td>
              <td class="bold">{{ item.sizes.reduce((s,z) => s + z.nos, 0) }}</td>
              <td class="bold">{{ Number(item.total_sqm).toFixed(3) }}</td>
              <td></td>
              <td class="text-right bold blue">{{ fmtNum(itemAmt(item)) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Accessories -->
      <div v-if="quotation.accessories?.length > 0" class="acc-section">
        <h4>Accessories</h4>
        <table class="size-table">
          <thead>
            <tr><th>Item</th><th>Qty</th><th>Unit</th><th class="text-right">Rate (₹)</th><th class="text-right">Amount (₹)</th></tr>
          </thead>
          <tbody>
            <tr v-for="acc in quotation.accessories" :key="acc.id">
              <td>{{ acc.name }}</td>
              <td>{{ acc.pivot?.quantity }}</td>
              <td>{{ acc.unit || 'NOS' }}</td>
              <td class="text-right">{{ fmtNum(acc.pivot?.unit_price) }}</td>
              <td class="text-right bold blue">{{ fmtNum(acc.pivot?.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Inline rate-entry save bar (sticky — live totals visible without scrolling) -->
    <div v-if="canEditRates" class="rate-save-bar">
      <div class="rsb-live">
        <span class="rsb-item"><label>Subtotal</label>₹ {{ fmtNum(fin.subtotal) }}</span>
        <span class="rsb-item"><label>GST</label>₹ {{ fmtNum(fin.cgst + fin.sgst + fin.igst) }}</span>
        <span class="rsb-item rsb-grand"><label>Grand Total</label>₹ {{ fmtNum(fin.grand) }}</span>
        <span class="rsb-item"><label>Balance</label>₹ {{ fmtNum(fin.balance) }}</span>
      </div>
      <button class="btn btn-primary" :disabled="savingRates" @click="saveRates">{{ savingRates ? 'Saving…' : '💾 Save Rates' }}</button>
    </div>

    <!-- Totals -->
    <div class="totals-card card">
      <h3>Financial Summary</h3>
      <div class="totals-grid">
        <div class="t-row"><span>Panel Subtotal</span><span>₹ {{ fmtNum(fin.panel) }}</span></div>
        <div class="t-row" v-if="fin.acc > 0"><span>Accessories</span><span>₹ {{ fmtNum(fin.acc) }}</span></div>
        <div class="t-row" v-if="fin.install > 0"><span>Installation</span><span>₹ {{ fmtNum(fin.install) }}</span></div>
        <div class="t-row border-top"><span>Subtotal</span><span>₹ {{ fmtNum(fin.subtotal) }}</span></div>
        <div class="t-row discount" v-if="fin.discPct > 0"><span>Discount ({{ fin.discPct }}%)</span><span>- ₹ {{ fmtNum(fin.disc) }}</span></div>
        <div class="t-row border-top"><span>Taxable Amount</span><span>₹ {{ fmtNum(fin.taxable) }}</span></div>
        <div class="t-row" v-if="fin.inter"><span>IGST @ 18%</span><span>₹ {{ fmtNum(fin.igst) }}</span></div>
        <template v-else>
          <div class="t-row"><span>CGST @ 9%</span><span>₹ {{ fmtNum(fin.cgst) }}</span></div>
          <div class="t-row"><span>SGST @ 9%</span><span>₹ {{ fmtNum(fin.sgst) }}</span></div>
        </template>
        <div class="t-row" v-if="fin.transportFixed"><span>Transportation</span><span>₹ {{ fmtNum(fin.transport) }}</span></div>
        <div class="t-row" v-else><span>Transportation</span><span class="text-muted">Extra as Actual</span></div>
        <div class="t-row" v-if="fin.roundOff != 0"><span>Round Off</span><span>₹ {{ fmtNum(fin.roundOff) }}</span></div>
        <div class="t-row grand"><span>GRAND TOTAL</span><span>₹ {{ fmtNum(fin.grand) }}</span></div>
        <div class="t-row"><span>Advance ({{ fin.advPct }}%)</span><span>₹ {{ fmtNum(fin.advance) }}</span></div>
        <div class="t-row balance"><span>Balance Due</span><span>₹ {{ fmtNum(fin.balance) }}</span></div>
        <div class="t-row"><span>Total SQM</span><span>{{ Number(fin.sqm).toFixed(3) }} SQM</span></div>
      </div>
    </div>

    <!-- Notes -->
    <div class="card" v-if="quotation.notes">
      <h3>Notes</h3>
      <p style="font-size:13px;color:#555;line-height:1.7">{{ quotation.notes }}</p>
    </div>

    <!-- Confirm delete -->
    <div v-if="deleteConfirm" class="modal-overlay" @click.self="deleteConfirm = false">
      <div class="modal-box">
        <h3>Delete Quotation?</h3>
        <p>This will permanently delete <strong>{{ quotation.quotation_no }}</strong>. This cannot be undone.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="deleteConfirm = false">Cancel</button>
          <button class="btn btn-danger" :disabled="actionLoading" @click="doDelete">{{ actionLoading ? 'Deleting…' : 'Delete' }}</button>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="loading" class="loading-state">Loading quotation…</div>
  <div v-else-if="loadError" class="error-banner">{{ loadError }}</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import quotationService from '../services/quotationService.js'
import { toastSuccess, toastError } from '../services/ui.js'

const props = defineProps({ quotationId: { type: Number, required: true } })
const emit = defineEmits(['back', 'edit', 'view', 'order-created'])

const quotation = ref(null)
const loading = ref(false)
const loadError = ref(null)
const actionLoading = ref(false)
const deleteConfirm = ref(false)
const pdfLoading = ref(false)
const boqLoading = ref(false)
const savingRates = ref(false)

// Rates can be entered inline on draft/sent quotations (the rates-pending workflow).
const canEditRates = computed(() => ['draft', 'sent'].includes(quotation.value?.status))
function lineAmt(sz) {
  return canEditRates.value ? (Number(sz.sqm) * (Number(sz.rate_per_sqm) || 0)) : Number(sz.amount || 0)
}
function itemAmt(item) {
  return (item.sizes || []).reduce((s, z) => s + lineAmt(z), 0)
}

// Live financial summary — mirrors backend recalculate() so it updates as rates
// are typed (before saving).
const fin = computed(() => {
  const q = quotation.value || {}
  const panel = (q.items || []).reduce((s, it) => s + itemAmt(it), 0)
  const acc = Number(q.accessory_subtotal || 0)
  const install = Number(q.installation_amount || 0)
  const subtotal = panel + acc + install
  const discPct = Number(q.discount_pct || 0)
  const disc = subtotal * discPct / 100
  const taxable = subtotal - disc
  const gst = taxable * 0.18
  const inter = !!q.is_inter_state
  const transport = (q.transport_fixed && Number(q.transport_amount) > 0) ? Number(q.transport_amount) : 0
  const raw = taxable + gst + transport
  const roundOff = Math.round(raw) - raw
  const grand = raw + roundOff
  const advPct = Number(q.advance_pct || 0)
  const advance = grand * advPct / 100
  return {
    panel, acc, install, subtotal, discPct, disc, taxable, gst,
    cgst: inter ? 0 : gst / 2, sgst: inter ? 0 : gst / 2, igst: inter ? gst : 0,
    inter, transport, transportFixed: !!(q.transport_fixed && Number(q.transport_amount) > 0),
    roundOff, grand, advPct, advance, balance: grand - advance,
    sqm: (q.items || []).reduce((s, it) => s + Number(it.total_sqm || 0), 0),
  }
})

async function saveRates() {
  savingRates.value = true
  try {
    const rates = []
    for (const item of (quotation.value.items || [])) {
      for (const sz of (item.sizes || [])) rates.push({ id: sz.id, rate_per_sqm: Number(sz.rate_per_sqm) || 0 })
    }
    const res = await quotationService.saveRates(quotation.value.id, rates)
    quotation.value = res?.data ?? res
    toastSuccess('Rates saved.')
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Failed to save rates.')
  } finally {
    savingRates.value = false
  }
}

async function openPdf() {
  pdfLoading.value = true
  try { await quotationService.openPdf(props.quotationId) }
  catch { toastError('Failed to open PDF.') }
  finally { pdfLoading.value = false }
}
async function openBoq() {
  boqLoading.value = true
  try { await quotationService.openBoqPdf(props.quotationId) }
  catch { toastError('Failed to open BOQ sheet.') }
  finally { boqLoading.value = false }
}
async function downloadPdf() {
  pdfLoading.value = true
  try { await quotationService.downloadPdf(props.quotationId, quotation.value?.quotation_no) }
  catch { toastError('Failed to download PDF.') }
  finally { pdfLoading.value = false }
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const res = await quotationService.get(props.quotationId)
    quotation.value = res?.data ?? res
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? 'Failed to load quotation.'
  } finally {
    loading.value = false
  }
}

async function action(type) {
  actionLoading.value = true
  try {
    if (type === 'send')              { await quotationService.send(quotation.value.id);      toastSuccess('Quotation sent.') }
    else if (type === 'accept')       { await quotationService.accept(quotation.value.id);    toastSuccess('Quotation accepted.') }
    else if (type === 'reject')       { await quotationService.reject(quotation.value.id);    toastSuccess('Quotation rejected.') }
    else if (type === 'expire')       { await quotationService.expire(quotation.value.id);    toastSuccess('Quotation marked expired.') }
    else if (type === 'revise')       { const res = await quotationService.revise(quotation.value.id);    emit('view', res?.data?.id ?? res?.id); return }
    else if (type === 'duplicate')    { const res = await quotationService.duplicate(quotation.value.id); emit('view', res?.data?.id ?? res?.id); return }
    else if (type === 'create-order') { const res = await quotationService.createOrder(quotation.value.id); emit('order-created', res?.data?.id ?? res?.id); return }
    await load()
  } catch (e) {
    toastError(e?.response?.data?.message ?? `Failed to ${type}.`)
  } finally {
    actionLoading.value = false
  }
}

function confirmDelete() { deleteConfirm.value = true }

async function doDelete() {
  actionLoading.value = true
  try {
    await quotationService.delete(quotation.value.id)
    toastSuccess('Quotation deleted.')
    emit('back')
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Failed to delete.')
    deleteConfirm.value = false
  } finally {
    actionLoading.value = false
  }
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtNum(n) {
  return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function isExpired(d) { return d && new Date(d) < new Date() }

onMounted(load)
</script>

<style scoped>
.qd-wrap { font-family: inherit; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }

.rate-input { width: 110px; padding: 6px 8px; border: 1px solid var(--primary); border-radius: 6px; font-size: 13px; text-align: right; font-variant-numeric: tabular-nums; }
.rate-input:focus { outline: none; box-shadow: 0 0 0 2px var(--primary-tint); }
.rate-save-bar { position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  background: var(--surface, #fff); border: 1px solid var(--primary-bd, #c5cae9); border-radius: 10px; padding: 12px 18px; box-shadow: 0 -4px 20px rgba(0,0,0,0.10); }
.rsb-live { display: flex; gap: 24px; flex-wrap: wrap; }
.rsb-item { display: flex; flex-direction: column; font-size: 14px; font-weight: 700; color: var(--ink, #15181E); font-variant-numeric: tabular-nums; }
.rsb-item label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-3, #888); margin-bottom: 1px; }
.rsb-grand { color: var(--primary); font-size: 16px; }

.qd-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.qd-title { display: flex; align-items: center; gap: 8px; flex: 1; }
.qno { font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace; letter-spacing: 1px; }
.action-bar { display: flex; gap: 6px; flex-wrap: wrap; }

.error-banner  { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; }
.success-banner{ background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; }

.card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px 24px; }
.card h3 { margin: 0 0 14px; font-size: 15px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }
.card h4 { margin: 14px 0 8px; font-size: 13px; color: #555; }

.info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.info-cell { padding: 4px; }
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 4px; }
.info-value { font-size: 14px; color: #222; }
.info-value.bold { font-weight: 700; }
.info-sub { font-size: 11px; color: #888; margin-top: 2px; }
.expired { color: #c62828 !important; font-weight: 700; }

.rev-chain { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 13px; }
.rev-item { display: flex; align-items: center; gap: 6px; }
.rev-link { color: var(--primary); cursor: pointer; text-decoration: underline; }
.rev-arrow { color: #aaa; }

.item-block { border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; background: #fafafe; }
.item-header { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 10px; }
.item-num { background: var(--primary); color: white; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.item-spec { flex: 1; }
.spec-title { font-weight: 700; font-size: 14px; color: var(--primary); }
.spec-detail { font-size: 11px; color: #666; margin-top: 4px; line-height: 1.7; }
.item-total { text-align: right; }
.item-total-sqm { font-size: 12px; color: #888; }
.item-total-amt { font-size: 16px; font-weight: 700; color: var(--primary); }

.size-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.size-table th { background: var(--primary-tint); color: #333; padding: 5px 8px; border: 1px solid var(--primary-bd); font-size: 10px; text-transform: uppercase; }
.size-table td { padding: 5px 8px; border: 1px solid #e0e0e0; }
.size-total-row td { background: var(--primary-tint) !important; font-weight: 700; }
.warn-tag { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; border-radius: 3px; padding: 1px 4px; font-size: 9px; font-weight: 700; margin-left: 4px; }
.acc-section { margin-top: 14px; }
.text-right { text-align: right; }
.bold { font-weight: 700; }
.blue { color: var(--primary); }

.totals-card { max-width: 480px; margin-left: auto; }
.totals-grid { display: flex; flex-direction: column; }
.t-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.t-row.border-top { border-top: 2px solid var(--primary); margin-top: 4px; padding-top: 9px; font-weight: 600; }
.t-row.discount { color: #c62828; }
.t-row.grand { background: var(--primary); color: white; font-size: 15px; font-weight: 700; padding: 10px 8px; border-radius: 6px; margin: 6px 0; }
.t-row.balance { color: var(--primary); font-weight: 700; }
.text-muted { color: #aaa; }

.rev-badge { background: #f3e5f5; color: #6a1b9a; border: 1px solid #ce93d8; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
.rates-pending-badge { background: #FBF0DA; color: #B5740A; border: 1px solid #EFD9A8; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.pending-banner { background: #FBF0DA; border: 1px solid #EFD9A8; color: #8a5a08; padding: 10px 16px; border-radius: 8px; font-size: 13px; }

.status-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.draft    { background: var(--primary-tint); color: var(--primary); }
.status-badge.sent     { background: #fff8e1; color: #f57f17; }
.status-badge.accepted { background: #e8f5e9; color: #2e7d32; }
.status-badge.rejected { background: #ffebee; color: #c62828; }
.status-badge.revised  { background: #f3e5f5; color: #6a1b9a; }
.status-badge.expired  { background: #fafafa; color: #aaa; }
.status-badge-sm { font-size: 10px; padding: 1px 7px; border-radius: 10px; font-weight: 700; text-transform: uppercase; }
.status-badge-sm.draft    { background: var(--primary-tint); color: var(--primary); }
.status-badge-sm.sent     { background: #fff8e1; color: #f57f17; }
.status-badge-sm.accepted { background: #e8f5e9; color: #2e7d32; }
.status-badge-sm.rejected { background: #ffebee; color: #c62828; }
.status-badge-sm.revised  { background: #f3e5f5; color: #6a1b9a; }

.btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-ghost   { background: var(--surface-3); border: 1px solid var(--border-2); color: var(--text-2); }
.btn-ghost:hover:not(:disabled) { background: var(--border); }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover:not(:disabled) { background: var(--primary-hover); }
.btn-edit    { background: var(--warning-bg); color: var(--warning); }
.btn-danger  { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-bd); }
.btn-danger:hover:not(:disabled) { background: var(--danger); color: #fff; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Action-bar grouping */
.ab-sep { width: 1px; align-self: stretch; background: var(--border); margin: 2px 4px; }
.ab-spacer { flex: 1 1 auto; }

.loading-state { text-align: center; padding: 60px; color: #888; font-size: 14px; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box { background: white; border-radius: 10px; padding: 28px 32px; min-width: 360px; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.modal-box h3 { margin: 0 0 10px; color: #c62828; }
.modal-box p { color: #555; font-size: 14px; margin-bottom: 20px; line-height: 1.6; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
</style>
