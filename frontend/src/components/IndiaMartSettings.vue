<template>
  <section class="im-card" aria-label="IndiaMART">
    <h3>IndiaMART</h3>
    <p class="im-intro">
      Bring your IndiaMART enquiries into Leads. In your IndiaMART seller account open <strong>Lead Manager → CRM Integration</strong>, copy the CRM key, and save it here.
      Making a new key there cancels the old one, and a key stops working after 7 days without use.
    </p>
    <div v-if="error" class="im-error" role="alert">{{ error }}</div>
    <div v-if="state.health?.severity" class="im-health" :class="state.health.severity" role="alert" data-testid="im-health">
      <strong>{{ state.health.title }}</strong> {{ state.health.body }}
    </div>

    <div class="im-status">
      <span v-if="state.configured" class="im-set">Key saved <code>…{{ state.key_hint }}</code></span>
      <span v-else class="im-unset">No key saved</span>
    </div>

    <form class="im-form" @submit.prevent="save">
      <label for="im-key">{{ state.configured ? 'Replace the key' : 'CRM key' }}</label>
      <input id="im-key" v-model.trim="key" type="password" autocomplete="off" spellcheck="false" maxlength="200" :placeholder="state.configured ? 'Leave blank to keep the saved key' : 'Paste the key here'" />
      <button class="btn btn-primary" type="submit" :disabled="busy || !key">Save key</button>
      <button class="btn btn-ghost" type="button" :disabled="busy || !state.configured" @click="test">{{ testing ? 'Testing…' : 'Test connection' }}</button>
      <button v-if="state.configured" class="link" type="button" :disabled="busy" @click="remove">Remove key</button>
    </form>

    <div v-if="state.configured" class="im-auto">
      <label class="im-switch">
        <input type="checkbox" :checked="state.is_enabled" :disabled="busy" aria-label="Import IndiaMART enquiries automatically" @change="setEnabled($event.target.checked)" />
        <span><strong>Import automatically every 10 minutes</strong>
          <small v-if="state.is_enabled && state.last_success_at">Last import {{ when(state.last_success_at) }}.</small>
          <small v-else-if="state.is_enabled">Waiting for the first import.</small>
          <small v-else>Off. Nothing is imported until you switch this on.</small></span>
      </label>
      <p v-if="state.last_error && state.health?.state !== 'key_rejected'" class="im-trouble" role="alert">Last import failed {{ when(state.last_error_at) }}: {{ state.last_error }}</p>
    </div>

    <form v-if="state.configured" class="im-range" aria-label="Pull a date range" @submit.prevent="pullRange">
      <strong>Pull a date range</strong>
      <label>From <input v-model="range.from" type="date" required :max="today" /></label>
      <label>To <input v-model="range.to" type="date" required :max="today" /></label>
      <button class="btn btn-ghost" type="submit" :disabled="busy || !range.from || !range.to">{{ pulling ? 'Pulling…' : 'Pull now' }}</button>
      <small>Up to 7 days at a time, for enquiries from before you switched on, or ones that were missed.</small>
    </form>
    <div v-if="pullResult" class="im-result" :class="pullResult.ok ? 'ok' : 'bad'" role="status">{{ pullResult.ok ? '✓' : '✗' }} {{ pullResult.message }}</div>

    <div v-if="result" class="im-result" :class="result.ok ? 'ok' : 'bad'" role="status">{{ result.ok ? '✓' : '✗' }} {{ result.message }}</div>
    <p v-else-if="state.last_test_at" class="im-last">
      Last test, {{ when(state.last_test_at) }}: <span :class="state.last_test_ok ? 'okt' : 'badt'">{{ state.last_test_message }}</span>
    </p>
    <p class="im-note">A test looks at the last 6 hours and only counts the enquiries; nothing is imported. IndiaMART allows one request every 5 minutes, so a test or a pull can be done once in 5 minutes.
      Each enquiry becomes a lead (or a note on that buyer's open lead); the same enquiry is never added twice.</p>
  </section>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../services/api.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const state = reactive({ configured: false, key_hint: null, is_enabled: false, last_success_at: null, last_error_at: null, last_error: null, last_test_at: null, last_test_ok: null, last_test_message: null, health: null })
const key = ref('')
const busy = ref(false)
const testing = ref(false)
const error = ref('')
const result = ref(null)
const pulling = ref(false)
const pullResult = ref(null)
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' })   // IndiaMART works in Indian time
const range = reactive({ from: '', to: '' })

const when = (d) => new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
const apply = (res) => Object.assign(state, res?.data?.data ?? res?.data ?? {})

async function load() {
  error.value = ''
  try { apply(await api.get('/settings/lead-connectors/indiamart')) }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the IndiaMART settings.' }
}

async function save() {
  busy.value = true; error.value = ''; result.value = null
  try { apply(await api.put('/settings/lead-connectors/indiamart', { crm_key: key.value })); key.value = ''; toastSuccess('Key saved. You can test it now.') }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not save the key.' }
  finally { busy.value = false }
}

async function test() {
  busy.value = true; testing.value = true; error.value = ''; result.value = null
  try { result.value = (await api.post('/settings/lead-connectors/indiamart/test'))?.data?.data ?? null; await load() }
  catch (e) { error.value = e?.response?.data?.message ?? 'The test could not run.' }
  finally { busy.value = false; testing.value = false }
}

async function setEnabled(on) {
  busy.value = true; error.value = ''
  try { apply(await api.put('/settings/lead-connectors/indiamart', { is_enabled: on })); toastSuccess(on ? 'Automatic import switched on.' : 'Automatic import switched off.') }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not change that.'; await load() }
  finally { busy.value = false }
}

async function pullRange() {
  busy.value = true; pulling.value = true; error.value = ''; pullResult.value = null
  try { pullResult.value = (await api.post('/settings/lead-connectors/indiamart/pull', { from: range.from, to: range.to }))?.data?.data ?? null; await load() }
  catch (e) { pullResult.value = { ok: false, message: e?.response?.data?.message ?? 'The pull could not run.' } }
  finally { busy.value = false; pulling.value = false }
}

async function remove() {
  const ok = await confirmDialog({ title: 'Remove the IndiaMART key?', message: 'Nothing will be imported from IndiaMART until you save a key again.', confirmLabel: 'Remove', cancelLabel: 'Cancel', danger: true })
  if (!ok) return
  busy.value = true; error.value = ''; result.value = null
  try { apply(await api.put('/settings/lead-connectors/indiamart', { clear_key: true })); toastSuccess('Key removed.') }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not remove the key.') }
  finally { busy.value = false }
}

onMounted(load)
</script>

<style scoped>
.im-card { background: #fff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 18px; }
.im-card h3 { margin: 0 0 6px; font-size: 15px; color: var(--primary); }
.im-intro { color: #555; font-size: 13px; max-width: 80ch; margin: 0 0 12px; line-height: 1.5; }
.im-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 8px 12px; border-radius: 7px; font-size: 13px; margin: 0 0 10px; }
.im-status { margin-bottom: 10px; font-size: 13px; }
.im-set { color: #2e7d32; font-weight: 600; }
.im-unset { color: #888; }
.im-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.im-form label { font-size: 12px; font-weight: 600; color: #555; }
.im-form input { flex: 1; min-width: 260px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.im-result { margin-top: 12px; padding: 9px 12px; border-radius: 7px; font-size: 13px; }
.im-result.ok { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; }
.im-result.bad { background: #fff3e0; border: 1px solid #ffcc80; color: #bf360c; }
.im-auto { margin: 14px 0 4px; padding: 10px 12px; background: #f7f9fa; border-radius: 8px; }
.im-switch { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; cursor: pointer; }
.im-switch input { width: 17px; height: 17px; margin-top: 2px; }
.im-switch small { display: block; color: #777; font-size: 12px; margin-top: 2px; }
.im-health { margin: 10px 0; padding: 9px 12px; border-radius: 6px; font-size: 13px; line-height: 1.4; }
.im-health.danger { background: #fdecea; color: #8e1b0f; border: 1px solid #f5c2bc; }
.im-health.warning { background: #fff4e0; color: #8a5300; border: 1px solid #f3d9a4; }
.im-trouble { margin: 8px 0 0; font-size: 12.5px; color: #bf360c; }
.im-range { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 12px; font-size: 13px; }
.im-range label { display: flex; gap: 6px; align-items: center; color: #555; }
.im-range input { padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.im-range small { color: #888; font-size: 12px; }
.im-last { margin: 12px 0 0; font-size: 12.5px; color: #666; }
.okt { color: #2e7d32; }
.badt { color: #bf360c; }
.im-note { margin: 12px 0 0; font-size: 12px; color: #888; max-width: 80ch; }
.link { background: none; border: 0; color: #c62828; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-ghost { background: #eceff1; color: #455a64; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
code { font-size: 12px; }
</style>
