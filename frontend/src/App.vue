<template>
  <login-screen v-if="!loggedIn" @logged-in="onLogin" />
  <app-shell v-else @logout="onLogout" />
  <ui-host />
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import LoginScreen from './components/LoginScreen.vue'
import AppShell from './components/AppShell.vue'
import UiHost from './components/UiHost.vue'
import authService from './services/authService.js'

const loggedIn = ref(authService.isLoggedIn())

function onLogin() { loggedIn.value = true }
async function onLogout() {
  await authService.logout()
  loggedIn.value = false
}

// Session expired (401 caught by the global axios interceptor) — drop to login.
function onSessionExpired() { loggedIn.value = false }
onMounted(() => window.addEventListener('auth:expired', onSessionExpired))
onBeforeUnmount(() => window.removeEventListener('auth:expired', onSessionExpired))
</script>
