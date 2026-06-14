<template>
  <div class="od-wrap" v-if="order">
    <!-- Toolbar -->
    <div class="od-toolbar">
      <button class="btn btn-ghost" @click="$emit('back')">← Back to Orders</button>
      <div class="od-title">
        <span class="order-no">{{ order.order_no }}</span>
        <span :class="['status-badge', order.status]">{{ statusLabel(order.status) }}</span>
      </div>
      <div class="action-bar">
        <!-- Status transitions -->
        <button
          v-if="order.status === 'pending'"
          class="btn btn-start"
          :disabled="actionLoading"
          @click="updateStatus('in_production')"
        >Start Production</button>
        <button
          v-if="order.status === 'in_production'"
          class="btn btn-complete"
          :disabled="actionLoading"
          @click="updateStatus('completed')"
        >Mark Completed</button>
        <button
          v-if="['pending','in_production'].includes(order.status)"
          class="btn btn-cancel"
          :disabled="actionLoading"
          @click="confirmCancelVisible = true"
        >Cancel</button>
        <!-- View source quotation -->
        <button
          v-if="order.quotation"
          class="btn btn-ghost"
          @click="$emit('view-quotation', order.quotation_id)"
        >View Quotation</button>
      </div>
    </div>

    <div v-if="actionError"   class="error-banner">{{ actionError }}</div>
    <div v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</div>

    <!-- Info grid -->
    <div class="info-grid card">
      <div class="info-cell">
        <div class="info-label">Customer</div>
        <div class="info-value bold">{{ order.customer?.name }}</div>
        <div class="info-sub">{{ order.customer?.city }}, {{ order.customer?.state }}</div>
        <div class="info-sub" v-if="order.customer?.gstin">GSTIN: {{ order.customer.gstin }}</div>
        <div class="info-sub" v-if="order.customer?.phone">{{ order.customer.phone }}</div>
      </div>
      <div class="info-cell" v-if="order.project_name">
        <div class="info-label">Project</div>
        <div class="info-value bold">{{ order.project_name }}</div>
        <div class="info-sub">{{ order.project_location }}</div>
        <div class="info-sub">Quality: <strong>{{ order.quality_grade }}</strong></div>
      </div>
      <div class="info-cell">
        <div class="info-label">Dates</div>
        <div class="info-value">Order: {{ fmtDate(order.order_date) }}</div>
        <div class="info-sub" :class="{ overdue: isOverdue(order.expected_delivery_date) }">
          Delivery: {{ fmtDate(order.expected_delivery_date) }}
          <span v-if="isOverdue(order.expected_delivery_date)" class="overdue-tag">Overdue</span>
        </div>
      </div>
      <div class="info-cell">
        <div class="info-label">Source</div>
        <div class="info-value mono">{{ order.quotation?.quotation_no ?? '—' }}</div>
        <div class="info-sub">GST: {{ order.is_inter_state ? 'IGST (Inter-state)' : 'CGST + SGST' }}</div>
        <div class="info-sub">Total SQM: <strong>{{ fmtSqm(order.total_sqm) }}</strong></div>
      </div>
    </div>

    <!-- Edit delivery date + notes inline -->
    <div class="card edit-card">
      <div class="edit-row">
        <div class="form-group">
          <label>Expected Delivery Date</label>
          <input v-model="editForm.expected_delivery_date" type="date" />
        </div>
        <div class="form-group notes-group">
          <label>Notes</label>
          <input v-model="editForm.notes" placeholder="Internal notes…" />
        </div>
        <button class="btn btn-save" :disabled="saving" @click="saveEdits">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>

    <!-- Panel BOQ -->
    <div class="card">
      <h3>Panel Specification — BOQ</h3>

      <div v-for="(item, ii) in order.items" :key="item.id" class="item-block">
        <div class="item-header">
          <span class="item-num">{{ ii + 1 }}</span>
          <div class="item-spec">
            <div class="spec-title">{{ item.panel_type?.name }}</div>
            <div class="spec-detail">
              {{ item.thickness }}mm &nbsp;|&nbsp;
              {{ item.density_type }} {{ item.density_kgm3 }} kg/m³ &nbsp;|&nbsp;
              TOP: {{ item.top_skin_thickness }}mm {{ item.top_skin_material }} {{ item.top_color }} ({{ item.top_surface }}) &nbsp;|&nbsp;
              BTM: {{ item.bottom_skin_thickness }}mm {{ item.bottom_skin_material }} {{ item.bottom_color }}
              <span v-if="item.guard_film"> &nbsp;| Guard Film</span>
              <span v-if="item.cello_tap"> &nbsp;| Cello Tap</span>
              &nbsp;| HSN: {{ item.hsn_code }}
            </div>
          </div>
          <div class="item-total">
            <div class="item-sqm">{{ fmtSqm(item.total_sqm) }} SQM</div>
            <div class="item-amt">₹ {{ fmtNum(item.amount) }}</div>
          </div>
        </div>

        <!-- Size breakdown -->
        <table class="size-table" v-if="item.sizes?.length > 0">
          <thead>
            <tr>
              <th>Length (mm)</th>
              <th class="text-center">Width (mm)</th>
              <th class="text-center">NOS</th>
              <th class="text-center">SQM</th>
              <th class="text-right">Rate (₹/SQM)</th>
              <th class="text-right">Amount (₹)</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sz in item.sizes" :key="sz.id">
              <td class="bold">
                {{ sz.length_mm }}
                <span v-if="sz.length_mm < 2000" class="warn-tag">⚠ DL</span>
              </td>
              <td class="text-center">{{ sz.width_mm }}</td>
              <td class="text-center bold">{{ sz.nos }}</td>
              <td class="text-center bold">{{ Number(sz.sqm).toFixed(3) }}</td>
              <td class="text-right">{{ fmtNum(sz.rate_per_sqm) }}</td>
              <td class="text-right bold blue">{{ fmtNum(sz.amount) }}</td>
            </tr>
            <tr class="size-total-row">
              <td colspan="2" class="text-right bold">Total</td>
              <td class="text-center bold">{{ item.sizes.reduce((s, z) => s + z.nos, 0) }}</td>
              <td class="text-center bold">{{ fmtSqm(item.total_sqm) }}</td>
              <td></td>
              <td class="text-right bold blue">{{ fmtNum(item.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Financial Summary -->
    <div class="totals-card card">
      <h3>Financial Summary</h3>
      <div class="totals-grid">
        <div class="t-row"><span>Subtotal</span><span>₹ {{ fmtNum(order.subtotal) }}</span></div>
        <div class="t-row discount" v-if="order.discount_amount > 0">
          <span>Discount</span><span>− ₹ {{ fmtNum(order.discount_amount) }}</span>
        </div>
        <div class="t-row border-top"><span>Taxable Amount</span><span>₹ {{ fmtNum(order.taxable_amount) }}</span></div>
        <template v-if="order.is_inter_state">
          <div class="t-row"><span>IGST @ 18%</span><span>₹ {{ fmtNum(order.igst_amount) }}</span></div>
        </template>
        <template v-else>
          <div class="t-row"><span>CGST @ 9%</span><span>₹ {{ fmtNum(order.cgst_amount) }}</span></div>
          <div class="t-row"><span>SGST @ 9%</span><span>₹ {{ fmtNum(order.sgst_amount) }}</span></div>
        </template>
        <div class="t-row" v-if="order.transport_fixed && order.transport_amount > 0">
          <span>Transportation</span><span>₹ {{ fmtNum(order.transport_amount) }}</span>
        </div>
        <div class="t-row" v-else>
          <span>Transportation</span><span class="text-muted">Extra as Actual</span>
        </div>
        <div class="t-row grand"><span>GRAND TOTAL</span><span>₹ {{ fmtNum(order.total_amount) }}</span></div>
        <div class="t-row"><span>Advance ({{ order.advance_pct }}%)</span><span>₹ {{ fmtNum(order.advance_amount) }}</span></div>
        <div class="t-row balance"><span>Balance Due</span><span>₹ {{ fmtNum(order.balance_amount) }}</span></div>
        <div class="t-row"><span>Total SQM</span><span>{{ fmtSqm(order.total_sqm) }} SQM</span></div>
      </div>
    </div>

    <!-- Production Batches -->
    <div class="card" v-if="order.batches?.length > 0">
      <h3>Production Batches</h3>
      <table class="batch-table">
        <thead>
          <tr>
            <th>Batch No</th>
            <th>Status</th>
            <th class="text-right">Planned Qty</th>
            <th class="text-right">Completed Qty</th>
            <th>Started</th>
            <th>Completed</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="b in order.batches"
            :key="b.id"
            class="clickable"
            @click="$emit('view-batch', b.id)"
          >
            <td class="mono bold">{{ b.batch_no }}</td>
            <td><span :class="['status-badge', b.status]">{{ b.status }}</span></td>
            <td class="text-right">{{ b.planned_quantity }}</td>
            <td class="text-right">{{ b.completed_quantity ?? '—' }}</td>
            <td>{{ b.started_at ? fmtDate(b.started_at) : '—' }}</td>
            <td>{{ b.completed_at ? fmtDate(b.completed_at) : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="card no-batches" v-else-if="order.status !== 'cancelled'">
      <h3>Production Batches</h3>
      <div class="empty-hint">No production batches created yet for this order.</div>
    </div>

    <!-- Cancel confirm modal -->
    <div v-if="confirmCancelVisible" class="modal-overlay" @click.self="confirmCancelVisible = false">
      <div class="modal-box">
        <h3>Cancel Order?</h3>
        <p>This will mark <strong>{{ order.order_no }}</strong> as cancelled. This cannot be undone.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="confirmCancelVisible = false">Back</button>
          <button class="btn btn-danger" :disabled="actionLoading" @click="updateStatus('cancelled')">
            {{ actionLoading ? 'Cancelling…' : 'Cancel Order' }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="loading" class="loading-state">Loading order…</div>
  <div v-else-if="loadError" class="error-banner">{{ loadError }}</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import orderService from '../services/orderService.js'

const props = defineProps({ orderId: { type: Number, required: true } })
const emit  = defineEmits(['back', 'view-quotation', 'view-batch'])

const order   = ref(null)
const loading = ref(false)
const loadError = ref(null)
const saving  = ref(false)
const actionLoading = ref(false)
const actionError   = ref(null)
const actionSuccess = ref(null)
const confirmCancelVisible = ref(false)

const editForm = reactive({ expected_delivery_date: '', notes: '' })

async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res   = await orderService.get(props.orderId)
    order.value = res?.data ?? res
    editForm.expected_delivery_date = order.value.expected_delivery_date?.slice(0, 10) ?? ''
    editForm.notes                  = order.value.notes ?? ''
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? 'Failed to load order.'
  } finally {
    loading.value = false
  }
}

async function saveEdits() {
  saving.value      = true
  actionError.value = null
  try {
    await orderService.update(props.orderId, {
      expected_delivery_date: editForm.expected_delivery_date || null,
      notes:                  editForm.notes || null,
    })
    actionSuccess.value = 'Order updated.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to update.'
  } finally {
    saving.value = false
  }
}

async function updateStatus(status) {
  actionLoading.value       = true
  actionError.value         = null
  actionSuccess.value       = null
  confirmCancelVisible.value = false
  try {
    await orderService.update(props.orderId, { status })
    actionSuccess.value = `Order marked as ${statusLabel(status)}.`
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to update status.'
  } finally {
    actionLoading.value = false
  }
}

function statusLabel(s) {
  return { pending: 'Pending', in_production: 'In Production', completed: 'Completed', cancelled: 'Cancelled' }[s] ?? s
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtNum(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtSqm(n) { return Number(n || 0).toFixed(2) }
function isOverdue(d) { return d && new Date(d) < new Date() }

onMounted(load)
</script>

<style scoped>
.od-wrap { font-family: inherit; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }

.od-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.od-title   { display: flex; align-items: center; gap: 8px; flex: 1; }
.order-no   { font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace; letter-spacing: 1px; }
.action-bar { display: flex; gap: 6px; flex-wrap: wrap; }

.error-banner   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; }

.card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px 24px; }
.card h3 { margin: 0 0 14px; font-size: 15px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }

/* Info grid */
.info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.info-cell { padding: 4px; }
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 4px; }
.info-value { font-size: 14px; color: #222; }
.info-value.bold { font-weight: 700; }
.info-sub   { font-size: 11px; color: #888; margin-top: 2px; }
.overdue    { color: #c62828 !important; }
.overdue-tag { font-size: 10px; font-weight: 700; background: #ffebee; color: #c62828; border-radius: 8px; padding: 1px 6px; margin-left: 4px; }

/* Edit card */
.edit-card { padding: 14px 20px; }
.edit-row  { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; }
.form-group input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.notes-group { flex: 1; min-width: 200px; }

/* Item blocks */
.item-block  { border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; background: #fafafe; }
.item-header { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 10px; }
.item-num    { background: var(--primary); color: white; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.item-spec   { flex: 1; }
.spec-title  { font-weight: 700; font-size: 14px; color: var(--primary); }
.spec-detail { font-size: 11px; color: #666; margin-top: 4px; line-height: 1.7; }
.item-total  { text-align: right; flex-shrink: 0; }
.item-sqm    { font-size: 12px; color: #888; }
.item-amt    { font-size: 16px; font-weight: 700; color: var(--primary); }

/* Size table */
.size-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.size-table th { background: var(--primary-tint); color: #333; padding: 5px 8px; border: 1px solid var(--primary-bd); font-size: 10px; text-transform: uppercase; }
.size-table td { padding: 5px 8px; border: 1px solid #e0e0e0; }
.size-total-row td { background: var(--primary-tint) !important; font-weight: 700; }
.warn-tag { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; border-radius: 3px; padding: 1px 4px; font-size: 9px; font-weight: 700; margin-left: 4px; }
.text-right  { text-align: right; }
.text-center { text-align: center; }
.bold { font-weight: 700; }
.blue { color: var(--primary); }
.mono { font-family: monospace; letter-spacing: 0.5px; }

/* Totals */
.totals-card  { max-width: 480px; margin-left: auto; }
.totals-grid  { display: flex; flex-direction: column; }
.t-row        { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.t-row.border-top { border-top: 2px solid var(--primary); margin-top: 4px; padding-top: 9px; font-weight: 600; }
.t-row.discount   { color: #c62828; }
.t-row.grand      { background: var(--primary); color: white; font-size: 15px; font-weight: 700; padding: 10px 8px; border-radius: 6px; margin: 6px 0; }
.t-row.balance    { color: var(--primary); font-weight: 700; }
.text-muted   { color: #aaa; }

/* Batch table */
.batch-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.batch-table th { background: var(--primary-tint); color: #333; padding: 7px 10px; text-align: left; font-size: 11px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.batch-table td { padding: 8px 10px; border: 1px solid #e0e0e0; }
.batch-table tr.clickable { cursor: pointer; }
.batch-table tr.clickable:hover td { background: var(--surface-2); }
.no-batches .empty-hint { text-align: center; color: #aaa; font-style: italic; padding: 20px; border: 2px dashed #e0e0e0; border-radius: 8px; }

/* Status badges */
.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.pending       { background: #fff8e1; color: #f57f17; }
.status-badge.in_production { background: #e8f5e9; color: #2e7d32; }
.status-badge.completed     { background: var(--primary-tint); color: var(--primary); }
.status-badge.cancelled     { background: #fafafa; color: #aaa; border: 1px solid #e0e0e0; }
.status-badge.draft         { background: var(--primary-tint); color: var(--primary); }
.status-badge.qc_pending    { background: #fff3e0; color: #e65100; }
.status-badge.qc_passed     { background: #e8f5e9; color: #2e7d32; }
.status-badge.qc_failed     { background: #ffebee; color: #c62828; }

/* Buttons */
.btn          { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost    { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-save     { background: var(--primary); color: white; }
.btn-start    { background: #e8f5e9; color: #2e7d32; }
.btn-complete { background: var(--primary); color: white; }
.btn-cancel   { background: #ffebee; color: #c62828; }
.btn-danger   { background: #c62828; color: white; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.loading-state { text-align: center; padding: 60px; color: #888; font-size: 14px; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box     { background: white; border-radius: 10px; padding: 28px 32px; min-width: 360px; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.modal-box h3  { margin: 0 0 10px; color: #c62828; }
.modal-box p   { color: #555; font-size: 14px; margin-bottom: 20px; line-height: 1.6; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
</style>
