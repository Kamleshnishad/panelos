<template>
  <div class="ms">
    <p class="ms-intro">
      Ready-made messages your team can start from when they contact a lead: a WhatsApp text or an e-mail. Words in braces are filled in for each lead:
      <code v-for="(label, key) in placeholders" :key="key" :title="label">{{ '{' + key + '}' }}</code>.
    </p>
    <div v-if="error" class="ms-error" role="alert">{{ error }}</div>

    <section v-for="ch in channels" :key="ch.key" class="ms-card" :aria-label="ch.label">
      <h3>{{ ch.label }}</h3>
      <ul>
        <li v-for="t in byChannel(ch.key)" :key="t.id" :class="{ off: !t.is_active }">
          <div class="ms-top">
            <strong>{{ t.name }}</strong>
            <label class="ms-on"><input type="checkbox" :checked="t.is_active" :disabled="busy" :aria-label="'Offer ' + t.name" @change="setActive(t, $event.target.checked)" /> offered</label>
            <button class="link" type="button" @click="edit(t)">Edit</button>
            <button class="x" type="button" :aria-label="'Delete ' + t.name" @click="remove(t)">✕</button>
          </div>
          <div v-if="t.subject" class="ms-sub">Subject: {{ t.subject }}</div>
          <pre>{{ t.body }}</pre>
        </li>
        <li v-if="!byChannel(ch.key).length" class="ms-empty">None.</li>
      </ul>
      <button class="btn btn-ghost" type="button" @click="add(ch.key)">+ New {{ ch.key === 'whatsapp' ? 'WhatsApp text' : 'e-mail' }}</button>
    </section>

    <div v-if="form.open" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Message template" @click.self="form.open = false">
      <div class="modal-box">
        <div class="modal-header"><h3>{{ form.id ? 'Edit' : 'New' }} {{ form.channel === 'whatsapp' ? 'WhatsApp text' : 'e-mail' }}</h3><button class="btn-close" aria-label="Close" @click="form.open = false">✕</button></div>
        <p v-if="form.error" class="ms-error" role="alert">{{ form.error }}</p>
        <label for="ms-name">Name</label><input id="ms-name" v-model.trim="form.name" maxlength="80" />
        <template v-if="form.channel === 'email'"><label for="ms-sub">Subject</label><input id="ms-sub" v-model="form.subject" maxlength="150" /></template>
        <label for="ms-body">Message</label><textarea id="ms-body" v-model="form.body" :rows="form.channel === 'email' ? 9 : 5" maxlength="5000"></textarea>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="form.open = false">Cancel</button>
          <button class="btn btn-primary" :disabled="busy || !form.name || !form.body.trim()" @click="save">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../services/api.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const channels = [{ key: 'whatsapp', label: 'WhatsApp texts' }, { key: 'email', label: 'E-mails' }]
const templates = ref([])
const placeholders = ref({})
const busy = ref(false)
const error = ref('')
const form = reactive({ open: false, id: null, channel: 'whatsapp', name: '', subject: '', body: '', error: '' })
const byChannel = (k) => templates.value.filter(t => t.channel === k)
const body = (res) => res?.data?.data ?? res?.data ?? {}

async function load() {
  error.value = ''
  try { const d = body(await api.get('/settings/lead-message-templates')); templates.value = d.templates ?? []; placeholders.value = d.placeholders ?? {} }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the messages.' }
}

function add(channel) { Object.assign(form, { open: true, id: null, channel, name: '', subject: '', body: '', error: '' }) }
function edit(t) { Object.assign(form, { open: true, id: t.id, channel: t.channel, name: t.name, subject: t.subject || '', body: t.body, error: '' }) }

async function save() {
  busy.value = true; form.error = ''
  const data = { channel: form.channel, name: form.name, subject: form.channel === 'email' ? form.subject : null, body: form.body }
  try {
    if (form.id) await api.put(`/settings/lead-message-templates/${form.id}`, data)
    else await api.post('/settings/lead-message-templates', data)
    toastSuccess('Saved.'); form.open = false; await load()
  } catch (e) { form.error = e?.response?.data?.message ?? 'Could not save.' }
  finally { busy.value = false }
}

async function setActive(t, on) {
  busy.value = true
  try { await api.put(`/settings/lead-message-templates/${t.id}`, { is_active: on }); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'That did not work.') }
  finally { busy.value = false }
}

async function remove(t) {
  const ok = await confirmDialog({ title: `Delete "${t.name}"?`, message: 'It is only a piece of text; messages already sent are not affected.', confirmLabel: 'Delete', cancelLabel: 'Keep', danger: true })
  if (!ok) return
  try { await api.delete(`/settings/lead-message-templates/${t.id}`); await load() }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not delete.') }
}

onMounted(load)
</script>

<style scoped>
.ms { max-width: 900px; }
.ms-intro { color: #555; font-size: 13.5px; line-height: 1.6; margin: 0 0 14px; }
.ms-intro code { background: #eef1f5; border-radius: 4px; padding: 1px 5px; margin-right: 4px; font-size: 12px; }
.ms-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 8px 12px; border-radius: 7px; font-size: 13px; margin-bottom: 12px; }
.ms-card { background: #fff; border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 16px; }
.ms-card h3 { margin: 0 0 8px; font-size: 15px; color: var(--primary); }
.ms-card ul { list-style: none; margin: 0 0 10px; padding: 0; }
.ms-card li { border-bottom: 1px solid #f0f0f0; padding: 9px 0; }
.ms-card li.off { opacity: .55; }
.ms-top { display: flex; gap: 12px; align-items: center; }
.ms-top strong { flex: 1; font-size: 13.5px; }
.ms-on { font-size: 12px; color: #666; }
.ms-sub { font-size: 12px; color: #666; margin-top: 3px; }
.ms-card pre { margin: 4px 0 0; white-space: pre-wrap; font: inherit; font-size: 12.5px; color: #555; }
.ms-empty { color: #999; font-size: 13px; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 560px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.modal-header h3 { margin: 0; font-size: 16px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-box label { display: block; font-size: 12px; font-weight: 600; color: #666; margin: 10px 0 4px; }
.modal-box input, .modal-box textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; }
.link { background: none; border: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; }
.x { background: none; border: none; color: #b0b0b0; cursor: pointer; font-size: 14px; }
.x:hover { color: #c62828; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
