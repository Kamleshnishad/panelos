<template>
  <landing v-if="!loggedIn && !showAuth" @sign-in="openAuth('login')" @start-trial="openAuth('signup')" />
  <login-screen v-else-if="!loggedIn" :start-mode="authMode" @logged-in="onLogin" @back="showAuth = false" />
  <subscription-gate v-else-if="subInactive" :info="subInfo" @logout="onLogout" @retry="onRetry" />
  <app-shell v-else @logout="onLogout" />
  <ui-host />
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import Landing from './components/Landing.vue'
import LoginScreen from './components/LoginScreen.vue'
import AppShell from './components/AppShell.vue'
import SubscriptionGate from './components/SubscriptionGate.vue'
import UiHost from './components/UiHost.vue'
import authService from './services/authService.js'

const loggedIn = ref(authService.isLoggedIn())
const subInactive = ref(false)
const subInfo = ref({})
// Marketing landing is the default screen for visitors; clicking Sign in / Start
// trial reveals the auth form (login vs signup mode).
const showAuth = ref(false)
const authMode = ref('login')

function openAuth(mode) { authMode.value = mode; showAuth.value = true }

function onLogin() { loggedIn.value = true; subInactive.value = false; showAuth.value = false }
async function onLogout() {
  await authService.logout()
  loggedIn.value = false
  subInactive.value = false
  showAuth.value = false
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
