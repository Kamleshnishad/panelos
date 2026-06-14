<template>
  <div class="txl-wrap">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Stock Transaction Log</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} entries</span>
      </div>
      <button class="btn btn-ghost btn-sm" @click="load">↻ Refresh</button>
    </div>

    <!-- Filters -->
    <div class="filters">
      <select v-model="filters.kind" class="filter-select" @change="load">
        <option value="">All Items</option>
        <option value="coil">Coils</option>
        <option value="chemical">Chemicals</option>
      </select>
      <select v-model="filters.type" class="filter-select" @change="load">
        <option value="">All Movements</option>
        <option value="in">Stock In</option>
        <option value="out">Stock Out</option>
        <option value="adjustment">Adjustment</option>
      </select>
      <div class="date-group">
        <label>From</label>
        <input v-model="filters.from_date" type="date" class="filter-input" @change="load" />
      </div>
      <div class="date-group">
        <label>To</label>
        <input v-model="filters.to_date" type="date" class="filter-input" @change="load" />
      </div>
      <div class="quick-range">
        <button :class="['chip', { active: quickRange === 7 }]"  @click="setQuickRange(7)">7d</button>
        <button :class="['chip', { active: quickRange === 30 }]" @click="setQuickRange(30)">30d</button>
        <button :class="['chip', { active: quickRange === 90 }]" @click="setQuickRange(90)">90d</button>
      </div>
      <button class="btn btn-ghost btn-sm" @click="clearFilters">Clear</button>
    </div>

    <!-- Summary strip -->
    <div class="summary-strip" v-if="!loading && rows.length > 0">
      <span class="sum-item in">▲ In: <strong>{{ countByType('in') }}</strong></span>
      <span class="sum-item out">▼ Out: <strong>{{ countByType('out') }}</strong></span>
      <span class="sum-item adj">⊘ Adjustments: <strong>{{ countByType('adjustment') }}</strong></span>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <div v-if="loading" class="loading-row">Loading transactions…</div>
      <div v-else-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th>Date &amp; Time</th>
            <th>Item</th>
            <th>Kind</th>
            <th>Movement</th>
            <th class="text-right">Quantity</th>
            <th>Reference</th>
            <th>Notes</th>
            <th>By</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="8" class="empty-row">No transactions match the current filters.</td>
          </tr>
          <tr v-for="tx in rows" :key="tx.id" :class="['tx-row', tx.type]">
            <td class="nowrap">{{ fmtDateTime(tx.transaction_date ?? tx.created_at) }}</td>
            <td class="bold">{{ tx.item_name }}</td>
            <td>
              <span :class="['kind-badge', tx.item_kind]">{{ tx.item_kind }}</span>
            </td>
            <td>
              <span :class="['move-badge', tx.type]">
                {{ moveIcon(tx.type) }} {{ moveLabel(tx.type) }}
              </span>
            </td>
            <td class="text-right bold" :class="qtyClass(tx.type)">
              {{ qtySign(tx.type) }}{{ fmtQty(tx.quantity) }} {{ tx.unit }}
            </td>
            <td class="muted">{{ tx.reference_no ?? '—' }}</td>
            <td class="notes-cell">{{ tx.notes ?? '—' }}</td>
            <td class="muted">{{ tx.created_by_user?.name ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination" v-if="pagination.last_page > 1">
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
      <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import stockService from '../services/stockService.js'

const rows       = ref([])
const loading    = ref(false)
const error      = ref(null)
const quickRange = ref(null)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })

const filters = reactive({ kind: '', type: '', from_date: '', to_date: '' })

async function load(page = 1) {
  loading.value = true
  error.value   = null
  try {
    const params = { page, per_page: 25 }
    if (filters.kind)      params.kind      = filters.kind
    if (filters.type)      params.type      = filters.type
    if (filters.from_date) params.from_date = filters.from_date
    if (filters.to_date)   params.to_date   = filters.to_date

    const res  = await stockService.getTransactions(params)
    rows.value = res?.data?.data ?? res?.data ?? []
    const meta = res?.data?.meta ?? res?.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load transactions.'
  } finally {
    loading.value = false
  }
}

function goPage(p) { load(p) }

function setQuickRange(days) {
  quickRange.value = days
  const to   = new Date()
  const from = new Date(Date.now() - days * 86400000)
  filters.to_date   = to.toISOString().slice(0, 10)
  filters.from_date = from.toISOString().slice(0, 10)
  load()
}

function clearFilters() {
  filters.kind = ''; filters.type = ''; filters.from_date = ''; filters.to_date = ''
  quickRange.value = null
  load()
}

function countByType(type) { return rows.value.filter(t => t.type === type).length }

function moveLabel(t) { return { in: 'Stock In', out: 'Stock Out', adjustment: 'Adjustment' }[t] ?? t }
function moveIcon(t)  { return { in: '▲', out: '▼', adjustment: '⊘' }[t] ?? '' }
function qtySign(t)   { return t === 'in' ? '+' : t === 'out' ? '−' : '' }
function qtyClass(t)  { return t === 'in' ? 'pos' : t === 'out' ? 'neg' : 'neu' }

function fmtQty(n)  { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

onMounted(() => load())
</script>

<style scoped>
.txl-wrap { font-family: inherit; }

.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.total-badge { background: var(--primary-tint); color: var(--primary); border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

.filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px; }
.filter-select { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.filter-input  { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.date-group  { display: flex; flex-direction: column; gap: 3px; }
.date-group label { font-size: 10px; font-weight: 700; color: #999; text-transform: uppercase; }
.quick-range { display: flex; gap: 4px; }
.chip { padding: 6px 12px; border: 1px solid #ddd; background: white; border-radius: 16px; font-size: 12px; font-weight: 600; color: #666; cursor: pointer; }
.chip.active { background: var(--primary); color: white; border-color: var(--primary); }

.summary-strip { display: flex; gap: 18px; padding: 10px 16px; background: var(--surface-2); border: 1px solid #e0e4f5; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
.sum-item.in  { color: #2e7d32; }
.sum-item.out { color: #c62828; }
.sum-item.adj { color: var(--primary); }

.table-wrap { background: white; border: 1px solid #e0e0e0; border-radius: 10px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
.tx-row.in  td:first-child { border-left: 3px solid #2e7d32; }
.tx-row.out td:first-child { border-left: 3px solid #c62828; }
.tx-row.adjustment td:first-child { border-left: 3px solid var(--primary); }

.bold { font-weight: 700; }
.muted { color: #999; }
.nowrap { white-space: nowrap; }
.text-right { text-align: right; }
.pos { color: #2e7d32; }
.neg { color: #c62828; }
.neu { color: var(--primary); }
.notes-cell { color: #666; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.empty-row { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.error-row { padding: 16px; color: #c62828; background: #ffebee; border-radius: 6px; margin: 8px; }

.kind-badge { display: inline-block; padding: 2px 9px; border-radius: 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.kind-badge.coil     { background: var(--primary-tint); color: var(--primary); }
.kind-badge.chemical { background: #f3e5f5; color: #6a1b9a; }
.kind-badge.other    { background: #f5f5f5; color: #888; }

.move-badge { display: inline-block; padding: 2px 9px; border-radius: 8px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.move-badge.in         { background: #e8f5e9; color: #2e7d32; }
.move-badge.out        { background: #ffebee; color: #c62828; }
.move-badge.adjustment { background: var(--primary-tint); color: var(--primary); }

.pagination { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 14px 0; }
.page-info { font-size: 13px; color: #666; }

.btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm { padding: 5px 11px; font-size: 12px; }
</style>
