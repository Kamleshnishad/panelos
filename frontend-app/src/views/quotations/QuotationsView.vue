<template>
  <div>
    <div class="page-header">
      <div>
        <div class="page-title">Quotations / BOQ</div>
        <div class="page-subtitle">{{ pagination.total }} quotations found</div>
      </div>
      <button class="btn btn-primary" @click="$router.push('/quotations/create')">+ New BOQ</button>
    </div>

    <!-- Filters -->
    <div class="card" style="margin-bottom:16px;padding:14px 20px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="form-label" style="margin-bottom:4px">Search</label>
          <input v-model="filters.search" class="form-control" placeholder="Quotation #, project, customer…" style="min-width:220px" @input="debounceLoad" />
        </div>
        <div>
          <label class="form-label" style="margin-bottom:4px">Status</label>
          <select v-model="filters.status" class="form-control" @change="load">
            <option value="">All</option>
            <option value="draft">Draft</option>
            <option value="sent">Sent</option>
            <option value="accepted">Accepted</option>
            <option value="rejected">Rejected</option>
            <option value="revised">Revised</option>
            <option value="expired">Expired</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="margin-bottom:4px">From Date</label>
          <input v-model="filters.from_date" type="date" class="form-control" @change="load" />
        </div>
        <div>
          <label class="form-label" style="margin-bottom:4px">To Date</label>
          <input v-model="filters.to_date" type="date" class="form-control" @change="load" />
        </div>
        <button class="btn btn-outline" @click="clearFilters">Clear</button>
        <button class="btn btn-outline" @click="load">🔄 Refresh</button>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="cursor:pointer" @click="sortBy('quotation_no')">PFI No. {{ sortIcon('quotation_no') }}</th>
              <th>Customer</th>
              <th>Project</th>
              <th class="text-right" style="cursor:pointer" @click="sortBy('total_amount')">Amount {{ sortIcon('total_amount') }}</th>
              <th class="text-right">SQM</th>
              <th>Status</th>
              <th style="cursor:pointer" @click="sortBy('quoted_on')">Date {{ sortIcon('quoted_on') }}</th>
              <th>Valid Until</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in items" :key="q.id">
              <td>
                <a @click="$router.push('/quotations/'+q.id)" style="cursor:pointer;color:var(--primary);font-weight:700;font-family:monospace">
                  {{ q.quotation_no }}
                </a>
                <span v-if="q.revision_number > 1" style="font-size:10px;background:#f3e5f5;color:#6a1b9a;padding:1px 5px;border-radius:8px;margin-left:4px">v{{ q.revision_number }}</span>
              </td>
              <td>{{ q.customer?.name ?? '—' }}</td>
              <td style="color:#888;font-size:12px">{{ q.project_name || '—' }}</td>
              <td class="text-right" style="font-weight:600">₹{{ fmt(q.total_amount) }}</td>
              <td class="text-right" style="font-size:12px;color:#555">{{ q.total_sqm ? Number(q.total_sqm).toFixed(2) + ' SQM' : '—' }}</td>
              <td><span :class="'badge badge-'+q.status">{{ q.status }}</span></td>
              <td style="font-size:12px">{{ fmtDate(q.quoted_on || q.created_at) }}</td>
              <td style="font-size:12px" :style="isExpired(q.valid_until) ? 'color:#c62828;font-weight:600' : ''">{{ fmtDate(q.valid_until) }}</td>
              <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                  <button class="btn btn-sm btn-outline" @click="$router.push('/quotations/'+q.id)">View</button>
                  <button v-if="q.status==='draft'" class="btn btn-sm btn-primary" @click="$router.push('/quotations/'+q.id+'/edit')">Edit</button>
                  <button v-if="q.status==='draft'" class="btn btn-sm btn-success" :disabled="actionId===q.id" @click="send(q)">Send</button>
                  <button v-if="['sent','accepted'].includes(q.status)" class="btn btn-sm btn-outline" :disabled="actionId===q.id" @click="revise(q)">Revise</button>
                  <button class="btn btn-sm btn-outline" :disabled="actionId===q.id" @click="duplicate(q)" title="Duplicate as new draft">⊕ Dup</button>
                  <button v-if="['draft','sent'].includes(q.status)" class="btn btn-sm btn-outline" style="color:#888" :disabled="actionId===q.id" @click="expire(q)">Expire</button>
                  <button v-if="q.status==='accepted'" class="btn btn-sm" style="background:#e8f5e9;color:#2e7d32" :disabled="actionId===q.id" @click="createOrder(q)">Order</button>
                  <a :href="pdfUrl(q.id)" target="_blank" class="btn btn-sm btn-outline" style="color:#c62828;border-color:#c62828" @click.stop>PDF</a>
                </div>
              </td>
            </tr>
            <tr v-if="!items.length">
              <td colspan="9">
                <div class="empty-state">
                  <div class="icon">📋</div>
                  <p>No quotations found. <a @click="$router.push('/quotations/create')" style="cursor:pointer;color:var(--primary)">Create your first BOQ →</a></p>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.last_page > 1" style="display:flex;align-items:center;gap:12px;justify-content:center;padding:16px;font-size:13px">
        <button class="btn btn-outline btn-sm" :disabled="pagination.current_page <= 1" @click="gotoPage(pagination.current_page - 1)">‹ Prev</button>
        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }} &nbsp;({{ pagination.total }} total)</span>
        <button class="btn btn-outline btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="gotoPage(pagination.current_page + 1)">Next ›</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'

