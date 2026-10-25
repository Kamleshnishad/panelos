<template>
  <div class="li-wrap">
    <p class="li-intro">
      Let a website form, a partner or another system send enquiries straight into Leads. Each connection gets its own secret address. Anyone who has the address can
      create leads, so keep it private; if it leaks, make a new one and the old one stops working at once.
    </p>
    <div v-if="error" class="li-error" role="alert">{{ error }}</div>

    <div v-if="fresh" class="li-fresh" role="status">
      <strong>Copy this address now. It is shown only once.</strong>
      <code class="li-url">{{ fullUrl(fresh) }}</code>
      <button class="btn btn-primary" @click="copy(fresh)">{{ copied ? 'Copied' : 'Copy address' }}</button>
      <p class="li-note">Send a POST with JSON such as <code>{"name": "Ravi", "mobile": "9876543210", "message": "Need 300 sqm PUF", "id": "form-123"}</code>. Keep a hidden field <code>_hp</code> in the form, empty: bots fill it in and are dropped.</p>
    </div>

    <section class="li-card" aria-label="Inbound addresses">
      <h3>Inbound addresses</h3>
      <table class="li-table">
        <thead><tr><th>Name</th><th>Leads are recorded as</th><th>Address</th><th>Last used</th><th>On</th><th></th></tr></thead>
        <tbody>
          <tr v-for="t in tokens" :key="t.id" :class="{ off: !t.is_active }">
            <td>{{ t.name }}</td>
            <td>{{ t.source }}</td>
            <td><code>…{{ t.token_hint }}</code></td>
            <td>{{ t.last_used_at ? when(t.last_used_at) : 'never' }}</td>
            <td><input type="checkbox" :checked="t.is_active" :disabled="busy" :aria-label="'Switch ' + t.name + ' on or off'" @change="setActive(t, $event.target.checked)" /></td>
            <td class="r">
              <button class="link" :disabled="busy" @click="regenerate(t)">New address</button>
              <button class="x" :disabled="busy" :aria-label="'Delete ' + t.name" @click="remove(t)">✕</button>
            </td>
          </tr>
          <tr v-if="!tokens.length"><td colspan="6" class="li-empty">No inbound address yet.</td></tr>
        </tbody>
      </table>
      <form class="li-add" @submit.prevent="add">
        <input v-model.trim="form.name" placeholder="Name, e.g. Website contact form" maxlength="100" aria-label="Name of the new address" />
        <select v-model="form.source" aria-label="Lead source for the new address"><option v-for="s in sources" :key="s" :value="s">{{ s }}</option></select>
        <button class="btn btn-primary" type="submit" :disabled="busy || !form.name || !form.source">Make address</button>
      </form>
    </section>

    <section class="li-card" aria-label="Example request">
      <h3>Example request</h3>
      <p class="li-note2">Paste this into a terminal, or give it to whoever builds the form. Replace the address with one of yours{{ fresh ? ' (the one you just made is filled in)' : '' }}.</p>
      <pre class="li-code" aria-label="curl example">{{ example }}</pre>
      <button class="btn btn-primary" @click="copyExample">{{ copiedExample ? 'Copied' : 'Copy example' }}</button>
    </section>

    <section class="li-card" aria-label="Website form">
      <h3>Website form</h3>
      <p class="li-note2">Put this on your website where the enquiry form should appear. It draws a short form (name, phone, e-mail, city, what they need), and every enquiry arrives here as a lead with the page and the campaign it came from.
        {{ fresh ? 'The address you just made is filled in.' : 'Make an address above (or a new one) and it is filled in here; it can be shown only once.' }}</p>
      <pre class="li-code" aria-label="website snippet">{{ snippet }}</pre>
      <button class="btn btn-primary" @click="copySnippet">{{ copiedSnippet ? 'Copied' : 'Copy snippet' }}</button>
      <p class="li-note2 li-gap">Anyone can read the address in your page's source, so you can limit it to your own websites. A form on any other website is then refused.
        Use the full address of each site, and list the "www" and plain versions if both are used.</p>
      <form class="li-sites" @submit.prevent="saveSites">
        <select v-model="siteId" aria-label="Address to limit" @change="pickSite">
          <option :value="null" disabled>Choose an address…</option>
          <option v-for="t in tokens" :key="t.id" :value="t.id">{{ t.name }} (…{{ t.token_hint }})</option>
        </select>
        <textarea v-model="siteText" rows="3" :disabled="!siteId" aria-label="Allowed websites, one per line" placeholder="https://www.example.com&#10;https://example.com&#10;Leave empty to allow any website"></textarea>
        <button class="btn btn-primary" type="submit" :disabled="busy || !siteId">Save websites</button>
      </form>
    </section>

    <slot />

    <section class="li-card" aria-label="Import log">
      <h3>What arrived <span class="li-sub">last 7 days</span></h3>
      <div class="li-chips">
        <span v-for="o in outcomes" :key="o.key" class="chip" :class="o.key">{{ o.label }}: <strong>{{ summary[o.key] || 0 }}</strong></span>
      </div>
      <table class="li-table">
        <thead><tr><th>When</th><th>From</th><th>Result</th><th>Lead</th><th>Reference</th><th>Note</th></tr></thead>
        <tbody>
          <tr v-for="l in logs" :key="l.id">
            <td>{{ when(l.at) }}</td>
            <td>{{ l.source }}</td>
            <td><span class="chip" :class="l.outcome">{{ label(l.outcome) }}</span></td>
            <td>{{ l.lead_no || '' }}</td>
            <td>{{ l.external_id || '' }}</td>
            <td class="why">{{ l.reason || '' }}</td>
          </tr>
          <tr v-if="!logs.length"><td colspan="6" class="li-empty">Nothing has arrived yet.</td></tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '../services/api.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const outcomes = [
  { key: 'created', label: 'New leads' }, { key: 'merged', label: 'Added to a lead' }, { key: 'already_received', label: 'Repeats ignored' },
  { key: 'rejected', label: 'Rejected' }, { key: 'spam', label: 'Spam' }, { key: 'blocked', label: 'Wrong website' }, { key: 'too_large', label: 'Too large' }, { key: 'error', label: 'Errors' },
]
const label = (k) => outcomes.find(o => o.key === k)?.label ?? k

