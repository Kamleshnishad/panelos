<template>
  <div class="ls">
    <p class="ls-intro">
      The reasons your floor picks when panels are rejected or material is wasted. Use the names your team already uses.
      Turn off a reason instead of deleting it, so old records keep their meaning.
    </p>
    <div v-if="error" class="ls-error" role="alert">{{ error }}</div>

    <div v-for="cat in categories" :key="cat.key" class="wr-group">
      <h4>{{ cat.label }}</h4>
      <ol class="ls-list" :aria-label="cat.label">
        <li v-for="r in byCategory(cat.key)" :key="r.id" :class="{ off: !r.is_active }">
          <span class="wr-code">{{ r.code }}</span>
          <div class="ls-main">
            <strong>{{ r.label }}</strong>
            <small v-if="r.root_cause_hint">Check: {{ r.root_cause_hint }}</small>
          </div>
          <label class="wr-toggle"><input type="checkbox" :checked="r.is_active" :disabled="busy" @change="toggle(r)" /> Active</label>
          <button class="link" type="button" @click="edit(r)">Edit</button>
        </li>
        <li v-if="!byCategory(cat.key).length" class="wr-empty">None yet.</li>
      </ol>
    </div>
    <button class="btn btn-ghost" type="button" @click="edit()">+ New reason</button>

    <div v-if="form.open" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Reason" @click.self="form.open = false">
      <div class="modal-box">
        <div class="modal-header"><h3>{{ form.id ? 'Edit reason' : 'New reason' }}</h3><button class="btn-close" aria-label="Close" @click="form.open = false">✕</button></div>
        <p v-if="form.error" class="ls-error" role="alert">{{ form.error }}</p>
        <label for="wr-code">Short code (letters, numbers, _)</label>
        <input id="wr-code" v-model.trim="form.code" maxlength="40" />
        <label for="wr-label">Name</label>
        <input id="wr-label" v-model.trim="form.label" maxlength="120" />
        <label for="wr-cat">Type</label>
        <select id="wr-cat" v-model="form.category">
          <option v-for="c in categories" :key="c.key" :value="c.key">{{ c.label }}</option>
        </select>
        <label for="wr-hint">What to check (optional)</label>
        <input id="wr-hint" v-model.trim="form.root_cause_hint" maxlength="255" placeholder="e.g. Mix ratio, sheet temperature" />
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="form.open = false">Cancel</button>
          <button class="btn btn-primary" :disabled="busy || !form.code || !form.label" @click="save">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import batchService from '../services/batchService.js'

const categories = [
  { key: 'defect',        label: 'Panel defects' },
  { key: 'process_scrap', label: 'Process scrap (start-up, changeover, trim)' },
  { key: 'handling',      label: 'Handling & storage damage' },
  { key: 'transit',       label: 'Transit damage' },
  { key: 'supplier',      label: 'Supplier material defects' },
  { key: 'stock_adjust',  label: 'Stock adjustments' },
]

const reasons = ref([])
const error   = ref(null)
const busy    = ref(false)
const form    = reactive({ open: false, id: null, code: '', label: '', category: 'defect', root_cause_hint: '', error: null })

const byCategory = key => reasons.value.filter(r => r.category === key)
const msg = e => e?.response?.data?.message ?? 'Something went wrong.'

async function load() {
  try {
    const res = await batchService.reasonCodes()
    reasons.value = res?.data ?? res ?? []
  } catch (e) { error.value = msg(e) }
}

function edit(r = null) {
  Object.assign(form, { open: true, error: null, id: r?.id ?? null, code: r?.code ?? '', label: r?.label ?? '',
    category: r?.category ?? 'defect', root_cause_hint: r?.root_cause_hint ?? '' })
}

async function save() {
  busy.value = true
  form.error = null
  const data = { code: form.code, label: form.label, category: form.category, root_cause_hint: form.root_cause_hint || null }
  try {
    if (form.id) await batchService.updateReasonCode(form.id, data)
    else await batchService.createReasonCode(data)
    form.open = false
    await load()
  } catch (e) {
    const errs = e?.response?.data?.errors
    form.error = errs ? Object.values(errs).flat().join(' ') : msg(e)
  } finally { busy.value = false }
}

async function toggle(r) {
  busy.value = true
  error.value = null
  try {
    await batchService.updateReasonCode(r.id, { is_active: !r.is_active })
    r.is_active = !r.is_active
  } catch (e) { error.value = msg(e) } finally { busy.value = false }
}

onMounted(load)
</script>

<style scoped>
.ls { max-width: 760px; }
.ls-intro { color: #555; font-size: 13.5px; line-height: 1.6; margin: 0 0 14px; }
.ls-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 8px 12px; border-radius: 7px; font-size: 13px; margin-bottom: 12px; }
.wr-group h4 { margin: 16px 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #777; }
.ls-list { list-style: none; margin: 0 0 6px; padding: 0; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.ls-list li { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #f0f0f0; }
.ls-list li:last-child { border-bottom: 0; }
.ls-list li.off { opacity: .5; }
.wr-empty { color: #999; font-size: 12.5px; font-style: italic; }
.wr-code { font-family: monospace; font-size: 11px; font-weight: 700; background: var(--primary-tint); color: var(--primary); border-radius: 5px; padding: 2px 6px; flex: none; }
.ls-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ls-main strong { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ls-main small { color: #888; font-size: 12px; }
.wr-toggle { font-size: 12px; color: #555; display: flex; align-items: center; gap: 4px; cursor: pointer; }
.link { background: none; border: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 440px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.modal-header h3 { margin: 0; font-size: 16px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-box label { display: block; font-size: 12px; font-weight: 600; color: #666; margin: 10px 0 4px; }
.modal-box input, .modal-box select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
