<template>
  <div class="modal-overlay" @click.self="$emit('cancel')">
    <div class="modal-box">
      <div class="modal-header">
        <h2>New Invoice</h2>
        <button class="btn-close" @click="$emit('cancel')">✕</button>
      </div>

      <!-- Source toggle -->
      <div class="source-toggle">
        <button :class="['src-btn', { active: source === 'dispatch' }]" @click="setSource('dispatch')">From Dispatch</button>
        <button :class="['src-btn', { active: source === 'order' }]"    @click="setSource('order')">From Order</button>
      </div>

      <form @submit.prevent="submit">
        <!-- Dispatch picker -->
        <div class="form-group" v-if="source === 'dispatch'">
          <label>Dispatch <span class="req">*</span></label>
          <select v-model="form.dispatch_id" required>
            <option value="">— Select a dispatch —</option>
            <option v-for="d in dispatches" :key="d.id" :value="d.id">
              {{ d.dispatch_no }} — {{ d.batch?.order?.customer?.name ?? 'Batch ' + d.batch_id }} ({{ d.status }})
            </option>
          </select>
          <span class="hint" v-if="!loadingSrc && dispatches.length === 0">No dispatches available.</span>
        </div>

        <!-- Order picker -->
        <div class="form-group" v-else>
          <label>Order <span class="req">*</span></label>
          <select v-model="form.order_id" required>
            <option value="">— Select an order —</option>
            <option v-for="o in orders" :key="o.id" :value="o.id">
              {{ o.order_no }} — {{ o.customer?.name ?? 'Customer ' + o.customer_id }} (₹ {{ fmtNum(o.total_amount) }})
            </option>
          </select>
          <span class="hint" v-if="!loadingSrc && orders.length === 0">No orders available.</span>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Invoice Date</label>
            <input v-model="form.invoice_date" type="date" />
          </div>
          <div class="form-group">
            <label>Due Date</label>
            <input v-model="form.due_date" type="date" />
          </div>
        </div>

        <div class="form-group">
          <label>Notes</label>
          <input v-model="form.notes" placeholder="Invoice notes…" />
        </div>
        <div class="form-group">
          <label>Payment Terms</label>
          <input v-model="form.terms" placeholder="e.g. Net 30, 50% advance…" />
        </div>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" @click="$emit('cancel')">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="saving || !canSubmit">
            {{ saving ? 'Creating…' : 'Create Invoice' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import invoiceService from '../services/invoiceService.js'

const emit = defineEmits(['created', 'cancel'])

const source      = ref('dispatch')
const dispatches  = ref([])
const orders      = ref([])
const loadingSrc  = ref(false)
const saving      = ref(false)
const error       = ref(null)

const today = new Date().toISOString().slice(0, 10)
const due30 = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10)

const form = reactive({
  dispatch_id: '',
  order_id: '',
  invoice_date: today,
  due_date: due30,
  notes: '',
  terms: '',
})

const canSubmit = computed(() => source.value === 'dispatch' ? !!form.dispatch_id : !!form.order_id)

function setSource(s) { source.value = s; error.value = null }

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  error.value  = null
  try {
    const base = { invoice_date: form.invoice_date || null, due_date: form.due_date || null, notes: form.notes || null, terms: form.terms || null }
    const res = source.value === 'dispatch'
      ? await invoiceService.createFromDispatch({ ...base, dispatch_id: form.dispatch_id })
      : await invoiceService.createFromOrder({ ...base, order_id: form.order_id })
    const inv = res?.data ?? res
    emit('created', inv?.id ?? inv)
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to create invoice.'
  } finally {
    saving.value = false
  }
}

async function loadSources() {
  loadingSrc.value = true
  try {
    const [dRes, oRes] = await Promise.all([
      invoiceService.dispatches({ per_page: 100 }),
      invoiceService.orders({ per_page: 100 }),
    ])
    // dispatches: apiResponse -> { data: paginator }
    dispatches.value = (dRes?.data?.data ?? dRes?.data ?? []).filter(d => d.status !== 'cancelled')
    // orders: paginatedResponse -> { data: [...], meta }
    orders.value = oRes?.data?.data ?? oRes?.data ?? []
  } catch {
    error.value = 'Failed to load sources.'
  } finally {
    loadingSrc.value = false
  }
}

function fmtNum(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }

// Unsaved-changes guard (OPS-L1): warn before a refresh/close loses entered data.
const dirty = ref(false)
function beforeUnloadGuard(e) { if (dirty.value && !saving.value) { e.preventDefault(); e.returnValue = '' } }
onMounted(async () => {
  await loadSources()
  watch(form, () => { dirty.value = true }, { deep: true })
  window.addEventListener('beforeunload', beforeUnloadGuard)
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnloadGuard))
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 26px 30px; width: 100%; max-width: 540px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 92vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.modal-header h2 { margin: 0; font-size: 18px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.btn-close:hover { color: #333; background: #f0f0f0; }

.source-toggle { display: flex; gap: 6px; margin-bottom: 18px; }
.src-btn { flex: 1; padding: 9px; border: 1px solid #ddd; background: white; border-radius: 8px; font-size: 13px; font-weight: 600; color: #666; cursor: pointer; }
.src-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

.form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.4px; }
.form-group select, .form-group input { padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; width: 100%; box-sizing: border-box; }
.form-group select:focus, .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.form-row { display: flex; gap: 12px; }
.form-row .form-group { flex: 1; }
.hint { font-size: 11px; color: #c62828; margin-top: 2px; }
.req { color: #c62828; }

.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.modal-footer { display: flex; gap: 10px; justify-content: flex-end; padding-top: 8px; border-top: 1px solid #f0f0f0; margin-top: 6px; }
.btn { padding: 8px 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