const tokens = ref([])
const logs = ref([])
const summary = ref({})
const sources = ref([])
const form = reactive({ name: '', source: '' })
const fresh = ref(null)       // the token just made, shown once
const copied = ref(false)
const copiedExample = ref(false)
const copiedSnippet = ref(false)
const siteId = ref(null)
const siteText = ref('')
const busy = ref(false)
const error = ref('')

const fullUrl = (t) => `${window.location.origin}${t.path}`
const example = computed(() => {
  const url = fresh.value ? fullUrl(fresh.value) : `${window.location.origin}/api/inbound/leads/YOUR_ADDRESS_TOKEN`
  return `curl -X POST "${url}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"name": "Ravi Patel", "mobile": "9876543210", "message": "Need 300 sqm PUF wall panel", "city": "Rajkot", "id": "form-123"}'`
})
const snippet = computed(() => `<div id="panelos-enquiry"></div>\n<script src="${window.location.origin}/lead-form.js" data-token="${fresh.value?.token ?? 'YOUR_ADDRESS_TOKEN'}" async><\/script>`)
const when = (d) => new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
const body = (res) => res?.data?.data ?? res?.data ?? {}

async function load() {
  error.value = ''
  try {
    const [t, l, s] = await Promise.all([api.get('/settings/lead-inbound-tokens'), api.get('/settings/lead-import-logs'), api.get('/settings/lead-lists')])
    tokens.value = body(t) || []
    logs.value = body(l).logs ?? []
    summary.value = body(l).summary ?? {}
    sources.value = (body(s).sources ?? []).filter(x => x.is_active).map(x => x.name)
    if (!form.source) form.source = sources.value.includes('Website') ? 'Website' : (sources.value[0] ?? '')
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load.' }
}

async function run(fn, done) {
  busy.value = true; error.value = ''
  try { const r = await fn(); if (done) toastSuccess(done); await load(); return r }
  catch (e) { const msg = e?.response?.data?.message ?? 'That did not work.'; toastError(msg); await load(); error.value = msg }
  finally { busy.value = false }
}

async function add() {
  const r = await run(() => api.post('/settings/lead-inbound-tokens', { name: form.name, source: form.source }), 'Address made.')
  if (r) { fresh.value = body(r); copied.value = false; form.name = '' }
}

async function regenerate(t) {
  const ok = await confirmDialog({ title: `New address for "${t.name}"?`, message: 'The current address stops working immediately. Anything still using it must be given the new one.', confirmLabel: 'Make new address', cancelLabel: 'Cancel' })
  if (!ok) return
  const r = await run(() => api.post(`/settings/lead-inbound-tokens/${t.id}/regenerate`), 'New address made.')
  if (r) { fresh.value = body(r); copied.value = false }
}

const setActive = (t, on) => run(() => api.put(`/settings/lead-inbound-tokens/${t.id}`, { is_active: on }))

async function remove(t) {
  const ok = await confirmDialog({ title: `Delete "${t.name}"?`, message: 'Its address stops working for good. Leads already received stay.', confirmLabel: 'Delete', cancelLabel: 'Cancel', danger: true })
  if (!ok) return
  if (fresh.value?.id === t.id) fresh.value = null
  return run(() => api.delete(`/settings/lead-inbound-tokens/${t.id}`), 'Deleted.')
}

async function copy(t) {
  try { await navigator.clipboard.writeText(fullUrl(t)); copied.value = true } catch { toastError('Could not copy. Select the address and copy it by hand.') }
}

async function copySnippet() {
  try { await navigator.clipboard.writeText(snippet.value); copiedSnippet.value = true } catch { toastError('Could not copy. Select the text and copy it by hand.') }
}

function pickSite() { siteText.value = (tokens.value.find(t => t.id === siteId.value)?.allowed_origins ?? []).join('\n') }

async function saveSites() {
  const list = siteText.value.split(/[\n,]+/).map(x => x.trim()).filter(Boolean)
  const r = await run(() => api.put(`/settings/lead-inbound-tokens/${siteId.value}`, { allowed_origins: list }), list.length ? 'Websites saved.' : 'Any website may use this address.')
  if (r) pickSite()
}

async function copyExample() {
  try { await navigator.clipboard.writeText(example.value); copiedExample.value = true } catch { toastError('Could not copy. Select the text and copy it by hand.') }
}

onMounted(load)
</script>

<style scoped>
.li-wrap { max-width: 1100px; }
.li-intro { color: #555; font-size: 13.5px; max-width: 75ch; margin: 0 0 14px; line-height: 1.5; }
.li-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 12px; }
.li-fresh { background: #fff8e1; border: 1px solid #ffe082; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; font-size: 13px; color: #6d4c00; }
.li-url { display: block; max-width: 100%; overflow-wrap: anywhere; background: #fff; border: 1px solid #ffe082; padding: 8px 10px; border-radius: 6px; color: #333; }
.li-note { margin: 0; font-size: 12px; color: #8d6e00; }
.li-card { background: #fff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 18px; overflow-x: auto; }
.li-card h3 { margin: 0 0 10px; font-size: 15px; color: var(--primary); }
.li-sub { font-size: 12px; font-weight: 400; color: #888; margin-left: 6px; }
.li-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.li-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #666; padding: 5px 6px; border-bottom: 1px solid #ddd; }
.li-table td { padding: 6px; border-bottom: 1px solid #f2f2f2; vertical-align: middle; }
.li-table .r { text-align: right; white-space: nowrap; }
.li-table tr.off td { color: #999; }
.li-table .why { color: #777; max-width: 280px; }
.li-empty { color: #999; text-align: center; padding: 14px 0 !important; }
.li-add { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.li-add input, .li-add select { padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.li-add input { flex: 1; min-width: 200px; }
.li-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.chip { font-size: 12px; padding: 3px 10px; border-radius: 12px; background: #eceff1; color: #455a64; white-space: nowrap; }
.chip.created { background: #e8f5e9; color: #2e7d32; }
.chip.merged { background: #e3f2fd; color: #1565c0; }
.chip.rejected, .chip.too_large { background: #fff3e0; color: #e65100; }
.chip.error { background: #ffebee; color: #c62828; }
.chip.spam, .chip.blocked { background: #f3e5f5; color: #6a1b9a; }
.link { background: none; border: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; margin-right: 6px; }
.x { background: none; border: none; color: #b0b0b0; cursor: pointer; font-size: 14px; padding: 2px 6px; border-radius: 4px; }
.x:hover:not(:disabled) { color: #c62828; background: #fdecea; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
code { font-size: 12px; }
.li-gap { margin-top: 14px; }
.li-sites { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }
.li-sites select, .li-sites textarea { padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: inherit; }
.li-sites textarea { flex: 1; min-width: 260px; }
.li-note2 { margin: 0 0 8px; font-size: 12.5px; color: #666; }
.li-code { background: #263238; color: #eceff1; padding: 12px 14px; border-radius: 8px; font-size: 12px; overflow-x: auto; margin: 0 0 10px; white-space: pre; }
</style>
