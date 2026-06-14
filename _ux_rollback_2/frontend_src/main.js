import { createApp } from 'vue'
import axios from 'axios'
import App from './App.vue'
import './styles.css'

// Global auth guard: an expired/invalid Sanctum token must not leave the app
// looking "logged in". On any 401 (other than the login attempt itself) we clear
// the stored credentials and signal App.vue to return to the login screen.
axios.interceptors.response.use(
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
    return Promise.reject(error)
  }
)

createApp(App).mount('#app')
