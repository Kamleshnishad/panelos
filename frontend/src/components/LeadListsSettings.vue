<template>
  <div class="ll-wrap">
    <p class="ll-intro">
      The lists your team picks from when working leads. Add a channel the day you start using it; switch one off when you stop (it stays on the leads that
      already have it). Renaming changes it on every lead that uses it.
    </p>
    <div v-if="error" class="ll-error" role="alert">{{ error }}</div>
    <div v-if="loading" class="ll-loading">Loading…</div>

    <div v-else class="ll-grid">
      <section v-for="list in lists" :key="list.key" class="ll-card" :aria-label="list.title">
        <h3>{{ list.title }}</h3>
        <p class="ll-hint">{{ list.hint }}</p>
        <table class="ll-table">
          <thead><tr><th>Name</th><th class="r">Leads</th><th>On</th><th></th></tr></thead>
          <tbody>
            <tr v-for="row in data[list.key]" :key="row.id" :class="{ off: !row.is_active }">
              <td>
                <input v-if="!row.is_system" :value="drafts[list.key + row.id] ?? row.name" :aria-label="'Name of ' + row.name" maxlength="100"
                       @input="drafts[list.key + row.id] = $event.target.value"
                       @blur="rename(list, row)" @keyup.enter="$event.target.blur()" />
                <span v-else class="sys" title="Built in: the fallback">{{ row.name }}</span>
              </td>
              <td class="r">{{ row.used }}</td>
              <td>
                <label class="sw" :title="row.is_system ? 'The fallback cannot be switched off' : 'Offer this when adding or editing a lead'">
                  <input type="checkbox" :checked="row.is_active" :disabled="row.is_system || busy" :aria-label="'Switch ' + row.name + ' on or off'" @change="toggle(list, row, $event.target.checked)" />
                </label>
              </td>
              <td class="r"><button v-if="!row.is_system" class="x" :disabled="busy" :aria-label="'Delete ' + row.name" @click="remove(list, row)">✕</button></td>
            </tr>
          </tbody>
        </table>
        <form class="ll-add" @submit.prevent="add(list)">
          <input v-model.trim="newName[list.key]" :placeholder="list.placeholder" maxlength="100" :aria-label="'New ' + list.singular" />
          <button class="btn btn-primary" type="submit" :disabled="busy || !newName[list.key]">Add</button>
        </form>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../services/api.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const lists = [
  { key: 'sources', path: 'lead-sources', title: 'Lead sources', singular: 'source', placeholder: 'e.g. Trade fair 2027', hint: 'Where enquiries come from. Used on every lead and in the source reports.' },
  { key: 'lost_reasons', path: 'lead-lost-reasons', title: 'Lost reasons', singular: 'lost reason', placeholder: 'e.g. Delivery too slow', hint: 'Why a lead was lost. Keep it short and few: the report groups on these.' },
]

const data = reactive({ sources: [], lost_reasons: [] })
const drafts = reactive({})
const newName = reactive({ sources: '', lost_reasons: '' })
const loading = ref(true)
const busy = ref(false)
const error = ref('')

function apply(res) {
  const d = res?.data?.data ?? res?.data ?? {}
  data.sources = d.sources ?? []
  data.lost_reasons = d.lost_reasons ?? []
  for (const k of Object.keys(drafts)) delete drafts[k]
}

async function load() {
  loading.value = true; error.value = ''
  try { apply(await api.get('/settings/lead-lists')) }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the lists.' }
  finally { loading.value = false }
}

async function run(fn, done) {
  busy.value = true; error.value = ''
  try { apply(await fn()); if (done) toastSuccess(done) }
  catch (e) {
    const msg = e?.response?.data?.message ?? 'That did not work.'
    toastError(msg)
    await load()                                   // put the screen back to what the server holds
    error.value = msg                              // load() clears the banner, so show the reason after it
  } finally { busy.value = false }
}

const add = (list) => run(() => api.post(`/settings/${list.path}`, { name: newName[list.key] }), 'Added.').then(() => { newName[list.key] = '' })
const toggle = (list, row, on) => run(() => api.put(`/settings/${list.path}/${row.id}`, { is_active: on }))

function rename(list, row) {
  const key = list.key + row.id
  const name = (drafts[key] ?? row.name).trim()
  delete drafts[key]
  if (name === row.name) return
  return run(() => api.put(`/settings/${list.path}/${row.id}`, { name }), 'Renamed on every lead that uses it.')
}

async function remove(list, row) {
  const ok = await confirmDialog({ title: `Delete "${row.name}"?`, message: row.used ? `${row.used} lead(s) use it, so it cannot be deleted. Switch it off instead.` : 'It is not used by any lead.', confirmLabel: 'Delete', cancelLabel: 'Cancel' })
  if (!ok) return
  return run(() => api.delete(`/settings/${list.path}/${row.id}`), 'Deleted.')
}

onMounted(load)
</script>

<style scoped>
.ll-wrap { max-width: 1100px; }
.ll-intro { color: #555; font-size: 13.5px; max-width: 70ch; margin: 0 0 14px; line-height: 1.5; }
.ll-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 12px; }
.ll-loading { color: #888; padding: 20px 0; }
.ll-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; align-items: start; }
.ll-card { background: #fff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); min-width: 0; }
.ll-card h3 { margin: 0 0 4px; font-size: 15px; color: var(--primary); }
.ll-hint { margin: 0 0 10px; font-size: 12.5px; color: #777; }
.ll-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ll-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #666; padding: 5px 6px; border-bottom: 1px solid #ddd; }
.ll-table td { padding: 5px 6px; border-bottom: 1px solid #f2f2f2; vertical-align: middle; }
.ll-table .r { text-align: right; }
.ll-table tr.off td { color: #999; }
.ll-table input[type=text], .ll-table td input:not([type=checkbox]) { width: 100%; padding: 6px 8px; border: 1px solid transparent; border-radius: 6px; font-size: 13px; background: transparent; box-sizing: border-box; }
.ll-table td input:not([type=checkbox]):hover { border-color: #ddd; }
.ll-table td input:not([type=checkbox]):focus { outline: none; border-color: var(--primary); background: #fff; }
.sys { padding: 6px 8px; display: inline-block; font-weight: 600; }
.sw input { width: 16px; height: 16px; cursor: pointer; }
.x { background: none; border: none; color: #b0b0b0; cursor: pointer; font-size: 14px; padding: 2px 6px; border-radius: 4px; }
.x:hover:not(:disabled) { color: #c62828; background: #fdecea; }
.ll-add { display: flex; gap: 8px; margin-top: 12px; }
.ll-add input { flex: 1; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; min-width: 0; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width: 800px) { .ll-grid { grid-template-columns: 1fr; } }
</style>
