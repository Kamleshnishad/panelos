<template>
  <div class="modal-overlay" @click.self="$emit('cancel')">
    <div class="modal-box" :class="{ wide: plan && plan.items.length }">
      <div class="modal-header">
        <h2>New Dispatch</h2>
        <button class="btn-close" @click="$emit('cancel')">✕</button>
      </div>

      <div v-if="loadingBatches" class="loading-state">Loading dispatch-ready batches…</div>

      <form v-else @submit.prevent="submit">
        <!-- Batch selection -->
        <div class="form-group">
          <label>Batch <span class="req">*</span></label>
          <select v-model="form.batch_id" required @change="onBatchChange">
            <option value="">— Select a QC-passed / completed batch —</option>
            <option v-for="b in batches" :key="b.id" :value="b.id">
              {{ b.batch_no }} — {{ b.order?.customer?.name ?? 'Order ' + b.order_id }} ({{ b.status }})
            </option>
          </select>
          <span class="hint" v-if="batches.length === 0">No batches are ready for dispatch. Complete QC on a batch first.</span>
        </div>

        <!-- Batch summary -->
        <div class="batch-summary" v-if="selectedBatch">
          <div class="summary-row">
            <span class="summary-label">Order</span>
            <span class="mono">{{ selectedBatch.order?.order_no ?? '—' }}</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Customer</span>
            <span>{{ selectedBatch.order?.customer?.name ?? '—' }}</span>
          </div>
          <div class="summary-row" v-if="selectedBatch.order?.project_name">
            <span class="summary-label">Project</span>
            <span>{{ selectedBatch.order.project_name }}</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Completed Qty</span>
            <span class="bold">{{ fmtQty(selectedBatch.completed_quantity ?? selectedBatch.planned_quantity) }} SQM</span>
          </div>
        </div>

        <!-- What goes on this load (partial dispatch): pieces of each size row, defaulting to everything still to send -->
        <div class="plan" v-if="plan && plan.items.length">
          <div class="plan-head">
            <b>What goes on this load</b>
            <span class="plan-note">{{ fmtQty(plan.remaining_sqm) }} SQM still to send of {{ fmtQty(plan.ordered_sqm) }}</span>
          </div>
          <div v-if="plan.fully_dispatched" class="hint">Everything on this batch is already on a dispatch.</div>
          <template v-else>
            <table class="plan-table">
              <thead>
                <tr><th>Item / size</th><th class="num">Ordered</th><th class="num">Sent</th><th class="num">Left</th><th class="num">This load</th></tr>
              </thead>
              <tbody v-for="it in plan.items" :key="it.order_item_id">
                <tr class="plan-item">
                  <td colspan="5">{{ it.panel_type }} {{ it.application }} {{ it.thickness ? it.thickness + ' mm' : '' }}</td>
                </tr>
                <tr v-if="it.whole_line">
                  <td>Whole line ({{ fmtQty(it.ordered_sqm) }} SQM)</td>
                  <td class="num">—</td><td class="num">—</td>
                  <td class="num">{{ it.remaining_sqm > 0 ? 'all' : 0 }}</td>
                  <td class="num"><input type="checkbox" :disabled="it.remaining_sqm <= 0" v-model="sendWhole[it.order_item_id]" :aria-label="'Send ' + it.panel_type" /></td>
                </tr>
                <tr v-for="s in it.sizes" :key="s.order_item_size_id">
                  <td>{{ s.length_mm }} × {{ s.width_mm }} mm</td>
                  <td class="num">{{ s.ordered_nos }}</td>
                  <td class="num">{{ s.dispatched_nos }}</td>
                  <td class="num">{{ s.remaining_nos }}</td>
                  <td class="num">
                    <input type="number" min="0" :max="s.remaining_nos" step="1" :disabled="s.remaining_nos === 0"
                           v-model.number="send[s.order_item_size_id]" @blur="clamp(s)"
                           :aria-label="'Pieces of ' + s.length_mm + ' mm to send now'" />
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="plan-foot">
              <span>This load: <b>{{ loadPieces }}</b> pieces · <b>{{ fmtQty(loadSqm) }}</b> SQM</span>
              <span>
                <button type="button" class="link" @click="sendAll">Send everything left</button>
                <button type="button" class="link" @click="sendNone">Clear</button>
              </span>
            </div>
          </template>
        </div>
        <div v-else-if="planLoading" class="hint-info">Loading what is left to send…</div>

        <!-- Delivery details -->
        <div class="form-group">
          <label>Delivery Address</label>
          <textarea v-model="form.customer_address" rows="2" placeholder="Site / delivery address…"></textarea>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Expected Delivery Date</label>
            <input v-model="form.expected_delivery_date" type="date" />
          </div>
          <div class="form-group flex-1">
            <label>Tracking Number</label>
            <input v-model="form.tracking_number" placeholder="Transporter LR / tracking no." />
          </div>
        </div>

        <!-- Freight is settled here, not at quotation: the real cost depends on
             which vehicle is available and changes until the goods move. -->
        <div class="form-row">
          <div class="form-group flex-1">
            <label>Transporter</label>
            <input v-model="form.transporter_name" placeholder="e.g. Gujarat Roadlines" />
          </div>
          <div class="form-group flex-1">
            <label>Vehicle No.</label>
            <input v-model="form.vehicle_no" placeholder="e.g. GJ01AB1234" />
          </div>
          <div class="form-group flex-1">
            <label>Transport Charges (₹)</label>
            <input v-model.number="form.transport_amount" type="number" min="0" step="0.01" placeholder="0.00" />
            <small class="field-hint">Actual freight for this trip — billed on the invoice.</small>
          </div>
        </div>

        <div class="form-group">
          <label>Notes</label>
          <input v-model="form.notes" placeholder="Special handling instructions…" />
        </div>

        <label class="toggle-line">
          <input type="checkbox" v-model="form.auto_allocate" />
          Auto-allocate coil stock now (reserves stock immediately)
        </label>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" @click="$emit('cancel')">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="!form.batch_id || saving || planBlocks">
            {{ saving ? 'Creating…' : 'Create Dispatch' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import dispatchService from '../services/dispatchService.js'

const emit = defineEmits(['created', 'cancel'])

const batches        = ref([])
const loadingBatches = ref(false)
const saving         = ref(false)
const error          = ref(null)

const form = reactive({
  batch_id: '',
  customer_address: '',
  expected_delivery_date: '',
  tracking_number: '',
  transporter_name: '',
  vehicle_no: '',
  transport_amount: null,
  notes: '',
  auto_allocate: false,
})

const selectedBatch = computed(() => batches.value.find(b => b.id === form.batch_id) ?? null)

// Partial dispatch: what is left on the chosen batch, and how many pieces of each size row go on this load.
const plan        = ref(null)
const planLoading = ref(false)
const send        = reactive({})        // order_item_size_id -> pieces on this load
const sendWhole   = reactive({})        // order_item_id -> true (a line with no size rows can only go in one go)

const sizeRows = computed(() => (plan.value?.items ?? []).flatMap(i => i.sizes))
const loadPieces = computed(() => sizeRows.value.reduce((n, s) => n + Math.max(0, Number(send[s.order_item_size_id]) || 0), 0))
const loadSqm = computed(() => {
  const sized = sizeRows.value.reduce((q, s) => q + Math.max(0, Number(send[s.order_item_size_id]) || 0) * s.sqm_each, 0)
  const whole = (plan.value?.items ?? []).filter(i => i.whole_line && sendWhole[i.order_item_id]).reduce((q, i) => q + i.remaining_sqm, 0)
  return sized + whole
})
// Nothing to send, or the plan says the batch is already fully covered: do not offer an empty dispatch.
const planBlocks = computed(() => !!plan.value && plan.value.items.length > 0 && (plan.value.fully_dispatched || (loadPieces.value === 0 && !Object.values(sendWhole).some(Boolean))))

function sendAll() {
  for (const s of sizeRows.value) send[s.order_item_size_id] = s.remaining_nos
  for (const i of plan.value?.items ?? []) if (i.whole_line) sendWhole[i.order_item_id] = i.remaining_sqm > 0
}
function sendNone() {
  for (const s of sizeRows.value) send[s.order_item_size_id] = 0
  for (const i of plan.value?.items ?? []) sendWhole[i.order_item_id] = false
}
function clamp(s) {
  const n = Math.floor(Number(send[s.order_item_size_id]) || 0)
  send[s.order_item_size_id] = Math.min(s.remaining_nos, Math.max(0, n))
}
async function loadPlan() {
  plan.value = null
  if (!form.batch_id) return
  planLoading.value = true
  try {
    const res = await dispatchService.plan(form.batch_id)
    plan.value = res?.data ?? null
    sendAll()
  } catch {
    plan.value = null           // the batch is still dispatchable whole; the server defaults to everything left
  } finally {
    planLoading.value = false
  }
}

function onBatchChange() {
  loadPlan()
  // Prefill address from customer if available
  const c = selectedBatch.value?.order?.customer
  if (c && !form.customer_address) {
    form.customer_address = [c.address_line1, c.city, c.state, c.pincode].filter(Boolean).join(', ')
  }
}

function chosenItems() {
  const rows = sizeRows.value
    .filter(s => (Number(send[s.order_item_size_id]) || 0) > 0)
    .map(s => ({ order_item_size_id: s.order_item_size_id, nos: Math.min(s.remaining_nos, Math.floor(Number(send[s.order_item_size_id]))) }))
  const whole = (plan.value?.items ?? []).filter(i => i.whole_line && sendWhole[i.order_item_id]).map(i => ({ order_item_id: i.order_item_id }))
  return [...rows, ...whole]
}

async function submit() {
  if (!form.batch_id) return
  saving.value = true
  error.value  = null
  try {
    const res = await dispatchService.createFromBatch(form.batch_id, {
      customer_address:       form.customer_address || null,
      expected_delivery_date: form.expected_delivery_date || null,
      tracking_number:        form.tracking_number || null,
      transporter_name:       form.transporter_name || null,
      vehicle_no:             form.vehicle_no || null,
      transport_amount:       form.transport_amount || 0,
      notes:                  form.notes || null,
      auto_allocate:          form.auto_allocate,
      // Only when the plan is on screen; without it the server sends everything still to send.
      ...(plan.value?.items?.length ? { items: chosenItems() } : {}),
    })
    const dispatch = res?.data ?? res
    emit('created', dispatch?.id ?? dispatch)
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to create dispatch.'
  } finally {
    saving.value = false
  }
}

async function loadBatches() {
  loadingBatches.value = true
  try {
    // Fetch qc_passed and completed batches (two calls, merge)
    const [passed, completed] = await Promise.all([
      dispatchService.dispatchableBatches({ status: 'qc_passed', per_page: 100 }),
      dispatchService.dispatchableBatches({ status: 'completed',  per_page: 100 }),
    ])
    const a = passed?.data?.data    ?? passed?.data    ?? []
    const b = completed?.data?.data ?? completed?.data ?? []
    batches.value = [...a, ...b]
  } catch {
    error.value = 'Failed to load batches.'
  } finally {
    loadingBatches.value = false
  }
}

function fmtQty(n) { return Number(n || 0).toFixed(2) }

onMounted(loadBatches)
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box.wide { max-width: 760px; }
.modal-box     { background: white; border-radius: 12px; padding: 26px 30px; width: 100%; max-width: 560px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 92vh; overflow-y: auto; }
.modal-header  { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.modal-header h2 { margin: 0; font-size: 18px; color: var(--primary); }
.btn-close     { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.btn-close:hover { color: #333; background: #f0f0f0; }

.form-group  { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.4px; }
.form-group select, .form-group input, .form-group textarea {
  padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; width: 100%; box-sizing: border-box;
}
.form-group textarea { resize: vertical; }
.form-group select:focus, .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.form-row { display: flex; gap: 12px; }
.flex-1 { flex: 1; }
.hint { font-size: 11px; color: #c62828; margin-top: 2px; }
.req  { color: #c62828; }

.batch-summary { background: var(--surface-2); border: 1px solid var(--primary-bd); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
.summary-row   { display: flex; justify-content: space-between; align-items: baseline; padding: 3px 0; font-size: 13px; }
.summary-label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; min-width: 110px; }
.mono { font-family: monospace; }
.bold { font-weight: 700; color: var(--primary); }

.toggle-line { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #444; cursor: pointer; margin-bottom: 14px; }
.toggle-line input { width: 15px; height: 15px; cursor: pointer; }

.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.modal-footer { display: flex; gap: 10px; justify-content: flex-end; padding-top: 10px; border-top: 1px solid #f0f0f0; margin-top: 6px; }
.btn { padding: 8px 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.loading-state { text-align: center; padding: 30px; color: #888; }
.field-hint { display: block; font-size: 11px; color: #888; margin-top: 3px; }

.plan { border: 1px solid var(--primary-bd); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
.plan-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 8px; font-size: 13px; }
.plan-note { font-size: 12px; color: #666; }
.plan-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.plan-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #666; padding: 4px 6px; border-bottom: 1px solid #ddd; }
.plan-table td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; }
.plan-table .num { text-align: right; }
.plan-table .plan-item td { background: var(--surface-2); font-weight: 700; font-size: 12px; }
.plan-table input[type=number] { width: 84px; padding: 4px 6px; text-align: right; border: 1px solid #ddd; border-radius: 6px; }
.plan-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 10px; font-size: 13px; flex-wrap: wrap; }
.link { background: none; border: none; color: var(--primary); cursor: pointer; font-size: 13px; padding: 0 6px; text-decoration: underline; }
.hint-info { font-size: 12px; color: #666; margin-bottom: 12px; }
</style>
