<template>
  <div class="al-wrap">
    <div class="al-header">
      <div>
        <h2>Audit Log</h2>
        <p class="al-sub">Every create, update and delete — who, what, when. (Admins only.)</p>
      </div>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
    </div>

    <div class="filters">
      <select v-model="f.type" @change="load"><option value="">All entities</option><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select>
      <select v-model="f.action" @change="load">
        <option value="">All actions</option>
        <option value="created">Created</option><option value="updated">Updated</option>
        <option value="deleted">Deleted</option><option value="restored">Restored</option>
      </select>
      <input v-model="f.search" placeholder="Search ref / user…" @keyup.enter="load" />
      <input v-model="f.from" type="date" @change="load" title="From" />
      <input v-model="f.to" type="date" @change="load" title="To" />
    </div>

    <div v-if="forbidden" class="info-banner">Only company admins can view the audit log.</div>
    <div v-else-if="loading" class="al-loading">Loading…</div>
    <p v-else-if="!rows.length" class="empty">No audit entries for these filters.</p>

    <table v-else class="al-table">
      <thead>
        <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Changes</th></tr>
      </thead>
      <tbody>
        <tr v-for="a in rows" :key="a.id">
          <td class="nowrap">{{ fmtDateTime(a.created_at) }}</td>
          <td>{{ a.user_name || '—' }}</td>
          <td><span class="act" :class="a.action">{{ a.action.replace('_',' ') }}</span></td>
          <td><span class="ent">{{ a.auditable_type }}</span> <span class="lbl">{{ a.label }}</span></td>
          <td class="changes">
            <template v-if="a.action === 'updated' && a.after">
              <span v-for="(v, k) in a.after" :key="k" class="chg">
                <b>{{ k }}</b>: <span class="old">{{ short(a.before?.[k]) }}</span> → <span class="new">{{ short(v) }}</span>
              </span>
            </template>
            <span v-else-if="a.action === 'created'" class="muted">record created</span>
            <span v-else-if="a.action.includes('deleted')" class="muted">record deleted</span>
            <span v-else class="muted">—</span>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="meta && meta.last_page > 1" class="pager">
      <button class="btn btn-ghost sm" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">← Prev</button>
      <span>Page {{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="btn btn-ghost sm" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">Next →</button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import auditService from '../services/auditService.js'

const rows = ref([])
const types = ref([])
const meta = ref(null)
const loading = ref(false)
const forbidden = ref(false)
const f = reactive({ type: '', action: '', search: '', from: '', to: '', page: 1 })

function fmtDateTime(d) { return d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—' }
function short(v) {
  if (v === null || v === undefined || v === '') return '∅'
  const s = typeof v === 'object' ? JSON.stringify(v) : String(v)
  return s.length > 40 ? s.slice(0, 40) + '…' : s
}

async function load() {
  loading.value = true; forbidden.value = false
  try {
    const res = await auditService.list({ ...f })
    const d = res?.data ?? res
    rows.value = d.logs?.data ?? []
    meta.value = d.logs ? { current_page: d.logs.current_page, last_page: d.logs.last_page } : null
    types.value = d.types ?? []
  } catch (e) {
    if (e?.response?.status === 403) forbidden.value = true
    rows.value = []
  } finally { loading.value = false }
}
function go(p) { f.page = p; load() }

onMounted(load)
</script>

<style scoped>
.al-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.al-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
.al-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.al-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filters select, .filters input { padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.info-banner { background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; padding: 12px 16px; border-radius: 8px; font-size: 13px; }
.al-loading { text-align: center; padding: 50px; color: #888; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 40px; }

.al-table { width: 100%; border-collapse: collapse; font-size: 12.5px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.al-table th { background: var(--primary); color: #fff; padding: 8px 12px; text-align: left; font-size: 11px; text-transform: uppercase; }
.al-table td { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.nowrap { white-space: nowrap; color: var(--text-2); }
.act { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 8px; text-transform: uppercase; }
.act.created { background: #e8f5e9; color: #2e7d32; }
.act.updated { background: var(--primary-tint); color: var(--primary); }
.act.deleted, .act.force_deleted { background: #ffebee; color: #c62828; }
.act.restored { background: #fff8e1; color: #b5740a; }
.ent { font-weight: 700; } .lbl { color: var(--text-3); font-size: 11px; }
.changes { max-width: 460px; }
.chg { display: inline-block; margin: 0 10px 3px 0; font-size: 11.5px; }
.chg b { color: var(--ink); } .old { color: #c62828; } .new { color: #2e7d32; }
.muted { color: #aaa; font-style: italic; }

.pager { display: flex; gap: 12px; align-items: center; justify-content: center; margin-top: 16px; font-size: 13px; color: var(--text-2); }
.btn { padding: 8px 14px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 5px 12px; font-size: 12px; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
