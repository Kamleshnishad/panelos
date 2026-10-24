<template>
  <div class="lm-wrap">
    <div class="lm-header">
      <div>
        <h2>Leads / Inquiries</h2>
        <p class="lm-sub">Capture inquiries → follow up → convert to quotation.</p>
      </div>
      <div class="lm-head-actions">
        <div class="view-toggle">
          <button :class="{ on: view === 'list' }" @click="view = 'list'">List</button>
          <button :class="{ on: view === 'insights' }" @click="showInsights">📊 Insights</button>
        </div>
        <input v-if="view === 'list'" v-model="search" class="lm-search" placeholder="Search name / company / phone…" @keyup.enter="load" />
        <button class="btn btn-primary" @click="openCreate">+ New Lead</button>
        <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
      </div>
    </div>

    <!-- Insights dashboard -->
    <div v-if="view === 'insights'" class="insights">
      <div v-if="dashLoading" class="lm-loading">Loading insights…</div>
      <template v-else-if="dash">
        <div class="kpi-row">
          <div class="kpi"><label>Total Leads</label><span>{{ dash.total }}</span></div>
          <div class="kpi"><label>Open</label><span>{{ dash.open }}</span></div>
          <div class="kpi"><label>Won</label><span class="green">{{ dash.won }}</span></div>
          <div class="kpi"><label>Lost</label><span class="red">{{ dash.lost }}</span></div>
          <div class="kpi"><label>Win Rate</label><span>{{ dash.win_rate }}%</span></div>
          <div class="kpi"><label>On hold</label><span>{{ dash.on_hold || 0 }}</span></div>
        </div>

        <div class="ins-grid">
          <div class="ins-card">
            <h4>Funnel</h4>
            <div v-for="f in dash.funnel" :key="f.status" class="funnel-row">
              <span class="funnel-label" :class="f.status">{{ f.status }}</span>
              <div class="funnel-bar-track"><div class="funnel-bar" :class="f.status" :style="{ width: barPct(f.count) + '%' }"></div></div>
              <span class="funnel-count">{{ f.count }}</span>
            </div>
          </div>

          <div class="ins-card">
            <h4>By Source</h4>
            <table class="ins-table">
              <thead><tr><th>Source</th><th class="r">Leads</th><th class="r">Won</th><th class="r">Conv%</th></tr></thead>
              <tbody>
                <tr v-for="s in dash.by_source" :key="s.source">
                  <td>{{ s.source }}</td><td class="r">{{ s.total }}</td><td class="r">{{ s.won }}</td><td class="r">{{ s.conv_pct }}%</td>
                </tr>
                <tr v-if="!dash.by_source.length"><td colspan="4" class="muted">No data.</td></tr>
              </tbody>
            </table>
          </div>

          <div class="ins-card">
            <h4>Follow-ups</h4>
            <div class="fu-row red"><span>Overdue</span><b>{{ dash.follow_ups.overdue }}</b></div>
            <div class="fu-row amber"><span>Due today</span><b>{{ dash.follow_ups.today }}</b></div>
            <div class="fu-row"><span>Next 7 days</span><b>{{ dash.follow_ups.this_week }}</b></div>
          </div>

          <div class="ins-card">
            <h4>Top Lost Reasons</h4>
            <div v-for="r in dash.lost_reasons" :key="r.reason" class="lr-row"><span>{{ r.reason }}</span><b>{{ r.count }}</b></div>
            <p v-if="!dash.lost_reasons.length" class="muted">No lost leads with reasons.</p>
          </div>
        </div>
      </template>
    </div>

    <!-- Status filter tabs -->
    <div v-if="view === 'list'" class="status-tabs">
      <button v-for="s in statusTabs" :key="s.key" :class="['stab', { active: statusFilter === s.key }]" @click="statusFilter = s.key; load()">
        {{ s.label }}<span v-if="counts[s.key]" class="stab-count">{{ counts[s.key] }}</span>
      </button>
    </div>

    <template v-if="view === 'list'">
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
          <td><span class="status-badge" :class="l.status">{{ statusLabel(l.status) }}</span></td>
        </tr>
      </tbody>
    </table>
    <div class="pagination" v-if="pagination.last_page > 1">
      <button class="pg-btn" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
      <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} total</span>
      <button class="pg-btn" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
    </div>
    </template>

    <!-- Hold / junk / lost / reopen: the moves that need a date or a reason -->
    <div v-if="sd.open" class="modal-overlay sd-overlay" @click.self="sd.open = false">
      <div class="modal-box">
        <div class="modal-header"><h3>{{ sd.title }}</h3><button class="btn-close" @click="sd.open = false">✕</button></div>
        <div class="form-grid one">
          <div v-if="sd.isLost && lostReasons.length" class="form-group"><label for="sd-lost">Reason *</label>
            <select id="sd-lost" v-model="sd.lostReasonId" class="sd-input"><option value="">Choose a reason…</option><option v-for="r in lostReasons" :key="r.id" :value="r.id">{{ r.name }}</option></select>
          </div>
          <div v-if="sd.needsDate" class="form-group"><label for="sd-date">Comes back on *</label><input id="sd-date" v-model="sd.hold_until" type="date" :min="today" class="sd-input" /></div>
          <div class="form-group"><label for="sd-reason">{{ sd.reasonLabel }}</label><input id="sd-reason" v-model="sd.reason" type="text" maxlength="500" class="sd-input" /></div>
        </div>
        <p v-if="sd.error" class="sd-error">{{ sd.error }}</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="sd.open = false">Cancel</button>
          <button class="btn btn-primary" :disabled="busy" @click="submitStatusDialog">Save</button>
        </div>
      </div>
    </div>

    <!-- Convert: the customer's state, and the look-alike customers if there are any -->
    <div v-if="cv.open" class="modal-overlay sd-overlay" @click.self="cv.open = false">
      <div class="modal-box">
        <div class="modal-header"><h3>Convert to quotation</h3><button class="btn-close" @click="cv.open = false">✕</button></div>
        <template v-if="!cv.matches.length">
          <p class="cv-note">A customer is created from this lead. The state decides whether the quotation charges CGST + SGST or IGST.</p>
          <div class="form-grid one">
            <div class="form-group"><label for="cv-state">State *</label>
              <select id="cv-state" v-model="cv.state_code" class="sd-input"><option value="">Choose a state…</option><option v-for="(name, code) in states" :key="code" :value="code">{{ name }}</option></select>
            </div>
            <div class="form-group"><label for="cv-gstin">GSTIN (optional, fills the state)</label><input id="cv-gstin" v-model="cv.gstin" type="text" maxlength="15" class="sd-input" /></div>
            <div class="form-group"><label for="cv-address">Address (optional)</label><input id="cv-address" v-model="cv.address_line1" type="text" maxlength="255" class="sd-input" /></div>
          </div>
        </template>
        <template v-else>
          <p class="cv-note"><strong>This buyer looks like a customer you already have.</strong> Use that customer, or create a new one.</p>
          <ul class="cv-matches">
            <li v-for="m in cv.matches" :key="m.id">
              <span><strong>{{ m.name }}</strong> ({{ m.code }})<span v-if="m.city"> · {{ m.city }}</span> · same {{ m.matched_on.join(' and ') }}</span>
              <button class="btn btn-primary sm" :disabled="cv.busy" @click="submitConvert({ customer_id: m.id })">Use this customer</button>
            </li>
          </ul>
        </template>
        <p v-if="cv.error" class="sd-error">{{ cv.error }}</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="cv.open = false">Cancel</button>
          <button v-if="cv.matches.length" class="btn btn-ghost" :disabled="cv.busy" @click="cv.matches = []; cv.create_new = true">None of these: create a new customer</button>
          <button v-else class="btn btn-primary" :disabled="cv.busy" @click="submitConvert()">{{ cv.busy ? 'Working…' : 'Convert' }}</button>
        </div>
      </div>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="showForm" class="modal-overlay" @click.self="showForm = false">
      <div class="modal-box lg">
        <div class="modal-header"><h3>{{ form.id ? 'Edit Lead' : 'New Lead' }}</h3><button class="btn-close" @click="showForm = false">✕</button></div>
        <div class="form-grid">
          <div class="form-group"><label>Contact Name *</label><FormInput v-model="form.contact_name" name="contact_name" required :rules="[{ minLength: 2, maxLength: 100 }]" /></div>
          <div class="form-group"><label>Company</label><FormInput v-model="form.company_name" name="company_name" :rules="[{ maxLength: 150 }]" /></div>
          <div class="form-group"><label>Phone</label><FormInput v-model="form.phone" type="phone" name="phone" /></div>
          <div class="form-group"><label>Email</label><FormInput v-model="form.email" type="email" name="email" /></div>
          <div class="form-group"><label>City</label><FormInput v-model="form.city" name="city" :rules="[{ maxLength: 60 }]" /></div>
          <div class="form-group"><label>Source</label>
            <FormSelect v-model="form.source" name="source" :options="sources" />
          </div>
          <div class="form-group"><label>Application</label>
            <FormSelect v-model="form.application" name="application" :options="applications" />
          </div>
          <div class="form-group"><label>Assigned To</label>
            <FormSelect v-model="form.assigned_to_user_id" name="assigned_to" :options="users.map(u => ({value:u.id,label:u.name}))" clearable />
          </div>
          <div class="form-group"><label>Est. Qty (SQM)</label><FormInput v-model.number="form.est_qty_sqm" type="number" name="est_qty_sqm" :rules="[{ min: 0 }]" /></div>
          <div class="form-group"><label>Est. Value (₹)</label><FormInput v-model.number="form.est_value" type="number" name="est_value" :rules="[{ min: 0 }]" /></div>
          <div class="form-group"><label>Next Follow-up</label><FormInput v-model="form.next_follow_up_date" type="date" name="next_follow_up" /></div>
          <div class="form-group full"><label>Requirement / Inquiry</label><FormInput v-model="form.requirement" name="requirement" as="textarea" :rows="2" placeholder="e.g. 500 sqm 50mm wall panel for cold storage, RAL 9002" :rules="[{ maxLength: 1000 }]" /></div>
          <div class="form-group full"><label>Notes</label><FormInput v-model="form.notes" name="notes" :rules="[{ maxLength: 1000 }]" /></div>
        </div>
        <div v-if="formError" class="error-msg">{{ formError }}</div>
        <div v-if="dupes.length" class="dup-box" role="alert">
          <strong>This looks like a buyer you already have:</strong>
          <ul>
            <li v-for="d in dupes" :key="d.id">
              <a href="#" @click.prevent="openDuplicate(d)">{{ d.lead_no }}</a> — {{ d.contact_name }}<span v-if="d.company_name">, {{ d.company_name }}</span>
              · {{ statusLabel(d.status) }}<span v-if="d.assigned_to"> · {{ d.assigned_to }}</span> · same {{ d.matched_on.join(' and ') }}
            </li>
          </ul>
          <span class="dup-hint">Open it, or save this one anyway.</span>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving || !form.contact_name" @click="save">{{ saving ? 'Saving…' : (dupes.length ? (form.id ? 'Save anyway' : 'Create anyway') : (form.id ? 'Save' : 'Create Lead')) }}</button>
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

        <span class="status-badge big" :class="detail.status">{{ statusLabel(detail.status) }}</span>

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
        <div class="d-req" v-if="detail.external_id || detail.campaign || utmText || detail.first_seen_at">
          <label>Where it came from</label>
          <p>{{ detail.source }}<template v-if="detail.external_id"> · ref {{ detail.external_id }}</template><template v-if="detail.campaign"> · {{ detail.campaign }}</template><template v-if="utmText"> · {{ utmText }}</template><template v-if="detail.first_seen_at"> · first seen {{ fmtDateTime(detail.first_seen_at) }}</template></p>
          <button class="link-btn" @click="togglePayload">{{ rawMsg.open ? 'Hide' : 'Show' }} original message</button>
          <p v-if="rawMsg.open && !rawMsg.items.length" class="tl-empty">{{ rawMsg.loading ? 'Loading…' : 'No original message was kept.' }}</p>
          <pre v-for="m in (rawMsg.open ? rawMsg.items : [])" :key="m.id" class="raw-msg">{{ JSON.stringify(m.payload, null, 2) }}</pre>
        </div>
        <div class="d-req" v-if="detail.lost_reason"><label>Lost reason</label><p>{{ detail.lost_reason }}<template v-if="detail.lost_note"> — {{ detail.lost_note }}</template></p></div>

        <div class="d-section">
          <label class="d-label">Move to</label>
          <div class="status-actions">
            <button v-for="s in moveTo(detail.status)" :key="s" :class="['btn', 'sm', s === 'lost' ? 'btn-danger' : 'btn-status']" :disabled="busy" @click="setStatus(detail, s)">{{ isClosed(detail.status) ? 'Reopen as ' + statusLabel(s) : statusLabel(s) }}</button>
          </div>
          <p v-if="detail.status === 'on_hold'" class="conv-note">On hold until {{ fmtDate(detail.next_follow_up_date) }}.</p>
          <button v-if="!isClosed(detail.status)" class="btn btn-convert" :disabled="busy" @click="convertLead(detail)">
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
import { ref, reactive, computed, watch, onMounted } from 'vue'
import leadService, { LEAD_APPLICATIONS } from '../services/leadService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'
import FormInput from './Form/FormInput.vue'
import FormSelect from './Form/FormSelect.vue'

