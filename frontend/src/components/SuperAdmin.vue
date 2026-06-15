<template>
  <div class="sa-wrap">
    <div class="sa-header">
      <div>
        <h2>Platform Admin</h2>
        <p class="sa-sub">Manage every tenant on PanelOS — activate, extend, suspend, support.</p>
      </div>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻ Refresh</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>

    <!-- Overview KPIs -->
    <div class="kpi-grid" v-if="ov">
      <div class="kpi"><div class="kpi-val">{{ ov.total_companies }}</div><div class="kpi-lbl">Companies</div></div>
      <div class="kpi green"><div class="kpi-val">{{ ov.active }}</div><div class="kpi-lbl">Active</div></div>
      <div class="kpi amber"><div class="kpi-val">{{ ov.trial }}</div><div class="kpi-lbl">On Trial</div></div>
      <div class="kpi red"><div class="kpi-val">{{ ov.expired }}</div><div class="kpi-lbl">Expired</div></div>
      <div class="kpi"><div class="kpi-val">{{ ov.suspended }}</div><div class="kpi-lbl">Suspended</div></div>
      <div class="kpi primary"><div class="kpi-val">₹{{ fmtShort(ov.mrr_estimate) }}</div><div class="kpi-lbl">Est. MRR</div></div>
    </div>

    <!-- Tabs -->
    <div class="sa-tabs">
      <button :class="{ on: tab === 'companies' }" @click="tab = 'companies'">Companies</button>
      <button :class="{ on: tab === 'expiring' }" @click="tab = 'expiring'; loadExpiring()">Expiring Soon
        <span v-if="expiring.length" class="tab-badge">{{ expiring.length }}</span>
      </button>
      <button :class="{ on: tab === 'revenue' }" @click="tab = 'revenue'; loadRevenue()">Revenue &amp; Growth</button>
      <button :class="{ on: tab === 'admins' }" @click="tab = 'admins'; loadAdmins()">Platform Admins</button>
      <button :class="{ on: tab === 'settings' }" @click="tab = 'settings'; loadSettings()">Settings</button>
    </div>

    <!-- ════ COMPANIES ════ -->
    <template v-if="tab === 'companies'">
      <div class="sa-toolbar">
        <input v-model="search" placeholder="Search company / email…" class="sa-search" @keyup.enter="load" />
        <select v-model="status" @change="load">
          <option value="">All statuses</option>
          <option value="trial">Trial</option>
          <option value="active">Active</option>
          <option value="expired">Expired</option>
        </select>
      </div>

      <div v-if="loading" class="loading-hint">Loading…</div>
      <table v-else class="sa-table">
        <thead>
          <tr><th>Company</th><th>Plan</th><th>Status</th><th class="c">Users</th><th>Trial / Sub ends</th><th>Signed up</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="c in companies" :key="c.id" :class="{ suspended: !c.is_active }">
            <td>
              <a class="c-name" href="#" @click.prevent="openDetail(c)">{{ c.name }}</a>
              <div class="c-sub">{{ c.email || '—' }} · {{ c.subdomain }}</div>
            </td>
            <td><span class="plan-chip">{{ c.subscription_plan }}</span></td>
            <td><span class="status-chip" :class="c.is_active ? c.subscription_status : 'suspended'">{{ c.is_active ? c.subscription_status : 'suspended' }}</span></td>
            <td class="c">{{ c.users }}</td>
            <td class="small">{{ fmtDate(c.subscription_ends_at || c.trial_ends_at) }}</td>
            <td class="small">{{ fmtDate(c.created_at) }}</td>
            <td class="actions">
              <button class="mini ghost" @click="openDetail(c)">Detail</button>
              <button class="mini" @click="openActivate(c)">Activate</button>
              <button class="mini ghost" @click="extend(c)">+Trial</button>
              <button class="mini warn" @click="impersonate(c)">Login as</button>
              <button class="mini" :class="c.is_active ? 'danger' : 'green'" @click="toggleActive(c)">{{ c.is_active ? 'Suspend' : 'Restore' }}</button>
            </td>
          </tr>
          <tr v-if="!companies.length"><td colspan="7" class="empty">No companies found.</td></tr>
        </tbody>
      </table>
    </template>

    <!-- ════ EXPIRING ════ -->
    <template v-else-if="tab === 'expiring'">
      <p class="hint-line">Tenants whose trial / subscription ends within 7 days. Reach out to convert / renew.</p>
      <div v-if="expLoading" class="loading-hint">Loading…</div>
      <table v-else class="sa-table">
        <thead><tr><th>Company</th><th>Status</th><th class="c">Days left</th><th>Ends</th><th>Contact</th><th></th></tr></thead>
        <tbody>
          <tr v-for="e in expiring" :key="e.id">
            <td><div class="c-name">{{ e.name }}</div><div class="c-sub">{{ e.admin_name || '—' }}</div></td>
            <td><span class="status-chip" :class="e.status">{{ e.status }}</span></td>
            <td class="c" :class="{ red: e.days_left <= 2 }"><b>{{ e.days_left }}</b></td>
            <td class="small">{{ fmtDate(e.ends_at) }}</td>
            <td class="small">{{ e.phone || e.email || '—' }}</td>
            <td class="actions">
              <a v-if="e.phone" class="mini green" :href="waLink(e)" target="_blank">WhatsApp</a>
              <a v-if="e.email" class="mini ghost" :href="mailLink(e)">Email</a>
            </td>
          </tr>
          <tr v-if="!expiring.length"><td colspan="6" class="empty">No tenants expiring in the next 7 days. 🎉</td></tr>
        </tbody>
      </table>
    </template>

    <!-- ════ REVENUE & GROWTH ════ -->
    <template v-else-if="tab === 'revenue'">
      <div v-if="revLoading" class="loading-hint">Loading…</div>
      <template v-else-if="rev">
        <div class="kpi-grid five">
          <div class="kpi primary"><div class="kpi-val">₹{{ fmtShort(rev.mrr) }}</div><div class="kpi-lbl">MRR</div></div>
          <div class="kpi"><div class="kpi-val">₹{{ fmtShort(rev.arr) }}</div><div class="kpi-lbl">ARR</div></div>
          <div class="kpi"><div class="kpi-val">₹{{ fmtShort(rev.arpu) }}</div><div class="kpi-lbl">ARPU</div></div>
          <div class="kpi green"><div class="kpi-val">₹{{ fmtShort(rev.collected_this_month) }}</div><div class="kpi-lbl">Collected (mo)</div></div>
          <div class="kpi red"><div class="kpi-val">{{ rev.churned_this_month }}</div><div class="kpi-lbl">Churned (mo)</div></div>
        </div>

        <div class="two-col">
          <!-- Funnel -->
          <div class="panel" v-if="funnel">
            <h3 class="sec-h">Signup Funnel (30 days)</h3>
            <div class="funnel-row"><span>Signups</span><b>{{ funnel.signups }}</b></div>
            <div class="funnel-row"><span>Still on trial</span><b>{{ funnel.still_trial }}</b></div>
            <div class="funnel-row"><span>Converted (paid)</span><b class="green-t">{{ funnel.converted }}</b></div>
            <div class="funnel-row"><span>Expired</span><b class="red">{{ funnel.expired }}</b></div>
            <div class="conv-bar"><div class="conv-fill" :style="{ width: funnel.conversion_pct + '%' }"></div></div>
            <div class="conv-lbl">{{ funnel.conversion_pct }}% conversion</div>
            <h4 class="d-h">By source (UTM)</h4>
            <div v-for="s in funnel.by_source" :key="s.source" class="src-row"><span>{{ s.source }}</span><b>{{ s.count }}</b></div>
          </div>

          <!-- Plan distribution -->
          <div class="panel">
            <h3 class="sec-h">Active Plans</h3>
            <div v-for="p in rev.by_plan" :key="p.plan" class="plan-row">
              <span class="plan-chip">{{ p.plan }}</span>
              <span class="plan-bar-track"><span class="plan-bar-fill" :style="{ width: planPct(p) + '%' }"></span></span>
              <b>{{ p.count }}</b>
            </div>
          </div>
        </div>

        <!-- Payment history -->
        <h3 class="sec-h" style="margin:8px 0 10px">Recent Subscription Payments</h3>
        <table class="sa-table">
          <thead><tr><th>Invoice</th><th>Company</th><th>Plan</th><th class="c">Months</th><th class="r">Amount</th><th>Method</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="p in rev.payments" :key="p.id">
              <td class="mono">{{ p.invoice_no || '—' }}</td>
              <td>{{ p.company }}</td>
              <td><span class="plan-chip">{{ p.plan }}</span></td>
              <td class="c">{{ p.months }}</td>
              <td class="r"><b>₹ {{ Number(p.total).toLocaleString('en-IN') }}</b></td>
              <td><span class="status-chip" :class="p.method === 'razorpay' ? 'active' : 'trial'">{{ p.method }}</span></td>
              <td class="small">{{ fmtDate(p.date) }}</td>
              <td><button v-if="p.invoice_no" class="mini ghost" @click="dlInvoice(p)">Invoice</button></td>
            </tr>
            <tr v-if="!rev.payments.length"><td colspan="8" class="empty">No payments yet.</td></tr>
          </tbody>
        </table>
      </template>
    </template>

    <!-- ════ PLATFORM ADMINS ════ -->
    <template v-else-if="tab === 'admins'">
      <div class="sa-toolbar">
        <h3 class="sec-h">Platform Administrators</h3>
        <button class="btn btn-primary btn-sm" @click="showAddAdmin = true">+ Add Admin</button>
      </div>
      <div v-if="admLoading" class="loading-hint">Loading…</div>
      <table v-else class="sa-table">
        <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Last login</th></tr></thead>
        <tbody>
          <tr v-for="a in admins" :key="a.id">
            <td class="c-name">{{ a.name }}</td>
            <td class="small">{{ a.email }}</td>
            <td><span class="status-chip" :class="a.is_active ? 'active' : 'suspended'">{{ a.is_active ? 'active' : 'inactive' }}</span></td>
            <td class="small">{{ a.last_login_at ? fmtDate(a.last_login_at) : 'never' }}</td>
          </tr>
        </tbody>
      </table>
      <p class="warn-note">⚠️ Platform admins can see and manage <b>every</b> tenant. Add only trusted people.</p>
    </template>

    <!-- ════ SETTINGS ════ -->
    <template v-else-if="tab === 'settings'">
      <div v-if="setLoading" class="loading-hint">Loading…</div>
      <template v-else-if="settings">
        <div class="status-strip" :class="settings.razorpay_ready ? 'ok' : 'off'">
          <span class="dot"></span>
          Razorpay {{ settings.razorpay_ready ? 'Active — online payments live' : 'Not configured — tenants pay manually' }}
        </div>

        <div class="panel">
          <h3 class="sec-h">🔑 Razorpay Credentials</h3>
          <p class="hint-line">From Razorpay Dashboard → Settings → API Keys. Webhook secret from Settings → Webhooks.</p>
          <label class="sw">
            <input type="checkbox" v-model="settings.razorpay_enabled" />
            <span>Enable online payments</span>
          </label>
          <div class="form-grid">
            <div class="form-group"><label>Key ID</label><input v-model="settings.razorpay_key_id" placeholder="rzp_live_xxx / rzp_test_xxx" /></div>
            <div class="form-group"><label>Key Secret</label><input v-model="settings.razorpay_key_secret" type="password" :placeholder="settings.secret_is_set ? 'saved — leave blank to keep' : 'paste secret'" /></div>
            <div class="form-group"><label>Webhook Secret</label><input v-model="settings.razorpay_webhook_secret" type="password" placeholder="(optional) for webhook backstop" /></div>
          </div>
          <div class="row-actions">
            <button class="btn btn-primary" :disabled="saving" @click="saveSettings(true)">{{ saving ? 'Saving…' : 'Save Settings' }}</button>
            <button class="btn btn-ghost" :disabled="testing" @click="testRzp">{{ testing ? 'Testing…' : 'Test Razorpay' }}</button>
            <span v-if="testMsg" :class="testOk ? 'ok-t' : 'err-t'">{{ testMsg }}</span>
          </div>
        </div>

        <div class="panel">
          <h3 class="sec-h">🧾 GST Seller Identity (your subscription invoices)</h3>
          <p class="hint-line">Shown as the seller on tax invoices you raise to tenants for their subscription.</p>
          <div class="form-grid">
            <div class="form-group"><label>Business Name</label><input v-model="settings.platform_name" /></div>
            <div class="form-group"><label>GSTIN</label><input v-model="settings.platform_gstin" /></div>
            <div class="form-group"><label>PAN</label><input v-model="settings.platform_pan" /></div>
            <div class="form-group"><label>Address</label><input v-model="settings.platform_address" /></div>
            <div class="form-group"><label>State</label><input v-model="settings.platform_state" /></div>
            <div class="form-group"><label>State Code</label><input v-model="settings.platform_state_code" placeholder="24" /></div>
            <div class="form-group"><label>Email</label><input v-model="settings.platform_email" /></div>
            <div class="form-group"><label>Phone</label><input v-model="settings.platform_phone" /></div>
            <div class="form-group"><label>SAC Code</label><input v-model="settings.platform_sac" placeholder="997331" /></div>
          </div>
          <div class="row-actions">
            <button class="btn btn-primary" :disabled="saving" @click="saveSettings(false)">{{ saving ? 'Saving…' : 'Save Settings' }}</button>
          </div>
        </div>
      </template>
    </template>

    <!-- Activate modal -->
    <div v-if="actTarget" class="modal-overlay" @click.self="actTarget = null">
      <div class="modal-box">
        <div class="modal-header"><h3>Activate {{ actTarget.name }}</h3><button class="x" @click="actTarget = null">✕</button></div>
        <div class="form-group"><label>Plan</label>
          <select v-model="actForm.plan">
            <option value="starter">Starter — ₹2,999/mo</option>
            <option value="growth">Growth — ₹5,999/mo</option>
            <option value="pro">Pro — ₹9,999/mo</option>
            <option value="enterprise">Enterprise — ₹19,999/mo</option>
          </select>
        </div>
        <div class="form-group"><label>Months</label><input v-model.number="actForm.months" type="number" min="1" max="36" /></div>
        <div v-if="actError" class="error-banner">{{ actError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="actTarget = null">Cancel</button>
          <button class="btn btn-primary" :disabled="acting" @click="doActivate">{{ acting ? 'Activating…' : 'Activate' }}</button>
        </div>
      </div>
    </div>

    <!-- Tenant detail drawer -->
    <div v-if="detail" class="modal-overlay" @click.self="detail = null">
      <div class="modal-box lg">
        <div class="modal-header"><h3>{{ detail.company.name }}</h3><button class="x" @click="detail = null">✕</button></div>
        <div class="d-grid">
          <div class="d-cell"><div class="d-lbl">Plan</div><div class="d-val">{{ detail.company.subscription_plan }}</div></div>
          <div class="d-cell"><div class="d-lbl">Status</div><div class="d-val">{{ detail.company.subscription_status }}</div></div>
          <div class="d-cell"><div class="d-lbl">GSTIN</div><div class="d-val">{{ detail.company.gstin || '—' }}</div></div>
          <div class="d-cell"><div class="d-lbl">Phone</div><div class="d-val">{{ detail.company.phone || '—' }}</div></div>
        </div>
        <div class="usage-row">
          <div class="u-box"><div class="u-n">{{ detail.usage.users }}</div><div class="u-l">Users</div></div>
          <div class="u-box"><div class="u-n">{{ detail.usage.quotations }}</div><div class="u-l">Quotations</div></div>
          <div class="u-box"><div class="u-n">{{ detail.usage.orders }}</div><div class="u-l">Orders</div></div>
          <div class="u-box"><div class="u-n">{{ detail.usage.invoices }}</div><div class="u-l">Invoices</div></div>
        </div>
        <div class="d-sec">Last activity: {{ detail.usage.last_login ? fmtDate(detail.usage.last_login) : 'never' }}</div>
        <h4 class="d-h">Users</h4>
        <table class="mini-table">
          <tr v-for="u in detail.users" :key="u.id"><td>{{ u.name }}</td><td class="small">{{ u.email }}</td><td>{{ u.is_company_admin ? 'Admin' : 'User' }}</td><td class="small">{{ u.last_login_at ? fmtDate(u.last_login_at) : 'never' }}</td></tr>
        </table>
        <h4 class="d-h" v-if="detail.recent_actions?.length">Recent platform actions</h4>
        <table class="mini-table" v-if="detail.recent_actions?.length">
          <tr v-for="(a, i) in detail.recent_actions" :key="i"><td>{{ a.label }}</td><td class="small">{{ a.user_name }}</td><td class="small">{{ fmtDate(a.created_at) }}</td></tr>
        </table>
        <div class="modal-actions">
          <button class="btn btn-warn" @click="impersonate(detail.company)">Login as this tenant</button>
          <button class="btn btn-ghost" @click="detail = null">Close</button>
        </div>
      </div>
    </div>

    <!-- Add platform admin modal -->
    <div v-if="showAddAdmin" class="modal-overlay" @click.self="showAddAdmin = false">
      <div class="modal-box">
        <div class="modal-header"><h3>Add Platform Admin</h3><button class="x" @click="showAddAdmin = false">✕</button></div>
        <div class="form-group"><label>Name</label><input v-model="admForm.name" /></div>
        <div class="form-group"><label>Email</label><input v-model="admForm.email" type="email" /></div>
        <div class="form-group"><label>Password</label><input v-model="admForm.password" type="password" placeholder="min 8, letter+number" /></div>
        <div v-if="admError" class="error-banner">{{ admError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showAddAdmin = false">Cancel</button>
          <button class="btn btn-primary" :disabled="admSaving" @click="addAdmin">{{ admSaving ? 'Adding…' : 'Add Admin' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import superAdminService from '../services/superAdminService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const tab = ref('companies')
const ov = ref(null)
const companies = ref([])
const loading = ref(false)
const error = ref(null)
const search = ref('')
const status = ref('')

const actTarget = ref(null)
const actForm = reactive({ plan: 'growth', months: 1 })
const acting = ref(false)
const actError = ref(null)

const detail = ref(null)
const expiring = ref([])
const expLoading = ref(false)
const rev = ref(null)
const funnel = ref(null)
const revLoading = ref(false)
const settings = ref(null)
const setLoading = ref(false)
const saving = ref(false)
const testing = ref(false)
const testMsg = ref('')
const testOk = ref(false)
const admins = ref([])
const admLoading = ref(false)
const showAddAdmin = ref(false)
const admForm = reactive({ name: '', email: '', password: '' })
const admSaving = ref(false)
const admError = ref(null)

function fmtShort(n) { const v = Number(n || 0); if (v >= 100000) return (v / 100000).toFixed(2) + 'L'; if (v >= 1000) return (v / 1000).toFixed(1) + 'K'; return String(v) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: '2-digit' }) : '—' }

async function load() {
  loading.value = true; error.value = null
  try {
    const [o, c] = await Promise.all([
      superAdminService.overview(),
      superAdminService.companies({ search: search.value || undefined, status: status.value || undefined }),
    ])
    ov.value = o?.data ?? null
    companies.value = c?.data ?? []
  } catch (e) { error.value = e?.response?.data?.message ?? 'Failed to load.' }
  finally { loading.value = false }
}

async function loadExpiring() {
  expLoading.value = true
  try { expiring.value = (await superAdminService.expiring(7))?.data ?? [] }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
  finally { expLoading.value = false }
}

async function loadRevenue() {
  revLoading.value = true
  try {
    const [r, f] = await Promise.all([superAdminService.revenue(), superAdminService.funnel(30)])
    rev.value = r?.data ?? null
    funnel.value = f?.data ?? null
  } catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
  finally { revLoading.value = false }
}
function planPct(p) {
  const total = (rev.value?.by_plan ?? []).reduce((s, x) => s + x.count, 0) || 1
  return Math.round((p.count / total) * 100)
}
async function dlInvoice(p) {
  try { await superAdminService.downloadInvoice(p.id, p.invoice_no) }
  catch (e) { toastError('Could not download invoice.') }
}

async function loadSettings() {
  setLoading.value = true; testMsg.value = ''
  try {
    const s = (await superAdminService.getSettings())?.data ?? {}
    // blank the masked secrets so the user types only to change
    s.razorpay_key_secret = ''
    s.razorpay_webhook_secret = ''
    settings.value = s
  } catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
  finally { setLoading.value = false }
}
async function saveSettings() {
  saving.value = true
  try {
    const p = { ...settings.value }
    if (!p.razorpay_key_secret) delete p.razorpay_key_secret
    if (!p.razorpay_webhook_secret) delete p.razorpay_webhook_secret
    await superAdminService.saveSettings(p)
    toastSuccess('Settings saved.')
    await loadSettings()
  } catch (e) { toastError(e?.response?.data?.message ?? 'Save failed.') }
  finally { saving.value = false }
}
async function testRzp() {
  testing.value = true; testMsg.value = ''
  try {
    const r = await superAdminService.testRazorpay()
    testOk.value = true; testMsg.value = r?.message ?? '✓ Razorpay works!'
  } catch (e) {
    testOk.value = false; testMsg.value = e?.response?.data?.message ?? 'Test failed.'
  } finally { testing.value = false }
}

async function loadAdmins() {
  admLoading.value = true
  try { admins.value = (await superAdminService.platformAdmins())?.data ?? [] }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
  finally { admLoading.value = false }
}

async function openDetail(c) {
  detail.value = null
  try { detail.value = (await superAdminService.company(c.id))?.data ?? null }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not load detail.') }
}

function openActivate(c) { actTarget.value = c; actForm.plan = c.subscription_plan || 'growth'; actForm.months = 1; actError.value = null }
async function doActivate() {
  acting.value = true; actError.value = null
  try { await superAdminService.activate(actTarget.value.id, actForm.plan, actForm.months); toastSuccess(`${actTarget.value.name} activated.`); actTarget.value = null; await load() }
  catch (e) { actError.value = e?.response?.data?.message ?? 'Activation failed.' }
  finally { acting.value = false }
}

async function extend(c) {
  const ok = await confirmDialog({ title: 'Extend trial?', message: `Add 14 trial days for ${c.name}?`, confirmLabel: 'Extend', cancelLabel: 'No' })
  if (!ok) return
  try { await superAdminService.extendTrial(c.id, 14); toastSuccess('Trial extended.'); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
}

async function toggleActive(c) {
  const suspend = c.is_active
  const ok = await confirmDialog({ title: suspend ? 'Suspend tenant?' : 'Restore tenant?', message: suspend ? `${c.name} loses access immediately.` : `${c.name} regains access.`, confirmLabel: suspend ? 'Suspend' : 'Restore', cancelLabel: 'Cancel', danger: suspend })
  if (!ok) return
  try { await superAdminService.setActive(c.id, !c.is_active); toastSuccess(suspend ? 'Suspended.' : 'Restored.'); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
}

async function impersonate(c) {
  const ok = await confirmDialog({ title: `Login as ${c.name}?`, message: 'You will enter this tenant to view/support. This action is logged. Your platform session is saved and you can exit anytime.', confirmLabel: 'Login as tenant', cancelLabel: 'Cancel' })
  if (!ok) return
  try {
    const r = await superAdminService.impersonate(c.id)
    const d = r?.data ?? r
    // Save current super-admin session, swap to the impersonation token
    localStorage.setItem('token_superadmin', localStorage.getItem('token'))
    localStorage.setItem('user_superadmin', localStorage.getItem('user'))
    localStorage.setItem('token', d.token)
    localStorage.setItem('user', JSON.stringify(d.user))
    localStorage.setItem('impersonating', d.company_name)
    window.location.reload()
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not impersonate.') }
}

function waLink(e) {
  const phone = String(e.phone).replace(/\D/g, '')
  const num = phone.length === 10 ? '91' + phone : phone
  const msg = encodeURIComponent(`Hi ${e.admin_name || ''}, your PanelOS ${e.status} ends in ${e.days_left} day(s). Reply to renew/upgrade and keep your account active.`)
  return `https://wa.me/${num}?text=${msg}`
}
function mailLink(e) {
  const sub = encodeURIComponent('Your PanelOS subscription is expiring')
  const body = encodeURIComponent(`Hi ${e.admin_name || ''},\n\nYour PanelOS ${e.status} ends in ${e.days_left} day(s) (${fmtDate(e.ends_at)}). Reply to renew or upgrade.\n\n— PanelOS`)
  return `mailto:${e.email}?subject=${sub}&body=${body}`
}

async function addAdmin() {
  admSaving.value = true; admError.value = null
  try { await superAdminService.createPlatformAdmin({ ...admForm }); toastSuccess('Platform admin added.'); showAddAdmin.value = false; Object.assign(admForm, { name: '', email: '', password: '' }); await loadAdmins() }
  catch (e) { admError.value = e?.response?.data?.errors ? Object.values(e.response.data.errors).flat()[0] : (e?.response?.data?.message ?? 'Failed.') }
  finally { admSaving.value = false }
}

onMounted(load)
</script>

<style scoped>
.sa-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.sa-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; }
.sa-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.sa-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.loading-hint { text-align: center; padding: 40px; color: #999; }
.hint-line { font-size: 13px; color: var(--text-2); margin-bottom: 12px; }

.kpi-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 18px; }
.kpi { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 14px; text-align: center; }
.kpi-val { font-size: 22px; font-weight: 800; color: var(--primary); }
.kpi-lbl { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 3px; }
.kpi.green .kpi-val { color: #2e7d32; } .kpi.amber .kpi-val { color: #b5740a; } .kpi.red .kpi-val { color: #c62828; }
.kpi.primary { background: var(--primary); } .kpi.primary .kpi-val { color: #fff; } .kpi.primary .kpi-lbl { color: rgba(255,255,255,.7); }

.sa-tabs { display: flex; gap: 4px; background: #eef1f5; padding: 4px; border-radius: 8px; margin-bottom: 16px; width: fit-content; }
.sa-tabs button { border: 0; background: transparent; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; color: #555; cursor: pointer; }
.sa-tabs button.on { background: #fff; color: var(--primary); box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.tab-badge { background: #c62828; color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 8px; margin-left: 4px; }

.sa-toolbar { display: flex; gap: 10px; margin-bottom: 12px; align-items: center; }
.sa-search { flex: 1; max-width: 360px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.sa-toolbar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.sec-h { margin: 0; font-size: 15px; color: var(--primary); flex: 1; }

.sa-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; font-size: 13px; }
.sa-table th { background: var(--primary); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
.sa-table th.c, .sa-table td.c { text-align: center; }
.sa-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.sa-table tr.suspended { background: #fff5f5; }
.c-name { font-weight: 700; color: var(--primary); text-decoration: none; }
.c-name:hover { text-decoration: underline; }
.c-sub { font-size: 11px; color: #888; }
.small { font-size: 12px; color: #555; }
.red { color: #c62828; }
.plan-chip { font-size: 11px; font-weight: 700; background: #eef1fe; color: var(--primary); padding: 2px 9px; border-radius: 10px; text-transform: capitalize; }
.status-chip { font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 10px; text-transform: uppercase; }
.status-chip.trial { background: #fff8e1; color: #b5740a; }
.status-chip.active { background: #e8f5e9; color: #2e7d32; }
.status-chip.expired { background: #ffebee; color: #c62828; }
.status-chip.suspended { background: #eceff1; color: #607d8b; }
.actions { display: flex; gap: 5px; flex-wrap: wrap; }
.mini { padding: 4px 10px; border: 1px solid var(--primary); background: var(--primary); color: #fff; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
.mini.ghost { background: #fff; color: var(--primary); }
.mini.danger { background: #c62828; border-color: #c62828; }
.mini.green { background: #2e7d32; border-color: #2e7d32; }
.mini.warn { background: #b5740a; border-color: #b5740a; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 24px; }
.warn-note { font-size: 12px; color: #b5740a; background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 10px 14px; margin-top: 12px; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: #fff; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 420px; }
.modal-box.lg { max-width: 680px; max-height: 86vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.x { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #666; }
.form-group select, .form-group input { padding: 9px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 12px; }
.btn { padding: 9px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-sm { padding: 6px 14px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; } .btn-primary:disabled { opacity: .5; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-warn { background: #b5740a; color: #fff; }

.d-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
.d-cell { background: #f8fafc; border-radius: 8px; padding: 10px; }
.d-lbl { font-size: 10px; text-transform: uppercase; color: #888; font-weight: 700; }
.d-val { font-size: 14px; font-weight: 700; color: #15181E; text-transform: capitalize; }
.usage-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
.u-box { background: var(--primary); border-radius: 8px; padding: 12px; text-align: center; color: #fff; }
.u-n { font-size: 20px; font-weight: 800; }
.u-l { font-size: 11px; opacity: .8; }
.d-sec { font-size: 12px; color: #667085; margin-bottom: 14px; }
.d-h { margin: 14px 0 6px; font-size: 13px; color: var(--primary); }
.mini-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.mini-table td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; }

.kpi-grid.five { grid-template-columns: repeat(5, 1fr); }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
.panel { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 16px 18px; }
.funnel-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f3f3f3; font-size: 13px; }
.green-t { color: #2e7d32; }
.conv-bar { height: 10px; background: #eef1f5; border-radius: 6px; overflow: hidden; margin: 12px 0 4px; }
.conv-fill { height: 100%; background: linear-gradient(90deg, #2e7d32, #43a047); }
.conv-lbl { font-size: 12px; font-weight: 700; color: #2e7d32; text-align: center; }
.src-row { display: flex; justify-content: space-between; font-size: 12.5px; padding: 4px 0; color: #444; }
.plan-row { display: flex; align-items: center; gap: 10px; margin: 8px 0; }
.plan-bar-track { flex: 1; height: 14px; background: #eef1f5; border-radius: 7px; overflow: hidden; }
.plan-bar-fill { height: 100%; background: linear-gradient(90deg, var(--primary), #4863e0); }
.mono { font-family: monospace; font-size: 12px; }
.r { text-align: right; }

.status-strip { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 14px; }
.status-strip.ok { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.status-strip.off { background: #fff8e1; color: #b5740a; border: 1px solid #ffe082; }
.status-strip .dot { width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
.panel .sec-h { margin: 0 0 4px; }
.sw { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; margin: 8px 0 14px; cursor: pointer; }
.sw input { width: 16px; height: 16px; accent-color: var(--primary); }
.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.form-grid .form-group { margin-bottom: 0; }
.row-actions { display: flex; align-items: center; gap: 10px; margin-top: 14px; }
.ok-t { font-size: 12px; color: #2e7d32; font-weight: 600; }
.err-t { font-size: 12px; color: #c62828; }
.panel + .panel { margin-top: 14px; }
@media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
@media (max-width: 1000px) { .kpi-grid.five { grid-template-columns: repeat(3, 1fr); } .two-col { grid-template-columns: 1fr; } }

@media (max-width: 1000px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } .d-grid, .usage-row { grid-template-columns: repeat(2, 1fr); } }
</style>
