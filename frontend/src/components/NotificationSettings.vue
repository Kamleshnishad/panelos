<template>
  <div class="ns-wrap">
    <div class="ns-header">
      <div>
        <h2>Notification Settings</h2>
        <p class="ns-sub">Configure Twilio credentials for SMS &amp; WhatsApp alerts.</p>
      </div>
      <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save Settings' }}</button>
    </div>

    <div v-if="error"   class="banner error">{{ error }}</div>
    <div v-if="success" class="banner success">{{ success }}</div>
    <div v-if="loading" class="loading-state">Loading…</div>

    <template v-else-if="form">

      <!-- Status bar -->
      <div class="status-bar">
        <div class="status-cell" :class="form.sms_ready ? 'ok' : 'off'">
          <span class="status-dot"></span>
          <span>SMS {{ form.sms_ready ? 'Active' : 'Not configured' }}</span>
        </div>
        <div class="status-cell" :class="form.whatsapp_ready ? 'ok' : 'off'">
          <span class="status-dot"></span>
          <span>WhatsApp {{ form.whatsapp_ready ? 'Active' : 'Not configured' }}</span>
        </div>
        <div class="status-note">
          Get free credentials at
          <a href="https://www.twilio.com/try-twilio" target="_blank">twilio.com</a>
          → Console → Account Info
        </div>
      </div>

      <!-- Twilio credentials -->
      <section class="card">
        <h3>🔑 Twilio Credentials</h3>
        <p class="card-hint">These are shared between SMS and WhatsApp. Find them in your Twilio Console.</p>
        <div class="form-grid">
          <div class="form-group">
            <label>Account SID <span class="req">*</span></label>
            <input v-model="form.twilio_account_sid" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" class="mono-input" />
            <span class="hint">Starts with "AC" — 34 characters</span>
          </div>
          <div class="form-group">
            <label>Auth Token <span class="req">*</span></label>
            <div class="secret-row">
              <input :type="showToken ? 'text' : 'password'" v-model="form.twilio_auth_token"
                     :placeholder="form.token_is_set ? 'Saved — leave blank to keep, or type new to change' : 'Paste auth token'" class="mono-input" />
              <button type="button" class="btn-eye" @click="showToken = !showToken">{{ showToken ? '🙈' : '👁' }}</button>
            </div>
            <span v-if="form.token_is_set && !form.twilio_auth_token" class="hint token-saved">✓ Token saved &amp; hidden for security. Leave blank to keep it.</span>
            <span v-else class="hint">32-character token from your Twilio Console</span>
          </div>
        </div>
      </section>

      <!-- SMS -->
      <section class="card">
        <div class="card-head">
          <h3>📱 SMS (Text Messages)</h3>
          <label class="toggle">
            <input type="checkbox" v-model="form.sms_enabled" />
            <span class="slider"></span>
            <span class="toggle-label">{{ form.sms_enabled ? 'Enabled' : 'Disabled' }}</span>
          </label>
        </div>
        <div class="form-grid" :class="{ dimmed: !form.sms_enabled }">
          <div class="form-group">
            <label>Twilio From Number</label>
            <input v-model="form.twilio_from_number" placeholder="+91XXXXXXXXXX or +1XXXXXXXXXX" :disabled="!form.sms_enabled" />
            <span class="hint">Your Twilio phone number in E.164 format</span>
          </div>
          <div class="form-group">
            <label>Admin Phone (internal alerts)</label>
            <input v-model="form.admin_phone" placeholder="+91XXXXXXXXXX" :disabled="!form.sms_enabled" />
            <span class="hint">Receives low-stock, production alerts</span>
          </div>
        </div>
        <div class="test-row" v-if="form.sms_enabled">
          <input v-model="testPhone" placeholder="+91XXXXXXXXXX" class="test-input" />
          <button class="btn btn-secondary" :disabled="testing || !testPhone" @click="testSend('sms')">
            {{ testing === 'sms' ? 'Sending…' : '📤 Send Test SMS' }}
          </button>
          <span v-if="testResult.sms" :class="testResult.sms.ok ? 'test-ok' : 'test-err'">{{ testResult.sms.msg }}</span>
        </div>
      </section>

      <!-- WhatsApp -->
      <section class="card">
        <div class="card-head">
          <h3>💬 WhatsApp</h3>
          <label class="toggle">
            <input type="checkbox" v-model="form.whatsapp_enabled" />
            <span class="slider"></span>
            <span class="toggle-label">{{ form.whatsapp_enabled ? 'Enabled' : 'Disabled' }}</span>
          </label>
        </div>

        <div class="wa-info" v-if="form.whatsapp_enabled">
          <strong>Two ways to use WhatsApp via Twilio:</strong>
          <ol>
            <li><b>Sandbox (free testing):</b> Use <code>+14155238886</code> as "From". Ask customers to send "join &lt;keyword&gt;" to that number once. Good for testing.</li>
            <li><b>Business number (production):</b> Apply for a Twilio WhatsApp-enabled number in the Twilio Console → Messaging → Senders → WhatsApp senders.</li>
          </ol>
        </div>

        <div class="form-grid" :class="{ dimmed: !form.whatsapp_enabled }">
          <div class="form-group">
            <label>WhatsApp From Number</label>
            <input v-model="form.whatsapp_from" placeholder="+14155238886 (sandbox) or your approved number" :disabled="!form.whatsapp_enabled" />
            <span class="hint">E.164 format — Twilio adds "whatsapp:" prefix automatically</span>
          </div>
        </div>
        <div class="test-row" v-if="form.whatsapp_enabled">
          <input v-model="testPhone" placeholder="+91XXXXXXXXXX (must have joined sandbox)" class="test-input" />
          <button class="btn btn-wa" :disabled="testing || !testPhone" @click="testSend('whatsapp')">
            {{ testing === 'whatsapp' ? 'Sending…' : '💬 Send Test WhatsApp' }}
          </button>
          <span v-if="testResult.whatsapp" :class="testResult.whatsapp.ok ? 'test-ok' : 'test-err'">{{ testResult.whatsapp.msg }}</span>
        </div>
      </section>

      <!-- Notification triggers -->
      <section class="card">
        <h3>🔔 When to Notify</h3>
        <div class="trigger-grid">
          <label class="trigger-row">
            <input type="checkbox" v-model="form.notify_payment_due" />
            <div>
              <div class="trigger-label">Payment due soon</div>
              <div class="trigger-sub">Send reminder before invoice due date</div>
            </div>
            <div v-if="form.notify_payment_due" class="trigger-days">
              <input v-model.number="form.payment_due_days_before" type="number" min="1" max="30" class="days-input" /> days before
            </div>
          </label>
          <label class="trigger-row">
            <input type="checkbox" v-model="form.notify_payment_overdue" />
            <div>
              <div class="trigger-label">Payment overdue</div>
              <div class="trigger-sub">Alert when invoice passes due date unpaid</div>
            </div>
          </label>
          <label class="trigger-row">
            <input type="checkbox" v-model="form.notify_low_stock" />
            <div>
              <div class="trigger-label">Low stock alert</div>
              <div class="trigger-sub">Notify admin when material hits reorder level</div>
            </div>
          </label>
          <label class="trigger-row">
            <input type="checkbox" v-model="form.notify_order_confirmed" />
            <div>
              <div class="trigger-label">Order confirmed</div>
              <div class="trigger-sub">Notify customer when order is created</div>
            </div>
          </label>
          <label class="trigger-row">
            <input type="checkbox" v-model="form.notify_dispatch_done" />
            <div>
              <div class="trigger-label">Dispatch done</div>
              <div class="trigger-sub">Notify customer when goods are dispatched</div>
            </div>
          </label>
        </div>
      </section>

      <!-- Recent deliveries (OPS-H3) -->
      <section class="card">
        <div class="deliv-head">
          <h3>Recent Deliveries</h3>
          <span v-if="failed7d > 0" class="fail-badge">{{ failed7d }} failed in last 7 days</span>
        </div>
        <p v-if="!deliveryLogs.length" class="deliv-empty">No notifications sent yet.</p>
        <table v-else class="log-table">
          <thead><tr><th>When</th><th>Type</th><th>Channel</th><th>To</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="l in deliveryLogs" :key="l.id">
              <td>{{ fmtDateTime(l.created_at) }}</td>
              <td>{{ l.type }}</td>
              <td>{{ l.channel || '—' }}</td>
              <td>{{ l.recipient || '—' }}</td>
              <td>
                <span :class="['log-status', l.status]">{{ l.status }}</span>
                <span v-if="l.error" class="log-err" :title="l.error"> — {{ String(l.error).slice(0, 50) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

    </template>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import notificationService from '../services/notificationService.js'

const loading   = ref(false)
const saving    = ref(false)
const error     = ref(null)
const success   = ref(null)
const showToken = ref(false)
const testing   = ref(null)
const testPhone = ref('')
const testResult = reactive({ sms: null, whatsapp: null })
const form = ref(null)
const deliveryLogs = ref([])
const failed7d = ref(0)

function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

async function loadLogs() {
  try {
    const lr = await notificationService.logs()
    const ld = lr?.data ?? lr ?? {}
    deliveryLogs.value = ld.logs ?? []
    failed7d.value = ld.failed_7d ?? 0
  } catch { /* non-fatal */ }
}

async function load() {
  loading.value = true; error.value = null
  try {
    const r = await notificationService.get()
    const d = { ...(r?.data ?? r) }
    // Keep the token field empty (real token is masked server-side); token_is_set
    // drives the "✓ saved" badge. Leaving it blank on save keeps the stored token.
    d.twilio_auth_token = ''
    form.value = d
    loadLogs()
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Could not load settings.'
  } finally { loading.value = false }
}

async function save() {
  saving.value = true; error.value = null; success.value = null
  try {
    const payload = { ...form.value }
    // Don't send placeholder token
    if (payload.twilio_auth_token === '••••••••' || payload.twilio_auth_token?.startsWith('*')) {
      delete payload.twilio_auth_token
    }
    await notificationService.save(payload)
    success.value = 'Settings saved.'
    await load()   // reload to get updated sms_ready / whatsapp_ready
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Save failed.'
  } finally { saving.value = false }
}

async function testSend(channel) {
  if (!testPhone.value) return
  testing.value = channel; testResult[channel] = null
  try {
    await notificationService.test(channel, testPhone.value)
    testResult[channel] = { ok: true, msg: `✓ Test ${channel === 'sms' ? 'SMS' : 'WhatsApp'} sent! Check your phone.` }
  } catch (e) {
    testResult[channel] = { ok: false, msg: e?.response?.data?.message ?? 'Send failed.' }
  } finally { testing.value = null }
}

onMounted(load)
</script>

<style scoped>
.ns-wrap { padding: 24px 32px 48px; max-width: 900px; margin: 0 auto; }
.ns-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 12px; }
.ns-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.ns-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }

