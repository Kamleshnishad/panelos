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

      <!-- ── 2FA OTP ─────────────────────────────────── -->
      <template v-if="mode === 'otp'">
        <h2>Enter verification code</h2>
        <p class="muted">We sent a 6-digit code to <b>{{ otpEmail }}</b>. It expires in 10 minutes.</p>
        <form @submit.prevent="submitOtp">
          <div class="field">
            <label>Verification Code</label>
            <input v-model="otpCode" inputmode="numeric" maxlength="6" placeholder="••••••" autofocus />
          </div>
          <div v-if="error" class="error-msg">{{ error }}</div>
          <button class="btn-login" :disabled="loading || otpCode.length < 6">{{ loading ? 'Verifying…' : 'Verify & Sign In' }}</button>
        </form>
        <p class="switch"><a href="#" @click.prevent="switchMode('login')">← Back to sign in</a></p>
      </template>

      <!-- ── SIGN IN ─────────────────────────────────── -->
      <template v-else-if="mode === 'login'">
        <h2>Sign in</h2>
        <p class="muted">Use your company account to continue.</p>

        <form @submit.prevent="submit">
          <div class="field">
            <label>Email</label>
            <input v-model="email" type="email" autocomplete="username" placeholder="you@company.com" required />
          </div>
          <div class="field">
            <label>Password</label>
            <input v-model="password" type="password" autocomplete="current-password" placeholder="••••••••" required />
          </div>

          <div v-if="error" class="error-msg">{{ error }}</div>

          <button class="btn-login" :disabled="loading">{{ loading ? 'Signing in…' : 'Sign In' }}</button>
        </form>

        <p class="switch">New factory? <a href="#" @click.prevent="switchMode('signup')">Start a free 14-day trial →</a></p>

        <div class="demo-accounts">
          <div class="demo-title">Demo accounts (click to fill)</div>
          <div class="demo-grid">
            <button v-for="a in accounts" :key="a.email" type="button" class="demo-chip" @click="fill(a)">
              {{ a.label }}
            </button>
          </div>
        </div>
      </template>

      <!-- ── SIGN UP (new tenant) ────────────────────── -->
      <template v-else-if="mode === 'signup'">
        <h2>Start your free trial</h2>
        <p class="muted">14 days free. No card required. Set up your factory in a minute.</p>

        <form @submit.prevent="submitSignup">
          <div class="field">
            <label>Company / Factory Name</label>
            <input v-model="su.company_name" type="text" placeholder="e.g. Shree PUF Panels Pvt Ltd" required />
          </div>
          <div class="field">
            <label>Your Name</label>
            <input v-model="su.name" type="text" placeholder="Owner / Admin name" required />
          </div>
          <div class="field">
            <label>Work Email</label>
            <input v-model="su.email" type="email" autocomplete="username" placeholder="you@company.com" required />
          </div>
          <div class="row2">
            <div class="field">
              <label>Phone</label>
              <input v-model="su.phone" type="text" placeholder="98XXXXXXXX" />
            </div>
            <div class="field">
              <label>Password</label>
              <input v-model="su.password" type="password" autocomplete="new-password" placeholder="min 8, letter+number" required />
            </div>
          </div>
          <div class="field">
            <label>Confirm Password</label>
            <input v-model="su.password_confirmation" type="password" autocomplete="new-password" placeholder="repeat password" required />
          </div>

          <div v-if="error" class="error-msg">{{ error }}</div>

          <button class="btn-login" :disabled="loading">{{ loading ? 'Creating your account…' : 'Create Account & Start Trial' }}</button>
        </form>

        <p class="switch">Already have an account? <a href="#" @click.prevent="switchMode('login')">Sign in →</a></p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import authService from '../services/authService.js'

const emit = defineEmits(['logged-in'])

const mode = ref('login')
const email = ref('admin@panelos.local')
const password = ref('Admin@123')
const loading = ref(false)
const error = ref(null)
const otpEmail = ref('')
const otpCode = ref('')

const su = ref({ company_name: '', name: '', email: '', phone: '', password: '', password_confirmation: '' })

function switchMode(m) { mode.value = m; error.value = null }

function utmParams() {
  const q = new URLSearchParams(window.location.search)
  return {
    utm_source: q.get('utm_source') || undefined,
    utm_medium: q.get('utm_medium') || undefined,
    utm_campaign: q.get('utm_campaign') || undefined,
    signup_referrer: document.referrer || undefined,
  }
}

async function submitSignup() {
  loading.value = true; error.value = null
  try {
    if (su.value.password !== su.value.password_confirmation) {
      error.value = 'Passwords do not match.'; loading.value = false; return
    }
    await authService.register({ ...su.value, ...utmParams() })
    emit('logged-in')
  } catch (e) {
    const errs = e?.response?.data?.errors
    error.value = errs ? Object.values(errs).flat()[0] : (e?.response?.data?.message ?? 'Signup failed.')
  } finally { loading.value = false }
}

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
    const data = await authService.login(email.value, password.value)
    if (data?.needs_2fa) {
      otpEmail.value = data.email || email.value
      otpCode.value = ''
      mode.value = 'otp'
      return
    }
    emit('logged-in')
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Login failed. Check your credentials.'
  } finally {
    loading.value = false
  }
}

async function submitOtp() {
  loading.value = true; error.value = null
  try {
    await authService.verifyOtp(otpEmail.value, otpCode.value)
    emit('logged-in')
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Invalid or expired code.'
  } finally { loading.value = false }
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

.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.switch { margin-top: 16px; font-size: 13px; color: var(--text-3); text-align: center; }
.switch a { color: var(--brand-700); font-weight: 600; text-decoration: none; }
.switch a:hover { text-decoration: underline; }

.demo-accounts { margin-top: 28px; border-top: 1px solid var(--border); padding-top: 20px; }
.demo-title { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 11px; font-weight: 600; }
.demo-grid { display: flex; flex-wrap: wrap; gap: 7px; }
.demo-chip { padding: 7px 13px; border: 1px solid var(--border-2); background: var(--surface-2); border-radius: var(--r-pill); font-size: 12px; color: var(--text-2); cursor: pointer; font-weight: 500; transition: all var(--t-fast); }
.demo-chip:hover { border-color: var(--brand-400); color: var(--brand-700); background: var(--brand-50); }
</style>
