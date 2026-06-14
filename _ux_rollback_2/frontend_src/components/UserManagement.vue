<template>
  <div class="um-wrap">
    <div class="um-header">
      <h2>User Management</h2>
      <button class="btn btn-primary" @click="openCreate">+ Add User</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="success" class="success-banner">{{ success }}</div>
    <div v-if="forbidden" class="info-banner">Only company admins can manage users.</div>

    <div v-if="loading" class="loading-state">Loading users…</div>

    <table v-else-if="!forbidden" class="um-table">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Admin</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-if="users.length === 0"><td colspan="7" class="empty-row">No users.</td></tr>
        <tr v-for="u in users" :key="u.id" :class="{ inactive: !u.is_active }">
          <td class="bold">{{ u.name }}<span v-if="u.id === currentUserId" class="you-tag">you</span></td>
          <td class="muted">{{ u.email }}</td>
          <td class="muted">{{ u.phone || '—' }}</td>
          <td><span class="role-badge" v-if="u.role_name">{{ u.role_name }}</span><span v-else class="muted">—</span></td>
          <td>
            <span v-if="u.is_super_admin" class="admin-badge super">Super</span>
            <span v-else-if="u.is_company_admin" class="admin-badge">Admin</span>
            <span v-else class="muted">—</span>
          </td>
          <td><span :class="['status-dot', u.is_active ? 'on' : 'off']">{{ u.is_active ? 'Active' : 'Inactive' }}</span></td>
          <td class="actions">
            <button class="btn-icon edit" @click="openEdit(u)">Edit</button>
            <button class="btn-icon pass" @click="openReset(u)">Reset Password</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Create / Edit modal -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>{{ editing ? 'Edit User' : 'New User' }}</h3>
          <button class="btn-close" @click="showModal = false">✕</button>
        </div>
        <div class="form-grid">
          <div class="form-group"><label>Name *</label><input v-model="form.name" /></div>
          <div class="form-group"><label>Email *</label><input v-model="form.email" type="email" /></div>
          <div class="form-group"><label>Phone</label><input v-model="form.phone" /></div>
          <div class="form-group">
            <label>Role</label>
            <select v-model="form.role_id">
              <option :value="null">— No role —</option>
              <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </div>
          <div class="form-group" v-if="!editing">
            <label>Password *</label>
            <input v-model="form.password" type="password" placeholder="Min 6 characters" />
          </div>
          <div class="form-group toggles">
            <label class="toggle"><input type="checkbox" v-model="form.is_company_admin" /> Company Admin</label>
            <label class="toggle"><input type="checkbox" v-model="form.is_active" /> Active</label>
          </div>
        </div>
        <div v-if="modalError" class="error-msg">{{ modalError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </div>

    <!-- Reset password modal -->
    <div v-if="resetTarget" class="modal-overlay" @click.self="resetTarget = null">
      <div class="modal-box sm">
        <div class="modal-header">
          <h3>Reset Password</h3>
          <button class="btn-close" @click="resetTarget = null">✕</button>
        </div>
        <p>Set a new password for <strong>{{ resetTarget.name }}</strong>. They will be logged out of all sessions.</p>
        <div class="form-group">
          <label>New Password *</label>
          <input v-model="resetPass" type="password" placeholder="Min 6 characters" />
        </div>
        <div v-if="modalError" class="error-msg">{{ modalError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="resetTarget = null">Cancel</button>
          <button class="btn btn-danger" :disabled="saving || !resetPass" @click="doReset">{{ saving ? 'Resetting…' : 'Reset Password' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import userService from '../services/userService.js'

const users = ref([])
const roles = ref([])
const loading = ref(false)
const error = ref(null)
const success = ref(null)
const forbidden = ref(false)
const currentUserId = ref(null)

const showModal = ref(false)
const editing = ref(false)
const editId = ref(null)
const saving = ref(false)
const modalError = ref(null)

const resetTarget = ref(null)
const resetPass = ref('')

const form = reactive({ name: '', email: '', phone: '', role_id: null, password: '', is_company_admin: false, is_active: true })

async function load() {
  loading.value = true; error.value = null; forbidden.value = false
  try {
    const [uRes, rRes] = await Promise.all([userService.list(), userService.roles()])
    users.value = uRes?.data ?? []
    roles.value = rRes?.data ?? []
    // best-effort current user id from /auth/me cache if present (optional)
    currentUserId.value = Number(localStorage.getItem('user_id')) || null
  } catch (e) {
    if (e?.response?.status === 403) { forbidden.value = true }
    else error.value = e?.response?.data?.message ?? 'Failed to load users.'
  } finally { loading.value = false }
}

function openCreate() {
  editing.value = false; editId.value = null; modalError.value = null
  Object.assign(form, { name: '', email: '', phone: '', role_id: null, password: '', is_company_admin: false, is_active: true })
  showModal.value = true
}
function openEdit(u) {
  editing.value = true; editId.value = u.id; modalError.value = null
  Object.assign(form, { name: u.name, email: u.email, phone: u.phone ?? '', role_id: u.role_id ?? null, password: '', is_company_admin: !!u.is_company_admin, is_active: !!u.is_active })
  showModal.value = true
}

async function save() {
  saving.value = true; modalError.value = null
  try {
    if (editing.value) {
      const { password, ...payload } = form
      await userService.update(editId.value, payload)
      success.value = 'User updated.'
    } else {
      await userService.create(form)
      success.value = 'User created.'
    }
    showModal.value = false
    await load()
  } catch (e) {
    modalError.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Failed to save.'
  } finally { saving.value = false }
}

function openReset(u) { resetTarget.value = u; resetPass.value = ''; modalError.value = null }
async function doReset() {
  saving.value = true; modalError.value = null
  try {
    await userService.resetPassword(resetTarget.value.id, { password: resetPass.value })
    success.value = `Password reset for ${resetTarget.value.name}.`
    resetTarget.value = null
  } catch (e) {
    modalError.value = e?.response?.data?.message ?? 'Failed to reset password.'
  } finally { saving.value = false }
}

onMounted(load)
</script>

<style scoped>
.um-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.um-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.um-header h2 { margin: 0; font-size: 22px; color: var(--primary); }

.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.info-banner { background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.loading-state { text-align: center; padding: 60px; color: #888; }

.um-table { width: 100%; border-collapse: collapse; font-size: 13px; background: white; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; }
.um-table th { background: var(--primary); color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
.um-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; }
.um-table tr:last-child td { border-bottom: none; }
.um-table tr.inactive td { opacity: 0.55; }
.bold { font-weight: 700; }
.muted { color: #999; }
.empty-row { text-align: center; padding: 30px; color: #aaa; font-style: italic; }
.you-tag { background: var(--primary-tint); color: var(--primary); font-size: 10px; font-weight: 700; border-radius: 8px; padding: 1px 6px; margin-left: 6px; }

.role-badge { background: var(--primary-tint); color: var(--primary); border-radius: 8px; padding: 2px 9px; font-size: 11px; font-weight: 600; }
.admin-badge { background: #ede7f6; color: #4527a0; border-radius: 8px; padding: 2px 9px; font-size: 11px; font-weight: 700; }
.admin-badge.super { background: #fce4ec; color: #ad1457; }
.status-dot { font-size: 11px; font-weight: 700; }
.status-dot.on { color: #2e7d32; } .status-dot.off { color: #aaa; }
.actions { display: flex; gap: 6px; }
.btn-icon { padding: 4px 10px; border: 1px solid #ddd; background: white; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer; }
.btn-icon.edit { color: var(--primary); border-color: #bbdefb; }
.btn-icon.pass { color: #e65100; border-color: #ffcc80; }

.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-danger { background: #c62828; color: white; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 24px 28px; width: 100%; max-width: 560px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); }
.modal-box.sm { max-width: 420px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.modal-box p { color: #555; font-size: 14px; margin: 0 0 14px; line-height: 1.5; }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.toggles { grid-column: 1 / -1; flex-direction: row; gap: 20px; align-items: center; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input, .form-group select { padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.toggle { display: flex; align-items: center; gap: 7px; font-size: 13px; color: #444; cursor: pointer; text-transform: none; font-weight: 500; }
.toggle input { width: 15px; height: 15px; }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }
</style>
