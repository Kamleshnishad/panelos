import axios from 'axios'

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
    if (status === 401 && hadToken && !url.includes('/auth/login')) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      localStorage.removeItem('user_id')
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
