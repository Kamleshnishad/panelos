<template>
  <!-- Toasts -->
  <div class="toast-stack" role="status" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" :class="['toast', `toast--${t.type}`]">
      <span class="toast__msg">{{ t.message }}</span>
      <button class="toast__close" aria-label="Dismiss notification" @click="dismissToast(t.id)">×</button>
    </div>
  </div>

  <!-- Confirm dialog -->
  <div v-if="confirmState.open" class="confirm-overlay" @click.self="resolveConfirm(false)">
    <div class="confirm-box" role="alertdialog" aria-modal="true" :aria-label="confirmState.title">
      <h3 class="confirm-title">{{ confirmState.title }}</h3>
      <p class="confirm-msg">{{ confirmState.message }}</p>
      <div class="confirm-actions">
        <button class="btn btn--ghost" @click="resolveConfirm(false)">{{ confirmState.cancelLabel }}</button>
        <button :class="['btn', confirmState.danger ? 'btn--danger' : 'btn--primary']" @click="resolveConfirm(true)">{{ confirmState.confirmLabel }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { toasts, dismissToast, confirmState, resolveConfirm } from '../services/ui.js'
</script>

<style scoped>
.confirm-overlay {
  position: fixed; inset: 0; background: rgba(16,24,40,0.45);
  display: flex; align-items: center; justify-content: center; z-index: 10000;
  animation: fade-in 150ms ease;
}
.confirm-box {
  background: var(--surface); border-radius: var(--r-lg); padding: 22px 24px;
  width: 420px; max-width: calc(100vw - 32px); box-shadow: var(--shadow-lg);
}
.confirm-title { margin: 0 0 8px; font-size: 16px; color: var(--ink); }
.confirm-msg { margin: 0 0 18px; font-size: 13.5px; color: var(--text-2); line-height: 1.5; white-space: pre-line; }
.confirm-actions { display: flex; justify-content: flex-end; gap: 10px; }
@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
</style>
