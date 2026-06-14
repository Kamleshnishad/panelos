<template>
  <div class="dd-wrap" v-if="dispatch">
    <!-- Toolbar -->
    <div class="dd-toolbar">
      <button class="btn btn-ghost" @click="$emit('back')">← Back to Dispatches</button>
      <div class="dd-title">
        <span class="dispatch-no">{{ dispatch.dispatch_no }}</span>
        <span :class="['status-badge', dispatch.status]">{{ statusLabel(dispatch.status) }}</span>
      </div>
      <div class="action-bar">
        <button
          v-if="dispatch.status === 'pending' && !isAllocated"
          class="btn btn-allocate" :disabled="actionLoading" @click="doAllocate"
        >Allocate Stock</button>
        <button
          v-if="dispatch.status === 'pending'"
          class="btn btn-complete" :disabled="actionLoading" @click="showComplete = true"
        >Mark Delivered</button>
        <button
          v-if="dispatch.status === 'pending'"
          class="btn btn-cancel" :disabled="actionLoading" @click="showCancel = true"
        >Cancel</button>
        <button class="btn btn-challan" :disabled="pdfLoading" @click="openChallan">
          {{ pdfLoading ? 'Opening…' : '📄 Challan' }}
        </button>
        <button class="btn btn-ghost" :disabled="pdfLoading" @click="downloadChallan" title="Download challan PDF">⬇</button>
        <button v-if="dispatch.batch" class="btn btn-ghost" @click="$emit('view-batch', dispatch.batch_id)">View Batch</button>
      </div>
    </div>

    <div v-if="actionError"   class="error-banner">{{ actionError }}</div>
    <div v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</div>

    <!-- Info grid -->
    <div class="info-grid card">
      <div class="info-cell">
        <div class="info-label">Batch</div>
        <div class="info-value mono bold">{{ dispatch.batch?.batch_no ?? '—' }}</div>
        <div class="info-sub">{{ dispatch.batch?.order?.customer?.name ?? '' }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Dispatch Date</div>
        <div class="info-value">{{ fmtDate(dispatch.dispatch_date) }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Delivery</div>
        <div class="info-value">Exp: {{ fmtDate(dispatch.expected_delivery_date) }}</div>
        <div class="info-sub" v-if="dispatch.actual_delivery_date">Actual: {{ fmtDate(dispatch.actual_delivery_date) }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Tracking</div>
        <div class="info-value">{{ dispatch.tracking_number ?? '—' }}</div>
        <div class="info-sub">Allocation: <strong :class="isAllocated ? 'ok' : 'pend'">{{ isAllocated ? 'Allocated' : 'Not allocated' }}</strong></div>
      </div>
    </div>

    <!-- Delivery address + editable fields -->
    <div class="card edit-card" v-if="dispatch.status === 'pending'">
      <div class="edit-grid">
        <div class="form-group full">
          <label>Delivery Address</label>
          <textarea v-model="editForm.customer_address" rows="2"></textarea>
        </div>
        <div class="form-group">
          <label>Expected Delivery</label>
          <input v-model="editForm.expected_delivery_date" type="date" />
        </div>
        <div class="form-group">
          <label>Tracking Number</label>
          <input v-model="editForm.tracking_number" />
        </div>
        <div class="form-group full">
          <label>Notes</label>
          <input v-model="editForm.notes" />
        </div>
      </div>
      <button class="btn btn-save" :disabled="saving" @click="saveEdits">{{ saving ? 'Saving…' : 'Save Changes' }}</button>
    </div>
    <div class="card addr-display" v-else-if="dispatch.customer_address">
      <span class="addr-label">Delivery Address:</span> {{ dispatch.customer_address }}
    </div>

    <!-- Items -->
    <div class="card">
      <h3>Dispatch Items</h3>
      <table class="items-table">
        <thead>
          <tr>
            <th>Panel Type</th>
            <th class="text-right">Quantity (SQM)</th>
            <th class="text-right">Rate (₹/SQM)</th>
            <th class="text-right">Amount (₹)</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in dispatch.items" :key="item.id">
            <td class="bold">{{ item.panel_type?.name ?? 'Panel #' + item.panel_type_id }}</td>
            <td class="text-right">{{ fmtQty(item.quantity) }}</td>
            <td class="text-right">{{ fmtNum(item.unit_price) }}</td>
            <td class="text-right bold blue">{{ fmtNum(item.amount) }}</td>
          </tr>
          <tr class="total-row">
            <td class="bold">Total</td>
            <td class="text-right bold">{{ fmtQty(totalQty) }}</td>
            <td></td>
            <td class="text-right bold blue">₹ {{ fmtNum(totalAmount) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Allocations -->
    <div class="card" v-if="dispatch.allocations?.length">
      <h3>Stock Allocations</h3>
      <table class="items-table">
        <thead>
          <tr><th>Allocation</th><th class="text-right">Qty Allocated</th><th>Status</th><th>Allocated At</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in dispatch.allocations" :key="a.id">
            <td class="mono">#{{ a.id }}</td>
            <td class="text-right bold">{{ fmtQty(a.quantity_allocated) }}</td>
            <td><span :class="['alloc-badge', a.status]">{{ a.status }}</span></td>
            <td>{{ a.allocated_at ? fmtDateTime(a.allocated_at) : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Complete modal -->
    <div v-if="showComplete" class="modal-overlay" @click.self="showComplete = false">
      <div class="modal-box">
        <h3>Mark as Delivered</h3>
        <p v-if="!isAllocated" class="warn-text">⚠ Stock is not allocated yet. Allocate stock before completing.</p>
        <div class="form-group">
          <label>Actual Delivery Date</label>
          <input v-model="completeDate" type="date" />
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showComplete = false">Cancel</button>
          <button class="btn btn-complete" :disabled="actionLoading || !isAllocated" @click="doComplete">
            {{ actionLoading ? 'Completing…' : 'Confirm Delivery' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Cancel modal -->
    <div v-if="showCancel" class="modal-overlay" @click.self="showCancel = false">
      <div class="modal-box">
        <h3>Cancel Dispatch?</h3>
        <p>This releases any allocated stock and reverts the batch to QC-passed.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showCancel = false">Back</button>
          <button class="btn btn-danger" :disabled="actionLoading" @click="doCancel">
            {{ actionLoading ? 'Cancelling…' : 'Cancel Dispatch' }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="loading" class="loading-state">Loading dispatch…</div>
  <div v-else-if="loadError" class="error-banner">{{ loadError }}</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import dispatchService from '../services/dispatchService.js'

const props = defineProps({ dispatchId: { type: Number, required: true } })
const emit  = defineEmits(['back', 'view-batch'])

const pdfLoading = ref(false)

const dispatch    = ref(null)
const loading     = ref(false)
const loadError   = ref(null)
const saving      = ref(false)
const actionLoading = ref(false)
const actionError   = ref(null)
const actionSuccess = ref(null)
const showComplete  = ref(false)
const showCancel    = ref(false)
const completeDate  = ref(new Date().toISOString().slice(0, 10))

const editForm = reactive({ customer_address: '', expected_delivery_date: '', tracking_number: '', notes: '' })

const isAllocated = computed(() => {
  const items  = dispatch.value?.items?.length ?? 0
  const allocs = (dispatch.value?.allocations ?? []).filter(a => a.status !== 'released').length
  return items > 0 && allocs >= items
})
const totalQty    = computed(() => (dispatch.value?.items ?? []).reduce((s, i) => s + Number(i.quantity || 0), 0))
const totalAmount = computed(() => (dispatch.value?.items ?? []).reduce((s, i) => s + Number(i.amount || 0), 0))

async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res = await dispatchService.get(props.dispatchId)
    dispatch.value = res?.data ?? res
    editForm.customer_address       = dispatch.value.customer_address ?? ''
    editForm.expected_delivery_date = dispatch.value.expected_delivery_date?.slice(0, 10) ?? ''
    editForm.tracking_number        = dispatch.value.tracking_number ?? ''
    editForm.notes                  = dispatch.value.notes ?? ''
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? 'Failed to load dispatch.'
  } finally {
    loading.value = false
  }
}

async function saveEdits() {
  saving.value = true; actionError.value = null
  try {
    await dispatchService.update(props.dispatchId, {
      customer_address:       editForm.customer_address || null,
      expected_delivery_date: editForm.expected_delivery_date || null,
      tracking_number:        editForm.tracking_number || null,
      notes:                  editForm.notes || null,
    })
    actionSuccess.value = 'Dispatch updated.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to update.'
  } finally { saving.value = false }
}

async function doAllocate() {
  actionLoading.value = true; actionError.value = null; actionSuccess.value = null
  try {
    await dispatchService.allocate(props.dispatchId)
    actionSuccess.value = 'Stock allocated.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to allocate stock.'
  } finally { actionLoading.value = false }
}

async function doComplete() {
  actionLoading.value = true; actionError.value = null
  try {
    await dispatchService.complete(props.dispatchId, { actual_delivery_date: completeDate.value })
    showComplete.value = false
    actionSuccess.value = 'Dispatch marked delivered.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to complete.'
  } finally { actionLoading.value = false }
}

async function doCancel() {
  actionLoading.value = true; actionError.value = null
  try {
    await dispatchService.cancel(props.dispatchId)
    showCancel.value = false
    actionSuccess.value = 'Dispatch cancelled.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to cancel.'
  } finally { actionLoading.value = false }
}

async function openChallan() {
  pdfLoading.value = true
  actionError.value = null
  try {
    await dispatchService.openChallanPdf(props.dispatchId)
  } catch {
    actionError.value = 'Failed to open challan PDF.'
  } finally { pdfLoading.value = false }
}

async function downloadChallan() {
  pdfLoading.value = true
  actionError.value = null
  try {
    await dispatchService.downloadChallanPdf(props.dispatchId, dispatch.value?.dispatch_no)
  } catch {
    actionError.value = 'Failed to download challan PDF.'
  } finally { pdfLoading.value = false }
}

function statusLabel(s) { return { pending: 'Pending', delivered: 'Delivered', cancelled: 'Cancelled' }[s] ?? s }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
function fmtDateTime(d) { return d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—' }
function fmtQty(n) { return Number(n || 0).toFixed(2) }
function fmtNum(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

onMounted(load)
</script>

<style scoped>
.dd-wrap { font-family: inherit; max-width: 1000px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
.dd-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.dd-title { display: flex; align-items: center; gap: 8px; flex: 1; }
.dispatch-no { font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace; letter-spacing: 1px; }
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
.ok   { color: #2e7d32; }
.pend { color: #e65100; }

.edit-card { }
.edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; }
.form-group input, .form-group textarea { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.addr-display { font-size: 13px; color: #555; }
.addr-label { font-weight: 700; color: #888; text-transform: uppercase; font-size: 11px; margin-right: 6px; }

.items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.items-table th { background: var(--primary-tint); color: #333; padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.items-table td { padding: 8px 10px; border: 1px solid #e0e0e0; }
.total-row td { background: var(--surface-2); font-weight: 700; }
.text-right { text-align: right; }
.blue { color: var(--primary); }

.alloc-badge { display: inline-block; padding: 2px 9px; border-radius: 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.alloc-badge.allocated { background: #fff8e1; color: #f57f17; }
.alloc-badge.used      { background: #e8f5e9; color: #2e7d32; }
.alloc-badge.released  { background: #fafafa; color: #aaa; }

.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.pending   { background: #fff8e1; color: #f57f17; }
.status-badge.delivered { background: #e8f5e9; color: #2e7d32; }
.status-badge.cancelled { background: #fafafa; color: #aaa; border: 1px solid #e0e0e0; }

.btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-save { background: var(--primary); color: white; }
.btn-allocate { background: #fff3e0; color: #e65100; }
.btn-complete { background: var(--primary); color: white; }
.btn-cancel { background: #ffebee; color: #c62828; }
.btn-challan { background: #e0f2f1; color: #00695c; }
.btn-danger { background: #c62828; color: white; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.loading-state { text-align: center; padding: 60px; color: #888; }
.warn-text { color: #c62828; font-size: 13px; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box { background: white; border-radius: 10px; padding: 26px 30px; min-width: 360px; max-width: 440px; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.modal-box h3 { margin: 0 0 10px; color: var(--primary); }
.modal-box p { color: #555; font-size: 14px; margin-bottom: 14px; line-height: 1.5; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
</style>
