import { defineStore } from 'pinia'
import api from '@/services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(localStorage.getItem('user') || 'null'),
    token: localStorage.getItem('token') || null,
  }),
  getters: {
    isLoggedIn: s => !!s.token,
    userName: s => s.user?.name || 'User',
    userRole: s => s.user?.role?.name || (s.user?.is_super_admin ? 'Super Admin' : 'User'),
  },
  actions: {
    async login(email, password) {
      const { data } = await api.post('/auth/login', { email, password })
      this.token = data.data.token
      this.user = data.data.user
      localStorage.setItem('token', this.token)
      localStorage.setItem('user', JSON.stringify(this.user))
    },
    async logout() {
      try { await api.post('/auth/logout') } catch {}
      this.token = null
      this.user = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
    },
  }
})
