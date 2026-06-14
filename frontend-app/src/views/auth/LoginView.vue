<template>
  <div class="login-page">
    <div class="login-box">
      <div class="login-logo">
        <span>🏭</span>
        <h1>PanelOS</h1>
        <p>ERP for Steel Panel Manufacturing</p>
      </div>

      <form @submit.prevent="submit" class="login-form">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input v-model="email" type="email" class="form-control" placeholder="you@company.com" required />
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input v-model="password" type="password" class="form-control" placeholder="••••••••" required />
        </div>

        <div v-if="error" class="alert alert-error">{{ error }}</div>

        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px" :disabled="loading">
          {{ loading ? 'Signing in...' : 'Sign In' }}
        </button>
      </form>

      <div class="quick-logins">
        <p>Quick Login:</p>
        <div class="role-pills">
          <button v-for="r in roles" :key="r.email" @click="quickLogin(r)" class="role-pill">
            {{ r.label }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()
const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

const roles = [
  { label: 'Super Admin', email: 'superadmin@panelos.local', password: 'Admin@123' },
  { label: 'Company Admin', email: 'admin@panelos.local', password: 'Admin@123' },
  { label: 'Sales Manager', email: 'sales@panelos.local', password: 'Sales@123' },
  { label: 'Production Mgr', email: 'production@panelos.local', password: 'Prod@123' },
  { label: 'Accounts', email: 'accounts@panelos.local', password: 'Accounts@123' },
  { label: 'Viewer', email: 'viewer@panelos.local', password: 'Viewer@123' },
]

const quickLogin = (r) => { email.value = r.email; password.value = r.password }

const submit = async () => {
  loading.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    router.push('/dashboard')
  } catch (e) {
    error.value = e.response?.data?.message || 'Invalid credentials'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  background: linear-gradient(135deg, #1a237e 0%, #1976d2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.login-box {
  background: white;
  border-radius: 12px;
  padding: 40px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.login-logo {
  text-align: center;
  margin-bottom: 32px;
}
.login-logo span { font-size: 48px; }
.login-logo h1 { font-size: 28px; font-weight: 800; color: #1a237e; margin: 8px 0 4px; }
.login-logo p { color: #666; font-size: 13px; }
.login-form { margin-bottom: 24px; }
.quick-logins { border-top: 1px solid #eee; padding-top: 16px; }
.quick-logins p { font-size: 12px; color: #888; margin-bottom: 10px; text-align: center; }
.role-pills { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; }
.role-pill {
  padding: 4px 10px;
  background: #e3f2fd;
  color: #1565c0;
  border: 1px solid #90caf9;
  border-radius: 12px;
  font-size: 11px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.2s;
}
.role-pill:hover { background: #1976d2; color: white; border-color: #1976d2; }
</style>
