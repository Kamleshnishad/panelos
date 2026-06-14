<template>
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand">
        <div class="brand-mark">P</div>
        <div>
          <div class="brand-name">PanelOS</div>
          <div class="brand-sub">PUF Panel ERP</div>
        </div>
      </div>

      <h2>Sign in</h2>
      <p class="muted">Use your company account to continue.</p>

      <form @submit.prevent="submit">
        <div class="field">
          <label>Email</label>
          <input v-model="email" type="email" autocomplete="username" placeholder="admin@panelos.local" required />
        </div>
        <div class="field">
          <label>Password</label>
          <input v-model="password" type="password" autocomplete="current-password" placeholder="••••••••" required />
        </div>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <button class="btn-login" :disabled="loading">{{ loading ? 'Signing in…' : 'Sign In' }}</button>
      </form>

      <div class="demo-accounts">
        <div class="demo-title">Demo accounts (click to fill)</div>
        <div class="demo-grid">
          <button v-for="a in accounts" :key="a.email" type="button" class="demo-chip" @click="fill(a)">
            {{ a.label }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import authService from '../services/authService.js'

const emit = defineEmits(['logged-in'])

const email = ref('admin@panelos.local')
const password = ref('Admin@123')
const loading = ref(false)
const error = ref(null)

const accounts = [
  { label: 'Company Admin', email: 'admin@panelos.local', password: 'Admin@123' },
  { label: 'Sales', email: 'sales@panelos.local', password: 'Sales@123' },
  { label: 'Production', email: 'production@panelos.local', password: 'Prod@123' },
  { label: 'Accounts', email: 'accounts@panelos.local', password: 'Accounts@123' },
  { label: 'Viewer', email: 'viewer@panelos.local', password: 'Viewer@123' },
]

function fill(a) { email.value = a.email; password.value = a.password; error.value = null }

async function submit() {
  loading.value = true
  error.value = null
  try {
    await authService.login(email.value, password.value)
    emit('logged-in')
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Login failed. Check your credentials.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
  background: radial-gradient(1200px 600px at 20% -10%, #2a35a0 0%, transparent 60%),
              linear-gradient(135deg, var(--brand-900) 0%, var(--brand-ink) 100%);
}
.login-card {
  background: var(--surface); border-radius: var(--r-xl); padding: 40px 42px;
  width: 100%; max-width: 430px; box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255,255,255,0.6);
}

.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; }
.brand-mark {
  width: 46px; height: 46px; border-radius: 13px;
  background: linear-gradient(135deg, var(--brand-700), var(--brand-900));
  color: white; display: flex; align-items: center; justify-content: center;
  font-size: 23px; font-weight: 800; box-shadow: var(--shadow-brand);
}
.brand-name { font-size: 19px; font-weight: 800; color: var(--brand-900); letter-spacing: -0.02em; }
.brand-sub { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; }

h2 { margin: 0 0 5px; font-size: 23px; color: var(--text); }
.muted { color: var(--text-3); font-size: 13px; margin: 0 0 24px; }

.field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 17px; }
.field label { font-size: 11px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px; }
.field input { padding: 12px 14px; border: 1px solid var(--border-2); border-radius: var(--r); font-size: 14px; transition: all var(--t-fast); background: var(--surface-2); }
.field input:focus { outline: none; border-color: var(--brand-500); box-shadow: 0 0 0 3px var(--brand-50); background: #fff; }

.error-msg { background: var(--danger-bg); border: 1px solid var(--danger-bd); color: var(--danger); padding: 10px 14px; border-radius: var(--r-sm); font-size: 13px; margin-bottom: 15px; }

.btn-login {
  width: 100%; padding: 13px; color: white; border: none; border-radius: var(--r);
  background: linear-gradient(135deg, var(--brand-700), var(--brand-900));
  font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: var(--shadow-brand);
  transition: transform var(--t-fast), box-shadow var(--t-fast), opacity var(--t-fast);
}
.btn-login:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(26,35,126,0.3); }
.btn-login:disabled { opacity: 0.6; cursor: not-allowed; }

.demo-accounts { margin-top: 28px; border-top: 1px solid var(--border); padding-top: 20px; }
.demo-title { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 11px; font-weight: 600; }
.demo-grid { display: flex; flex-wrap: wrap; gap: 7px; }
.demo-chip { padding: 7px 13px; border: 1px solid var(--border-2); background: var(--surface-2); border-radius: var(--r-pill); font-size: 12px; color: var(--text-2); cursor: pointer; font-weight: 500; transition: all var(--t-fast); }
.demo-chip:hover { border-color: var(--brand-400); color: var(--brand-700); background: var(--brand-50); }
</style>
