<template>
  <section class="la-card" aria-label="Assignment rules">
    <h3>Who gets a new lead</h3>
    <p class="la-intro">
      With no rules, new leads stay unassigned until someone takes them. A rule hands matching leads to the people you pick, taking turns in the order shown.
      The first rule that fits wins, top to bottom; a rule with no conditions catches everything left. The person who gets a lead is told in their bell.
    </p>
    <div v-if="error" class="la-error" role="alert">{{ error }}</div>

    <table class="la-table">
      <thead><tr><th>Order</th><th>Rule</th><th>When the lead is</th><th>Goes to (in turn)</th><th>On</th><th></th></tr></thead>
      <tbody>
        <tr v-for="(r, i) in rules" :key="r.id" :class="{ off: !r.is_active }">
          <td class="mv">
            <button :disabled="busy || i === 0" :aria-label="'Move ' + r.name + ' up'" @click="move(i, -1)">▲</button>
            <button :disabled="busy || i === rules.length - 1" :aria-label="'Move ' + r.name + ' down'" @click="move(i, 1)">▼</button>
          </td>
          <td><strong>{{ r.name }}</strong></td>
          <td>{{ when(r) }}</td>
          <td>{{ people(r) }}</td>
          <td><input type="checkbox" :checked="r.is_active" :disabled="busy" :aria-label="'Switch ' + r.name + ' on or off'" @change="toggle(r, $event.target.checked)" /></td>
          <td class="r">
            <button class="link" :disabled="busy" @click="edit(r)">Edit</button>
            <button class="x" :disabled="busy" :aria-label="'Delete ' + r.name" @click="remove(r)">✕</button>
          </td>
        </tr>
        <tr v-if="!rules.length"><td colspan="6" class="la-empty">No rules: every new lead stays unassigned.</td></tr>
      </tbody>
    </table>

    <div v-if="!form.open"><button class="btn btn-primary" :disabled="busy" @click="openNew">Add a rule</button></div>

    <form v-else class="la-form" @submit.prevent="save">
      <h4>{{ form.id ? 'Edit rule' : 'New rule' }}</h4>
      <div class="la-grid">
        <label>Name<input v-model.trim="form.name" maxlength="100" placeholder="e.g. IndiaMART Rajkot" required /></label>
        <label>Source
          <select v-model="form.source"><option value="">Any source</option><option v-for="s in sources" :key="s" :value="s">{{ s }}</option></select>
        </label>
        <label>City<input v-model.trim="form.city" maxlength="120" placeholder="Any city" /></label>
        <label>Application
          <select v-model="form.application"><option value="">Any application</option><option v-for="a in applications" :key="a" :value="a">{{ a }}</option></select>
        </label>
      </div>
      <fieldset class="la-people">
        <legend>Who takes these leads (tick one or more; several take turns)</legend>
        <label v-for="u in users" :key="u.id" class="la-person"><input type="checkbox" :value="u.id" v-model="form.user_ids" /> {{ u.name }}</label>
        <span v-if="!users.length" class="la-empty">No active users found.</span>
      </fieldset>
      <p v-if="formError" class="la-error" role="alert">{{ formError }}</p>
      <div class="la-actions">
        <button class="btn btn-ghost" type="button" @click="form.open = false">Cancel</button>
        <button class="btn btn-primary" type="submit" :disabled="busy || !form.name || !form.user_ids.length">Save rule</button>
      </div>
    </form>
  </section>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../services/api.js'
import { LEAD_APPLICATIONS as applications } from '../services/leadService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const rules = ref([])
const users = ref([])
const sources = ref([])
const busy = ref(false)
const error = ref('')
const formError = ref('')
const form = reactive({ open: false, id: null, name: '', source: '', city: '', application: '', user_ids: [] })

const body = (res) => res?.data?.data ?? res?.data ?? []
const nameOf = (id) => users.value.find(u => u.id === id)?.name ?? `user ${id}`
const people = (r) => (r.user_ids || []).map(nameOf).join(' → ')
const when = (r) => [r.source && `source ${r.source}`, r.city && `city ${r.city}`, r.application && `for ${r.application}`].filter(Boolean).join(', ') || 'anything not matched above'

