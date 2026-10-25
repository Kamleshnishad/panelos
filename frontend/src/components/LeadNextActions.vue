<template>
  <section class="na" aria-label="Next actions">
    <div class="na-head">
      <label>Next actions</label>
      <button v-if="!closed && !adding" class="link" type="button" @click="startAdd">+ Plan an action</button>
    </div>
    <p v-if="error" class="na-error" role="alert">{{ error }}</p>

    <ul v-if="open.length" class="na-list">
      <li v-for="t in open" :key="t.id" :class="{ late: isLate(t) }">
        <span class="na-type">{{ t.type_label }}</span>
        <span class="na-when" :class="{ late: isLate(t) }">{{ whenText(t) }}</span>
        <span class="na-what">{{ t.title || '' }}<small v-if="t.assignee"> · {{ t.assignee }}</small></span>
        <span class="na-btns">
          <button class="btn btn-primary sm" type="button" @click="finishing = t">Done</button>
          <button class="link" type="button" :aria-label="'Change the ' + t.type_label" @click="startEdit(t)">Change</button>
          <button class="x" type="button" :aria-label="'Remove the ' + t.type_label" @click="remove(t)">✕</button>
        </span>
      </li>
    </ul>
    <p v-else-if="!adding" class="na-empty">{{ closed ? 'This lead is closed.' : 'Nothing planned. Plan the next call, visit or quotation so the lead does not go quiet.' }}</p>

    <form v-if="adding" class="na-form" @submit.prevent="save">
      <select v-model="form.type" aria-label="Kind of action"><option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option></select>
      <input v-model="form.due_date" type="date" required :min="editing ? undefined : today" aria-label="Day" />
      <input v-model="form.due_time" type="time" aria-label="Time (optional)" />
      <select v-model="form.assigned_to_user_id" aria-label="Who does it"><option value="">The lead's owner</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
      <input v-model="form.title" class="wide" maxlength="150" placeholder="What to do (optional), e.g. Send the revised rate" aria-label="What to do" />
      <span class="na-btns"><button class="btn btn-primary sm" type="submit" :disabled="busy || !form.due_date">{{ editing ? 'Save' : 'Plan it' }}</button><button class="link" type="button" @click="adding = false">Cancel</button></span>
    </form>

    <details v-if="done.length" class="na-done">
      <summary>Done recently ({{ done.length }})</summary>
      <ul><li v-for="t in done" :key="t.id"><strong>{{ t.type_label }}</strong> {{ t.title }}<span v-if="t.done_note"> — {{ t.done_note }}</span></li></ul>
    </details>

    <lead-task-done-dialog v-if="finishing" :task="finishing" :types="types" @close="finishing = null" @done="onDone" />
  </section>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'
import LeadTaskDoneDialog from './LeadTaskDoneDialog.vue'

const props = defineProps({ leadId: { type: Number, required: true }, closed: { type: Boolean, default: false }, users: { type: Array, default: () => [] } })
const emit = defineEmits(['changed'])

const open = ref([])
const done = ref([])
const types = ref({})
const adding = ref(false)
const editing = ref(null)
const busy = ref(false)
const error = ref('')
const finishing = ref(null)
const form = reactive({ type: 'call', due_date: '', due_time: '', assigned_to_user_id: '', title: '' })
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' })       // Indian time

const isLate = (t) => t.due_date < today
const whenText = (t) => new Date(t.due_date + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + (t.due_time ? ', ' + t.due_time : '') + (isLate(t) ? ' (overdue)' : t.due_date === today ? ' (today)' : '')

async function load() {
  error.value = ''
  try {
    const d = (await leadService.tasks(props.leadId))?.data ?? {}
    open.value = d.open ?? []; done.value = d.done ?? []; types.value = d.types ?? {}
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the next actions.' }
}

function startAdd() { editing.value = null; Object.assign(form, { type: 'call', due_date: '', due_time: '', assigned_to_user_id: '', title: '' }); adding.value = true }
function startEdit(t) { editing.value = t; Object.assign(form, { type: t.type, due_date: t.due_date, due_time: t.due_time || '', assigned_to_user_id: t.assigned_to_user_id || '', title: t.title || '' }); adding.value = true }

async function save() {
  busy.value = true; error.value = ''
  const body = { type: form.type, due_date: form.due_date, due_time: form.due_time || null, title: form.title || null, assigned_to_user_id: form.assigned_to_user_id || null }
  try {
    if (editing.value) await leadService.updateTask(editing.value.id, body)
    else await leadService.addTask(props.leadId, body)
    toastSuccess(editing.value ? 'Action changed.' : 'Action planned.')
    adding.value = false
    await load(); emit('changed')
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not save.' }
  finally { busy.value = false }
}

async function remove(t) {
  const ok = await confirmDialog({ title: `Remove this ${t.type_label.toLowerCase()}?`, message: 'It is taken off the lead without being marked done.', confirmLabel: 'Remove', cancelLabel: 'Keep', danger: true })
  if (!ok) return
  try { await leadService.deleteTask(t.id); await load(); emit('changed') }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not remove it.') }
}

async function onDone() { finishing.value = null; toastSuccess('Done.'); await load(); emit('changed') }

watch(() => props.leadId, load)
onMounted(load)
</script>

<style scoped>
.na { margin: 14px 0; }
.na-head { display: flex; align-items: center; justify-content: space-between; }
.na-head label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: .4px; }
.na-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; margin: 6px 0; }
.na-list { list-style: none; margin: 6px 0 0; padding: 0; }
.na-list li { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.na-type { font-weight: 700; color: var(--primary); min-width: 88px; }
.na-when { color: #555; min-width: 120px; }
.na-when.late { color: #c62828; font-weight: 600; }
.na-what { flex: 1; color: #333; }
.na-what small { color: #888; }
.na-btns { display: inline-flex; gap: 8px; align-items: center; }
.na-empty { font-size: 12.5px; color: #888; margin: 6px 0 0; }
.na-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; background: #f7f9fb; border-radius: 8px; padding: 10px; margin-top: 8px; }
.na-form select, .na-form input { padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.na-form .wide { flex: 1 1 100%; }
.na-done { margin-top: 8px; font-size: 12.5px; color: #666; }
.na-done ul { margin: 6px 0 0; padding-left: 18px; }
.link { background: none; border: 0; color: var(--primary); font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0; }
.x { background: none; border: 0; color: #b0b0b0; cursor: pointer; font-size: 14px; }
.x:hover { color: #c62828; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 4px 12px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
</style>
