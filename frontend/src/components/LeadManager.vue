<template>
  <div class="lm-wrap">
    <div class="lm-header">
      <div>
        <h2>Leads / Inquiries</h2>
        <p class="lm-sub">Capture inquiries → follow up → convert to quotation.</p>
      </div>
      <div class="lm-head-actions">
        <input v-model="search" class="lm-search" placeholder="Search name / company / phone…" @keyup.enter="load" />
        <button class="btn btn-primary" @click="openCreate">+ New Lead</button>
        <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
      </div>
    </div>

    <!-- Status filter tabs -->
    <div class="status-tabs">
      <button v-for="s in statusTabs" :key="s.key" :class="['stab', { active: statusFilter === s.key }]" @click="statusFilter = s.key; load()">
        {{ s.label }}<span v-if="counts[s.key]" class="stab-count">{{ counts[s.key] }}</span>
      </button>
    </div>

    <div v-if="loading" class="lm-loading">Loading…</div>
    <p v-else-if="!leads.length" class="empty">No leads here. Click <strong>+ New Lead</strong> to add an inquiry.</p>

    <table v-else class="lead-table">
      <thead>
        <tr><th>Lead</th><th>Contact</th><th>Source</th><th>Requirement</th><th>Follow-up</th><th>Status</th></tr>
      </thead>
      <tbody>
        <tr v-for="l in leads" :key="l.id" class="lead-row" tabindex="0" role="button" @click="openDetail(l)" @keyup.enter="openDetail(l)">
          <td class="mono">{{ l.lead_no }}</td>
          <td><div class="bold">{{ l.contact_name }}</div><div class="muted">{{ l.company_name || l.phone || '—' }}</div></td>
          <td><span class="src-badge">{{ l.source }}</span></td>
          <td class="req">{{ l.requirement || '—' }}</td>
          <td :class="followClass(l)">{{ l.next_follow_up_date ? fmtDate(l.next_follow_up_date) : '—' }}</td>
          <td><span class="status-badge" :class="l.status">{{ l.status }}</span></td>
        </tr>
      </tbody>
    </table>

    <!-- Create / Edit modal -->
    <div v-if="showForm" class="modal-overlay" @click.self="showForm = false">
      <div class="modal-box lg">
        <div class="modal-header"><h3>{{ form.id ? 'Edit Lead' : 'New Lead' }}</h3><button class="btn-close" @click="showForm = false">✕</button></div>
        <div class="form-grid">
          <div class="form-group"><label>Contact Name *</label><input v-model="form.contact_name" /></div>
          <div class="form-group"><label>Company</label><input v-model="form.company_name" /></div>
          <div class="form-group"><label>Phone</label><input v-model="form.phone" /></div>
          <div class="form-group"><label>Email</label><input v-model="form.email" type="email" /></div>
          <div class="form-group"><label>City</label><input v-model="form.city" /></div>
          <div class="form-group"><label>Source</label>
            <select v-model="form.source"><option v-for="s in sources" :key="s" :value="s">{{ s }}</option></select>
          </div>
          <div class="form-group"><label>Application</label>
            <select v-model="form.application"><option value="">—</option><option v-for="a in applications" :key="a" :value="a">{{ a }}</option></select>
          </div>
          <div class="form-group"><label>Assigned To</label>
            <select v-model="form.assigned_to_user_id"><option :value="null">— Unassigned —</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
          </div>
          <div class="form-group"><label>Est. Qty (SQM)</label><input v-model.number="form.est_qty_sqm" type="number" min="0" /></div>
          <div class="form-group"><label>Est. Value (₹)</label><input v-model.number="form.est_value" type="number" min="0" /></div>
          <div class="form-group"><label>Next Follow-up</label><input v-model="form.next_follow_up_date" type="date" /></div>
          <div class="form-group full"><label>Requirement / Inquiry</label><textarea v-model="form.requirement" rows="2" placeholder="e.g. 500 sqm 50mm wall panel for cold storage, RAL 9002"></textarea></div>
          <div class="form-group full"><label>Notes</label><input v-model="form.notes" /></div>
        </div>
        <div v-if="formError" class="error-msg">{{ formError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving || !form.contact_name" @click="save">{{ saving ? 'Saving…' : (form.id ? 'Save' : 'Create Lead') }}</button>
        </div>
      </div>
    </div>

    <!-- Detail slide-over -->
    <div v-if="detail" class="drawer-scrim" @click.self="detail = null">
      <div class="drawer">
        <div class="drawer-head">
          <div>
            <div class="d-no mono">{{ detail.lead_no }}</div>
            <h3>{{ detail.contact_name }}</h3>
            <div class="muted">{{ detail.company_name }}</div>
          </div>
          <button class="btn-close" @click="detail = null">✕</button>
        </div>

        <span class="status-badge big" :class="detail.status">{{ detail.status }}</span>

        <div class="d-fields">
          <div><label>Phone</label><span>{{ detail.phone || '—' }}</span></div>
          <div><label>Email</label><span>{{ detail.email || '—' }}</span></div>
          <div><label>City</label><span>{{ detail.city || '—' }}</span></div>
          <div><label>Source</label><span>{{ detail.source }}</span></div>
          <div><label>Application</label><span>{{ detail.application || '—' }}</span></div>
          <div><label>Assigned</label><span>{{ detail.assigned_user?.name || '—' }}</span></div>
          <div><label>Est. Qty</label><span>{{ detail.est_qty_sqm ? detail.est_qty_sqm + ' sqm' : '—' }}</span></div>
          <div><label>Est. Value</label><span>{{ detail.est_value ? '₹ ' + fmt(detail.est_value) : '—' }}</span></div>
          <div :class="followClass(detail)"><label>Follow-up</label><span>{{ detail.next_follow_up_date ? fmtDate(detail.next_follow_up_date) : '—' }}</span></div>
        </div>
        <div class="d-req" v-if="detail.requirement"><label>Requirement</label><p>{{ detail.requirement }}</p></div>
        <div class="d-req" v-if="detail.lost_reason"><label>Lost reason</label><p>{{ detail.lost_reason }}</p></div>

        <div class="d-section">
          <label class="d-label">Move to</label>
          <div class="status-actions">
            <button v-for="s in moveTo(detail.status)" :key="s" class="btn btn-status sm" :disabled="busy" @click="setStatus(detail, s)">{{ s }}</button>
            <button v-if="detail.status !== 'lost' && detail.status !== 'won'" class="btn btn-danger sm" :disabled="busy" @click="markLost(detail)">Lost</button>
          </div>
          <button v-if="!['won','lost'].includes(detail.status)" class="btn btn-convert" :disabled="busy" @click="convertLead(detail)">
            → Convert to Quotation
          </button>
          <p v-if="detail.quotation_id" class="conv-note">✓ Quotation #{{ detail.quotation_id }} linked</p>
        </div>

        <!-- Activity / follow-up timeline -->
        <div class="d-section">
          <label class="d-label">Log activity</label>
          <div class="act-form">
            <select v-model="actForm.type" class="act-type">
              <option value="note">Note</option><option value="call">Call</option>
              <option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="meeting">Meeting</option>
            </select>
            <input v-model="actForm.description" class="act-input" placeholder="What happened…" @keyup.enter="addActivity" />
            <button class="btn btn-primary sm" :disabled="actBusy || !actForm.description" @click="addActivity">Add</button>
          </div>
          <div class="timeline">
            <div v-for="a in (detail.activities || [])" :key="a.id" class="tl-item">
              <span class="tl-type" :class="a.type">{{ a.type.replace('_', ' ') }}</span>
              <div class="tl-body">
                <div class="tl-desc">{{ a.description }}</div>
                <div class="tl-meta">{{ fmtDateTime(a.activity_date) }}<span v-if="a.user"> · {{ a.user.name }}</span></div>
              </div>
            </div>
            <p v-if="!(detail.activities || []).length" class="tl-empty">No activity yet.</p>
          </div>
        </div>

        <div class="drawer-foot">
          <button class="btn btn-ghost" @click="openEdit(detail)">Edit</button>
          <button class="btn btn-danger-ghost" @click="removeLead(detail)">Delete</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const emit = defineEmits(['convert'])

const sources = ['Website', 'Phone', 'WhatsApp', 'Referral', 'IndiaMART', 'Justdial', 'Exhibition', 'Walk-in', 'Other']
const applications = ['Wall', 'Roof', 'Cold Room', 'Partition', 'Clean Room', 'Ceiling', 'PEB Shade', 'Architectural']
const statusTabs = [
  { key: '', label: 'All' }, { key: 'new', label: 'New' }, { key: 'contacted', label: 'Contacted' },
  { key: 'qualified', label: 'Qualified' }, { key: 'quoted', label: 'Quoted' }, { key: 'won', label: 'Won' }, { key: 'lost', label: 'Lost' },
]

const leads = ref([])
const allCount = ref({})
const loading = ref(false)
const search = ref('')
const statusFilter = ref('')
const users = ref([])

const showForm = ref(false)
const saving = ref(false)
const formError = ref(null)
const form = reactive({ id: null, contact_name: '', company_name: '', phone: '', email: '', city: '', source: 'Other', application: '', assigned_to_user_id: null, est_qty_sqm: null, est_value: null, next_follow_up_date: '', requirement: '', notes: '' })

const detail = ref(null)
const busy = ref(false)
const actForm = reactive({ type: 'note', description: '' })
const actBusy = ref(false)

const counts = computed(() => allCount.value)

function fmt(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN') : '—' }
function fmtDateTime(d) { return d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—' }
function followClass(l) {
  if (!l.next_follow_up_date || ['won', 'lost'].includes(l.status)) return ''
  const days = Math.ceil((new Date(l.next_follow_up_date) - new Date().setHours(0, 0, 0, 0)) / 86400000)
  if (days < 0) return 'follow-over'
  if (days === 0) return 'follow-today'
  return ''
}
function moveTo(status) {
  const flow = { new: ['contacted'], contacted: ['qualified'], qualified: ['quoted'], quoted: ['won'] }
  return flow[status] || []
}

async function load() {
  loading.value = true
  try {
    const params = {}
    if (statusFilter.value) params.status = statusFilter.value
    if (search.value) params.search = search.value
    const res = await leadService.list(params)
    leads.value = res?.data ?? res ?? []
    if (!statusFilter.value && !search.value) computeCounts(leads.value)
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not load leads.') }
  finally { loading.value = false }
}

function computeCounts(list) {
  const c = { '': list.length }
  for (const l of list) c[l.status] = (c[l.status] || 0) + 1
  allCount.value = c
}

async function loadUsers() {
  try { const res = await leadService.users(); users.value = res?.data?.data ?? res?.data ?? [] } catch { /* ignore */ }
}

function openCreate() {
  Object.assign(form, { id: null, contact_name: '', company_name: '', phone: '', email: '', city: '', source: 'Other', application: '', assigned_to_user_id: null, est_qty_sqm: null, est_value: null, next_follow_up_date: '', requirement: '', notes: '' })
  formError.value = null
  showForm.value = true
  if (!users.value.length) loadUsers()
}

function openEdit(l) {
  Object.assign(form, {
    id: l.id, contact_name: l.contact_name, company_name: l.company_name || '', phone: l.phone || '', email: l.email || '',
    city: l.city || '', source: l.source || 'Other', application: l.application || '', assigned_to_user_id: l.assigned_to_user_id || null,
    est_qty_sqm: l.est_qty_sqm || null, est_value: l.est_value || null,
    next_follow_up_date: l.next_follow_up_date ? String(l.next_follow_up_date).slice(0, 10) : '', requirement: l.requirement || '', notes: l.notes || '',
  })
  formError.value = null
  detail.value = null
  showForm.value = true
  if (!users.value.length) loadUsers()
}

async function save() {
  saving.value = true; formError.value = null
  try {
    const payload = { ...form }; delete payload.id
    if (form.id) await leadService.update(form.id, payload)
    else await leadService.create(payload)
    toastSuccess(form.id ? 'Lead updated.' : 'Lead created.')
    showForm.value = false
    await load()
  } catch (e) {
    formError.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Failed to save lead.'
  } finally { saving.value = false }
}

async function openDetail(l) {
  Object.assign(actForm, { type: 'note', description: '' })
  try { const res = await leadService.get(l.id); detail.value = res?.data ?? res }
  catch { detail.value = l }
}

async function addActivity() {
  if (!actForm.description) return
  actBusy.value = true
  try {
    const res = await leadService.addActivity(detail.value.id, { type: actForm.type, description: actForm.description })
    detail.value = res?.data ?? res
    Object.assign(actForm, { type: 'note', description: '' })
  } catch (e) { toastError(e?.response?.data?.message ?? 'Failed to add activity.') }
  finally { actBusy.value = false }
}

async function setStatus(l, status) {
  busy.value = true
  try {
    await leadService.changeStatus(l.id, { status })
    toastSuccess(`Moved to ${status}.`)
    const res = await leadService.get(l.id); detail.value = res?.data ?? res
    await load()
  } catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
  finally { busy.value = false }
}

async function markLost(l) {
  const reason = window.prompt('Reason for marking lost? (optional)')
  if (reason === null) return
  busy.value = true
  try {
    await leadService.changeStatus(l.id, { status: 'lost', lost_reason: reason || null })
    toastSuccess('Marked lost.')
    const res = await leadService.get(l.id); detail.value = res?.data ?? res
    await load()
  } catch (e) { toastError('Failed.') }
  finally { busy.value = false }
}

async function convertLead(l) {
  const ok = await confirmDialog({
    title: 'Convert to Quotation?',
    message: 'A customer will be created/linked from this lead and a new quotation opened. The lead will move to "quoted" on save.',
    confirmLabel: 'Convert', cancelLabel: 'Cancel',
  })
  if (!ok) return
  busy.value = true
  try {
    const res = await leadService.convert(l.id)
    const d = res?.data ?? res
    detail.value = null
    emit('convert', { customer_id: d.customer_id, lead_id: d.lead_id })
  } catch (e) { toastError(e?.response?.data?.message ?? 'Convert failed.') }
  finally { busy.value = false }
}

async function removeLead(l) {
  const ok = await confirmDialog({ title: 'Delete lead?', message: `Delete ${l.lead_no}?`, confirmLabel: 'Delete', cancelLabel: 'Cancel', danger: true })
  if (!ok) return
  try { await leadService.remove(l.id); toastSuccess('Lead deleted.'); detail.value = null; await load() }
  catch (e) { toastError('Failed to delete.') }
}

onMounted(load)
</script>

<style scoped>
.lm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.lm-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; gap: 16px; flex-wrap: wrap; }
.lm-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.lm-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.lm-head-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.lm-search { padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; width: 240px; }

.status-tabs { display: flex; gap: 4px; margin-bottom: 18px; border-bottom: 2px solid #e0e0e0; flex-wrap: wrap; }
.stab { padding: 8px 16px; border: none; background: none; font-size: 13px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; }
.stab.active { color: var(--primary); border-bottom-color: var(--primary); }
.stab-count { margin-left: 6px; font-size: 11px; background: var(--surface-2); border-radius: 8px; padding: 0 6px; }

.lm-loading { text-align: center; padding: 60px; color: #888; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 40px; }

.lead-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.lead-table th { background: var(--primary); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
.lead-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.lead-row { cursor: pointer; }
.lead-row:hover td { background: var(--primary-tint); }
.lead-row:focus { outline: 2px solid var(--primary); outline-offset: -2px; }
.mono { font-variant-numeric: tabular-nums; } .bold { font-weight: 700; } .muted { color: #999; font-size: 12px; }
.req { max-width: 280px; color: #555; }
.src-badge { font-size: 11px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 2px 8px; }
.follow-over { color: #c62828; font-weight: 700; } .follow-today { color: #b5740a; font-weight: 700; }

.status-badge { font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
.status-badge.big { font-size: 12px; padding: 4px 12px; display: inline-block; margin-bottom: 14px; }
.status-badge.new { background: #e3f2fd; color: #1565c0; }
.status-badge.contacted { background: var(--primary-tint); color: var(--primary); }
.status-badge.qualified { background: #fff8e1; color: #b5740a; }
.status-badge.quoted { background: #ede7f6; color: #5e35b1; }
.status-badge.won { background: #e8f5e9; color: #2e7d32; }
.status-badge.lost { background: #ffebee; color: #c62828; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 720px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 14px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input, .form-group select, .form-group textarea { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
@media (max-width: 700px) { .form-grid { grid-template-columns: 1fr 1fr; } }

.drawer-scrim { position: fixed; inset: 0; background: rgba(16,24,40,0.4); z-index: 1000; display: flex; justify-content: flex-end; }
.drawer { width: 420px; max-width: 100vw; background: #fff; height: 100%; overflow-y: auto; padding: 22px 24px; box-shadow: -8px 0 30px rgba(0,0,0,0.15); }
.drawer-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
.drawer-head h3 { margin: 2px 0; font-size: 18px; color: var(--ink); }
.d-no { font-size: 11px; color: var(--text-3); }
.d-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; margin: 14px 0; }
.d-fields > div { display: flex; flex-direction: column; }
.d-fields label, .d-req label, .d-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-3); font-weight: 700; }
.d-fields span { font-size: 13px; color: var(--ink); margin-top: 1px; }
.d-req { margin: 8px 0; } .d-req p { margin: 3px 0 0; font-size: 13px; color: #444; line-height: 1.5; }
.d-section { margin: 16px 0; border-top: 1px solid var(--border); padding-top: 14px; }
.status-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.act-form { display: flex; gap: 6px; margin: 8px 0 12px; }
.act-type { padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; }
.act-input { flex: 1; padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.timeline { display: flex; flex-direction: column; gap: 8px; }
.tl-item { display: flex; gap: 8px; align-items: flex-start; }
.tl-type { font-size: 9px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 6px; background: var(--surface-2); color: var(--text-2); white-space: nowrap; margin-top: 1px; }
.tl-type.call { background: #e8f5e9; color: #2e7d32; }
.tl-type.status\ change, .tl-type.status_change { background: var(--primary-tint); color: var(--primary); }
.tl-type.whatsapp { background: #e8f5e9; color: #128c7e; }
.tl-body { flex: 1; }
.tl-desc { font-size: 13px; color: var(--ink); }
.tl-meta { font-size: 10.5px; color: var(--text-3); margin-top: 1px; }
.tl-empty { font-size: 12px; color: #aaa; font-style: italic; }

.drawer-foot { display: flex; gap: 10px; justify-content: space-between; margin-top: 20px; border-top: 1px solid var(--border); padding-top: 14px; }

.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 5px 12px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-status { background: var(--primary-tint); color: var(--primary); text-transform: capitalize; }
.btn-danger { background: #c62828; color: #fff; }
.btn-danger-ghost { background: transparent; border: 1px solid #ef9a9a; color: #c62828; }
.btn-convert { width: 100%; margin-top: 10px; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; font-weight: 700; }
.conv-note { font-size: 12px; color: #2e7d32; margin: 8px 0 0; font-weight: 600; }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }
</style>
