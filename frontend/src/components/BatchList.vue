<template>
  <div class="batch-list">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Production Batches</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} total</span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-primary" @click="$emit('create')">+ New Batch</button>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters">
      <input v-model="filters.search" class="filter-input" placeholder="Search batch no…" @input="debouncedLoad" />
      <select v-model="filters.status" class="filter-select" @change="loadList">
        <option value="">All Statuses</option>
        <option value="draft">Draft</option>
        <option value="in_progress">In Progress</option>
        <option value="qc_pending">QC Pending</option>
        <option value="qc_passed">QC Passed</option>
        <option value="qc_failed">QC Failed</option>
        <option value="completed">Completed</option>
      </select>
      <button class="btn btn-ghost" @click="clearFilters">Clear</button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <div v-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th>Batch No</th>
            <th>Order No</th>
            <th>Customer</th>
            <th class="text-right">Planned Qty</th>
            <th class="text-right">Completed Qty</th>
            <th>Status</th>
            <th>Started</th>
            <th>Completed</th>
            <th>QC</th>
          </tr>
        </thead>
        <tbody>
          <skeleton-rows v-if="loading" :rows="8" :cols="9" />
          <tr v-else-if="rows.length === 0">
            <td colspan="9" class="empty-row">No batches found.</td>
          </tr>
          <tr v-for="row in rows" v-else :key="row.id" class="clickable" tabindex="0" role="button" :aria-label="'Open ' + row.batch_no" @click="$emit('view', row.id)" @keyup.enter="$emit('view', row.id)">
            <td class="mono bold">{{ row.batch_no }}</td>
            <td class="mono">{{ row.order?.order_no ?? '—' }}</td>
            <td>{{ row.order?.customer?.name ?? '—' }}</td>
            <td class="text-right">{{ fmtQty(row.planned_quantity) }}</td>
            <td class="text-right">{{ row.completed_quantity ? fmtQty(row.completed_quantity) : '—' }}</td>
            <td><span :class="['status-badge', row.status]">{{ statusLabel(row.status) }}</span></td>
            <td>{{ row.started_at   ? fmtDate(row.started_at)   : '—' }}</td>
            <td>{{ row.completed_at ? fmtDate(row.completed_at) : '—' }}</td>
            <td>
              <span v-if="row.quality_control" :class="['qc-badge', row.quality_control.status]">
                {{ row.quality_control.status }}
              </span>
              <span v-else class="text-muted">—</span>
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
import batchService from '../services/batchService.js'
import SkeletonRows from './SkeletonRows.vue'

const emit = defineEmits(['view', 'create'])

const rows       = ref([])
const loading    = ref(false)
const error      = ref(null)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })

const filters = reactive({ search: '', status: '', sort_by: 'created_at', sort_order: 'desc' })

async function loadList(page = 1) {
  loading.value = true
  error.value   = null
  try {
    const res  = await batchService.list({ ...filters, page, per_page: 20 })
    rows.value = res?.data?.data ?? res?.data ?? []
    const meta = res?.data?.meta ?? res?.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load batches.'
  } finally {
    loading.value = false
  }
}

function goPage(page) { loadList(page) }
function clearFilters() { filters.search = ''; filters.status = ''; loadList() }

let _debTimer = null
function debouncedLoad() { clearTimeout(_debTimer); _debTimer = setTimeout(() => loadList(), 350) }

function statusLabel(s) {
  return { draft: 'Draft', in_progress: 'In Progress', qc_pending: 'QC Pending', qc_passed: 'QC Passed', qc_failed: 'QC Failed', completed: 'Completed' }[s] ?? s
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtQty(n) { return Number(n || 0).toFixed(2) }

defineExpose({ reload: () => loadList() })
onMounted(() => loadList())
</script>

<style scoped>
.batch-list { font-family: inherit; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.total-badge { background: var(--primary-tint); color: var(--primary); border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.filter-input  { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 200px; }
.filter-select { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.table-wrap { background: white; border: 1px solid var(--border); border-radius: 10px; overflow: auto; max-height: calc(100vh - 230px); }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { position: sticky; top: 0; z-index: 1; background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
td { padding: 10px 12px; border-bottom: 1px solid var(--row); vertical-align: middle; }
tbody tr:nth-child(even) td { background: var(--surface-2); }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--primary-tint); }

.mono { font-family: var(--mono); font-variant-numeric: tabular-nums; }
.bold { font-weight: 700; }
.text-right { text-align: right; font-variant-numeric: tabular-nums; }
.text-muted { color: var(--text-3); font-size: 12px; }
.empty-row { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.error-row   { padding: 16px; color: #c62828; background: #ffebee; border-radius: 6px; margin: 8px; }

.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
.status-badge.draft       { background: #f3f4f6; color: #6b7280; border: 1px solid #e0e0e0; }
.status-badge.in_progress { background: #e8f5e9; color: #2e7d32; }
.status-badge.qc_pending  { background: #fff3e0; color: #e65100; }
.status-badge.qc_passed   { background: var(--primary-tint); color: var(--primary); }
.status-badge.qc_failed   { background: #ffebee; color: #c62828; }
.status-badge.completed   { background: #e0f2f1; color: #00695c; }

.qc-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.qc-badge.pending  { background: #fff3e0; color: #e65100; }
.qc-badge.approved,
.qc-badge.pass     { background: #e8f5e9; color: #2e7d32; }
.qc-badge.rejected,
.qc-badge.fail     { background: #ffebee; color: #c62828; }

.pagination { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 14px 0; }
.page-info { font-size: 13px; color: #666; }

.btn       { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm { padding: 5px 11px; font-size: 12px; }
</style>
