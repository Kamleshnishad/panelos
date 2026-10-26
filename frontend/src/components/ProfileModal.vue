<template>
  <div class="modal-overlay" role="dialog" aria-modal="true" aria-label="My profile" @click.self="$emit('close')" @keydown.esc="$emit('close')">
    <div class="modal-box">
      <div class="pm-head">
        <div class="pm-avatar">{{ initials }}</div>
        <div class="pm-who">
          <div class="pm-name">{{ user?.name ?? 'User' }}</div>
          <div class="pm-email">{{ user?.email }}</div>
        </div>
        <button class="btn-close" aria-label="Close" @click="$emit('close')">✕</button>
      </div>

      <dl class="pm-facts">
        <div><dt>Role</dt><dd>{{ roleLabel }}</dd></div>
        <div v-if="companyName"><dt>Company</dt><dd>{{ companyName }}</dd></div>
        <div v-if="user?.phone"><dt>Phone</dt><dd>{{ user.phone }}</dd></div>
      </dl>

      <h3>Change password</h3>
      <p class="pm-note">You stay signed in here; any other devices using this account are signed out.</p>
      <form @submit.prevent="save">
        <label for="pm-cur">Current password</label>
        <input id="pm-cur" v-model="form.current_password" type="password" autocomplete="current-password" required />
        <p v-if="errors.current_password" class="pm-err">{{ errors.current_password }}</p>

        <label for="pm-new">New password</label>
        <input id="pm-new" v-model="form.new_password" type="password" autocomplete="new-password" required />
        <p v-if="errors.new_password" class="pm-err">{{ errors.new_password }}</p>

        <label for="pm-conf">Confirm new password</label>
        <input id="pm-conf" v-model="form.new_password_confirmation" type="password" autocomplete="new-password" required />

        <p v-if="message" :class="ok ? 'pm-ok' : 'pm-err'" role="status">{{ message }}</p>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" @click="$emit('close')">Close</button>
          <button type="submit" class="btn btn-primary" :disabled="saving || !form.current_password || !form.new_password">
            {{ saving ? 'Saving…' : 'Change password' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import authService from '../services/authService.js'

defineProps({ user: Object, initials: String, roleLabel: String, companyName: String })
defineEmits(['close'])

const form    = reactive({ current_password: '', new_password: '', new_password_confirmation: '' })
const errors  = reactive({ current_password: '', new_password: '' })
const message = ref('')
const ok      = ref(false)
const saving  = ref(false)

async function save() {
  saving.value = true
  message.value = ''
  errors.current_password = errors.new_password = ''
  try {
    await authService.changePassword({ ...form })
    ok.value = true
    message.value = 'Password changed.'
    Object.assign(form, { current_password: '', new_password: '', new_password_confirmation: '' })
  } catch (e) {
    ok.value = false
    const errs = e?.response?.data?.errors ?? {}
    errors.current_password = errs.current_password?.[0] ?? ''
    errors.new_password     = errs.new_password?.[0] ?? ''
    message.value = errors.current_password || errors.new_password ? '' : (e?.response?.data?.message ?? 'Could not change the password.')
  } finally { saving.value = false }
}
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 16px; }
.modal-box { background: #fff; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 420px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.pm-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.pm-avatar { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), var(--accent-600)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
.pm-who { flex: 1; min-width: 0; }
.pm-name { font-size: 16px; font-weight: 700; color: var(--ink); }
.pm-email { font-size: 12.5px; color: var(--text-3); overflow: hidden; text-overflow: ellipsis; }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; align-self: flex-start; }
.pm-facts { margin: 0 0 18px; display: grid; gap: 6px; }
.pm-facts div { display: flex; gap: 10px; font-size: 13px; }
.pm-facts dt { width: 80px; color: var(--text-3); }
.pm-facts dd { margin: 0; color: var(--text); font-weight: 600; }
h3 { margin: 0 0 4px; font-size: 14px; color: var(--text); border-top: 1px solid var(--border); padding-top: 14px; }
.pm-note { font-size: 12px; color: var(--text-3); margin: 0 0 8px; }
label { display: block; font-size: 12px; font-weight: 600; color: #666; margin: 10px 0 4px; }
input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.pm-err { color: #c62828; font-size: 12px; margin: 4px 0 0; }
.pm-ok { color: #2e7d32; font-size: 13px; font-weight: 600; margin: 10px 0 0; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
