import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import api from './services/api'

const app = createApp(App)

// Make API available to all components
app.provide('api', api)

app.use(router)
app.mount('#app')
