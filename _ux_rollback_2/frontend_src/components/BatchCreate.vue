<template>
  <div class="modal-overlay" @click.self="$emit('cancel')">
    <div class="modal-box">
      <div class="modal-header">
        <h2>New Production Batch</h2>
        <button class="btn-close" @click="$emit('cancel')">✕</button>
      </div>

      <div v-if="loadingOrders" class="loading-state">Loading orders…</div>

      <form v-else @submit.prevent="submit">
        <!-- Order selection -->
        <div class="form-group">
          <label>Order <span class="req">*</span></label>
          <select v-model="form.order_id" required @change="onOrderChange">
            <option value="">— Select an order —</option>
            <optgroup label="Pending">
              <option v-for="o in pendingOrders" :key="o.id" :value="o.id">
                {{ o.order_no }} — {{ o.customer?.name }} ({{ fmtSqm(o.total_sqm) }} SQM)
              </option>
            </optgroup>
            <optgroup label="In Production">
              <option v-for="o in inProdOrders" :key="o.id" :value="o.id">
                {{ o.order_no }} — {{ o.customer?.name }} ({{ fmtSqm(o.total_sqm) }} SQM)
              </option>
            </optgroup>
          </select>
        </div>

        <!-- Order summary card -->
        <div class="order-summary" v-if="selectedOrder">
          <div class="summary-row">
            <span class="summary-label">Project</span>
            <span>{{ selectedOrder.project_name || '—' }} {{ selectedOrder.project_location ? '/ ' + selectedOrder.project_location : '' }}</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Total SQM</span>
            <span class="bold">{{ fmtSqm(selectedOrder.total_sqm) }} SQM</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Delivery</span>
            <span :class="{ overdue: isOverdue(selectedOrder.expected_delivery_date) }">
              {{ fmtDate(selectedOrder.expected_delivery_date) }}
            </span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Panel Rows</span>
            <span>{{ selectedOrder.items?.length ?? 0 }} rows</span>
          </div>
          <!-- Panel spec preview -->
          <div class="items-preview" v-if="selectedOrder.items?.length">
            <div v-for="(item, i) in selectedOrder.items" :key="i" class="item-chip">
              <span class="chip-num">{{ i + 1 }}</span>
              {{ item.panel_type?.name }} | {{ item.thickness }}mm {{ item.density_type }} | {{ fmtSqm(item.total_sqm) }} SQM
            </div>
          </div>
        </div>

        <!-- Planned quantity -->
        <div class="form-group">
          <label>Planned Quantity (SQM)</label>
          <input
            v-model.number="form.planned_quantity"
            type="number"
            min="0.1"
            step="0.01"
            placeholder="Auto-filled from order total SQM"
          />
          <span class="hint">Leave blank to use full order SQM ({{ selectedOrder ? fmtSqm(selectedOrder.total_sqm) : '—' }})</span>
        </div>

        <!-- Notes -->
        <div class="form-group">
          <label>Notes</label>
          <textarea v-model="form.notes" rows="2" placeholder="Any special instructions for this batch…"></textarea>
        </div>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" @click="$emit('cancel')">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="!form.order_id || saving">
            {{ saving ? 'Creating…' : 'Create Batch' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import batchService from '../services/batchService.js'

const emit = defineEmits(['created', 'cancel'])

const orders        = ref([])
const loadingOrders = ref(false)
const saving        = ref(false)
const error         = ref(null)

const form = reactive({ order_id: '', planned_quantity: null, notes: '' })

const pendingOrders = computed(() => orders.value.filter(o => o.status === 'pending'))
const inProdOrders  = computed(() => orders.value.filter(o => o.status === 'in_production'))
const selectedOrder = computed(() => orders.value.find(o => o.id === form.order_id) ?? null)

function onOrderChange() {
  // Auto-fill planned quantity from order total_sqm
  if (selectedOrder.value) {
    form.planned_quantity = selectedOrder.value.total_sqm
      ? Number(selectedOrder.value.total_sqm)
      : null
  }
}

async function submit() {
  if (!form.order_id) return
  saving.value = true
  error.value  = null
  try {
    const payload = {
      planned_quantity: form.planned_quantity || null,
      notes: form.notes || null,
    }
    const res   = await batchService.createFromOrder(form.order_id, payload)
    const batch = res?.data ?? res
    emit('created', batch?.id ?? batch)
  } catch (e) {
    error.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Failed to create batch.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loadingOrders.value = true
  try {
    const res     = await batchService.orders({ status: '', per_page: 100 })
    const all     = res?.data?.data ?? res?.data ?? []
    // Only pending or in_production orders can have new batches
    orders.value  = all.filter(o => ['pending', 'in_production'].includes(o.status))
  } catch {
    error.value = 'Failed to load orders.'
  } finally {
    loadingOrders.value = false
  }
})

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtSqm(n) { return Number(n || 0).toFixed(2) }
function isOverdue(d) { return d && new Date(d) < new Date() }
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box     { background: white; border-radius: 12px; padding: 28px 32px; width: 100%; max-width: 560px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }

.modal-header  { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.modal-header h2 { margin: 0; font-size: 18px; color: var(--primary); }
.btn-close     { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.btn-close:hover { color: #333; background: #f0f0f0; }

.form-group    { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.4px; }
.form-group select, .form-group input, .form-group textarea {
  padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; width: 100%; box-sizing: border-box;
}
.form-group select:focus, .form-group input:focus, .form-group textarea:focus {
  outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint);
}
.form-group textarea { resize: vertical; }
.hint { font-size: 11px; color: #aaa; margin-top: 2px; }
.req  { color: #c62828; }

/* Order summary */
.order-summary { background: var(--surface-2); border: 1px solid var(--primary-bd); border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
.summary-row   { display: flex; justify-content: space-between; align-items: baseline; padding: 3px 0; font-size: 13px; }
.summary-label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; min-width: 90px; }
.bold          { font-weight: 700; color: var(--primary); }
.overdue       { color: #c62828; font-weight: 700; }

.items-preview { margin-top: 10px; display: flex; flex-direction: column; gap: 4px; }
.item-chip     { display: flex; align-items: center; gap: 8px; background: white; border: 1px solid #e0e0e0; border-radius: 6px; padding: 5px 10px; font-size: 12px; color: #555; }
.chip-num      { background: var(--primary); color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; }

.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }

.modal-footer  { display: flex; gap: 10px; justify-content: flex-end; padding-top: 8px; border-top: 1px solid #f0f0f0; margin-top: 8px; }

.btn         { padding: 8px 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost   { background: transparent; border: 1px solid #ddd; color: #555; }

.loading-state { text-align: center; padding: 30px; color: #888; }
</style>