const emit = defineEmits(['convert'])

// The company's own list (Master Data -> Lead lists). The fallback below only shows if the options call fails.
const sources = ref(['Website', 'Phone', 'WhatsApp', 'Referral', 'IndiaMART', 'Justdial', 'Exhibition', 'Walk-in', 'Other'])
const applications = LEAD_APPLICATIONS
const statusTabs = [
  { key: '', label: 'All' }, { key: 'new', label: 'New' }, { key: 'contacted', label: 'Contacted' },
  { key: 'qualified', label: 'Qualified' }, { key: 'quoted', label: 'Quoted' }, { key: 'on_hold', label: 'On hold' },
  { key: 'won', label: 'Won' }, { key: 'lost', label: 'Lost' }, { key: 'invalid', label: 'Invalid' },
]
const CLOSED = ['won', 'lost', 'invalid']
// What each status may move to. The server sends the real table with the options; this is only the fallback.
const transitions = ref({
  new: ['contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'],
  contacted: ['new', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'],
  qualified: ['new', 'contacted', 'quoted', 'on_hold', 'won', 'lost', 'invalid'],
  quoted: ['new', 'contacted', 'qualified', 'on_hold', 'won', 'lost', 'invalid'],
  on_hold: ['new', 'contacted', 'qualified', 'quoted', 'won', 'lost', 'invalid'],
  won: ['new', 'contacted', 'qualified', 'quoted'], lost: ['new', 'contacted', 'qualified', 'quoted'], invalid: ['new', 'contacted', 'qualified', 'quoted'],
})

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

const view = ref('list')
const dash = ref(null)
const dashLoading = ref(false)

async function showInsights() {
  view.value = 'insights'
  dashLoading.value = true
  try { const res = await leadService.dashboard(); dash.value = res?.data ?? res }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not load insights.') }
  finally { dashLoading.value = false }
}
function barPct(n) {
  const max = Math.max(1, ...(dash.value?.funnel || []).map(f => f.count))
  return Math.round((n / max) * 100)
}

const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })
const counts = computed(() => allCount.value)

