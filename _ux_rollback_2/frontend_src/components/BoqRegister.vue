<template>
  <div class="boq-wrap">
    <div class="toolbar">
      <div class="toolbar-left">
        <h2>BOQ Register</h2>
        <span class="total-badge" v-if="pagination.total">{{ pagination.total }} open</span>
      </div>
      <button class="btn btn-primary" @click="$emit('add')">+ Add BOQ</button>
    </div>

    <p class="boq-intro">
      BOQs are prepared by the technical team <strong>without rates</strong>. When ready,
      <strong>Convert to Quotation</strong> hands it to Sales to price and send.
    </p>

    <div class="filters">
      <input v-model="filters.search" class="filter-input" placeholder="Search BOQ no, project, customer…" @input="debouncedLoad" />
      <button class="btn btn-ghost" @click="clearFilters">Clear</button>
    </div>

    <div class="table-wrap">
      <div v-if="loading" class="loading-row">Loading…</div>
      <div v-else-if="error" class="error-row">{{ error }}</div>
      <table v-else>
        <thead>
          <tr>
            <th>BOQ No</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Project</th>
            <th class="text-center">Panels</th>
            <th class="text-right">Total SQM</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0"><td colspan="7" class="empty-row">No open BOQs. Click “+ Add BOQ” to create one.</td></tr>
          <tr v-for="row in rows" :key="row.id" class="clickable" @click="$emit('edit', row.id)">
            <td class="mono bold">{{ row.quotation_no }}</td>
            <td>{{ fmtDate(row.quoted_on) }}</td>
            <td>{{ row.customer?.name ?? '—' }}</td>
            <td class="muted">{{ row.project_name || '—' }}</td>
            <td class="text-center">{{ row.items?.length ?? 0 }}</td>
            <td class="text-right bold">{{ fmtSqm(row.total_sqm) }}</td>
            <td class="actions" @click.stop>
              <button class="btn-sm btn-boq" :disabled="boqBusy === row.id" title="Download BOQ cutting sheet (no rates)" @click="openBoq(row)">{{ boqBusy === row.id ? '…' : '🔧 Sheet' }}</button>
              <button class="btn-sm btn-edit" @click="$emit('edit', row.id)" title="Edit BOQ specs">Edit</button>
              <button class="btn-sm btn-convert" :disabled="convertBusy === row.id" title="Convert to a priced quotation (Sales adds rates)" @click="convert(row)">{{ convertBusy === row.id ? '…' : 'Convert →' }}</button>
            </td>
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
import quotationService from '../services/quotationService.js'
import { confirmDialog, toastSuccess, toastError } from '../services/ui.js'

const emit = defineEmits(['add', 'edit', 'converted'])

const rows        = ref([])
const loading     = ref(false)
const error       = ref(null)
const boqBusy     = ref(null)
const convertBusy = ref(null)
const pagination  = reactive({ current_page: 1, last_page: 1, total: 0 })
const filters     = reactive({ search: '', status: 'boq', sort_by: 'created_at', sort_order: 'desc' })

async function loadList(page = 1) {
  loading.value = true; error.value = null
  try {
    const res  = await quotationService.list({ ...filters, page, per_page: 20 })
    rows.value = res?.data?.data ?? res?.data ?? []
    const meta = res?.data?.meta ?? res?.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? rows.value.length
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load BOQs.'
  } finally { loading.value = false }
}

function goPage(p) { loadList(p) }
function clearFilters() { filters.search = ''; loadList() }
let _t = null
function debouncedLoad() { clearTimeout(_t); _t = setTimeout(() => loadList(), 350) }

async function openBoq(row) {
  boqBusy.value = row.id
  try { await quotationService.openBoqPdf(row.id) } catch { /* ignore */ }
  finally { boqBusy.value = null }
}

async function convert(row) {
  const ok = await confirmDialog({
    title: 'Convert BOQ to Quotation?',
    message: `${row.quotation_no} will move to Quotations as a draft where Sales can enter rates.`,
    confirmLabel: 'Convert',
  })
  if (!ok) return
  convertBusy.value = row.id
  try {
    const res = await quotationService.convert(row.id)
    const newId = res?.data?.id ?? row.id
    await loadList(pagination.current_page)
    toastSuccess(`${row.quotation_no} converted to a quotation.`)
    emit('converted', newId)
  } catch (e) {
    toastError(e?.response?.data?.message ?? 'Failed to convert BOQ.')
  } finally { convertBusy.value = null }
}

function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
function fmtSqm(n)  { return Number(n || 0).toFixed(2) }

defineExpose({ reload: () => loadList() })
onMounted(() => loadList())
</script>

<style scoped>
.boq-wrap { font-family: inherit; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-left h2 { margin: 0; font-size: 20px; color: var(--ink); letter-spacing: -0.01em; }
.total-badge { background: var(--primary-tint); color: var(--primary); border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

.boq-intro { margin: 0 0 16px; font-size: 12.5px; color: var(--text-2); line-height: 1.5; max-width: 720px; }

.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; align-items: center; }
.filter-input  { padding: 7px 11px; border: 1px solid var(--border-2); border-radius: 6px; font-size: 13px; min-width: 240px; }

.table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
td { padding: 10px 12px; border-bottom: 1px solid var(--row); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--surface-3); }
.mono { font-family: var(--mono); } .bold { font-weight: 700; } .muted { color: var(--text-3); }
.text-right { text-align: right; } .text-center { text-align: center; }
.empty-row { text-align: center; padding: 40px; color: #aaa; font-style: italic; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.error-row { padding: 16px; color: var(--danger); background: var(--danger-bg); border-radius: 6px; margin: 8px; }

.actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; border: none; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer; }
.btn-sm:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-boq { background: var(--teal-bg); color: var(--teal); }
.btn-edit { background: var(--primary-tint); color: var(--primary); }
.btn-convert { background: var(--primary); color: #fff; }

.pagination { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 14px 0; }
.page-info { font-size: 13px; color: #666; }
.btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-ghost { background: transparent; border: 1px solid var(--border-2); color: #555; }
.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }
</style>
