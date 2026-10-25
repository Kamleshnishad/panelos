<template>
  <div class="ls">
    <p class="ls-intro">
      The steps a lead moves through before it is won or lost. Rename them, change the order, give each a chance of winning, and add your own (up to {{ max }}).
      <strong>New</strong> is where every lead starts and <strong>Quoted</strong> is where a quotation puts it, so those two can be renamed but not removed.
      On hold, Won, Lost and Invalid are the same for everyone.
    </p>
    <div v-if="error" class="ls-error" role="alert">{{ error }}</div>

    <ol class="ls-list" aria-label="Lead stages">
      <li v-for="(s, i) in stages" :key="s.id" :aria-label="s.name">
        <span class="ls-n">{{ i + 1 }}</span>
        <div class="ls-main">
          <strong>{{ s.name }}</strong>
          <small>{{ s.probability }}% chance of winning · {{ s.leads }} lead{{ s.leads === 1 ? '' : 's' }}<template v-if="s.built_in"> · built in</template></small>
        </div>
        <button class="ic" type="button" :disabled="busy || i === 0" :aria-label="'Move ' + s.name + ' up'" @click="shift(i, -1)">↑</button>
        <button class="ic" type="button" :disabled="busy || i === stages.length - 1" :aria-label="'Move ' + s.name + ' down'" @click="shift(i, 1)">↓</button>
        <button class="link" type="button" @click="edit(s)">Edit</button>
        <button class="x" type="button" :disabled="s.built_in" :title="s.built_in ? 'Built in: it can be renamed but not removed' : ''" :aria-label="'Remove ' + s.name" @click="remove(s)">✕</button>
      </li>
    </ol>
    <button class="btn btn-ghost" type="button" :disabled="stages.length >= max" @click="add">+ New stage</button>
    <span v-if="stages.length >= max" class="ls-full">That is the most a pipeline can have.</span>

    <div v-if="form.open" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Lead stage" @click.self="form.open = false">
      <div class="modal-box">
        <div class="modal-header"><h3>{{ form.id ? 'Edit stage' : 'New stage' }}</h3><button class="btn-close" aria-label="Close" @click="form.open = false">✕</button></div>
        <p v-if="form.error" class="ls-error" role="alert">{{ form.error }}</p>
        <label for="ls-name">Name</label><input id="ls-name" v-model.trim="form.name" maxlength="40" @keyup.enter="save" />
        <label for="ls-prob">Chance of winning a lead in this stage (0 to 100 %)</label>
        <input id="ls-prob" v-model.number="form.probability" type="number" min="0" max="100" step="1" @keyup.enter="save" />
        <p v-if="!form.id" class="ls-note">A new stage goes to the end. Use the arrows to place it.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="form.open = false">Cancel</button>
          <button class="btn btn-primary" :disabled="busy || !form.name || form.probability === '' || form.probability === null" @click="save">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../services/api.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const stages = ref([])
const max = ref(12)
const busy = ref(false)
const error = ref('')
const form = reactive({ open: false, id: null, name: '', probability: 25, error: '' })
const body = (res) => res?.data?.data ?? res?.data ?? {}
const take = (d) => { stages.value = d.stages ?? stages.value; if (d.max) max.value = d.max }

async function load() {
  error.value = ''
  try { take(body(await api.get('/settings/lead-stages'))) }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the stages.' }
}

function add() { Object.assign(form, { open: true, id: null, name: '', probability: 25, error: '' }) }
function edit(s) { Object.assign(form, { open: true, id: s.id, name: s.name, probability: s.probability, error: '' }) }

async function save() {
  if (busy.value || !form.name) return
  busy.value = true; form.error = ''
  const data = { name: form.name, probability: form.probability }
  try {
    take(body(form.id ? await api.put(`/settings/lead-stages/${form.id}`, data) : await api.post('/settings/lead-stages', data)))
    toastSuccess('Saved.'); form.open = false
  } catch (e) { form.error = e?.response?.data?.message ?? 'Could not save.' }
  finally { busy.value = false }
}

async function shift(i, by) {
  const ids = stages.value.map(s => s.id)
  ;[ids[i], ids[i + by]] = [ids[i + by], ids[i]]
  busy.value = true
  try { take(body(await api.put('/settings/lead-stages/order', { ids }))) }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not change the order.'); await load() }
  finally { busy.value = false }
}

async function remove(s) {
  const ok = await confirmDialog({ title: `Remove "${s.name}"?`, message: 'Only a stage with no leads in it can be removed.', confirmLabel: 'Remove', cancelLabel: 'Keep', danger: true })
  if (!ok) return
  try { take(body(await api.delete(`/settings/lead-stages/${s.id}`))); toastSuccess('Removed.') }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not remove it.') }
}

onMounted(load)
</script>

<style scoped>
.ls { max-width: 760px; }
.ls-intro { color: #555; font-size: 13.5px; line-height: 1.6; margin: 0 0 14px; }
.ls-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 8px 12px; border-radius: 7px; font-size: 13px; margin-bottom: 12px; }
.ls-list { list-style: none; margin: 0 0 14px; padding: 0; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.ls-list li { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #f0f0f0; }
.ls-list li:last-child { border-bottom: 0; }
.ls-n { width: 22px; height: 22px; border-radius: 50%; background: var(--primary-tint); color: var(--primary); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: none; }
.ls-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ls-main strong { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ls-main small { color: #888; font-size: 12px; }
.ic { background: #f3f5f8; border: 1px solid #e3e6ec; border-radius: 6px; width: 28px; height: 28px; cursor: pointer; font-size: 14px; }
.ic:disabled { opacity: .35; cursor: not-allowed; }
.link { background: none; border: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; }
.x { background: none; border: none; color: #b0b0b0; cursor: pointer; font-size: 14px; }
.x:hover:not(:disabled) { color: #c62828; }
.x:disabled { opacity: .35; cursor: not-allowed; }
.ls-full, .ls-note { color: #888; font-size: 12.5px; margin-left: 10px; }
.ls-note { margin: 10px 0 0; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 440px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.modal-header h3 { margin: 0; font-size: 16px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-box label { display: block; font-size: 12px; font-weight: 600; color: #666; margin: 10px 0 4px; }
.modal-box input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-ghost:disabled { opacity: .5; cursor: not-allowed; }
</style>
