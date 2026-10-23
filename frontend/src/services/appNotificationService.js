import { api } from './api.js'

// The header bell. (notificationService.js is the unrelated SMS/WhatsApp settings.)
export default {
  list(params = {}) { return api.get('/notifications', { params }).then(r => r.data) },
  unreadCount()     { return api.get('/notifications/unread-count').then(r => r.data?.data?.count ?? 0) },
  markRead(id)      { return api.post(`/notifications/${id}/read`).then(r => r.data) },
  markAllRead()     { return api.post('/notifications/read-all').then(r => r.data) },
}
