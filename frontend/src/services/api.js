import axios from 'axios'
import authService from './authService.js'

const api = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (res) => res,
  (error) => {
    const status = error?.response?.status
    const url = error?.config?.url || ''
    const hadToken = !!localStorage.getItem('token')

    // Only a 401 that says UNAUTHENTICATED means "this token is dead". Any other
    // 401 is a different complaint and must not end the session — change-password
    // used to return 401 for a wrong current password, which signed people out
    // for a typo.
    const tokenIsDead = status === 401
      && error?.response?.data?.error_code === 'UNAUTHENTICATED'
      && hadToken
      && !url.includes('/auth/login')

    if (tokenIsDead) {
      // Another tab may have refreshed since this request was signed: the stored
      // token is newer than the one we sent. That is not an expired session, so
      // retry once with the current token instead of throwing the user out.
      const sent = error.config?.headers?.Authorization
      const current = localStorage.getItem('token')
      if (current && sent && sent !== `Bearer ${current}` && !error.config._retriedWithNewToken) {
        error.config._retriedWithNewToken = true
        error.config.headers.Authorization = `Bearer ${current}`
        return api.request(error.config)
      }

      authService.endSession('expired')
      window.dispatchEvent(new CustomEvent('auth:expired'))
    }

    if (status === 402 && error?.response?.data?.error_code === 'SUBSCRIPTION_INACTIVE') {
      window.dispatchEvent(new CustomEvent('subscription:inactive', { detail: error.response.data }))
    }
    return Promise.reject(error)
  }
)

export { api }
export default api
