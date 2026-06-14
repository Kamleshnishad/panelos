<template>
  <div class="pm-wrap">
    <div class="pm-header">
      <div>
        <h2>Procurement</h2>
        <p class="pm-sub">Purchase orders → receive goods → stock updates with cost.</p>
      </div>
      <div class="pm-head-actions">
        <div class="val-card" v-if="valuation">
          <label>Stock Value</label><span>₹ {{ fmt(valuation.total) }}</span>
        </div>
        <button class="btn btn-primary" @click="openCreate">+ New PO</button>
        <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
      </div>
    </div>

    <div v-if="loading" class="pm-loading">Loading…</div>
    <p v-else-if="!pos.length" class="empty">No purchase orders yet. Click <strong>+ New PO</strong>.</p>

    <table v-else class="po-table">
      <thead>
        <tr><th>PO No</th><th>Supplier</th><th>Date</th><th class="r">Total</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="po in pos" :key="po.id">
          <td class="mono bold">{{ po.po_no }}</td>
          <td>{{ po.supplier?.name || '—' }}</td>
          <td>{{ fmtDate(po.order_date) }}</td>
          <td class="r mono">₹ {{ fmt(po.total) }}</td>
          <td><span class="po-status" :class="po.status">{{ po.status }}</span></td>
          <td class="r">
            <button v-if="po.status !== 'received' && po.status !== 'cancelled'" class="btn btn-add sm" @click="openReceive(po)">Receive</button>
            <button v-if="po.status !== 'received' && po.status !== 'cancelled'" class="btn btn-ghost sm" @click="doCancel(po)">Cancel</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Create PO modal -->
    <div v-if="showCreate" class="modal-overlay" @click.self="showCreate = false">
      <div class="modal-box lg">
        <div class="modal-header"><h3>New Purchase Order</h3><button class="btn-close" @click="showCreate = false">✕</button></div>

        <div class="form-row">
          <div class="form-group flex-1">
            <label>Supplier</label>
            <div class="sup-row">
              <select v-model="form.supplier_id">
                <option :value="null">— Select —</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
              <button type="button" class="btn btn-ghost sm" @click="showSupplier = true">+ New</button>
            </div>
          </div>
          <div class="form-group"><label>Order Date</label><input v-model="form.order_date" type="date" /></div>
          <div class="form-group"><label>Expected</label><input v-model="form.expected_date" type="date" /></div>
          <div class="form-group"><label>GST %</label><input v-model.number="form.tax_pct" type="number" min="0" max="100" /></div>
        </div>

        <div class="lines-head">
          <span>Items</span>
          <button class="btn btn-add sm" @click="addLine">+ Add Item</button>
        </div>
        <table class="line-table">
          <thead><tr><th style="width:38%">Material</th><th class="r">Qty</th><th class="r">Rate ₹</th><th class="r">Amount</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(l, i) in form.items" :key="l._key">
              <td>
                <select v-model="l._pick" @change="onPick(l)">
                  <option value="">— Select item —</option>
                  <option v-for="(p, pi) in purchasable" :key="pi" :value="pi">{{ p.name }} ({{ p.unit }})</option>
                </select>
              </td>
              <td><input v-model.number="l.quantity" type="number" min="0" step="0.01" class="num" /></td>
              <td><input v-model.number="l.rate" type="number" min="0" step="0.01" class="num" /></td>
              <td class="r mono">₹ {{ fmt((l.quantity || 0) * (l.rate || 0)) }}</td>
              <td><button class="btn-x" @click="form.items.splice(i, 1)">✕</button></td>
            </tr>
            <tr v-if="!form.items.length"><td colspan="5" class="empty-sm">Add at least one item</td></tr>
          </tbody>
        </table>

        <div class="po-totals">
          <span>Subtotal: <b>₹ {{ fmt(subtotal) }}</b></span>
          <span>GST ({{ form.tax_pct || 0 }}%): <b>₹ {{ fmt(taxAmt) }}</b></span>
          <span class="grand">Total: <b>₹ {{ fmt(subtotal + taxAmt) }}</b></span>
        </div>

        <div v-if="createError" class="error-msg">{{ createError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showCreate = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving || !form.items.length" @click="savePO">{{ saving ? 'Saving…' : 'Create PO' }}</button>
        </div>
      </div>
    </div>

    <!-- New supplier modal -->
    <div v-if="showSupplier" class="modal-overlay" @click.self="showSupplier = false">
      <div class="modal-box">
        <div class="modal-header"><h3>New Supplier</h3><button class="btn-close" @click="showSupplier = false">✕</button></div>
        <div class="form-group"><label>Name *</label><input v-model="newSup.name" /></div>
        <div class="form-row">
          <div class="form-group flex-1"><label>Phone</label><input v-model="newSup.phone" /></div>
          <div class="form-group flex-1"><label>GSTIN</label><input v-model="newSup.gstin" /></div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showSupplier = false">Cancel</button>
          <button class="btn btn-primary" :disabled="!newSup.name" @click="saveSupplier">Add</button>
        </div>
      </div>
    </div>

    <!-- Receive modal -->
    <div v-if="receivePO" class="modal-overlay" @click.self="receivePO = null">
      <div class="modal-box lg">
        <div class="modal-header"><h3>Receive — {{ receivePO.po_no }}</h3><button class="btn-close" @click="receivePO = null">✕</button></div>
        <table class="line-table">
          <thead><tr><th>Item</th><th class="r">Ordered</th><th class="r">Already</th><th class="r">Receive</th><th class="r">Cost ₹</th><th>Batch / Expiry</th></tr></thead>
          <tbody>
            <tr v-for="l in recvLines" :key="l.po_item_id">
              <td>{{ l.item_name }} <small>({{ l.unit }})</small></td>
              <td class="r mono">{{ fmt(l.quantity) }}</td>
              <td class="r mono">{{ fmt(l.received_qty) }}</td>
              <td><input v-model.number="l._recv" type="number" min="0" step="0.01" class="num" /></td>
              <td><input v-model.number="l._cost" type="number" min="0" step="0.01" class="num" /></td>
              <td>
                <template v-if="l.material_kind === 'chemical'">
                  <input v-model="l._batch" placeholder="Batch" class="sm-input" />
                  <input v-model="l._expiry" type="date" class="sm-input" />
                </template>
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="recvError" class="error-msg">{{ recvError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="receivePO = null">Cancel</button>
          <button class="btn btn-primary" :disabled="receiving" @click="doReceive">{{ receiving ? 'Receiving…' : 'Receive Goods' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import procurementService from '../services/procurementService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const pos = ref([])
const loading = ref(false)
const valuation = ref(null)
const purchasable = ref([])
const suppliers = ref([])

const showCreate = ref(false)
const saving = ref(false)
const createError = ref(null)
let _k = 1
const form = reactive({ supplier_id: null, order_date: new Date().toISOString().slice(0, 10), expected_date: '', tax_pct: 18, notes: '', items: [] })

const showSupplier = ref(false)
const newSup = reactive({ name: '', phone: '', gstin: '' })

const receivePO = ref(null)
const recvLines = ref([])
const receiving = ref(false)
const recvError = ref(null)

const subtotal = computed(() => form.items.reduce((s, l) => s + (l.quantity || 0) * (l.rate || 0), 0))
const taxAmt = computed(() => subtotal.value * (form.tax_pct || 0) / 100)

function fmt(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN') : '—' }

async function load() {
  loading.value = true
  try {
    const [poRes, valRes] = await Promise.all([procurementService.listPOs(), procurementService.valuation()])
    pos.value = poRes?.data ?? poRes ?? []
    valuation.value = valRes?.data ?? valRes
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not load procurement.') }
  finally { loading.value = false }
}

async function loadRefs() {
  try {
    const [p, s] = await Promise.all([procurementService.purchasable(), procurementService.suppliers()])
    purchasable.value = p?.data ?? p ?? []
    suppliers.value = s?.data ?? s ?? []
  } catch { /* ignore */ }
}

function openCreate() {
  Object.assign(form, { supplier_id: null, order_date: new Date().toISOString().slice(0, 10), expected_date: '', tax_pct: 18, notes: '', items: [] })
  createError.value = null
  addLine()
  showCreate.value = true
  loadRefs()
}

function addLine() { form.items.push({ _key: _k++, _pick: '', material_kind: '', stock_id: null, item_name: '', unit: 'kg', quantity: null, rate: null }) }

function onPick(line) {
  const p = purchasable.value[line._pick]
  if (!p) return
  line.material_kind = p.material_kind
  line.stock_id = p.stock_id
  line.item_name = p.name
  line.unit = p.unit
  if (!line.rate) line.rate = p.unit_cost || null
}

async function savePO() {
  createError.value = null
  const items = form.items.filter(l => l.stock_id && l.quantity > 0)
  if (!items.length) { createError.value = 'Add at least one item with quantity.'; return }
  saving.value = true
  try {
    await procurementService.createPO({
      supplier_id: form.supplier_id, order_date: form.order_date,
      expected_date: form.expected_date || null, tax_pct: form.tax_pct || 0, notes: form.notes || null,
      items: items.map(l => ({ material_kind: l.material_kind, stock_id: l.stock_id, item_name: l.item_name, unit: l.unit, quantity: l.quantity, rate: l.rate || 0 })),
    })
    toastSuccess('Purchase order created.')
    showCreate.value = false
    await load()
  } catch (e) {
    createError.value = e?.response?.data?.message ?? 'Failed to create PO.'
  } finally { saving.value = false }
}

async function saveSupplier() {
  try {
    const res = await procurementService.createSupplier({ ...newSup })
    const s = res?.data ?? res
    suppliers.value.push(s); form.supplier_id = s.id
    showSupplier.value = false
    Object.assign(newSup, { name: '', phone: '', gstin: '' })
    toastSuccess('Supplier added.')
  } catch (e) { toastError(e?.response?.data?.message ?? 'Failed to add supplier.') }
}

async function openReceive(po) {
  recvError.value = null
  try {
    const res = await procurementService.getPO(po.id)
    const full = res?.data ?? res
    receivePO.value = full
    recvLines.value = (full.items || []).map(it => ({
      po_item_id: it.id, item_name: it.item_name, unit: it.unit, material_kind: it.material_kind,
      quantity: Number(it.quantity), received_qty: Number(it.received_qty),
      _recv: Math.max(0, Number(it.quantity) - Number(it.received_qty)),
      _cost: Number(it.rate) || null, _batch: '', _expiry: '',
    }))
  } catch (e) { toastError('Could not load PO.') }
}

async function doReceive() {
  receiving.value = true; recvError.value = null
  try {
    const receipts = recvLines.value.filter(l => l._recv > 0).map(l => ({
      po_item_id: l.po_item_id, received_qty: l._recv, cost: l._cost ?? null,
      batch_no: l._batch || null, expiry_date: l._expiry || null,
    }))
    if (!receipts.length) { recvError.value = 'Enter at least one received quantity.'; receiving.value = false; return }
    await procurementService.receivePO(receivePO.value.id, receipts)
    toastSuccess('Goods received — stock updated.')
    receivePO.value = null
    await load()
  } catch (e) {
    recvError.value = e?.response?.data?.message ?? 'Failed to receive goods.'
  } finally { receiving.value = false }
}

async function doCancel(po) {
  const ok = await confirmDialog({ title: 'Cancel PO?', message: `Cancel ${po.po_no}?`, confirmLabel: 'Cancel PO', cancelLabel: 'No', danger: true })
  if (!ok) return
  try { await procurementService.cancelPO(po.id); toastSuccess('PO cancelled.'); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed to cancel.') }
}

onMounted(load)
</script>

<style scoped>
.pm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.pm-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 16px; flex-wrap: wrap; }
.pm-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.pm-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.pm-head-actions { display: flex; align-items: center; gap: 10px; }
.val-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 8px 16px; text-align: right; }
.val-card label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-3); font-weight: 700; }
.val-card span { font-size: 18px; font-weight: 800; color: var(--primary); font-variant-numeric: tabular-nums; }

.pm-loading { text-align: center; padding: 60px; color: #888; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 40px; }

.po-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.po-table th { background: var(--primary); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
.po-table th.r, .po-table td.r { text-align: right; }
.po-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; }
.mono { font-variant-numeric: tabular-nums; } .bold { font-weight: 700; }
.po-status { font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 10px; text-transform: uppercase; }
.po-status.ordered { background: var(--primary-tint); color: var(--primary); }
.po-status.partial { background: #fff8e1; color: #b5740a; }
.po-status.received { background: #e8f5e9; color: #2e7d32; }
.po-status.cancelled { background: #ffebee; color: #c62828; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 540px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); }
.modal-box.lg { max-width: 820px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }

.form-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.flex-1 { flex: 1; min-width: 160px; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input, .form-group select { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.sup-row { display: flex; gap: 6px; } .sup-row select { flex: 1; }

.lines-head { display: flex; justify-content: space-between; align-items: center; margin: 8px 0 6px; font-size: 12px; font-weight: 700; color: var(--primary); text-transform: uppercase; }
.line-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.line-table th { background: var(--surface-2); color: #555; padding: 5px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
.line-table th.r { text-align: right; }
.line-table td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; }
.line-table select { width: 100%; padding: 5px 6px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; }
.num { width: 90px; padding: 5px 6px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; text-align: right; }
.sm-input { width: 100px; padding: 4px 6px; border: 1px solid #ddd; border-radius: 5px; font-size: 11px; margin-right: 4px; }
.btn-x { background: none; border: none; color: #c62828; cursor: pointer; }
.empty-sm { text-align: center; color: #aaa; font-style: italic; padding: 10px; }
.muted { color: #aaa; }

.po-totals { display: flex; gap: 20px; justify-content: flex-end; margin-top: 10px; font-size: 13px; }
.po-totals .grand b { color: var(--primary); font-size: 15px; }

.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 4px 11px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-add { background: #e8f5e9; color: #2e7d32; }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }

@media (max-width: 900px) { .pm-wrap { padding: 16px 16px 40px; } }
</style>
