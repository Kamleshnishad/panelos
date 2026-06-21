<template>
  <div class="auth">
    <!-- LEFT — brand / product visual -->
    <aside class="auth-hero">
      <div class="hero-top">
        <div class="hero-brand">
          <div class="hero-mark">P</div>
          <div>
            <div class="hero-name">PanelOS</div>
            <div class="hero-sub">PUF / PIR Panel ERP</div>
          </div>
        </div>
      </div>

      <div class="hero-mid">
        <h1>Run your panel factory,<br>end&#8209;to&#8209;end.</h1>
        <p>From enquiry to dispatch to payment — quotations, production planning,
           inventory, GST invoicing and reports in one place.</p>
        <ul class="hero-feats">
          <li>✓ BOQ → Quotation → Order → Production → Invoice</li>
          <li>✓ Live stock, BOM consumption &amp; auto purchase orders</li>
          <li>✓ GST invoices, e-Invoice / e-Way &amp; Tally export</li>
          <li>✓ WhatsApp alerts, MIS reports &amp; receivables tracking</li>
        </ul>
        <div class="hero-shot">
          <img src="/guide/dashboard.png" alt="PanelOS dashboard" />
        </div>
      </div>

      <div class="hero-foot">Trusted for PUF / PIR insulated panel manufacturing.</div>
    </aside>

    <!-- RIGHT — form -->
    <main class="auth-panel">
      <div class="auth-form">
        <div class="form-brand">
          <div class="fb-mark">P</div><span>PanelOS</span>
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
          <h2>Welcome back</h2>
          <p class="muted">Sign in to your company account to continue.</p>

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

          <div v-if="isDev" class="demo-accounts">
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
    </main>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import authService from '../services/authService.js'

const emit = defineEmits(['logged-in'])

const isDev = import.meta.env.DEV
const mode = ref('login')
const email = ref(isDev ? 'admin@panelos.local' : '')
const password = ref(isDev ? 'Admin@123' : '')
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
.auth { min-height: 100vh; display: grid; grid-template-columns: 1.1fr 0.9fr; }

/* ── LEFT hero ── */
.auth-hero {
  position: relative; overflow: hidden; color: #fff; padding: 38px 48px;
  display: flex; flex-direction: column;
  background: radial-gradient(900px 500px at 15% -10%, #2a35a0 0%, transparent 55%),
              linear-gradient(135deg, var(--brand-900, var(--primary)) 0%, var(--brand-ink, var(--brand-ink)) 100%);
}
.hero-brand { display: flex; align-items: center; gap: 12px; }
.hero-mark { width: 44px; height: 44px; border-radius: 13px; background: linear-gradient(135deg, #4863e0, #2a35a0); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; box-shadow: 0 6px 18px rgba(0,0,0,.3); }
.hero-name { font-size: 19px; font-weight: 800; letter-spacing: -.02em; }
.hero-sub { font-size: 11px; opacity: .7; text-transform: uppercase; letter-spacing: 1.4px; font-weight: 600; }

.hero-mid { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 24px 0; }
.hero-mid h1 { font-size: 34px; line-height: 1.18; font-weight: 800; letter-spacing: -.02em; margin: 0 0 14px; }
.hero-mid > p { font-size: 14.5px; opacity: .85; line-height: 1.6; max-width: 460px; margin: 0 0 20px; }
.hero-feats { list-style: none; padding: 0; margin: 0 0 26px; }
.hero-feats li { font-size: 13.5px; opacity: .9; margin: 8px 0; }
.hero-shot { border-radius: 14px; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.45); border: 1px solid rgba(255,255,255,.15); max-width: 560px; }
.hero-shot img { width: 100%; display: block; }

.hero-foot { font-size: 12px; opacity: .6; }

/* ── RIGHT panel ── */
.auth-panel { display: flex; align-items: center; justify-content: center; padding: 40px 28px; background: var(--surface, #fff); }
.auth-form { width: 100%; max-width: 400px; }

.form-brand { display: none; align-items: center; gap: 9px; margin-bottom: 24px; }
.fb-mark { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--brand-700, #283593), var(--brand-900, var(--primary))); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; }
.form-brand span { font-size: 17px; font-weight: 800; color: var(--brand-900, var(--primary)); }

h2 { margin: 0 0 6px; font-size: 25px; color: var(--text, #15181E); letter-spacing: -.01em; }
.muted { color: var(--text-3, #8a93a0); font-size: 13.5px; margin: 0 0 26px; line-height: 1.5; }

.field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.field label { font-size: 11px; font-weight: 700; color: var(--text-2, #5b6472); text-transform: uppercase; letter-spacing: .5px; }
.field input { padding: 12px 14px; border: 1px solid var(--border-2, #d9dee6); border-radius: var(--r, 9px); font-size: 14px; transition: all .15s; background: var(--surface-2, #f7f9fc); }
.field input:focus { outline: none; border-color: var(--brand-500, var(--primary)); box-shadow: 0 0 0 3px var(--brand-50, #eef1fe); background: #fff; }

.error-msg { background: var(--danger-bg, #ffebee); border: 1px solid var(--danger-bd, #ef9a9a); color: var(--danger, #c62828); padding: 10px 14px; border-radius: var(--r-sm, 7px); font-size: 13px; margin-bottom: 15px; }

.btn-login {
  width: 100%; padding: 13px; color: #fff; border: none; border-radius: var(--r, 9px);
  background: linear-gradient(135deg, var(--brand-700, #283593), var(--brand-900, var(--primary)));
  font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: 0 6px 18px rgba(26,35,126,.25);
  transition: transform .15s, box-shadow .15s, opacity .15s;
}
.btn-login:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(26,35,126,.32); }
.btn-login:disabled { opacity: .6; cursor: not-allowed; }

.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.switch { margin-top: 16px; font-size: 13px; color: var(--text-3, #8a93a0); text-align: center; }
.switch a { color: var(--brand-700, #283593); font-weight: 600; text-decoration: none; }
.switch a:hover { text-decoration: underline; }

.demo-accounts { margin-top: 26px; border-top: 1px solid var(--border, #eef1f5); padding-top: 18px; }
.demo-title { font-size: 11px; color: var(--text-3, #8a93a0); text-transform: uppercase; letter-spacing: .6px; margin-bottom: 10px; font-weight: 600; }
.demo-grid { display: flex; flex-wrap: wrap; gap: 7px; }
.demo-chip { padding: 7px 13px; border: 1px solid var(--border-2, #d9dee6); background: var(--surface-2, #f7f9fc); border-radius: 99px; font-size: 12px; color: var(--text-2, #5b6472); cursor: pointer; font-weight: 500; transition: all .15s; }
.demo-chip:hover { border-color: var(--brand-400, #6478f0); color: var(--brand-700, #283593); background: var(--brand-50, #eef1fe); }

/* ── Responsive: stack, hide hero on small screens ── */
@media (max-width: 880px) {
  .auth { grid-template-columns: 1fr; }
  .auth-hero { display: none; }
  .form-brand { display: flex; }
  .auth-panel { min-height: 100vh; }
}
</style>
