<template>
  <div class="quotation-list">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>Quotations</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} total</span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-primary" @click="$emit('create')">+ New Quotation</button>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters">
      <input v-model="filters.search" class="filter-input" placeholder="Search by number, project, customer…" @input="debouncedLoad" />
      <select v-model="filters.status" class="filter-select" @change="loadList">
        <option value="">All Statuses</option>
        <option value="draft">Draft</option>
        <option value="sent">Sent</option>
        <option value="accepted">Accepted</option>
        <option value="rejected">Rejected</option>
        <option value="revised">Revised</option>
        <option value="expired">Expired</option>
      </select>
      <input v-model="filters.from_date" type="date" class="filter-input" @change="loadList" />
      <input v-model="filters.to_date" type="date" class="filter-input" @change="loadList" />
      <button class="btn btn-ghost" @click="clearFilters">Clear</button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <div v-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th class="sortable" :aria-sort="aria('quotation_no')" @click="sortBy('quotation_no')">PFI No. <sort-icon field="quotation_no" :current="filters.sort_by" :order="filters.sort_order" /></th>
            <th class="sortable" :aria-sort="aria('created_at')" @click="sortBy('created_at')">Date <sort-icon field="created_at" :current="filters.sort_by" :order="filters.sort_order" /></th>
            <th>Customer</th>
            <th>Project</th>
            <th class="text-right sortable" :aria-sort="aria('total_amount')" @click="sortBy('total_amount')">Amount <sort-icon field="total_amount" :current="filters.sort_by" :order="filters.sort_order" /></th>
            <th class="text-right">SQM</th>
            <th>Status</th>
            <th>Valid Until</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <skeleton-rows v-if="loading" :rows="8" :cols="9" />
          <tr v-else-if="rows.length === 0">
            <td colspan="9" class="empty-row">No quotations found.</td>
          </tr>
          <tr v-for="row in rows" v-else :key="row.id" @click="$emit('view', row.id)" class="clickable">
            <td class="mono bold">{{ row.quotation_no }}</td>
            <td>{{ fmtDate(row.quoted_on) }}</td>
            <td>{{ row.customer?.name }}</td>
            <td class="text-muted">{{ row.project_name || '—' }}</td>
            <td class="text-right bold">₹ {{ fmtNum(row.total_amount) }}</td>
            <td class="text-right">{{ fmtSqm(row.total_sqm) }}</td>
            <td>
              <span :class="['status-badge', row.status]">{{ row.status }}</span>
              <span v-if="row.rates_pending" class="rates-pending-badge" title="Rates not yet entered">Rates Pending</span>
            </td>
            <td :class="{ expired: isExpired(row.valid_until) }">{{ fmtDate(row.valid_until) }}</td>
            <td class="actions" @click.stop>
              <button class="btn-sm btn-view" @click="$emit('view', row.id)" title="View">View</button>
              <button v-if="row.status === 'draft'" class="btn-sm btn-edit" @click="$emit('edit', row.id)" title="Edit">Edit</button>
              <button v-if="row.status === 'draft'" class="btn-sm btn-send" @click="confirmSend(row)" title="Send">Send</button>
              <button v-if="row.status === 'accepted'" class="btn-sm btn-order" @click="$emit('create-order', row.id)" title="Create Order">Order</button>
              <button class="btn-sm btn-pdf" :disabled="pdfBusy === row.id" title="Open PDF" @click.stop="openPdf(row)">{{ pdfBusy === row.id ? '…' : 'PDF' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination" v-if="pagination.last_page > 1">
      <button :disabled="pagination.current_page <= 1" @click="gotoPage(pagination.current_page - 1)">‹ Prev</button>
      <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
      <button :disabled="pagination.current_page >= pagination.last_page" @click="gotoPage(pagination.current_page + 1)">Next ›</button>
    </div>

    <!-- Confirm send dialog -->
    <div v-if="sendTarget" class="modal-overlay" @click.self="sendTarget = null">
      <div class="modal-box">
        <h3>Send Quotation?</h3>
        <p>Send <strong>{{ sendTarget.quotation_no }}</strong> to {{ sendTarget.customer?.name }}?<br>Status will change to <em>Sent</em> and cannot be edited.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="sendTarget = null">Cancel</button>
          <button class="btn btn-primary" :disabled="sending" @click="doSend">{{ sending ? 'Sending…' : 'Confirm Send' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import quotationService from '../services/quotationService.js'
import { toastSuccess, toastError } from '../services/ui.js'
import SortIcon from './SortIcon.vue'
import SkeletonRows from './SkeletonRows.vue'

const emit = defineEmits(['create', 'view', 'edit', 'create-order'])

const rows = ref([])
const loading = ref(false)
const error = ref(null)
const sendTarget = ref(null)
const sending = ref(false)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, per_page: 20 })

const filters = reactive({
  search: '',
  status: '',
  from_date: '',
  to_date: '',
  sort_by: 'created_at',
  sort_order: 'desc',
  page: 1,
  per_page: 20,
})

let debounceTimer = null
function debouncedLoad() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(loadList, 350)
}

async function loadList() {
  loading.value = true
  error.value = null
  try {
    const params = {}
    if (filters.search)    params.search    = filters.search
    if (filters.status)    params.status    = filters.status
    if (filters.from_date) params.from_date = filters.from_date
    if (filters.to_date)   params.to_date   = filters.to_date
    params.sort_by    = filters.sort_by
    params.sort_order = filters.sort_order
    params.page       = filters.page
    params.per_page   = filters.per_page

    const res = await quotationService.list(params)
    rows.value = res.data?.data ?? res.data ?? []
    const meta = res.data?.meta ?? res.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load quotations.'
  } finally {
    loading.value = false
  }
}

function sortBy(field) {
  if (filters.sort_by === field) {
    filters.sort_order = filters.sort_order === 'asc' ? 'desc' : 'asc'
  } else {
    filters.sort_by = field
    filters.sort_order = 'desc'
  }
  loadList()
}

function aria(field) {
  if (filters.sort_by !== field) return 'none'
  return filters.sort_order === 'asc' ? 'ascending' : 'descending'
}

function gotoPage(page) {
  filters.page = page
  loadList()
}

function clearFilters() {
  filters.search = ''
  filters.status = ''
  filters.from_date = ''
  filters.to_date = ''
  filters.page = 1
  loadList()
}

function confirmSend(row) { sendTarget.value = row }

async function doSend() {
  sending.value = true
  try {
    await quotationService.send(sendTarget.value.id)
    sendTarget.value = null
    toastSuccess('Quotation sent.')
    loadList()
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Failed to send.')
  } finally {
    sending.value = false
  }
}

const pdfBusy = ref(null)
async function openPdf(row) {
  pdfBusy.value = row.id
  try { await quotationService.openPdf(row.id) }
  catch { /* ignore */ }
  finally { pdfBusy.value = null }
}

function fmtDate(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}

function fmtNum(n) {
  return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtSqm(n) {
  return Number(n || 0).toFixed(2) + ' SQM'
}

function isExpired(d) {
  if (!d) return false
  return new Date(d) < new Date()
}

onMounted(loadList)
</script>

<style scoped>
.quotation-list { font-family: inherit; }

.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.toolbar-left { display: flex; align-items: center; gap: 12px; }
.toolbar-left h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--primary); }
.total-badge { background: var(--primary-tint); color: var(--primary); font-size: 12px; padding: 2px 8px; border-radius: 12px; font-weight: 600; }