async function load() {
  error.value = ''
  try {
    const [r, u, s] = await Promise.all([api.get('/settings/lead-assignment-rules'), api.get('/users'), api.get('/settings/lead-lists')])
    rules.value = body(r)
    users.value = (body(u).data ?? body(u)).filter(x => x.is_active !== false)
    sources.value = (body(s).sources ?? []).filter(x => x.is_active).map(x => x.name)
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the rules.' }
}

async function run(fn, done) {
  busy.value = true; error.value = ''
  try { await fn(); if (done) toastSuccess(done); await load(); return true }
  catch (e) { const msg = e?.response?.data?.message ?? 'That did not work.'; toastError(msg); await load(); error.value = msg; return false }
  finally { busy.value = false }
}

function openNew() { Object.assign(form, { open: true, id: null, name: '', source: '', city: '', application: '', user_ids: [] }); formError.value = '' }
function edit(r) { Object.assign(form, { open: true, id: r.id, name: r.name, source: r.source || '', city: r.city || '', application: r.application || '', user_ids: [...(r.user_ids || [])] }); formError.value = '' }

async function save() {
  formError.value = ''
  // keep the people in the order the list shows them: that is the order they take turns in
  const order = users.value.map(u => u.id).filter(id => form.user_ids.includes(id))
  const payload = { name: form.name, source: form.source || null, city: form.city || null, application: form.application || null, user_ids: order }
  busy.value = true
  try {
    if (form.id) await api.put(`/settings/lead-assignment-rules/${form.id}`, payload)
    else await api.post('/settings/lead-assignment-rules', payload)
    toastSuccess('Rule saved.')
    form.open = false
    await load()
  } catch (e) { formError.value = e?.response?.data?.message ?? 'Could not save the rule.' }
  finally { busy.value = false }
}

const toggle = (r, on) => run(() => api.put(`/settings/lead-assignment-rules/${r.id}`, { is_active: on }))

async function move(i, step) {
  const list = [...rules.value]
  const j = i + step
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
  await run(async () => {
    for (const [idx, r] of list.entries()) {
      if (r.priority !== idx + 1 || rules.value[idx]?.id !== r.id) await api.put(`/settings/lead-assignment-rules/${r.id}`, { priority: idx + 1 })
    }
  })
}

async function remove(r) {
  const ok = await confirmDialog({ title: `Delete "${r.name}"?`, message: 'Leads already assigned stay with their people. New leads will use the other rules.', confirmLabel: 'Delete', cancelLabel: 'Cancel', danger: true })
  if (!ok) return
  return run(() => api.delete(`/settings/lead-assignment-rules/${r.id}`), 'Deleted.')
}

onMounted(load)
</script>

<style scoped>
.la-card { background: #fff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 18px; overflow-x: auto; }
.la-card h3 { margin: 0 0 6px; font-size: 15px; color: var(--primary); }
.la-card h4 { margin: 0 0 10px; font-size: 14px; }
.la-intro { color: #555; font-size: 13px; max-width: 80ch; margin: 0 0 12px; line-height: 1.5; }
.la-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 8px 12px; border-radius: 7px; font-size: 13px; margin: 0 0 10px; }
.la-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 12px; }
.la-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #666; padding: 5px 6px; border-bottom: 1px solid #ddd; }
.la-table td { padding: 6px; border-bottom: 1px solid #f2f2f2; vertical-align: middle; }
.la-table tr.off td { color: #999; }
.la-table .r { text-align: right; white-space: nowrap; }
.mv button { background: none; border: 1px solid #ddd; border-radius: 4px; width: 24px; height: 22px; font-size: 9px; cursor: pointer; margin-right: 2px; color: #555; }
.mv button:disabled { opacity: .35; cursor: default; }
.la-empty { color: #999; text-align: center; padding: 12px 0 !important; }
.la-form { border-top: 1px solid #eee; padding-top: 12px; }
.la-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 14px; }
.la-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #555; font-weight: 600; }
.la-grid input, .la-grid select { padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-weight: 400; }
.la-people { margin: 12px 0 0; border: 1px solid #eee; border-radius: 8px; padding: 8px 12px; display: flex; flex-wrap: wrap; gap: 6px 18px; }
.la-people legend { font-size: 12px; color: #555; font-weight: 600; padding: 0 4px; }
.la-person { font-size: 13px; display: flex; gap: 6px; align-items: center; cursor: pointer; }
.la-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
.link { background: none; border: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; margin-right: 6px; }
.x { background: none; border: none; color: #b0b0b0; cursor: pointer; font-size: 14px; padding: 2px 6px; border-radius: 4px; }
.x:hover:not(:disabled) { color: #c62828; background: #fdecea; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: #eceff1; color: #455a64; }
@media (max-width: 800px) { .la-grid { grid-template-columns: 1fr; } }
</style>
