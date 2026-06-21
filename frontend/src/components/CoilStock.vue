<template>
  <div class="cs-wrap">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Coil Stock Inventory</h2>
      </div>
      <div class="toolbar-right">
        <label class="toggle-filter" :class="{ active: showLowOnly }" @click="toggleLowFilter">
          ⚠ Low Stock Only
        </label>
        <button class="btn btn-ghost" @click="load">↻ Refresh</button>
      </div>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</div>
    <div v-if="actionError"   class="error-banner">{{ actionError }}</div>

    <!-- Summary cards -->
    <div class="summary-bar" v-if="rows.length > 0">
      <div class="sum-card">
        <div class="sum-val">{{ pagination.total }}</div>
        <div class="sum-lbl">Panel Types Tracked</div>
      </div>
      <div class="sum-card warn" v-if="lowTotal > 0">
        <div class="sum-val">{{ lowTotal }}</div>
        <div class="sum-lbl">Low Stock Alerts</div>
      </div>
      <div class="sum-card ok" v-else>
        <div class="sum-val">✓</div>
        <div class="sum-lbl">All Stocked OK</div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="loading-row">Loading inventory…</div>

    <!-- Inventory cards -->
    <div v-else class="inventory-grid">
      <div
        v-for="row in rows"
        :key="row.id"
        class="inv-card"
        :class="{ 'low-stock': isLow(row), 'active-card': activeId === row.id }"
        @click="openCard(row)"
      >
        <!-- Card header -->
        <div class="inv-card-header">
          <div class="inv-panel-name">{{ row.panel_type?.name ?? 'Panel Type #' + row.panel_type_id }}</div>
          <span v-if="isLow(row)" class="low-badge">⚠ Low</span>
        </div>

        <!-- Stock level bar -->
        <div class="stock-bar-wrap">
          <div class="stock-bar-track">
            <div class="stock-bar-fill" :style="{ width: stockPct(row) + '%' }" :class="stockColor(row)"></div>
          </div>
          <div class="stock-bar-labels">
            <span class="qty-main">{{ fmtQty(row.quantity_in_stock) }} kg</span>
            <span class="qty-reorder">Reorder: {{ fmtQty(row.reorder_level) }} kg</span>
          </div>
        </div>

        <!-- Dates -->
        <div class="inv-dates">
          <span v-if="row.last_stock_in">In: {{ fmtDate(row.last_stock_in) }}</span>
          <span v-if="row.last_stock_out">Out: {{ fmtDate(row.last_stock_out) }}</span>
        </div>
      </div>
    </div>

    <!-- Pager -->
    <div class="pagination" v-if="pagination.last_page > 1">
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
      <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} total</span>
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
    </div>

    <!-- ── Action drawer (opens below the selected card) ── -->
    <div class="action-drawer" v-if="activeRow">
      <div class="drawer-header">
        <span class="drawer-title">{{ activeRow.panel_type?.name }} — Stock Actions</span>
        <button class="btn-close" @click="closeDrawer">✕</button>
      </div>

      <div class="drawer-tabs">
        <button :class="['tab-btn', { active: drawerTab === 'add' }]"      @click="drawerTab = 'add'">+ Add Stock</button>
        <button :class="['tab-btn', { active: drawerTab === 'remove' }]"   @click="drawerTab = 'remove'">− Remove Stock</button>
        <button :class="['tab-btn', { active: drawerTab === 'adjust' }]"   @click="drawerTab = 'adjust'">⊘ Adjust / Count</button>
        <button :class="['tab-btn', { active: drawerTab === 'history' }]"  @click="drawerTab = 'history'; loadHistory()">📋 History</button>
      </div>

      <!-- ADD -->
      <div v-if="drawerTab === 'add'" class="drawer-form">
        <div class="form-row">
          <div class="form-group">
            <label>Quantity to Add (kg) *</label>
            <input v-model.number="addForm.quantity" type="number" min="0.01" step="0.01" placeholder="e.g. 500" />
          </div>
          <div class="form-group flex-1">
            <label>Notes / Reference</label>
            <input v-model="addForm.notes" placeholder="Supplier, challan no., etc." />
          </div>
          <button class="btn btn-add" :disabled="submitting || !addForm.quantity" @click="doAdd">
            {{ submitting ? 'Adding…' : '+ Add Stock' }}
          </button>
        </div>
      </div>

      <!-- REMOVE -->
      <div v-if="drawerTab === 'remove'" class="drawer-form">
        <div class="form-row">
          <div class="form-group">
            <label>Quantity to Remove (kg) *</label>
            <input v-model.number="removeForm.quantity" type="number" min="0.01" step="0.01" placeholder="e.g. 100" />
          </div>
          <div class="form-group flex-1">
            <label>Reason / Notes</label>
            <input v-model="removeForm.notes" placeholder="Reason for removal…" />
          </div>
          <button class="btn btn-remove" :disabled="submitting || !removeForm.quantity" @click="doRemove">
            {{ submitting ? 'Removing…' : '− Remove Stock' }}
          </button>
        </div>
        <div class="avail-hint">
          Available: <strong>{{ fmtQty(activeRow.quantity_in_stock) }} kg</strong>
        </div>
      </div>

      <!-- ADJUST -->
      <div v-if="drawerTab === 'adjust'" class="drawer-form">
        <div class="form-row">
          <div class="form-group">
            <label>New Actual Quantity (kg) *</label>
            <input v-model.number="adjustForm.new_quantity" type="number" min="0" step="0.01" :placeholder="activeRow.quantity_in_stock" />
          </div>
          <div class="form-group flex-1">
            <label>Reason for Adjustment *</label>
            <input v-model="adjustForm.reason" placeholder="Physical count, wastage correction…" />
          </div>
          <button class="btn btn-adjust" :disabled="submitting || adjustForm.new_quantity === null || !adjustForm.reason" @click="doAdjust">
            {{ submitting ? 'Adjusting…' : '⊘ Set Quantity' }}
          </button>
        </div>
        <div class="avail-hint" v-if="adjustForm.new_quantity !== null">
          Difference:
          <strong :class="{ positive: adjustDiff > 0, negative: adjustDiff < 0 }">
            {{ adjustDiff > 0 ? '+' : '' }}{{ fmtQty(adjustDiff) }} kg
          </strong>
        </div>
        <div class="form-group reorder-group">
          <label>Update Reorder Level (kg)</label>
          <div class="reorder-row">
            <input v-model.number="reorderForm.level" type="number" min="0" step="0.01" :placeholder="activeRow.reorder_level" style="width:140px" />
            <button class="btn btn-ghost btn-sm" :disabled="submitting" @click="updateReorderLevel">Update Reorder Level</button>
          </div>
        </div>
      </div>

      <!-- HISTORY -->
      <div v-if="drawerTab === 'history'" class="drawer-form">
        <div v-if="loadingHistory" class="loading-hint">Loading transactions…</div>
        <div v-else-if="history.length === 0" class="empty-hint">No transactions recorded yet.</div>
        <table v-else class="history-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th class="text-right">Qty (kg)</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tx in history" :key="tx.id" :class="['tx-row', tx.type]">
              <td>{{ fmtDateTime(tx.transaction_date ?? tx.created_at) }}</td>
              <td><span :class="['tx-badge', tx.type]">{{ tx.type }}</span></td>
              <td class="text-right bold">{{ fmtQty(tx.quantity) }}</td>
              <td class="notes-cell">{{ tx.notes ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import stockService from '../services/stockService.js'
import { toastSuccess, toastError } from '../services/ui.js'

const rows          = ref([])
const loading       = ref(false)
const error         = ref(null)
const actionSuccess = ref(null)
const actionError   = ref(null)
const submitting    = ref(false)
const showLowOnly   = ref(false)

// Drawer state
const activeId      = ref(null)
const activeRow     = ref(null)
const drawerTab     = ref('add')
const history       = ref([])
const loadingHistory = ref(false)

const addForm    = reactive({ quantity: null, notes: '' })
const removeForm = reactive({ quantity: null, notes: '' })
const adjustForm = reactive({ new_quantity: null, reason: '' })
const reorderForm = reactive({ level: null })

const pagination    = reactive({ current_page: 1, last_page: 1, total: 0 })
const lowTotal      = ref(0)
const adjustDiff    = computed(() => {
  if (adjustForm.new_quantity === null || !activeRow.value) return 0
  return adjustForm.new_quantity - Number(activeRow.value.quantity_in_stock)
})

async function load(page = 1) {
  loading.value = true
  error.value   = null
  try {
    const params = { page, per_page: 24 }
    if (showLowOnly.value) params.low_stock = 1
    const res  = await stockService.getCoils(params)
    // apiResponse wraps a paginator: { success, data: { data:[], current_page, last_page, total }, message }
    const body = res?.data ?? {}
    rows.value = body.data ?? (Array.isArray(body) ? body : [])
    pagination.current_page = body.current_page ?? 1
    pagination.last_page    = body.last_page ?? 1
    pagination.total        = body.total ?? rows.value.length
    // Accurate low-stock total (independent of the current page) for the summary card
    try {
      const lr = await stockService.getCoils({ low_stock: 1, per_page: 1 })
      lowTotal.value = lr?.data?.total ?? 0
    } catch { /* non-fatal */ }
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load coil inventory.'
  } finally {
    loading.value = false
  }
}

function goPage(p) {
  if (p < 1 || p > pagination.last_page) return
  load(p)
}

function toggleLowFilter() {
  showLowOnly.value = !showLowOnly.value
  load()
}

function openCard(row) {
  if (activeId.value === row.id) { closeDrawer(); return }
  activeId.value  = row.id
  activeRow.value = row
  drawerTab.value = 'add'
  addForm.quantity    = null; addForm.notes    = ''
  removeForm.quantity = null; removeForm.notes = ''
  adjustForm.new_quantity = null; adjustForm.reason = ''
  reorderForm.level   = Number(row.reorder_level)
  actionSuccess.value = null
  actionError.value   = null
}

function closeDrawer() {
  activeId.value  = null
  activeRow.value = null
}

async function loadHistory() {
  if (!activeRow.value) return
  loadingHistory.value = true
  try {
    const res  = await stockService.getCoil(activeRow.value.id)
    const full = res?.data ?? res
    history.value = full?.transactions ?? []
  } catch { history.value = [] }
  finally { loadingHistory.value = false }
}

async function doAdd() {
  submitting.value    = true
  actionError.value   = null
  actionSuccess.value = null
  try {
    const res = await stockService.addCoil(activeRow.value.id, {
      quantity: addForm.quantity,
      notes:    addForm.notes || null,
    })
    const updated = res?.data ?? res
    updateRow(updated)
    actionSuccess.value = `Added ${fmtQty(addForm.quantity)} kg to ${activeRow.value.panel_type?.name}.`
    addForm.quantity = null; addForm.notes = ''
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to add stock.'
  } finally { submitting.value = false }
}

async function doRemove() {
  submitting.value    = true
  actionError.value   = null
  actionSuccess.value = null
  try {
    const res = await stockService.removeCoil(activeRow.value.id, {
      quantity: removeForm.quantity,
      notes:    removeForm.notes || null,
    })
    const updated = res?.data ?? res
    updateRow(updated)
    actionSuccess.value = `Removed ${fmtQty(removeForm.quantity)} kg from ${activeRow.value.panel_type?.name}.`
    removeForm.quantity = null; removeForm.notes = ''
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to remove stock.'
  } finally { submitting.value = false }
}

async function doAdjust() {
  submitting.value    = true
  actionError.value   = null
  actionSuccess.value = null
  try {
    const res = await stockService.adjustCoil(activeRow.value.id, {
      new_quantity: adjustForm.new_quantity,
      reason:       adjustForm.reason,
    })
    const updated = res?.data ?? res
    updateRow(updated)
    actionSuccess.value = `Stock adjusted to ${fmtQty(adjustForm.new_quantity)} kg.`
    adjustForm.new_quantity = null; adjustForm.reason = ''
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to adjust stock.'
  } finally { submitting.value = false }
}

async function updateReorderLevel() {
  if (reorderForm.level === null || !activeRow.value) return
  submitting.value  = true
  actionError.value = null
  try {
    const res = await stockService.updateCoilReorder(activeRow.value.id, reorderForm.level)
    updateRow(res?.data ?? { ...activeRow.value, reorder_level: reorderForm.level })
    toastSuccess('Reorder level updated.')
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to update reorder level.'
    toastError(actionError.value)
  } finally { submitting.value = false }
}

function updateRow(updated) {
  const idx = rows.value.findIndex(r => r.id === updated.id)
  if (idx !== -1) {
    // Merge — keep panelType since updated response may not include it
    rows.value[idx] = { ...rows.value[idx], ...updated }
    activeRow.value  = rows.value[idx]
  }
}

function isLow(row) { return Number(row.quantity_in_stock) <= Number(row.reorder_level) }

function stockPct(row) {
  const qty      = Number(row.quantity_in_stock)
  const reorder  = Number(row.reorder_level)
  const max      = Math.max(reorder * 3, qty * 1.1, 100)
  return Math.min(100, Math.round((qty / max) * 100))
}

function stockColor(row) {
  const qty     = Number(row.quantity_in_stock)
  const reorder = Number(row.reorder_level)
  if (qty <= reorder)       return 'red'
  if (qty <= reorder * 1.5) return 'amber'
  return 'green'
}

function fmtQty(n)  { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(load)
</script>

<style scoped>
.cs-wrap { font-family: inherit; }

.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.toolbar-right { display: flex; gap: 8px; align-items: center; }

.toggle-filter { padding: 6px 14px; border: 1px solid #ddd; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; color: #888; background: white; transition: all 0.15s; }
.toggle-filter.active { background: #fff3e0; border-color: #ffb74d; color: #e65100; }

.error-banner   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }

/* Summary */
.summary-bar { display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.sum-card     { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 12px 20px; text-align: center; min-width: 120px; }
.sum-card.warn { border-color: #ffb74d; background: #fff8f0; }
.sum-card.ok   { border-color: #a5d6a7; background: #f1f8f1; }
.sum-val  { font-size: 24px; font-weight: 800; color: var(--primary); }
.sum-card.warn .sum-val { color: #e65100; }
.sum-card.ok   .sum-val { color: #2e7d32; }
.sum-lbl  { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 2px; }

.loading-row { text-align: center; padding: 40px; color: #aaa; font-size: 14px; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 4px 0 18px; }
.page-info  { font-size: 12px; color: #666; font-variant-numeric: tabular-nums; }

/* Inventory grid */
.inventory-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-bottom: 18px; }

.inv-card { background: white; border: 2px solid #e0e0e0; border-radius: 10px; padding: 16px 18px; cursor: pointer; transition: all 0.15s; }
.inv-card:hover { border-color: var(--primary); box-shadow: 0 2px 8px rgba(26,35,126,0.1); }
.inv-card.low-stock { border-color: #ffb74d; background: #fffdf5; }
.inv-card.active-card { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }

.inv-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.inv-panel-name  { font-size: 14px; font-weight: 700; color: var(--primary); }
.low-badge       { font-size: 10px; font-weight: 700; background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; border-radius: 8px; padding: 2px 8px; }

.stock-bar-wrap   { margin-bottom: 8px; }
.stock-bar-track  { height: 8px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin-bottom: 5px; }
.stock-bar-fill   { height: 100%; border-radius: 10px; transition: width 0.4s ease; }
.stock-bar-fill.green { background: linear-gradient(90deg, #2e7d32, #43a047); }
.stock-bar-fill.amber { background: linear-gradient(90deg, #f57f17, #ffa726); }
.stock-bar-fill.red   { background: linear-gradient(90deg, #c62828, #ef5350); }
.stock-bar-labels { display: flex; justify-content: space-between; font-size: 12px; }
.qty-main    { font-weight: 700; color: #222; }
.qty-reorder { color: #aaa; }

.inv-dates { display: flex; gap: 10px; font-size: 11px; color: #aaa; flex-wrap: wrap; margin-top: 4px; }

/* Action drawer */
.action-drawer { background: #f8f9ff; border: 2px solid var(--primary); border-radius: 12px; padding: 20px 24px; margin-top: 4px; }
.drawer-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.drawer-title  { font-size: 15px; font-weight: 700; color: var(--primary); }
.btn-close     { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.btn-close:hover { color: #333; background: #f0f0f0; }

.drawer-tabs { display: flex; gap: 6px; margin-bottom: 18px; flex-wrap: wrap; }
.tab-btn     { padding: 6px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; background: white; color: #555; transition: all 0.12s; }
.tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
.tab-btn:hover:not(.active) { border-color: var(--primary); color: var(--primary); }

.drawer-form  { }
.form-row     { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.form-group   { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input { padding: 8px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 140px; }
.form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.flex-1 { flex: 1; min-width: 180px; }

.btn         { padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-add     { background: #2e7d32; color: white; }
.btn-remove  { background: #c62828; color: white; }
.btn-adjust  { background: var(--primary); color: white; }
.btn-ghost   { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-sm      { padding: 6px 12px; font-size: 12px; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.avail-hint { font-size: 12px; color: #666; margin-top: 6px; }
.positive   { color: #2e7d32; }
.negative   { color: #c62828; }

.reorder-group { margin-top: 14px; padding-top: 14px; border-top: 1px solid #e0e0e0; }
.reorder-row   { display: flex; gap: 10px; align-items: center; margin-top: 4px; }

/* Transaction history */
.history-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 4px; }
.history-table th { background: var(--primary-tint); color: #333; padding: 6px 10px; text-align: left; font-size: 10px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.history-table td { padding: 7px 10px; border: 1px solid #f0f0f0; vertical-align: middle; }
.tx-row.in    td:first-child { border-left: 3px solid #2e7d32; }
.tx-row.out   td:first-child { border-left: 3px solid #c62828; }
.tx-row.adjustment td:first-child { border-left: 3px solid var(--primary); }

.tx-badge         { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.tx-badge.in          { background: #e8f5e9; color: #2e7d32; }
.tx-badge.out         { background: #ffebee; color: #c62828; }
.tx-badge.adjustment  { background: var(--primary-tint); color: var(--primary); }

.text-right { text-align: right; }
.bold       { font-weight: 700; }
.notes-cell { color: #666; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.loading-hint { color: #aaa; font-size: 13px; padding: 16px 0; text-align: center; }
.empty-hint   { color: #aaa; font-style: italic; padding: 20px; text-align: center; border: 2px dashed #e0e0e0; border-radius: 8px; margin-top: 8px; }
</style>