.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.filter-input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 160px; }
.filter-select { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

.table-wrap { overflow: auto; max-height: calc(100vh - 230px); border: 1px solid var(--border); border-radius: 8px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th { position: sticky; top: 0; z-index: 1; background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-weight: 600; white-space: nowrap; }
thead th.sortable { cursor: pointer; user-select: none; }
thead th.sortable:hover { background: var(--primary-hover); }
tbody tr { border-bottom: 1px solid var(--row); }
tbody tr:nth-child(even) td { background: var(--surface-2); }
tbody tr.clickable:hover td { background: var(--primary-tint); cursor: pointer; }
td { padding: 9px 12px; vertical-align: middle; }

.mono { font-family: var(--mono); font-variant-numeric: tabular-nums; }
.bold { font-weight: 600; }
.text-right { text-align: right; font-variant-numeric: tabular-nums; }
.text-muted { color: var(--text-3); font-size: 12px; }
.expired { color: #c62828; font-weight: 600; }

.loading-row, .error-row, .empty-row { padding: 32px; text-align: center; color: #888; font-style: italic; }
.error-row { color: #c62828; }

.rates-pending-badge { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; background: #FBF0DA; color: #B5740A; border: 1px solid #EFD9A8; }
.status-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.status-badge.draft    { background: var(--primary-tint); color: var(--primary); }
.status-badge.sent     { background: #fff8e1; color: #f57f17; }
.status-badge.accepted { background: #e8f5e9; color: #2e7d32; }
.status-badge.rejected { background: #ffebee; color: #c62828; }
.status-badge.revised  { background: #f3e5f5; color: #6a1b9a; }
.status-badge.expired  { background: #fafafa; color: #aaa; }

.actions { display: flex; gap: 4px; white-space: nowrap; }
.btn-sm { padding: 3px 9px; border: none; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; }
.btn-view  { background: var(--primary-tint); color: var(--primary); }
.btn-edit  { background: #fff8e1; color: #f57f17; }
.btn-send  { background: #e0f2f1; color: #00695c; }
.btn-order { background: #e8f5e9; color: #2e7d32; }
.btn-pdf   { background: #fce4ec; color: #c62828; text-decoration: none; display: inline-block; }
.btn-sm:hover { filter: brightness(0.93); }

.pagination { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 16px; font-size: 13px; }
.pagination button { padding: 6px 14px; border: 1px solid #ddd; border-radius: 6px; background: white; cursor: pointer; }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }

.btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box { background: white; border-radius: 10px; padding: 28px 32px; min-width: 360px; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.modal-box h3 { margin: 0 0 10px; font-size: 17px; color: var(--primary); }
.modal-box p { color: #555; font-size: 14px; margin-bottom: 20px; line-height: 1.6; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
</style>
