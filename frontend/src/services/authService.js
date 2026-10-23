import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

const EXPIRY_KEY = 'token_expires_at'

/**
 * Keep the token AND when it dies. The server tells us a DURATION (expires_in,
 * seconds) rather than a timestamp, so we work out the end from OUR clock plus
 * that duration — a wrong clock on the user's machine then cannot make the
 * session warning fire early or never. The server stays the authority: a dead
 * token still returns 401 UNAUTHENTICATED whatever we believe.
 */
function storeSession(data) {
  if (!data?.token) return
  localStorage.setItem('token', data.token)
  if (data.expires_in) {
    localStorage.setItem(EXPIRY_KEY, String(Date.now() + data.expires_in * 1000))
  }
}

export default {
  async login(email, password) {
    const res = await axios.post('/api/auth/login', { email, password }, {
      headers: { Accept: 'application/json' },
    })
    const data = res.data?.data ?? {}
    if (data.token) {
      storeSession(data)
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
      storeSession(data)
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

  // Sliding session (OPS-H2): exchange the current valid token for a fresh one,
  // resetting its lifetime. Non-fatal on failure — a genuinely-expired token is
  // handled by the 401 interceptor (→ login).
  async refreshToken() {
    try {
      const res = await axios.post('/api/auth/refresh-token', {}, { headers: authHeaders(), silent: true })
      const data = res.data?.data ?? {}
      storeSession(data)
      return data.token ? data : null
    } catch { return null }
  },

  /** Seconds until the stored token dies, or null if we do not know (a session that began before this was tracked). */
  secondsLeft() {
    const end = Number(localStorage.getItem(EXPIRY_KEY))
    if (!end) return null
    return Math.floor((end - Date.now()) / 1000)
  },

  /**
   * Drop the session locally and remember WHY, so the login screen can say so
   * instead of just appearing. Used when the server says the token is dead and
   * when we run out of time ourselves.
   */
  endSession(reason = 'expired') {
    ;['token', 'user', 'user_id', EXPIRY_KEY].forEach((k) => localStorage.removeItem(k))
    try { sessionStorage.setItem('session_ended', reason) } catch { /* private mode */ }
  },

  async register(payload) {
    const res = await axios.post('/api/auth/register', payload, { headers: { Accept: 'application/json' } })
    const data = res.data?.data ?? {}
    if (data.token) {
      storeSession(data)
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
    ;['token', 'user', 'user_id', EXPIRY_KEY,
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