const router = useRouter()

const items = ref([])
const loading = ref(true)
const actionId = ref(null)

const filters = reactive({ search: '', status: '', from_date: '', to_date: '', sort_by: 'created_at', sort_order: 'desc' })
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, per_page: 25 })

const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const isExpired = (d) => d && new Date(d) < new Date()
const pdfUrl = (id) => { const t = localStorage.getItem('token'); return `/api/quotations/${id}/pdf${t ? '?token='+t : ''}` }

function sortIcon(field) { return filters.sort_by === field ? (filters.sort_order === 'asc' ? '↑' : '↓') : '' }

function sortBy(field) {
  if (filters.sort_by === field) filters.sort_order = filters.sort_order === 'asc' ? 'desc' : 'asc'
  else { filters.sort_by = field; filters.sort_order = 'desc' }
  load()
}

let debounceTimer = null
function debounceLoad() { clearTimeout(debounceTimer); debounceTimer = setTimeout(load, 350) }

async function load() {
  loading.value = true
  try {
    const params = { page: pagination.current_page, per_page: pagination.per_page, sort_by: filters.sort_by, sort_order: filters.sort_order }
    if (filters.search)    params.search    = filters.search
    if (filters.status)    params.status    = filters.status
    if (filters.from_date) params.from_date = filters.from_date
    if (filters.to_date)   params.to_date   = filters.to_date
    const { data } = await api.get('/quotations', { params })
    items.value = data.data?.data ?? data.data ?? []
    const meta = data.data?.meta ?? data.meta ?? {}
    pagination.current_page = meta.current_page ?? 1
    pagination.last_page    = meta.last_page    ?? 1
    pagination.total        = meta.total        ?? items.value.length
  } finally {
    loading.value = false
  }
}

function gotoPage(page) { pagination.current_page = page; load() }

function clearFilters() {
  filters.search = ''; filters.status = ''; filters.from_date = ''; filters.to_date = ''
  pagination.current_page = 1; load()
}

async function send(q) {
  if (!confirm(`Send quotation ${q.quotation_no} to customer? It cannot be edited after sending.`)) return
  actionId.value = q.id
  try { await api.post(`/quotations/${q.id}/send`); load() }
  catch (e) { alert(e.response?.data?.message || 'Failed to send') }
  finally { actionId.value = null }
}

async function revise(q) {
  if (!confirm(`Create a revision of ${q.quotation_no}? The current version will be locked as "Revised".`)) return
  actionId.value = q.id
  try {
    const { data } = await api.post(`/quotations/${q.id}/revise`)
    const newId = data.data?.id ?? data.id
    router.push(`/quotations/${newId}/edit`)
  } catch (e) { alert(e.response?.data?.message || 'Failed to create revision') }
  finally { actionId.value = null }
}

async function createOrder(q) {
  if (!confirm(`Create a production order from ${q.quotation_no}?`)) return
  actionId.value = q.id
  try {
    const { data } = await api.post(`/quotations/${q.id}/create-order`)
    const orderId = data.data?.id ?? data.id
    router.push(`/orders/${orderId}`)
  } catch (e) { alert(e.response?.data?.message || 'Failed to create order') }
  finally { actionId.value = null }
}

async function duplicate(q) {
  if (!confirm(`Duplicate ${q.quotation_no} as a new draft?`)) return
  actionId.value = q.id
  try {
    const { data } = await api.post(`/quotations/${q.id}/duplicate`)
    const newId = data.data?.id ?? data.id
    router.push(`/quotations/${newId}/edit`)
  } catch (e) { alert(e.response?.data?.message || 'Failed to duplicate') }
  finally { actionId.value = null }
}

async function expire(q) {
  if (!confirm(`Mark ${q.quotation_no} as Expired?`)) return
  actionId.value = q.id
  try { await api.post(`/quotations/${q.id}/expire`); load() }
  catch (e) { alert(e.response?.data?.message || 'Failed to expire') }
  finally { actionId.value = null }
}

onMounted(load)
</script>

<style scoped>
.badge-revised { background:#f3e5f5;color:#6a1b9a;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase; }
.badge-expired { background:#f5f5f5;color:#aaa;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase; }
.text-right { text-align:right; }
</style>
