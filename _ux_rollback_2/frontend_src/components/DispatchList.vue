<template>
  <div class="dl-wrap">
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Dispatches</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} total</span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-primary" @click="$emit('create')">+ New Dispatch</button>
      </div>
    </div>

    <div class="filters">
      <input v-model="filters.search" class="filter-input" placeholder="Search dispatch no…" @input="debouncedLoad" />
      <select v-model="filters.status" class="filter-select" @change="load">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="delivered">Delivered</option>
        <option value="cancelled">Cancelled</option>
      </select>
      <input v-model="filters.from_date" type="date" class="filter-input" @change="load" />
      <input v-model="filters.to_date" type="date" class="filter-input" @change="load" />
      <button class="btn btn-ghost" @click="clearFilters">Clear</button>
    </div>

    <div class="table-wrap">
      <div v-if="loading" class="loading-row">Loading…</div>
      <div v-else-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th>Dispatch No</th>
            <th>Batch</th>
            <th>Dispatch Date</th>
            <th>Expected Delivery</th>
            <th class="text-right">Items</th>
            <th class="text-right">Total Value</th>
            <th>Tracking</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0"><td colspan="8" class="empty-row">No dispatches found.</td></tr>
          <tr v-for="row in rows" :key="row.id" class="clickable" @click="$emit('view', row.id)">
            <td class="mono bold">{{ row.dispatch_no }}</td>
            <td class="mono">{{ row.batch?.batch_no ?? '—' }}</td>
            <td>{{ fmtDate(row.dispatch_date) }}</td>
            <td :class="{ overdue: isOverdue(row) }">
              {{ fmtDate(row.expected_delivery_date) }}
              <span v-if="isOverdue(row)" class="overdue-tag">Overdue</span>
            </td>
            <td class="text-right">{{ row.items?.length ?? 0 }}</td>
            <td class="text-right bold">₹ {{ fmtNum(itemsTotal(row)) }}</td>
            <td class="muted">{{ row.tracking_number ?? '—' }}</td>
            <td><span :class="['status-badge', row.status]">{{ statusLabel(row.status) }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="pagination" v-if="pagination.last_page > 1">
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
      <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
      <button class="btn btn-ghost btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import dispatchService from '../services/dispatchService.js'

const emit = defineEmits(['view', 'create'])

const rows       = ref([])
const loading    = ref(false)
const error      = ref(null)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })
const filters    = reactive({ search: '', status: '', from_date: '', to_date: '' })

async function load(page = 1) {
  loading.value = true
  error.value   = null
  try {
    const res = await dispatchService.list({ ...filters, page, per_page: 20 })
    // apiResponse -> { data: paginator }. paginator has .data + current_page/last_page/total
    const p = res?.data ?? {}
    rows.value = p.data ?? []
    pagination.current_page = p.current_page ?? 1
    pagination.last_page    = p.last_page    ?? 1
    pagination.total        = p.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load dispatches.'
  } finally {
    loading.value = false
  }
}

function goPage(p) { load(p) }
function clearFilters() { Object.assign(filters, { search: '', status: '', from_date: '', to_date: '' }); load() }

let _t = null
function debouncedLoad() { clearTimeout(_t); _t = setTimeout(() => load(), 350) }

function itemsTotal(row) { return (row.items ?? []).reduce((s, i) => s + Number(i.amount || 0), 0) }
function statusLabel(s) { return { pending: 'Pending', delivered: 'Delivered', cancelled: 'Cancelled' }[s] ?? s }
function isOverdue(row) {
  return row.status === 'pending' && row.expected_delivery_date && new Date(row.expected_delivery_date) < new Date()
}
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
function fmtNum(n)  { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

defineExpose({ reload: () => load() })
onMounted(() => load())
</script>

<style scoped>
.dl-wrap { font-family: inherit; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.total-badge { background: var(--primary-tint); color: var(--primary); border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.filter-input  { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 180px; }
.filter-select { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.table-wrap { background: white; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--surface-2); }

.mono { font-family: monospace; }
.bold { font-weight: 700; }
.muted { color: #999; }
.text-right { text-align: right; }
.empty-row { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.error-row { padding: 16px; color: #c62828; background: #ffebee; border-radius: 6px; margin: 8px; }

.overdue { color: #c62828; }
.overdue-tag { font-size: 10px; font-weight: 700; background: #ffebee; color: #c62828; border-radius: 8px; padding: 1px 6px; margin-left: 4px; }

.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.pending   { background: #fff8e1; color: #f57f17; }
.status-badge.delivered { background: #e8f5e9; color: #2e7d32; }
.status-badge.cancelled { background: #fafafa; color: #aaa; border: 1px solid #e0e0e0; }

.pagination { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 14px 0; }
.page-info { font-size: 13px; color: #666; }
.btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm { padding: 5px 11px; font-size: 12px; }
</style>
