import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

export default {
  async login(email, password) {
    const res = await axios.post('/api/auth/login', { email, password }, {
      headers: { Accept: 'application/json' },
    })
    const data = res.data?.data ?? {}
    if (data.token) {
      localStorage.setItem('token', data.token)
      if (data.user) {
        localStorage.setItem('user', JSON.stringify(data.user))
        localStorage.setItem('user_id', data.user.id)
      }
    }
    return data
  },

  async verifyOtp(email, code) {
    const res = await axios.post('/api/auth/verify-otp', { email, code }, { headers: { Accept: 'application/json' } })
    const data = res.data?.data ?? {}
    if (data.token) {
      localStorage.setItem('token', data.token)
      if (data.user) {
        localStorage.setItem('user', JSON.stringify(data.user))
        localStorage.setItem('user_id', data.user.id)
      }
    }
    return data
  },

  toggleTwoFactor(enabled) {
    return axios.post('/api/auth/two-factor', { enabled }, { headers: authHeaders() }).then(r => r.data)
  },

  async register(payload) {
    const res = await axios.post('/api/auth/register', payload, { headers: { Accept: 'application/json' } })
    const data = res.data?.data ?? {}
    if (data.token) {
      localStorage.setItem('token', data.token)
      if (data.user) {
        localStorage.setItem('user', JSON.stringify(data.user))
        localStorage.setItem('user_id', data.user.id)
      }
    }
    return data
  },

  async me() {
    const res = await axios.get('/api/auth/me', { headers: authHeaders() })
    const user = res.data?.data ?? res.data
    if (user) {
      localStorage.setItem('user', JSON.stringify(user))
      localStorage.setItem('user_id', user.id)
    }
    return user
  },

  async logout() {
    try {
      await axios.post('/api/auth/logout', {}, { headers: authHeaders() })
    } catch { /* ignore */ }
    // Clear primary session AND any stashed impersonation / super-admin shadow
    // tokens — otherwise the next user on this machine inherits stale state.
    ;['token', 'user', 'user_id',
      'impersonating', 'token_superadmin', 'user_superadmin']
      .forEach((k) => localStorage.removeItem(k))
  },

  isLoggedIn() {
    return !!localStorage.getItem('token')
  },

  currentUser() {
    try { return JSON.parse(localStorage.getItem('user') || 'null') } catch { return null }
  },
}
