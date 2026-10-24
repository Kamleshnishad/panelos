<template>
  <section class="card">
    <div class="card-head">
      <h3>{{ icon }} {{ title }}</h3>
      <label class="toggle">
        <input type="checkbox" v-model="form[p + 'enabled']" />
        <span class="slider"></span>
        <span class="toggle-label">{{ form[p + 'enabled'] ? 'On' : 'Off' }}</span>
      </label>
    </div>
    <p class="card-hint"><slot name="intro" /></p>

    <div class="form-grid" :class="{ dimmed: !form[p + 'enabled'] }">
      <div class="form-group">
        <label>Mail server</label>
        <input v-model="form[p + 'host']" placeholder="smtppro.zoho.in" autocomplete="off" />
      </div>
      <div class="form-group">
        <label>Security</label>
        <select v-model="form[p + 'encryption']" @change="onEncryptionChange">
          <option value="ssl">SSL (port 465)</option>
          <option value="tls">TLS / STARTTLS (port 587)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Port</label>
        <select v-model.number="form[p + 'port']">
          <option :value="465">465</option>
          <option :value="587">587</option>
          <option :value="2525">2525</option>
          <option :value="25">25</option>
        </select>
      </div>
      <div class="form-group">
        <label>User name</label>
        <input v-model="form[p + 'username']" placeholder="name@yourcompany.com" autocomplete="off" />
      </div>
      <div class="form-group">
        <label>Password</label>
        <input v-model="form[p + 'password']" type="password" autocomplete="new-password"
               :placeholder="passwordSet ? 'saved — leave blank to keep' : 'app password'" />
        <span class="hint">
          <template v-if="passwordSet">✓ A password is saved (stored encrypted, never shown again).
            <button type="button" class="link" @click="$emit('clear-password', p)">Remove it</button></template>
          <template v-else>Stored encrypted. It cannot be read back, only replaced.</template>
        </span>
      </div>
      <div class="form-group">
        <label>Send as (From address)</label>
        <input v-model="form[p + 'from_address']" placeholder="name@yourcompany.com" autocomplete="off" />
        <span class="hint">Most providers only accept the address of the mailbox you log in with.</span>
      </div>
      <div class="form-group">
        <label>Sender name</label>
        <input v-model="form[p + 'from_name']" placeholder="Your Company Name" autocomplete="off" />
      </div>
    </div>

    <div class="test-row">
      <button type="button" class="btn btn-secondary" :disabled="testing" @click="$emit('test', kind)">
        {{ testing ? 'Sending…' : '📤 Send me a test e-mail from this mailbox' }}
      </button>
      <span v-if="result" :class="result.ok ? 'test-ok' : 'test-err'">{{ result.msg }}</span>
    </div>
    <p class="hint" style="margin-top:8px">Save first, then test. The test goes to your own login address, and if the password is wrong the mail server's own answer is shown here.</p>
  </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  form: { type: Object, required: true },
  kind: { type: String, required: true },          // 'main' | 'notifications'
  title: { type: String, required: true },
  icon: { type: String, default: '📬' },
  testing: { type: Boolean, default: false },
  result: { type: Object, default: null },
})
defineEmits(['test', 'clear-password'])

// Column prefix: the main mailbox is smtp_*, the notifications mailbox notif_smtp_*.
const p = computed(() => (props.kind === 'notifications' ? 'notif_smtp_' : 'smtp_'))
const passwordSet = computed(() => !!props.form[p.value + 'password_set'])

function onEncryptionChange() {
  props.form[p.value + 'port'] = props.form[p.value + 'encryption'] === 'ssl' ? 465 : 587
}
</script>

<style scoped>
.card { background: #fff; border-radius: 12px; padding: 20px 22px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.card-head h3 { margin: 0; font-size: 16px; color: var(--primary); }
.card-hint { font-size: 13px; color: #555; margin: 0 0 12px; line-height: 1.5; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 18px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #5b6472; }
.form-group input, .form-group select { padding: 9px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; background: #fff; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 2px var(--primary-tint); }
.hint { font-size: 11px; color: #888; }
.dimmed { opacity: .5; }
.dimmed .link, .dimmed select { pointer-events: auto; }
.link { background: none; border: none; color: var(--primary); cursor: pointer; padding: 0 4px; text-decoration: underline; font-size: inherit; }
.toggle { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
.toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.slider { width: 38px; height: 22px; border-radius: 11px; background: #cfd4dc; position: relative; transition: background .15s; }
.slider::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; }
.toggle input:checked + .slider { background: var(--primary); }
.toggle input:checked + .slider::after { transform: translateX(16px); }
.toggle-label { font-size: 13px; font-weight: 600; color: #444; }
.test-row { display: flex; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.btn { padding: 8px 16px; border-radius: 7px; border: 1px solid #ccd; background: #f4f6fb; cursor: pointer; font-weight: 600; font-size: 13px; }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.test-ok { color: #2e7d32; font-size: 13px; font-weight: 600; }
.test-err { color: #c62828; font-size: 13px; }
@media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } }
</style>
