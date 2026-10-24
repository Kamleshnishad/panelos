<template>
  <section class="im-card" aria-label="IndiaMART">
    <h3>IndiaMART</h3>
    <p class="im-intro">
      Bring your IndiaMART enquiries into Leads. In your IndiaMART seller account open <strong>Lead Manager → CRM Integration</strong>, copy the CRM key, and save it here.
      Making a new key there cancels the old one, and a key stops working after 7 days without use.
    </p>
    <div v-if="error" class="im-error" role="alert">{{ error }}</div>

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

    <div v-if="result" class="im-result" :class="result.ok ? 'ok' : 'bad'" role="status">{{ result.ok ? '✓' : '✗' }} {{ result.message }}</div>
    <p v-else-if="state.last_test_at" class="im-last">
      Last test, {{ when(state.last_test_at) }}: <span :class="state.last_test_ok ? 'okt' : 'badt'">{{ state.last_test_message }}</span>
    </p>
    <p class="im-note">A test looks at the last 6 hours and only counts the enquiries; nothing is imported. IndiaMART allows one request every 5 minutes, so you can test once in 5 minutes.
      Automatic import is the next step and is not switched on yet.</p>
  </section>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../services/api.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const state = reactive({ configured: false, key_hint: null, last_test_at: null, last_test_ok: null, last_test_message: null })
const key = ref('')
const busy = ref(false)
const testing = ref(false)
const error = ref('')
const result = ref(null)

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
