<template>
  <section class="cb" aria-label="Contact this lead">
    <div class="cb-btns">
      <a v-if="lead.phone" class="btn btn-ghost" :href="'tel:' + lead.phone" @click="startCall">📞 Call</a>
      <button class="btn btn-ghost" type="button" :disabled="!lead.phone" :title="lead.phone ? '' : 'Add a phone number first'" @click="open('whatsapp')">💬 WhatsApp</button>
      <button class="btn btn-ghost" type="button" :disabled="!lead.email" :title="lead.email ? '' : 'Add an e-mail address first'" @click="open('email')">✉ E-mail</button>
    </div>

    <!-- After tapping Call: how did it go? Goes on the timeline as a call. -->
    <form v-if="call.open" class="cb-call" @submit.prevent="logCall">
      <strong>How did the call go?</strong>
      <div class="cb-outcomes" role="radiogroup" aria-label="Call outcome">
        <label v-for="o in OUTCOMES" :key="o" :class="['cb-chip', { on: call.outcome === o }]"><input v-model="call.outcome" type="radio" name="cb-outcome" :value="o" /> {{ o }}</label>
      </div>
      <input v-model="call.note" maxlength="500" placeholder="A note (optional), e.g. wants a quote by Friday" aria-label="Call note" />
      <span class="cb-btns"><button class="btn btn-primary sm" type="submit" :disabled="busy || !call.outcome">Save on the timeline</button><button class="link" type="button" @click="call.open = false">Not now</button></span>
    </form>

    <div v-if="panel" class="modal-overlay" role="dialog" aria-modal="true" :aria-label="panel === 'whatsapp' ? 'WhatsApp message' : 'E-mail'" @click.self="panel = ''">
      <div class="modal-box">
        <div class="modal-header"><h3>{{ panel === 'whatsapp' ? 'WhatsApp ' + lead.contact_name : 'E-mail ' + lead.contact_name }}</h3><button class="btn-close" aria-label="Close" @click="panel = ''">✕</button></div>
        <p v-if="error" class="cb-error" role="alert">{{ error }}</p>
        <p v-if="panel === 'email' && !emailReady" class="cb-warn">E-mail is not set up for your company yet. Add your mailbox in Customer Messaging, then come back.</p>

        <label class="cb-label" for="cb-tpl">Start from a ready-made message</label>
        <select id="cb-tpl" v-model="tplId" @change="useTemplate"><option value="">Write my own</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option></select>

        <template v-if="panel === 'email'">
          <label class="cb-label" for="cb-sub">To {{ lead.email }}. Subject</label>
          <input id="cb-sub" v-model="subject" maxlength="150" />
        </template>
        <label class="cb-label" for="cb-body">Message</label>
        <textarea id="cb-body" v-model="body" :rows="panel === 'email' ? 9 : 5" :maxlength="panel === 'email' ? 5000 : 1500"></textarea>
        <p class="cb-note">{{ panel === 'whatsapp' ? 'This opens WhatsApp with the message ready; you press send there. The timeline records that the chat was opened.' : 'It is sent from your company mailbox and replies come to ' + (me?.email || 'you') + '. The timeline records it.' }}</p>

        <div class="modal-actions">
          <button class="btn btn-ghost" :disabled="busy" @click="panel = ''">Cancel</button>
          <button v-if="panel === 'whatsapp'" class="btn btn-primary" :disabled="busy || !body.trim()" @click="sendWhatsapp">{{ busy ? 'Opening…' : 'Open WhatsApp' }}</button>
          <button v-else class="btn btn-primary" :disabled="busy || !emailReady || !subject.trim() || !body.trim()" @click="sendEmail">{{ busy ? 'Sending…' : 'Send e-mail' }}</button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, reactive } from 'vue'
import leadService from '../services/leadService.js'
import { toastSuccess, toastError } from '../services/ui.js'

const props = defineProps({ lead: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const OUTCOMES = ['Spoke to them', 'No answer', 'Busy, call later', 'Wrong number']
const me = (() => { try { return JSON.parse(localStorage.getItem('user') || 'null') } catch { return null } })()
const call = reactive({ open: false, outcome: '', note: '' })
const panel = ref('')
const templates = ref([])
const tplId = ref('')
const subject = ref('')
const body = ref('')
const emailReady = ref(true)
const busy = ref(false)
const error = ref('')

function startCall() { Object.assign(call, { open: true, outcome: '', note: '' }) }   // the tel: link does the dialling

async function logCall() {
  busy.value = true
  try {
    await leadService.addActivity(props.lead.id, { type: 'call', description: `Call: ${call.outcome}${call.note ? '. ' + call.note : ''}` })
    call.open = false
    toastSuccess('Call saved on the timeline.')
    emit('changed')
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not save the call.') }
  finally { busy.value = false }
}

async function open(kind) {
  panel.value = kind; error.value = ''; tplId.value = ''; subject.value = ''; body.value = ''; templates.value = []
  try {
    const d = (await leadService.messageTemplates(props.lead.id, kind))?.data ?? {}
    templates.value = d.templates ?? []
    emailReady.value = d.email_ready !== false
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the ready-made messages.' }
}

function useTemplate() {
  const t = templates.value.find(x => x.id === tplId.value)
  if (!t) return
  body.value = t.body
  if (panel.value === 'email') subject.value = t.subject || ''
}

async function sendWhatsapp() {
  busy.value = true; error.value = ''
  try {
    const url = (await leadService.whatsappLead(props.lead.id, body.value))?.data?.url
    if (url) window.open(url, '_blank', 'noopener')
    panel.value = ''
    emit('changed')
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not open WhatsApp.' }
  finally { busy.value = false }
}

async function sendEmail() {
  busy.value = true; error.value = ''
  try {
    await leadService.emailLead(props.lead.id, subject.value, body.value)
    toastSuccess('E-mail sent.')
    panel.value = ''
    emit('changed')
  } catch (e) { error.value = e?.response?.data?.message ?? 'The e-mail could not be sent.' }
  finally { busy.value = false }
}
</script>

<style scoped>
.cb { margin: 10px 0 4px; }
.cb-btns { display: inline-flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.cb-btns .btn { text-decoration: none; }
.cb-call { display: flex; flex-direction: column; gap: 8px; background: #f7f9fb; border-radius: 8px; padding: 10px 12px; margin-top: 8px; font-size: 13px; }
.cb-call input { padding: 7px 9px; border: 1px solid #ddd; border-radius: 6px; font: inherit; font-size: 13px; }
.cb-outcomes { display: flex; gap: 6px; flex-wrap: wrap; }
.cb-chip { border: 1px solid #d5d9e0; border-radius: 16px; padding: 4px 12px; font-size: 12.5px; font-weight: 600; color: #555; cursor: pointer; background: #fff; }
.cb-chip input { position: absolute; opacity: 0; pointer-events: none; }
.cb-chip.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.cb-chip:focus-within { outline: 2px solid var(--primary); outline-offset: 1px; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 560px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.modal-header h3 { margin: 0; font-size: 16px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; }
.cb-label { display: block; font-size: 12px; font-weight: 600; color: #666; margin: 10px 0 4px; }
.modal-box select, .modal-box input, .modal-box textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.cb-note { font-size: 12px; color: #777; margin: 8px 0 0; }
.cb-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 8px 12px; font-size: 13px; margin: 0 0 8px; }
.cb-warn { background: #fff4e0; color: #8a5300; border-radius: 8px; padding: 8px 12px; font-size: 13px; margin: 0 0 8px; }
.link { background: none; border: 0; color: var(--primary); font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 5px 12px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled, .btn-ghost:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
