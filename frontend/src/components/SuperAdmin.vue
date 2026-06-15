<template>
  <div class="sa-wrap">
    <div class="sa-header">
      <div>
        <h2>Platform Admin</h2>
        <p class="sa-sub">Manage every tenant on PanelOS — activate, extend, suspend.</p>
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

    <!-- Filters -->
    <div class="sa-toolbar">
      <input v-model="search" placeholder="Search company / email…" class="sa-search" @keyup.enter="load" />
      <select v-model="status" @change="load">
        <option value="">All statuses</option>
        <option value="trial">Trial</option>
        <option value="active">Active</option>
        <option value="expired">Expired</option>
      </select>
    </div>

    <!-- Companies table -->
    <div v-if="loading" class="loading-hint">Loading…</div>
    <table v-else class="sa-table">
      <thead>
        <tr><th>Company</th><th>Plan</th><th>Status</th><th class="c">Users</th><th>Trial / Sub ends</th><th>Signed up</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="c in companies" :key="c.id" :class="{ suspended: !c.is_active }">
          <td>
            <div class="c-name">{{ c.name }}</div>
            <div class="c-sub">{{ c.email || '—' }} · {{ c.subdomain }}</div>
          </td>
          <td><span class="plan-chip">{{ c.subscription_plan }}</span></td>
          <td>
            <span class="status-chip" :class="c.is_active ? c.subscription_status : 'suspended'">
              {{ c.is_active ? c.subscription_status : 'suspended' }}
            </span>
          </td>
          <td class="c">{{ c.users }}</td>
          <td class="small">{{ fmtDate(c.subscription_ends_at || c.trial_ends_at) }}</td>
          <td class="small">{{ fmtDate(c.created_at) }}</td>
          <td class="actions">
            <button class="mini" @click="openActivate(c)">Activate</button>
            <button class="mini ghost" @click="extend(c)">+Trial</button>
            <button class="mini" :class="c.is_active ? 'danger' : 'green'" @click="toggleActive(c)">
              {{ c.is_active ? 'Suspend' : 'Restore' }}
            </button>
          </td>
        </tr>
        <tr v-if="!companies.length"><td colspan="7" class="empty">No companies found.</td></tr>
      </tbody>
    </table>

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
        <div class="form-group"><label>Months</label>
          <input v-model.number="actForm.months" type="number" min="1" max="36" />
        </div>
        <div v-if="actError" class="error-banner">{{ actError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="actTarget = null">Cancel</button>
          <button class="btn btn-primary" :disabled="acting" @click="doActivate">{{ acting ? 'Activating…' : 'Activate' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import superAdminService from '../services/superAdminService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

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

function fmtShort(n) {
  const v = Number(n || 0)
  if (v >= 100000) return (v / 100000).toFixed(2) + 'L'
  if (v >= 1000) return (v / 1000).toFixed(1) + 'K'
  return String(v)
}
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
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load.'
  } finally { loading.value = false }
}

function openActivate(c) { actTarget.value = c; actForm.plan = c.subscription_plan || 'growth'; actForm.months = 1; actError.value = null }
async function doActivate() {
  acting.value = true; actError.value = null
  try {
    await superAdminService.activate(actTarget.value.id, actForm.plan, actForm.months)
    toastSuccess(`${actTarget.value.name} activated.`)
    actTarget.value = null
    await load()
  } catch (e) { actError.value = e?.response?.data?.message ?? 'Activation failed.' }
  finally { acting.value = false }
}

async function extend(c) {
  const ok = await confirmDialog({ title: 'Extend trial?', message: `Add 14 more trial days for ${c.name}?`, confirmLabel: 'Extend', cancelLabel: 'No' })
  if (!ok) return
  try { await superAdminService.extendTrial(c.id, 14); toastSuccess('Trial extended 14 days.'); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
}

async function toggleActive(c) {
  const suspend = c.is_active
  const ok = await confirmDialog({
    title: suspend ? 'Suspend tenant?' : 'Restore tenant?',
    message: suspend ? `${c.name} will lose access immediately.` : `${c.name} will regain access.`,
    confirmLabel: suspend ? 'Suspend' : 'Restore', cancelLabel: 'Cancel', danger: suspend,
  })
  if (!ok) return
  try { await superAdminService.setActive(c.id, !c.is_active); toastSuccess(suspend ? 'Suspended.' : 'Restored.'); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'Failed.') }
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

.kpi-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 18px; }
.kpi { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 14px; text-align: center; }
.kpi-val { font-size: 22px; font-weight: 800; color: var(--primary); }
.kpi-lbl { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-top: 3px; }
.kpi.green .kpi-val { color: #2e7d32; } .kpi.amber .kpi-val { color: #b5740a; } .kpi.red .kpi-val { color: #c62828; }
.kpi.primary { background: var(--primary); } .kpi.primary .kpi-val { color: #fff; } .kpi.primary .kpi-lbl { color: rgba(255,255,255,.7); }

.sa-toolbar { display: flex; gap: 10px; margin-bottom: 12px; }
.sa-search { flex: 1; max-width: 360px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.sa-toolbar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }

.sa-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; font-size: 13px; }
.sa-table th { background: var(--primary); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
.sa-table th.c, .sa-table td.c { text-align: center; }
.sa-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.sa-table tr.suspended { background: #fff5f5; }
.c-name { font-weight: 700; color: #15181E; }
.c-sub { font-size: 11px; color: #888; }
.small { font-size: 12px; color: #555; }
.plan-chip { font-size: 11px; font-weight: 700; background: #eef1fe; color: var(--primary); padding: 2px 9px; border-radius: 10px; text-transform: capitalize; }
.status-chip { font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 10px; text-transform: uppercase; }
.status-chip.trial { background: #fff8e1; color: #b5740a; }
.status-chip.active { background: #e8f5e9; color: #2e7d32; }
.status-chip.expired { background: #ffebee; color: #c62828; }
.status-chip.suspended { background: #eceff1; color: #607d8b; }
.actions { display: flex; gap: 5px; flex-wrap: wrap; }
.mini { padding: 4px 10px; border: 1px solid var(--primary); background: var(--primary); color: #fff; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; }
.mini.ghost { background: #fff; color: var(--primary); }
.mini.danger { background: #c62828; border-color: #c62828; }
.mini.green { background: #2e7d32; border-color: #2e7d32; }
.empty { text-align: center; color: #aaa; font-style: italic; padding: 24px; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: #fff; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 420px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.x { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #666; }
.form-group select, .form-group input { padding: 9px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 8px; }
.btn { padding: 9px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; } .btn-primary:disabled { opacity: .5; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }

@media (max-width: 1000px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
</style>
