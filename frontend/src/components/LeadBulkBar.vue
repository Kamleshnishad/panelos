<template>
  <div class="bb" role="region" aria-label="Actions for the selected leads">
    <div class="bb-top">
      <strong>{{ ids.length }} selected</strong>
      <div class="bb-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" type="button" :aria-selected="mode === t.key" :class="{ on: mode === t.key }" @click="mode = mode === t.key ? '' : t.key; result = null; error = ''">{{ t.label }}</button>
      </div>
      <button class="link" type="button" @click="$emit('clear')">Clear selection</button>
    </div>
    <p v-if="error" class="bb-error" role="alert">{{ error }}</p>

    <form v-if="mode === 'assign'" class="bb-form" @submit.prevent="run">
      <select v-model="user" aria-label="Assign to"><option value="">Choose a person…</option><option value="none">Nobody (unassign)</option><option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option></select>
      <button class="btn btn-primary sm" type="submit" :disabled="busy || !user">{{ busy ? 'Working…' : 'Assign' }}</button>
    </form>

    <form v-else-if="mode === 'status'" class="bb-form" @submit.prevent="run">
      <select v-model="status" aria-label="Move to"><option value="">Move to…</option><option v-for="s in STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option></select>
      <label v-if="status === 'on_hold'">Comes back on <input v-model="holdUntil" type="date" :min="today" aria-label="Date they come back" /></label>
      <select v-if="status === 'lost'" v-model="lostReason" aria-label="Why they were lost"><option value="">Why lost…</option><option v-for="r in lostReasons" :key="r.id" :value="r.id">{{ r.name }}</option></select>
      <input v-if="status === 'lost' || status === 'invalid'" v-model="reason" maxlength="500" placeholder="Note (optional)" aria-label="Note" />
      <button class="btn btn-primary sm" type="submit" :disabled="busy || !status || (status === 'on_hold' && !holdUntil) || (status === 'lost' && !lostReason)">{{ busy ? 'Working…' : 'Move' }}</button>
    </form>

    <form v-else-if="mode === 'plan'" class="bb-form" @submit.prevent="run">
      <select v-model="plan.type" aria-label="Kind of action"><option v-for="(label, key) in taskTypes" :key="key" :value="key">{{ label }}</option></select>
      <input v-model="plan.due_date" type="date" :min="today" required aria-label="Day" />
      <input v-model="plan.due_time" type="time" aria-label="Time (optional)" />
      <select v-model="plan.person" aria-label="Who does it"><option value="">Each lead's owner</option><option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option></select>
      <input v-model="plan.title" maxlength="150" placeholder="What to do (optional)" aria-label="What to do" />
      <button class="btn btn-primary sm" type="submit" :disabled="busy || !plan.due_date">{{ busy ? 'Working…' : 'Plan it' }}</button>
    </form>

    <div v-if="result" class="bb-result" role="status">
      <strong>{{ result.done }} of {{ result.requested }} done.</strong>
      <span v-if="result.skipped_count"> {{ result.skipped_count }} skipped:</span>
      <ul v-if="result.skipped.length"><li v-for="s in result.skipped" :key="s.id"><b>{{ s.lead_no || '#' + s.id }}</b> {{ s.reason }}</li></ul>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import leadService from '../services/leadService.js'

const props = defineProps({ ids: { type: Array, required: true }, users: { type: Array, default: () => [] } })
const emit = defineEmits(['clear', 'done'])

const tabs = [{ key: 'assign', label: 'Assign' }, { key: 'status', label: 'Move to' }, { key: 'plan', label: 'Plan an action' }]
const STATUSES = [{ key: 'contacted', label: 'Contacted' }, { key: 'qualified', label: 'Qualified' }, { key: 'on_hold', label: 'On hold' }, { key: 'lost', label: 'Lost' }, { key: 'invalid', label: 'Invalid (junk)' }]
const mode = ref('')
const user = ref('')
const status = ref('')
const holdUntil = ref('')
const lostReason = ref('')
const reason = ref('')
const plan = reactive({ type: 'call', due_date: '', due_time: '', person: '', title: '' })
const lostReasons = ref([])
const taskTypes = ref({ call: 'Call' })
const busy = ref(false)
const error = ref('')
const result = ref(null)
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' })     // Indian time

onMounted(async () => {
  try {
    const o = (await leadService.options())?.data ?? {}
    lostReasons.value = o.lost_reasons ?? []
    if (o.task_types) taskTypes.value = o.task_types
  } catch { /* the forms still work with what they have */ }
})

function body() {
  if (mode.value === 'assign') return { action: 'assign', user_id: user.value }
  if (mode.value === 'status') return { action: 'status', status: status.value, hold_until: holdUntil.value || undefined, lost_reason_id: lostReason.value || undefined, reason: reason.value || undefined, lost_note: status.value === 'lost' ? (reason.value || undefined) : undefined }
  return { action: 'plan', type: plan.type, due_date: plan.due_date, due_time: plan.due_time || undefined, title: plan.title || undefined, assigned_to_user_id: plan.person || undefined }
}

async function run() {
  busy.value = true; error.value = ''; result.value = null
  try {
    result.value = (await leadService.bulk(props.ids, body()))?.data ?? null
    emit('done', result.value)
  } catch (e) { error.value = e?.response?.data?.message ?? 'That did not work.' }
  finally { busy.value = false }
}
</script>

<style scoped>
.bb { background: #eef3ff; border: 1px solid #c9d7fb; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 13px; }
.bb-top { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.bb-tabs { display: inline-flex; gap: 6px; }
.bb-tabs button { border: 1px solid #b6c6f2; background: #fff; border-radius: 16px; padding: 4px 12px; font-size: 12.5px; font-weight: 600; color: #3b5bdb; cursor: pointer; }
.bb-tabs button.on { background: #3b5bdb; color: #fff; }
.bb-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
.bb-form select, .bb-form input { padding: 6px 8px; border: 1px solid #ccd; border-radius: 6px; font-size: 13px; background: #fff; }
.bb-form label { display: inline-flex; gap: 6px; align-items: center; color: #555; }
.bb-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 7px 10px; margin: 8px 0 0; }
.bb-result { margin-top: 10px; color: #333; }
.bb-result ul { margin: 6px 0 0; padding-left: 18px; color: #8a5300; max-height: 150px; overflow: auto; }
.link { background: none; border: 0; color: var(--primary); font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0; margin-left: auto; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 6px 14px; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
</style>