function fmt(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN') : '—' }
function fmtDateTime(d) { return d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—' }
function followClass(l) {
  if (!l.next_follow_up_date || CLOSED.includes(l.status)) return ''
  const days = Math.ceil((new Date(l.next_follow_up_date) - new Date().setHours(0, 0, 0, 0)) / 86400000)
  if (days < 0) return 'follow-over'
  if (days === 0) return 'follow-today'
  return ''
}
const isClosed = (status) => CLOSED.includes(status)
const statusLabel = (status) => String(status || '').replace('_', ' ')
const moveTo = (status) => transitions.value[status] || []
const today = new Date().toISOString().slice(0, 10)

// Guards against out-of-order responses: switching status tabs or searching
// quickly can let an earlier request resolve after a later one and overwrite
// the table with stale data (MD8).
let reqSeq = 0
async function load(page = 1) {
  const seq = ++reqSeq
  loading.value = true
  try {
    const params = { page, per_page: 50 }
    if (statusFilter.value) params.status = statusFilter.value
    if (search.value) params.search = search.value
    const res  = await leadService.list(params)
    if (seq !== reqSeq) return
    const body = res?.data ?? {}
    leads.value = Array.isArray(body) ? body : (body.data ?? [])
    if (body.counts) allCount.value = body.counts          // server-side status counts for tabs
    const pg = body.meta?.pagination ?? {}
    pagination.current_page = pg.current_page ?? 1
    pagination.last_page    = pg.last_page ?? 1
    pagination.total        = pg.total ?? leads.value.length
  } catch (e) { if (seq === reqSeq) toastError(e?.response?.data?.message ?? 'Could not load leads.') }
  finally { if (seq === reqSeq) loading.value = false }
}

function goPage(p) { if (p < 1 || p > pagination.last_page) return; load(p) }

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
  dupes.value = []
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
  dupes.value = []
  detail.value = null
  showForm.value = true
  if (!users.value.length) loadUsers()
}

// Same phone or e-mail as a lead that already exists: shown in the form until the buyer chooses to open it or save anyway.
const dupes = ref([])
watch(() => [form.phone, form.email], () => { dupes.value = [] })

function openDuplicate(d) {
  showForm.value = false
  dupes.value = []
  openDetail({ id: d.id })
}

async function save() {
  saving.value = true; formError.value = null
  try {
    const payload = { ...form }; delete payload.id
    if (dupes.value.length) payload.allow_duplicate = true      // they have seen the matches and chose to go on
    if (form.id) await leadService.update(form.id, payload)
    else await leadService.create(payload)
    toastSuccess(form.id ? 'Lead updated.' : 'Lead created.')
    showForm.value = false
    await load()
  } catch (e) {
    const body = e?.response?.data
    if (e?.response?.status === 409 && body?.error_code === 'DUPLICATE_LEAD') { dupes.value = body.errors?.duplicates ?? []; return }
    formError.value = body?.message ?? Object.values(body?.errors ?? {}).flat().join(' ') ?? 'Failed to save lead.'
  } finally { saving.value = false }
}

// Campaign / UTM line and the raw message of an imported lead (kept out of the normal view; support reads it here)
const utmText = computed(() => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].map(k => detail.value?.[k]).filter(Boolean).join(' / '))
const rawMsg = reactive({ open: false, loading: false, items: [] })
async function togglePayload() {
  rawMsg.open = !rawMsg.open
  if (!rawMsg.open || rawMsg.items.length) return
  rawMsg.loading = true
  try { const r = await leadService.payloads(detail.value.id); rawMsg.items = r?.data ?? [] }
  catch { rawMsg.items = [] }
  finally { rawMsg.loading = false }
}