.banner { padding: 11px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
.banner.error   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; }
.banner.success { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; }
.loading-state { text-align: center; padding: 60px; color: #888; }

/* Status bar */
.status-bar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; background: #f8faff; border: 1px solid #dce6f8; border-radius: 10px; padding: 12px 18px; margin-bottom: 18px; }
.status-cell { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; }
.status-cell.ok  .status-dot { background: #2e7d32; }
.status-cell.off .status-dot { background: #ccc; }
.status-dot { width: 9px; height: 9px; border-radius: 50%; }
.status-cell.ok  { color: #2e7d32; }
.status-cell.off { color: #888; }
.status-note { margin-left: auto; font-size: 12px; color: #667085; }
.status-note a { color: var(--primary); }

/* Cards */
.card { background: #fff; border: 1px solid #e2e6ec; border-radius: 10px; padding: 18px 22px; margin-bottom: 14px; }
.card h3 { margin: 0 0 6px; font-size: 15px; color: var(--primary); font-weight: 700; }
.card-hint { font-size: 12px; color: #667085; margin-bottom: 14px; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.card-head h3 { margin: 0; }

/* Toggle */
.toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.toggle input { display: none; }
.slider { width: 40px; height: 22px; background: #ccc; border-radius: 11px; position: relative; transition: background .2s; }
.slider::after { content: ''; position: absolute; width: 18px; height: 18px; background: #fff; border-radius: 50%; top: 2px; left: 2px; transition: left .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.toggle input:checked + .slider { background: var(--primary); }
.toggle input:checked + .slider::after { left: 20px; }
.toggle-label { font-size: 13px; font-weight: 600; color: var(--text); }

/* Form */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #5b6472; }
.form-group input { padding: 9px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.form-group input:disabled { background: #f5f5f5; color: #aaa; }
.mono-input { font-family: monospace; letter-spacing: .05em; }
.hint { font-size: 11px; color: #888; margin-top: 2px; }
.token-saved { color: #2e7d32; font-weight: 600; }
.req { color: #c62828; }
.secret-row { display: flex; gap: 6px; }
.secret-row input { flex: 1; }
.btn-eye { background: none; border: 1px solid #ddd; border-radius: 7px; padding: 0 10px; cursor: pointer; font-size: 14px; }
.dimmed { opacity: .5; pointer-events: none; }

/* WhatsApp info box */
.wa-info { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-size: 12.5px; color: #166534; }
.wa-info ol { margin: 6px 0 0 18px; } .wa-info li { margin: 4px 0; }
.wa-info code { background: #dcfce7; padding: 1px 5px; border-radius: 4px; font-family: monospace; font-size: 11px; }

/* Test row */
.test-row { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.test-input { flex: 1; min-width: 180px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.test-ok  { font-size: 12px; color: #2e7d32; font-weight: 600; }
.test-err { font-size: 12px; color: #c62828; }

/* Triggers */
.trigger-grid { display: flex; flex-direction: column; gap: 10px; }
.trigger-row { display: flex; align-items: center; gap: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e8edf3; border-radius: 8px; cursor: pointer; }
.trigger-row input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; flex-shrink: 0; }
.trigger-label { font-size: 13px; font-weight: 600; color: var(--text); }
.trigger-sub { font-size: 11.5px; color: var(--text-2); margin-top: 1px; }
.trigger-days { margin-left: auto; display: flex; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap; }
.days-input { width: 54px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; text-align: center; }

/* Buttons */
.btn { padding: 9px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary   { background: var(--primary); color: #fff; }
.btn-secondary { background: #EEF1FE; color: var(--primary); border: 1px solid #c5cae9; }
.btn-wa        { background: #25D366; color: #fff; }

@media (max-width: 700px) {
  .ns-wrap { padding: 16px 16px 40px; }
  .form-grid { grid-template-columns: 1fr; }
}
.deliv-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.fail-badge { background: #fceae9; color: #c62828; border: 1px solid #f3b4ae; border-radius: 20px; padding: 2px 10px; font-size: 11px; font-weight: 700; }
.deliv-empty { color: #888; font-size: 13px; font-style: italic; }
.log-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.log-table th { text-align: left; padding: 7px 10px; background: var(--surface-3, #f2f4f7); color: #555; font-size: 11px; text-transform: uppercase; }
.log-table td { padding: 7px 10px; border-bottom: 1px solid #eef0f4; vertical-align: top; }
.log-status { display: inline-block; padding: 1px 9px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
.log-status.sent { background: #e6f4ea; color: #1f7a3d; }
.log-status.failed { background: #fceae9; color: #c62828; }
.log-err { color: #c62828; font-size: 11px; }
</style>
