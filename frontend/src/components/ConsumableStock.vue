<template>
  <div class="cs-wrap">
    <div class="toolbar">
      <div class="toolbar-left"><h2>Consumables Inventory</h2></div>
      <div class="toolbar-right">
        <label class="toggle-filter" :class="{ active: showLowOnly }" @click="toggleLow">⚠ Low Stock</label>
        <button class="btn btn-primary btn-sm" @click="showCreate = true">+ New Consumable</button>
        <button class="btn btn-ghost btn-sm" @click="load">↻</button>
      </div>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</div>
    <div v-if="actionError" class="error-banner">{{ actionError }}</div>

    <div class="summary-bar" v-if="rows.length > 0">
      <div class="sum-card"><div class="sum-val">{{ pagination.total }}</div><div class="sum-lbl">Items Tracked</div></div>
      <div class="sum-card warn" v-if="lowTotal > 0"><div class="sum-val">{{ lowTotal }}</div><div class="sum-lbl">Low Stock</div></div>
    </div>

    <div v-if="loading" class="loading-row">Loading inventory…</div>
    <div v-else-if="rows.length === 0" class="empty-hint">
      No consumables yet. Click <strong>+ New Consumable</strong> to register one (Mould Oil, Protective Film, Sealant Tape, Packaging…).
    </div>

    <div v-else class="inventory-grid">
      <div v-for="row in rows" :key="row.id" class="inv-card"
           :class="{ 'low-stock': isLow(row), 'active-card': activeId === row.id }" @click="openCard(row)">
        <div class="inv-card-header">
          <div>
            <div class="inv-name">{{ row.name }}</div>
            <div class="inv-category" v-if="row.category">{{ row.category }}</div>
          </div>
          <span v-if="isLow(row)" class="low-badge">⚠ Low</span>
        </div>
        <div class="stock-bar-wrap">
          <div class="stock-bar-track">
            <div class="stock-bar-fill" :style="{ width: stockPct(row) + '%' }" :class="stockColor(row)"></div>
          </div>
          <div class="stock-bar-labels">
            <span class="qty-main">{{ fmtQty(row.quantity_in_stock) }} {{ row.unit }}</span>
            <span class="qty-reorder">Reorder: {{ fmtQty(row.reorder_level) }} {{ row.unit }}</span>
          </div>
        </div>
        <div class="inv-meta">
          <span v-if="Number(row.unit_cost) > 0">₹ {{ fmtQty(row.unit_cost) }} / {{ row.unit }}</span>
        </div>
      </div>
    </div>

    <!-- Pager -->
    <div class="pagination" v-if="pagination.last_page > 1">
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
      <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} total</span>
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
    </div>

    <!-- Action drawer -->
    <div class="action-drawer" v-if="activeRow">
      <div class="drawer-header">
        <span class="drawer-title">{{ activeRow.name }} — Stock Actions</span>
        <button class="btn-close" @click="closeDrawer">✕</button>
      </div>
      <div class="drawer-tabs">
        <button :class="['tab-btn', { active: drawerTab === 'add' }]"     @click="drawerTab = 'add'">+ Add Stock</button>
        <button :class="['tab-btn', { active: drawerTab === 'remove' }]"  @click="drawerTab = 'remove'">− Remove</button>
        <button :class="['tab-btn', { active: drawerTab === 'adjust' }]"  @click="drawerTab = 'adjust'">⊘ Adjust</button>
        <button :class="['tab-btn', { active: drawerTab === 'history' }]" @click="drawerTab = 'history'; loadHistory()">📋 History</button>
      </div>

      <div v-if="drawerTab === 'add'" class="drawer-form">
        <div class="form-row">
          <div class="form-group"><label>Quantity to Add ({{ activeRow.unit }}) *</label>
            <input v-model.number="addForm.quantity" type="number" min="0.01" step="0.01" /></div>
          <div class="form-group flex-1"><label>Notes / Reference</label>
            <input v-model="addForm.notes" placeholder="Supplier, PO/challan no." /></div>
          <button class="btn btn-add" :disabled="submitting || !addForm.quantity" @click="doAdd">
            {{ submitting ? 'Adding…' : '+ Add Stock' }}</button>
        </div>
      </div>

      <div v-if="drawerTab === 'remove'" class="drawer-form">
        <div class="form-row">
          <div class="form-group"><label>Quantity to Remove ({{ activeRow.unit }}) *</label>
            <input v-model.number="removeForm.quantity" type="number" min="0.01" step="0.01" /></div>
          <div class="form-group flex-1"><label>Reason / Notes</label>
            <input v-model="removeForm.notes" placeholder="Consumed, wastage…" /></div>
          <button class="btn btn-remove" :disabled="submitting || !removeForm.quantity" @click="doRemove">
            {{ submitting ? 'Removing…' : '− Remove Stock' }}</button>
        </div>
        <div class="avail-hint">Available: <strong>{{ fmtQty(activeRow.quantity_in_stock) }} {{ activeRow.unit }}</strong></div>
      </div>

      <div v-if="drawerTab === 'adjust'" class="drawer-form">
        <div class="form-row">
          <div class="form-group"><label>New Actual Quantity ({{ activeRow.unit }}) *</label>
            <input v-model.number="adjustForm.new_quantity" type="number" min="0" step="0.01" :placeholder="activeRow.quantity_in_stock" /></div>
          <div class="form-group flex-1"><label>Reason *</label>
            <input v-model="adjustForm.reason" placeholder="Physical count, spillage…" /></div>
          <button class="btn btn-adjust" :disabled="submitting || adjustForm.new_quantity === null || !adjustForm.reason" @click="doAdjust">
            {{ submitting ? 'Adjusting…' : '⊘ Set Quantity' }}</button>
        </div>
      </div>

      <div v-if="drawerTab === 'history'" class="drawer-form">
        <div v-if="loadingHistory" class="loading-hint">Loading…</div>
        <div v-else-if="history.length === 0" class="empty-hint">No transactions yet.</div>
        <table v-else class="history-table">
          <thead><tr><th>Date</th><th>Type</th><th class="text-right">Qty</th><th>Notes</th></tr></thead>
          <tbody>
            <tr v-for="tx in history" :key="tx.id" :class="['tx-row', tx.type]">
              <td>{{ fmtDateTime(tx.transaction_date ?? tx.created_at) }}</td>
              <td><span :class="['tx-badge', tx.type]">{{ tx.type }}</span></td>
              <td class="text-right bold">{{ fmtQty(tx.quantity) }} {{ tx.unit }}</td>
              <td class="notes-cell">{{ tx.notes ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="modal-overlay" @click.self="showCreate = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Register New Consumable</h3>
          <button class="btn-close" @click="showCreate = false">✕</button>
        </div>
        <div class="form-group"><label>Name *</label>
          <input v-model="createForm.name" placeholder="e.g. Mould Release Oil, Guard Film, Butyl Tape" /></div>
        <div class="form-row">
          <div class="form-group flex-1"><label>Category</label>
            <select v-model="createForm.category">
              <option value="">— Select —</option>
              <option value="oil">Oil / Release Agent</option>
              <option value="film">Protective Film</option>
              <option value="tape">Sealant / Butyl Tape</option>
              <option value="packaging">Packaging</option>
              <option value="other">Other</option>
            </select></div>
          <div class="form-group"><label>Unit *</label>
            <select v-model="createForm.unit">
              <option value="litre">litre</option>
              <option value="m">m (metre)</option>
              <option value="roll">roll</option>
              <option value="nos">nos</option>
              <option value="kg">kg</option>
            </select></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Reorder Level</label>
            <input v-model.number="createForm.reorder_level" type="number" min="0" step="0.01" /></div>
          <div class="form-group"><label>Unit Cost (₹)</label>
            <input v-model.number="createForm.unit_cost" type="number" min="0" step="0.01" /></div>
        </div>
        <div v-if="createError" class="error-msg">{{ createError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showCreate = false">Cancel</button>
          <button class="btn btn-primary" :disabled="creating || !createForm.name || !createForm.unit" @click="doCreate">
            {{ creating ? 'Creating…' : 'Create Consumable' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import stockService from '../services/stockService.js'

const rows = ref([])
const loading = ref(false)
const error = ref(null)
const actionSuccess = ref(null)
const actionError = ref(null)
const submitting = ref(false)
const showLowOnly = ref(false)

const activeId = ref(null)
const activeRow = ref(null)
const drawerTab = ref('add')
const history = ref([])
const loadingHistory = ref(false)

const showCreate = ref(false)
const creating = ref(false)
const createError = ref(null)

const addForm = reactive({ quantity: null, notes: '' })
const removeForm = reactive({ quantity: null, notes: '' })
const adjustForm = reactive({ new_quantity: null, reason: '' })
const createForm = reactive({ name: '', category: '', unit: 'litre', reorder_level: 0, unit_cost: 0 })

const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })
const lowTotal   = ref(0)

async function load(page = 1) {
  loading.value = true; error.value = null
  try {
    const params = { page, per_page: 24 }
    if (showLowOnly.value) params.low_stock = 1
    const res = await stockService.getConsumables(params)
    const body = res?.data ?? {}
    rows.value = body.data ?? (Array.isArray(body) ? body : [])
    pagination.current_page = body.current_page ?? 1
    pagination.last_page    = body.last_page ?? 1
    pagination.total        = body.total ?? rows.value.length
    try {
      const lr = await stockService.getConsumables({ low_stock: 1, per_page: 1 })
      lowTotal.value = lr?.data?.total ?? 0
    } catch { /* non-fatal */ }
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load consumables.'
  } finally { loading.value = false }
}

function goPage(p) { if (p < 1 || p > pagination.last_page) return; load(p) }

function toggleLow() { showLowOnly.value = !showLowOnly.value; load() }

function openCard(row) {
  if (activeId.value === row.id) { closeDrawer(); return }
  activeId.value = row.id; activeRow.value = row; drawerTab.value = 'add'
  Object.assign(addForm, { quantity: null, notes: '' })
  Object.assign(removeForm, { quantity: null, notes: '' })
  Object.assign(adjustForm, { new_quantity: null, reason: '' })
  actionSuccess.value = null; actionError.value = null
}
function closeDrawer() { activeId.value = null; activeRow.value = null }

async function loadHistory() {
  if (!activeRow.value) return
  loadingHistory.value = true
  try {
    const res = await stockService.getConsumable(activeRow.value.id)
    history.value = (res?.data ?? res)?.transactions ?? []
  } catch { history.value = [] }
  finally { loadingHistory.value = false }
}

async function doAdd() {
  submitting.value = true; actionError.value = null; actionSuccess.value = null
  try {
    const res = await stockService.addConsumable(activeRow.value.id, { quantity: addForm.quantity, notes: addForm.notes || null })
    updateRow(res?.data ?? res)
    actionSuccess.value = `Added ${fmtQty(addForm.quantity)} ${activeRow.value.unit}.`
    Object.assign(addForm, { quantity: null, notes: '' })
  } catch (e) { actionError.value = e?.response?.data?.message ?? 'Failed to add stock.' }
  finally { submitting.value = false }
}

async function doRemove() {
  submitting.value = true; actionError.value = null; actionSuccess.value = null
  try {
    const res = await stockService.removeConsumable(activeRow.value.id, { quantity: removeForm.quantity, notes: removeForm.notes || null })
    updateRow(res?.data ?? res)
    actionSuccess.value = `Removed ${fmtQty(removeForm.quantity)} ${activeRow.value.unit}.`
    Object.assign(removeForm, { quantity: null, notes: '' })
  } catch (e) { actionError.value = e?.response?.data?.message ?? 'Failed to remove stock.' }
  finally { submitting.value = false }
}

async function doAdjust() {
  submitting.value = true; actionError.value = null; actionSuccess.value = null
  try {
    const res = await stockService.adjustConsumable(activeRow.value.id, { new_quantity: adjustForm.new_quantity, reason: adjustForm.reason })
    updateRow(res?.data ?? res)
    actionSuccess.value = `Stock adjusted to ${fmtQty(adjustForm.new_quantity)} ${activeRow.value.unit}.`
    Object.assign(adjustForm, { new_quantity: null, reason: '' })
  } catch (e) { actionError.value = e?.response?.data?.message ?? 'Failed to adjust stock.' }
  finally { submitting.value = false }
}

async function doCreate() {
  creating.value = true; createError.value = null
  try {
    await stockService.createConsumable({
      name: createForm.name, category: createForm.category || null,
      unit: createForm.unit, reorder_level: createForm.reorder_level || 0, unit_cost: createForm.unit_cost || 0,
    })
    showCreate.value = false
    Object.assign(createForm, { name: '', category: '', unit: 'litre', reorder_level: 0, unit_cost: 0 })
    actionSuccess.value = 'Consumable registered.'
    await load()
  } catch (e) { createError.value = e?.response?.data?.message ?? 'Failed to create consumable.' }
  finally { creating.value = false }
}

function updateRow(updated) {
  const idx = rows.value.findIndex(r => r.id === updated.id)
  if (idx !== -1) { rows.value[idx] = { ...rows.value[idx], ...updated }; activeRow.value = rows.value[idx] }
}

function isLow(row) { return Number(row.quantity_in_stock) <= Number(row.reorder_level) }
function stockPct(row) {
  const qty = Number(row.quantity_in_stock), reorder = Number(row.reorder_level)
  const max = Math.max(reorder * 3, qty * 1.1, 100)
  return Math.min(100, Math.round((qty / max) * 100))
}
function stockColor(row) {
  const qty = Number(row.quantity_in_stock), reorder = Number(row.reorder_level)
  if (qty <= reorder) return 'red'
  if (qty <= reorder * 1.5) return 'amber'
  return 'green'
}
function fmtQty(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtDateTime(d) { return d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—' }

onMounted(load)
</script>

<style scoped>
.cs-wrap { font-family: inherit; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.toolbar-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.toggle-filter { padding: 6px 14px; border: 1px solid #ddd; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; color: #888; background: white; }
.toggle-filter.active { background: #fff3e0; border-color: #ffb74d; color: #e65100; }

.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }

.summary-bar { display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.sum-card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 12px 20px; text-align: center; min-width: 110px; }
.sum-card.warn { border-color: #ffb74d; background: #fff8f0; }
.sum-val { font-size: 24px; font-weight: 800; color: var(--primary); }
.sum-card.warn .sum-val { color: #e65100; }
.sum-lbl { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 2px; }

.loading-row { text-align: center; padding: 40px; color: #aaa; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 4px 0 18px; }
.page-info  { font-size: 12px; color: #666; font-variant-numeric: tabular-nums; }
.empty-hint { text-align: center; color: #999; padding: 30px; border: 2px dashed #e0e0e0; border-radius: 8px; }

.inventory-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 14px; margin-bottom: 18px; }
.inv-card { background: white; border: 2px solid #e0e0e0; border-radius: 10px; padding: 16px 18px; cursor: pointer; transition: all 0.15s; }
.inv-card:hover { border-color: var(--primary); box-shadow: 0 2px 8px rgba(26,35,126,0.1); }
.inv-card.low-stock { border-color: #ffb74d; background: #fffdf5; }
.inv-card.active-card { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.inv-card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; }
.inv-name { font-size: 14px; font-weight: 700; color: var(--primary); }
.inv-category { font-size: 11px; color: #888; margin-top: 1px; text-transform: capitalize; }
.low-badge { font-size: 10px; font-weight: 700; background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; border-radius: 8px; padding: 1px 7px; }

.stock-bar-wrap { margin-bottom: 8px; }
.stock-bar-track { height: 8px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin-bottom: 5px; }
.stock-bar-fill { height: 100%; border-radius: 10px; transition: width 0.4s ease; }
.stock-bar-fill.green { background: linear-gradient(90deg, #2e7d32, #43a047); }
.stock-bar-fill.amber { background: linear-gradient(90deg, #f57f17, #ffa726); }
.stock-bar-fill.red { background: linear-gradient(90deg, #c62828, #ef5350); }
.stock-bar-labels { display: flex; justify-content: space-between; font-size: 12px; }
.qty-main { font-weight: 700; color: #222; }
.qty-reorder { color: #aaa; }
.inv-meta { display: flex; gap: 12px; font-size: 11px; color: #888; flex-wrap: wrap; margin-top: 4px; }

.action-drawer { background: #f8f9ff; border: 2px solid var(--primary); border-radius: 12px; padding: 20px 24px; margin-top: 4px; }
.drawer-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.drawer-title { font-size: 15px; font-weight: 700; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.btn-close:hover { color: #333; background: #f0f0f0; }
.drawer-tabs { display: flex; gap: 6px; margin-bottom: 18px; flex-wrap: wrap; }
.tab-btn { padding: 6px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; background: white; color: #555; }
.tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

.form-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.form-row:last-child { margin-bottom: 0; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input, .form-group select { padding: 8px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 140px; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.flex-1 { flex: 1; min-width: 180px; }

.btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-add { background: #2e7d32; color: white; }
.btn-remove { background: #c62828; color: white; }
.btn-adjust { background: var(--primary); color: white; }
.btn-primary { background: var(--primary); color: white; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.avail-hint { font-size: 12px; color: #666; margin-top: 8px; }

.history-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.history-table th { background: var(--primary-tint); color: #333; padding: 6px 10px; text-align: left; font-size: 10px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.history-table td { padding: 7px 10px; border: 1px solid #f0f0f0; }
.tx-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.tx-badge.in { background: #e8f5e9; color: #2e7d32; }
.tx-badge.out { background: #ffebee; color: #c62828; }
.tx-badge.adjustment { background: var(--primary-tint); color: var(--primary); }
.text-right { text-align: right; } .bold { font-weight: 700; }
.notes-cell { color: #666; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.loading-hint { color: #aaa; padding: 16px; text-align: center; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 24px 28px; width: 100%; max-width: 520px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }
.modal-box .form-group { margin-bottom: 12px; }
.modal-box .form-group input, .modal-box .form-group select { width: 100%; box-sizing: border-box; }
</style>
