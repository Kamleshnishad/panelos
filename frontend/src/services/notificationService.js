import { api } from './api.js'

export default {
  get()           { return api.get('/settings/notifications').then(r => r.data) },
  save(data)      { return api.put('/settings/notifications', data).then(r => r.data) },
  test(channel, phone) { return api.post('/settings/notifications/test', { channel, phone }).then(r => r.data) },
  testEmail(mailbox = 'main') { return api.post('/settings/notifications/test-email', { mailbox }).then(r => r.data) },
  logs()          { return api.get('/settings/notifications/logs').then(r => r.data) },
}
