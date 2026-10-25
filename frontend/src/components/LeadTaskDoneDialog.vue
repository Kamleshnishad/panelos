<template>
  <div class="modal-overlay" role="dialog" aria-modal="true" :aria-label="'Finish ' + task.type_label" @click.self="$emit('close')">
    <div class="modal-box">
      <div class="modal-header"><h3>Done: {{ task.type_label }}<template v-if="task.lead"> · {{ task.lead.contact_name }}</template></h3><button class="btn-close" aria-label="Close" @click="$emit('close')">✕</button></div>
      <p v-if="error" class="td-error" role="alert">{{ error }}</p>

      <label class="td-field" for="td-note">What happened? <small>(goes on the lead's timeline)</small></label>
      <textarea id="td-note" v-model="note" rows="2" maxlength="500" placeholder="e.g. Wants a quote by Friday"></textarea>

      <fieldset class="td-next">
        <legend>What is next?</legend>
        <div class="td-chips" role="radiogroup" aria-label="When is the next action">
          <label v-for="o in options" :key="o.key" :class="['td-chip', { on: pick === o.key }]">
            <input v-model="pick" type="radio" name="td-pick" :value="o.key" /> {{ o.label }}
          </label>
        </div>
        <template v-if="pick !== 'none'">
          <div class="td-row">
            <label>Action
              <select v-model="next.type" aria-label="Next action"><option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option></select>
            </label>
            <label v-if="pick === 'pick'">Day <input v-model="next.due_date" type="date" :min="today" aria-label="Day of the next action" /></label>
            <label>Time <small>(optional)</small> <input v-model="next.due_time" type="time" aria-label="Time of the next action" /></label>
          </div>
          <input v-model="next.title" class="td-title" maxlength="150" placeholder="What to do, e.g. Send the revised rate" aria-label="Describe the next action" />
        </template>
        <p v-else class="td-none">The lead will have no follow-up date until you plan another action.</p>
      </fieldset>

      <div class="modal-actions">
        <button class="btn btn-ghost" :disabled="busy" @click="$emit('close')">Cancel</button>
        <button class="btn btn-primary" :disabled="busy || (pick === 'pick' && !next.due_date)" @click="save">{{ busy ? 'Saving…' : (pick === 'none' ? 'Mark done' : 'Mark done and plan next') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import leadService from '../services/leadService.js'

const props = defineProps({ task: { type: Object, required: true }, types: { type: Object, default: () => ({}) } })
const emit = defineEmits(['close', 'done'])

const note = ref('')
const busy = ref(false)
const error = ref('')
const pick = ref('in3')
// Indian time, whatever the computer's clock says
const ist = (plusDays = 0) => { const d = new Date(new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' }) + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + plusDays); return d.toISOString().slice(0, 10) }
const today = ist()
const options = [
  { key: 'tomorrow', label: 'Tomorrow', days: 1 }, { key: 'in3', label: 'In 3 days', days: 3 }, { key: 'week', label: 'Next week', days: 7 },
  { key: 'pick', label: 'Pick a day' }, { key: 'none', label: 'Nothing more for now' },
]
// After sending the quotation the natural next step is to chase it; otherwise repeat the same kind of action.
const next = reactive({ type: props.task.type === 'quote' ? 'call' : props.task.type, due_date: '', due_time: '', title: '' })

async function save() {
  busy.value = true; error.value = ''
  const o = options.find(x => x.key === pick.value)
  const body = { note: note.value || null }
  if (pick.value !== 'none') {
    body.next = { type: next.type, due_date: pick.value === 'pick' ? next.due_date : ist(o.days), due_time: next.due_time || null, title: next.title || null }
  }
  try {
    const res = await leadService.completeTask(props.task.id, body)
    emit('done', res?.data ?? res)
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not save.' }
  finally { busy.value = false }
}
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 520px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.modal-header h3 { margin: 0; font-size: 16px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.td-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 8px 12px; font-size: 13px; margin: 0 0 10px; }
.td-field { display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 4px; }
.td-field small, .td-row small { font-weight: 400; color: #888; }
textarea, .td-title, .td-row select, .td-row input { width: 100%; box-sizing: border-box; padding: 7px 9px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.td-next { border: 1px solid #eee; border-radius: 10px; margin: 14px 0 0; padding: 10px 14px 14px; }
.td-next legend { font-size: 13px; font-weight: 700; color: #444; padding: 0 6px; }
.td-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.td-chip { border: 1px solid #d5d9e0; border-radius: 16px; padding: 5px 12px; font-size: 12.5px; font-weight: 600; color: #555; cursor: pointer; }
.td-chip input { position: absolute; opacity: 0; pointer-events: none; }
.td-chip.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.td-chip:focus-within { outline: 2px solid var(--primary); outline-offset: 1px; }
.td-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.td-row label { flex: 1; min-width: 120px; font-size: 12px; font-weight: 600; color: #666; }
.td-none { margin: 4px 0 0; font-size: 12.5px; color: #8a5300; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
