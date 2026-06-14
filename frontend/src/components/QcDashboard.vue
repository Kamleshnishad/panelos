<template>
  <div class="qcd-wrap">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Quality Control</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} records</span>
      </div>
    </div>

    <!-- Stats bar -->
    <div class="stats-bar" v-if="stats">
      <div class="stat-card">
        <div class="stat-value">{{ stats.total }}</div>
        <div class="stat-label">Total Inspections</div>
      </div>
      <div class="stat-card pass">
        <div class="stat-value">{{ stats.passed }}</div>
        <div class="stat-label">Passed</div>
      </div>
      <div class="stat-card fail">
        <div class="stat-value">{{ stats.failed }}</div>
        <div class="stat-label">Failed</div>
      </div>
      <div class="stat-card rate" :class="{ good: stats.pass_rate >= 90, warn: stats.pass_rate >= 70 && stats.pass_rate < 90, bad: stats.pass_rate < 70 }">
        <div class="stat-value">{{ stats.pass_rate }}%</div>
        <div class="stat-label">Pass Rate</div>
      </div>
      <!-- Pass rate bar -->
      <div class="stat-bar-cell">
        <div class="stat-bar-track">
          <div class="stat-bar-fill" :style="{ width: (stats.pass_rate ?? 0) + '%' }" :class="{ good: stats.pass_rate >= 90, warn: stats.pass_rate < 90 }"></div>
        </div>
        <div class="stat-bar-label">Pass Rate</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters">
      <select v-model="filters.status" class="filter-select" @change="loadList">
        <option value="">All Results</option>
        <option value="pass">Pass</option>
        <option value="fail">Fail</option>
      </select>
      <input v-model="filters.date_from" type="date" class="filter-input" @change="loadStats(); loadList()" />
      <input v-model="filters.date_to"   type="date" class="filter-input" @change="loadStats(); loadList()" />
      <button class="btn btn-ghost" @click="clearFilters">Clear</button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <div v-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th>Batch No</th>
            <th>Order</th>
            <th>Customer</th>
            <th>Result</th>
            <th>Inspected By</th>
            <th>Inspected At</th>
            <th>Approved By</th>
            <th>Notes</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <skeleton-rows v-if="loading" :rows="8" :cols="9" />
          <tr v-else-if="rows.length === 0">
            <td colspan="9" class="empty-row">No QC records found.</td>
          </tr>
          <tr v-for="row in rows" v-else :key="row.id">
            <td class="mono bold">{{ row.batch?.batch_no ?? '—' }}</td>
            <td class="mono">{{ row.batch?.order?.order_no ?? '—' }}</td>
            <td>{{ row.batch?.order?.customer?.name ?? '—' }}</td>
            <td>
              <span :class="['qc-badge', row.status]">
                {{ row.status === 'pass' ? '✅ Pass' : '❌ Fail' }}
              </span>
            </td>
            <td>{{ row.inspected_by_user?.name ?? '—' }}</td>
            <td>{{ row.inspected_at ? fmtDate(row.inspected_at) : '—' }}</td>
            <td>{{ row.approved_by_user?.name ?? '—' }}</td>
            <td class="notes-cell">{{ row.notes ?? '—' }}</td>
            <td>
              <button
                class="btn-link"
                v-if="row.batch_id"
                @click="$emit('view-batch', row.batch_id)"
              >View Batch</button>
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

const emit = defineEmits(['view-batch'])

const rows       = ref([])
const stats      = ref(null)
const loading    = ref(false)
const error      = ref(null)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })

const filters = reactive({ status: '', date_from: '', date_to: '' })

async function loadList(page = 1) {
  loading.value = true
  error.value   = null
  try {
    const params = { ...filters, page, per_page: 20 }
    const res    = await batchService.qcList(params)
    rows.value   = res?.data?.data ?? res?.data ?? []
    const meta   = res?.data?.meta ?? res?.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load QC records.'
  } finally {
    loading.value = false
  }
}

async function loadStats() {
  try {
    const params = {}
    if (filters.date_from) params.date_from = filters.date_from
    if (filters.date_to)   params.date_to   = filters.date_to
    const res    = await batchService.qcStats(params)
    stats.value  = res?.data ?? res
  } catch { stats.value = null }
}

function goPage(page) { loadList(page) }

function clearFilters() {
  filters.status    = ''
  filters.date_from = ''
  filters.date_to   = ''
  loadStats()
  loadList()
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

onMounted(() => { loadStats(); loadList() })
</script>

<style scoped>
.qcd-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }

.toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; flex: 1; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--primary); }
.total-badge { background: var(--primary-tint); color: var(--primary); border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

/* Stats bar */
.stats-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; align-items: stretch; }
.stat-card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 14px 20px; text-align: center; min-width: 100px; }
.stat-card.pass { border-color: #a5d6a7; background: #f1f8f1; }
.stat-card.fail { border-color: #ef9a9a; background: #fff5f5; }
.stat-card.rate.good { border-color: var(--primary); background: var(--primary-tint); }
.stat-card.rate.warn { border-color: #ffc107; background: #fffde7; }
.stat-card.rate.bad  { border-color: #ef9a9a; background: #fff5f5; }
.stat-value { font-size: 26px; font-weight: 800; color: var(--primary); line-height: 1; }
.stat-card.pass .stat-value { color: #2e7d32; }
.stat-card.fail .stat-value { color: #c62828; }
.stat-label { font-size: 11px; color: #888; text-transform: uppercase; margin-top: 4px; font-weight: 600; }

.stat-bar-cell { flex: 1; min-width: 180px; background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 14px 20px; display: flex; flex-direction: column; justify-content: center; }
.stat-bar-track { height: 12px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin-bottom: 6px; }
.stat-bar-fill  { height: 100%; border-radius: 10px; transition: width 0.6s ease; background: #c62828; }
.stat-bar-fill.good { background: linear-gradient(90deg, #2e7d32, #43a047); }
.stat-bar-fill.warn { background: linear-gradient(90deg, #f57f17, #ffa726); }
.stat-bar-label { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; text-align: center; }

/* Filters */
.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.filter-select { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.filter-input  { padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

/* Table */
.table-wrap  { background: white; border: 1px solid var(--border); border-radius: 10px; overflow: auto; max-height: calc(100vh - 330px); }
table        { width: 100%; border-collapse: collapse; font-size: 13px; }
th           { position: sticky; top: 0; z-index: 1; background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
td           { padding: 10px 12px; border-bottom: 1px solid var(--row); vertical-align: middle; }
tbody tr:nth-child(even) td { background: var(--surface-2); }
tbody tr:hover td { background: var(--primary-tint); }
.mono        { font-family: var(--mono); font-variant-numeric: tabular-nums; }
.bold        { font-weight: 700; }
.notes-cell  { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #666; font-size: 12px; }
.empty-row   { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.error-row   { padding: 16px; color: #c62828; background: #ffebee; border-radius: 6px; margin: 8px; }

.qc-badge    { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; }
.qc-badge.pass { background: #e8f5e9; color: #2e7d32; }
.qc-badge.fail { background: #ffebee; color: #c62828; }

.btn-link    { background: none; border: none; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0; }
.btn-link:hover { color: var(--primary-hover); }

/* Pagination */
.pagination  { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 14px 0; }
.page-info   { font-size: 13px; color: #666; }
.btn         { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost   { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm      { padding: 5px 11px; font-size: 12px; }
</style>