async function openDetail(l) {
  Object.assign(rawMsg, { open: false, loading: false, items: [] })
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

const sd = reactive({ open: false, lead: null, to: '', title: '', needsDate: false, isLost: false, lostReasonId: '', reasonLabel: '', reasonRequired: false, hold_until: '', reason: '', error: '' })
const lostReasons = ref([])   // the company's list (Master Data -> Lead lists), from the options call

async function sendStatus(l, payload, done) {
  busy.value = true
  try {
    await leadService.changeStatus(l.id, payload)
    toastSuccess(done)
    const res = await leadService.get(l.id); detail.value = res?.data ?? res
    await load()
    return true
  } catch (e) {
    const msg = e?.response?.data?.message ?? 'Failed.'
    if (sd.open) sd.error = msg; else toastError(msg)
    return false
  } finally { busy.value = false }
}

function setStatus(l, to) {
  if (to === 'lost') return markLost(l)
  const reopen = isClosed(l.status)
  if (!reopen && to !== 'on_hold' && to !== 'invalid') return sendStatus(l, { status: to }, `Moved to ${statusLabel(to)}.`)
  Object.assign(sd, {
    open: true, lead: l, to, error: '', reason: '', hold_until: '', isLost: false, lostReasonId: '',
    needsDate: !reopen && to === 'on_hold',
    reasonRequired: reopen,
    title: reopen ? `Reopen as ${statusLabel(to)}` : to === 'on_hold' ? 'Put on hold' : 'Mark as invalid',
    reasonLabel: reopen ? 'Why are you reopening this lead? *' : to === 'on_hold' ? 'Note (optional)' : 'Why is it invalid? (spam, wrong number...)',
  })
}

async function submitStatusDialog() {
  sd.error = ''
  if (sd.needsDate && !sd.hold_until) { sd.error = 'Choose the date this lead comes back.'; return }
  if (sd.reasonRequired && !sd.reason.trim()) { sd.error = 'Say why you are reopening this lead.'; return }
  if (sd.isLost && lostReasons.value.length && !sd.lostReasonId) { sd.error = 'Choose why this lead was lost.'; return }
  const payload = { status: sd.to, reason: sd.reason.trim() || null }
  if (sd.isLost) {
    delete payload.reason
    if (lostReasons.value.length) { payload.lost_reason_id = Number(sd.lostReasonId); payload.lost_note = sd.reason.trim() || null }
    else payload.lost_reason = sd.reason.trim() || null          // the options call failed: free text still works
  }
  if (sd.needsDate) payload.hold_until = sd.hold_until
  if (await sendStatus(sd.lead, payload, `Moved to ${statusLabel(sd.to)}.`)) sd.open = false
}

function markLost(l) {
  Object.assign(sd, {
    open: true, lead: l, to: 'lost', error: '', reason: '', hold_until: '', isLost: true, lostReasonId: '',
    needsDate: false, reasonRequired: false, title: 'Mark as lost',
    reasonLabel: lostReasons.value.length ? 'Note (optional)' : 'Why was it lost? (optional)',
  })
}

const states = ref({})   // GST state code -> name, for the convert dialog
const cv = reactive({ open: false, lead: null, state_code: '', gstin: '', address_line1: '', create_new: false, matches: [], error: '', busy: false })
// A GSTIN starts with the state's code: fill the state from it.
watch(() => cv.gstin, (g) => { const code = String(g || '').slice(0, 2); if (/^\d{2}$/.test(code) && states.value[code]) cv.state_code = code })

async function convertLead(l) {
  if (l.customer_id) {                                        // already linked: nothing to ask
    busy.value = true
    try { const d = await leadService.convert(l.id); finishConvert(d?.data ?? d) }
    catch (e) { toastError(e?.response?.data?.message ?? 'Convert failed.') }
    finally { busy.value = false }
    return
  }
  Object.assign(cv, { open: true, lead: l, state_code: '', gstin: '', address_line1: '', create_new: false, matches: [], error: '', busy: false })
  if (!Object.keys(states.value).length) {
    try { const r = await leadService.states(); states.value = r?.data ?? {} } catch { /* the dialog still shows; the server refuses a missing state */ }
  }
}

function finishConvert(d) {
  cv.open = false
  detail.value = null
  emit('convert', { customer_id: d.customer_id, lead_id: d.lead_id })
}

async function submitConvert(extra = {}) {
  const body = { ...extra }
  if (!extra.customer_id) {
    if (cv.state_code) body.state_code = cv.state_code
    if (cv.gstin.trim()) body.gstin = cv.gstin.trim().toUpperCase()
    if (cv.address_line1.trim()) body.address_line1 = cv.address_line1.trim()
    if (cv.create_new) body.create_new = true
  }
  cv.busy = true; cv.error = ''
  try {
    const res = await leadService.convert(cv.lead.id, body)
    finishConvert(res?.data ?? res)
  } catch (e) {
    const data = e?.response?.data
    if (e?.response?.status === 409 && data?.error_code === 'CUSTOMER_EXISTS') { cv.matches = data.errors?.customers ?? []; return }
    cv.error = data?.message ?? 'Convert failed.'
  } finally { cv.busy = false }
}

async function removeLead(l) {
  const ok = await confirmDialog({ title: 'Delete lead?', message: `Delete ${l.lead_no}?`, confirmLabel: 'Delete', cancelLabel: 'Cancel', danger: true })
  if (!ok) return
  try { await leadService.remove(l.id); toastSuccess('Lead deleted.'); detail.value = null; await load() }
  catch (e) { toastError('Failed to delete.') }
}

async function loadOptions() {
  try {
    const res = await leadService.options()
    const o = res?.data ?? res
    if (Array.isArray(o?.sources) && o.sources.length) sources.value = o.sources
    if (o?.transitions && typeof o.transitions === 'object') transitions.value = o.transitions
    if (Array.isArray(o?.lost_reasons)) lostReasons.value = o.lost_reasons
  } catch { /* keep the fallback list */ }
}

onMounted(() => { load(); loadOptions() })
</script>

<style scoped>
.lm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.lm-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; gap: 16px; flex-wrap: wrap; }
.lm-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.lm-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.lm-head-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.lm-search { padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; width: 240px; }
.view-toggle { display: inline-flex; border: 1px solid var(--border); border-radius: 7px; overflow: hidden; }
.view-toggle button { border: none; background: #fff; padding: 8px 14px; font-size: 13px; font-weight: 600; color: #888; cursor: pointer; }
.view-toggle button.on { background: var(--primary); color: #fff; }

.insights { margin-bottom: 8px; }
.kpi-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; }
.kpi label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-3); font-weight: 700; }
.kpi span { font-size: 24px; font-weight: 800; color: var(--text); }
.kpi .green { color: #2e7d32; } .kpi .red { color: #c62828; }
.ins-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ins-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
.ins-card h4 { margin: 0 0 12px; font-size: 13px; color: var(--primary); }
.funnel-row { display: flex; align-items: center; gap: 10px; margin-bottom: 7px; }
.funnel-label { width: 78px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
.funnel-bar-track { flex: 1; height: 14px; background: var(--surface-2); border-radius: 7px; overflow: hidden; }
.funnel-bar { height: 100%; background: var(--primary); border-radius: 7px; }
.funnel-bar.won { background: #2e7d32; } .funnel-bar.lost { background: #c62828; }
.funnel-count { width: 30px; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.ins-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ins-table th { text-align: left; color: var(--text-3); font-size: 10px; text-transform: uppercase; padding: 4px 6px; border-bottom: 1px solid var(--border); }
.ins-table th.r, .ins-table td.r { text-align: right; }
.ins-table td { padding: 5px 6px; border-bottom: 1px solid var(--surface-2); }
.fu-row, .lr-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--surface-2); font-size: 13px; }
.fu-row.red b { color: #c62828; } .fu-row.amber b { color: #b5740a; }
@media (max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .ins-grid { grid-template-columns: 1fr; } }

.status-tabs { display: flex; gap: 4px; margin-bottom: 18px; border-bottom: 2px solid #e0e0e0; flex-wrap: wrap; }
.stab { padding: 8px 16px; border: none; background: none; font-size: 13px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; }
.stab.active { color: var(--primary); border-bottom-color: var(--primary); }
.stab-count { margin-left: 6px; font-size: 11px; background: var(--surface-2); border-radius: 8px; padding: 0 6px; }

.lm-loading { text-align: center; padding: 60px; color: #888; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 40px; }

.lead-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.lead-table th { background: var(--primary); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
.lead-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 14px 0 4px; }
.page-info  { font-size: 12px; color: #666; font-variant-numeric: tabular-nums; }
.pg-btn { padding: 5px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
.pg-btn:disabled { opacity: .5; cursor: not-allowed; }
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
.status-badge.on_hold { background: #fff3e0; color: #e65100; }
.status-badge.invalid { background: #eceff1; color: #546e7a; }
.modal-overlay.sd-overlay { z-index: 1100; }   /* above the lead drawer (1000) it is opened from */
.sd-input { width: 100%; padding: 8px 10px; border: 1px solid #cfd8dc; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.dup-box { margin-top: 12px; padding: 10px 14px; background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; font-size: 13px; color: #6d4c00; }
.dup-box ul { margin: 6px 0; padding-left: 18px; }
.dup-box a { color: var(--primary); font-weight: 700; }
.dup-hint { font-size: 12px; color: #8d6e00; }
.cv-note { font-size: 13px; color: #455a64; margin: 0 0 10px; }
.cv-matches { list-style: none; margin: 0 0 8px; padding: 0; }
.cv-matches li { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid #eceff1; font-size: 13px; }
.link-btn { background: none; border: 0; padding: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; }
.raw-msg { margin: 6px 0 0; padding: 8px 10px; background: #f5f7f9; border-radius: 6px; font-size: 11px; max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.sd-error { color: #c62828; font-size: 13px; margin: 8px 0 0; }
.form-grid.one { grid-template-columns: 1fr; }

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
