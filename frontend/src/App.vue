<template>
  <login-screen v-if="!loggedIn" @logged-in="onLogin" />
  <subscription-gate v-else-if="subInactive" :info="subInfo" @logout="onLogout" @retry="onRetry" />
  <app-shell v-else @logout="onLogout" />
  <ui-host />
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import LoginScreen from './components/LoginScreen.vue'
import AppShell from './components/AppShell.vue'
import SubscriptionGate from './components/SubscriptionGate.vue'
import UiHost from './components/UiHost.vue'
import authService from './services/authService.js'

const loggedIn = ref(authService.isLoggedIn())
const subInactive = ref(false)
const subInfo = ref({})

function onLogin() { loggedIn.value = true; subInactive.value = false }
async function onLogout() {
  await authService.logout()
  loggedIn.value = false
  subInactive.value = false
}
function onRetry() { subInactive.value = false; window.location.reload() }

// Session expired (401 caught by the global axios interceptor) — drop to login.
function onSessionExpired() { loggedIn.value = false }
// Subscription/trial inactive (402) — show the renew/upgrade gate.
function onSubInactive(e) { subInfo.value = e.detail ?? {}; subInactive.value = true }

onMounted(() => {
  window.addEventListener('auth:expired', onSessionExpired)
  window.addEventListener('subscription:inactive', onSubInactive)
})
onBeforeUnmount(() => {
  window.removeEventListener('auth:expired', onSessionExpired)
  window.removeEventListener('subscription:inactive', onSubInactive)
})
</script>
