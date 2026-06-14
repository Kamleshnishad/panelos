<template>
  <div class="order-list">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Production Orders</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} total</span>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters">
      <input
        v-model="filters.search"
        class="filter-input"
        placeholder="Search order no, project, customer…"
        @input="debouncedLoad"
      />
      <select v-model="filters.status" class="filter-select" @change="loadList">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="in_production">In Production</option>
        <option value="completed">Completed</option>
        <option value="cancelled">Cancelled</option>
      </select>
      <button class="btn btn-ghost" @click="clearFilters">Clear</button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <div v-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th class="sortable" :aria-sort="aria('order_no')" @click="sortBy('order_no')">Order No <sort-icon field="order_no" :current="filters.sort_by" :order="filters.sort_order" /></th>
            <th class="sortable" :aria-sort="aria('order_date')" @click="sortBy('order_date')">Date <sort-icon field="order_date" :current="filters.sort_by" :order="filters.sort_order" /></th>
            <th>Customer</th>
            <th>Project</th>
            <th class="text-right">Total SQM</th>
            <th class="text-right sortable" :aria-sort="aria('total_amount')" @click="sortBy('total_amount')">Amount <sort-icon field="total_amount" :current="filters.sort_by" :order="filters.sort_order" /></th>
            <th>Delivery</th>
            <th>Status</th>
            <th>Batches</th>
          </tr>
        </thead>
        <tbody>
          <skeleton-rows v-if="loading" :rows="8" :cols="9" />
          <tr v-else-if="rows.length === 0">
            <td colspan="9" class="empty-row">No orders found.</td>
          </tr>
          <tr
            v-for="row in rows"
            v-else
            :key="row.id"
            class="clickable"
            tabindex="0"
            role="button"
            :aria-label="'Open ' + row.order_no"
            @click="$emit('view', row.id)"
            @keyup.enter="$emit('view', row.id)"
          >
            <td class="mono bold">{{ row.order_no }}</td>
            <td>{{ fmtDate(row.order_date) }}</td>
            <td>{{ row.customer?.name }}</td>
            <td class="text-muted">{{ row.project_name || '—' }}</td>
            <td class="text-right">{{ fmtSqm(row.total_sqm) }}</td>
            <td class="text-right bold">₹ {{ fmtNum(row.total_amount) }}</td>
            <td :class="{ 'overdue': isOverdue(row.expected_delivery_date) }">
              {{ fmtDate(row.expected_delivery_date) }}
              <span v-if="isOverdue(row.expected_delivery_date)" class="overdue-tag">Overdue</span>
            </td>
            <td><span :class="['status-badge', row.status]">{{ statusLabel(row.status) }}</span></td>
            <td class="text-center">
              <span class="batch-count" v-if="row.batches?.length > 0">{{ row.batches.length }}</span>
              <span class="text-muted" v-else>—</span>
            </td>
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
import orderService from '../services/orderService.js'
import SortIcon from './SortIcon.vue'
import SkeletonRows from './SkeletonRows.vue'

const emit = defineEmits(['view'])

const rows       = ref([])
const loading    = ref(false)
const error      = ref(null)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })

const filters = reactive({
  search:     '',
  status:     '',
  sort_by:    'created_at',
  sort_order: 'desc',
})

async function loadList(page = 1) {
  loading.value = true
  error.value   = null
  try {
    const res  = await orderService.list({ ...filters, page, per_page: 20 })
    rows.value = res?.data?.data ?? res?.data ?? []
    const meta = res?.data?.meta ?? res?.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load orders.'
  } finally {
    loading.value = false
  }
}

function goPage(page) { loadList(page) }

function clearFilters() {
  filters.search = ''
  filters.status = ''
  loadList()
}

let _debTimer = null
function debouncedLoad() {
  clearTimeout(_debTimer)
  _debTimer = setTimeout(() => loadList(), 350)
}

function sortBy(field) {
  if (filters.sort_by === field) {
    filters.sort_order = filters.sort_order === 'asc' ? 'desc' : 'asc'
  } else {
    filters.sort_by    = field
    filters.sort_order = 'desc'
  }
  loadList()
}

function aria(field) {
  if (filters.sort_by !== field) return 'none'
  return filters.sort_order === 'asc' ? 'ascending' : 'descending'
}

function statusLabel(s) {
  return { pending: 'Pending', in_production: 'In Production', completed: 'Completed', cancelled: 'Cancelled' }[s] ?? s
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtNum(n)  { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtSqm(n)  { return Number(n || 0).toFixed(2) }
function isOverdue(d) { return d && new Date(d) < new Date() }

// Expose reload so parent (OrderManager) can refresh after actions
defineExpose({ reload: () => loadList() })

onMounted(() => loadList())
</script>

<style scoped>
.order-list { font-family: inherit; }

.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.total-badge { background: var(--primary-tint); color: var(--primary); border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.filter-input  { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 220px; }
.filter-select { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.table-wrap { background: white; border: 1px solid var(--border); border-radius: 10px; overflow: auto; max-height: calc(100vh - 230px); }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { position: sticky; top: 0; z-index: 1; background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
th.sortable { cursor: pointer; user-select: none; }
th.sortable:hover { background: var(--primary-hover); }
td { padding: 10px 12px; border-bottom: 1px solid var(--row); vertical-align: middle; }
tbody tr:nth-child(even) td { background: var(--surface-2); }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--primary-tint); }

.mono  { font-family: var(--mono); font-variant-numeric: tabular-nums; }
.bold  { font-weight: 700; }
.text-right  { text-align: right; font-variant-numeric: tabular-nums; }
.text-center { text-align: center; }
.text-muted  { color: var(--text-3); }
.empty-row   { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.error-row   { padding: 16px; color: #c62828; background: #ffebee; border-radius: 6px; margin: 8px; }

.overdue      { color: #c62828; }
.overdue-tag  { font-size: 10px; font-weight: 700; background: #ffebee; color: #c62828; border-radius: 8px; padding: 1px 6px; margin-left: 4px; }

.batch-count { background: var(--primary-tint); color: var(--primary); border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 700; }

.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
.status-badge.pending       { background: #fff8e1; color: #f57f17; }
.status-badge.in_production { background: #e8f5e9; color: #2e7d32; }
.status-badge.completed     { background: var(--primary-tint); color: var(--primary); }
.status-badge.cancelled     { background: #fafafa; color: #aaa; border: 1px solid #e0e0e0; }

.pagination { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 14px 0; }
.page-info  { font-size: 13px; color: #666; }

.btn      { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm   { padding: 5px 11px; font-size: 12px; }
</style>
