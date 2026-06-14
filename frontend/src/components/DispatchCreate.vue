<template>
  <div class="modal-overlay" @click.self="$emit('cancel')">
    <div class="modal-box">
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
          <button type="submit" class="btn btn-primary" :disabled="!form.batch_id || saving">
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
  notes: '',
  auto_allocate: false,
})

const selectedBatch = computed(() => batches.value.find(b => b.id === form.batch_id) ?? null)

function onBatchChange() {
  // Prefill address from customer if available
  const c = selectedBatch.value?.order?.customer
  if (c && !form.customer_address) {
    form.customer_address = [c.address_line1, c.city, c.state, c.pincode].filter(Boolean).join(', ')
  }
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
      notes:                  form.notes || null,
      auto_allocate:          form.auto_allocate,
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
</style>
