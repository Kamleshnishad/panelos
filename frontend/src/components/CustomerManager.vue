<template>
  <div class="cm-wrap">
    <customer-profile
      v-if="view === 'profile'"
      :key="profileId"
      :customer-id="profileId"
      @back="view = 'list'"
      @open-quotation="$emit('open-quotation', $event)"
      @view-orders="$emit('view-orders')"
    />

    <template v-else>
      <div class="cm-header">
        <div>
          <h2>Customers</h2>
          <p class="cm-sub">Click a customer for the full 360° profile — orders, quotations, invoices, repeat frequency.</p>
        </div>
        <div class="cm-actions">
          <input v-model="search" class="cm-search" placeholder="Search name / phone / email…" @keyup.enter="load" />
          <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
        </div>
      </div>

      <div v-if="loading" class="cm-loading">Loading…</div>
      <p v-else-if="!rows.length" class="empty">No customers found.</p>
      <table v-else class="cust-table">
        <thead>
          <tr><th>Name</th><th>Type</th><th>City</th><th>GSTIN</th><th>Phone</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="c in rows" :key="c.id" class="cust-row" tabindex="0" role="button" @click="open(c)" @keyup.enter="open(c)">
            <td class="bold">{{ c.name }}<span class="code">{{ c.code }}</span></td>
            <td><span class="type-badge">{{ c.type || '—' }}</span></td>
            <td>{{ c.city || '—' }}</td>
            <td class="mono">{{ c.gstin || '—' }}</td>
            <td>{{ c.phone || '—' }}</td>
            <td class="r"><span class="view-link">View 360 →</span></td>
          </tr>
        </tbody>
      </table>
      <div class="pagination" v-if="pagination.last_page > 1">
        <button class="pg-btn" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
        <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} total</span>
        <button class="pg-btn" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import customerService from '../services/customerService.js'
import { toastError } from '../services/ui.js'
import CustomerProfile from './CustomerProfile.vue'

defineEmits(['open-quotation', 'view-orders'])

const rows = ref([])
const loading = ref(false)
const search = ref('')
const view = ref('list')
const profileId = ref(null)
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })

async function load(page = 1) {
  loading.value = true
  try {
    const res = await customerService.list({ search: search.value, page, per_page: 50 })
    rows.value = res?.data?.data ?? res?.data ?? []
    const pg = res?.data?.meta?.pagination ?? {}
    pagination.current_page = pg.current_page ?? 1
    pagination.total        = pg.total ?? rows.value.length
    pagination.last_page    = pg.per_page ? Math.max(1, Math.ceil((pg.total ?? 0) / pg.per_page)) : 1
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not load customers.') }
  finally { loading.value = false }
}

function goPage(p) { if (p < 1 || p > pagination.last_page) return; load(p) }

function open(c) { profileId.value = c.id; view.value = 'profile' }

onMounted(load)
</script>

<style scoped>
.cm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.cm-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; gap: 16px; flex-wrap: wrap; }
.cm-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.cm-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.cm-actions { display: flex; gap: 8px; }
.cm-search { padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; width: 260px; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 14px 0 4px; }
.page-info  { font-size: 12px; color: #666; font-variant-numeric: tabular-nums; }
.pg-btn { padding: 5px 12px; border: 1px solid var(--border-2, #d0d5dd); background: #fff; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
.pg-btn:disabled { opacity: .5; cursor: not-allowed; }
.cm-loading { text-align: center; padding: 60px; color: #888; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 40px; }

.cust-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.cust-table th { background: var(--primary); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
.cust-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; }
.cust-row { cursor: pointer; }
.cust-row:hover td { background: var(--primary-tint); }
.cust-row:focus { outline: 2px solid var(--primary); outline-offset: -2px; }
.bold { font-weight: 700; } .mono { font-variant-numeric: tabular-nums; }
.code { font-size: 11px; color: var(--text-3); margin-left: 8px; font-weight: 500; }
.type-badge { font-size: 11px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 2px 8px; text-transform: capitalize; }
.r { text-align: right; }
.view-link { color: var(--primary); font-weight: 600; font-size: 12px; }
.btn { padding: 8px 14px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
