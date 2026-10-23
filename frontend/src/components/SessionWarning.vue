<template>
  <!-- Shown only when the session is about to end and could not be renewed
       quietly: someone who has stepped away, or a renewal that failed. People who
       are working never see it. -->
  <div class="sw-wrap" role="alertdialog" aria-live="assertive" aria-labelledby="sw-title">
    <div class="sw-card">
      <div class="sw-icon" aria-hidden="true">⏳</div>
      <div class="sw-text">
        <div id="sw-title" class="sw-title">
          Your session ends in <span class="sw-count mono">{{ countdown }}</span>
        </div>
        <div class="sw-sub">
          For security you will be signed out. Anything unsaved will be lost.
          <span v-if="failed" class="sw-fail">Could not reach the server — check your connection and try again.</span>
        </div>
      </div>
      <div class="sw-actions">
        <button class="sw-stay" :disabled="renewing" @click="$emit('extend')">
          {{ renewing ? 'Extending…' : 'Stay signed in' }}
        </button>
        <button class="sw-out" @click="$emit('signout')">Sign out</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { formatCountdown } from '../composables/sessionPolicy.js'

const props = defineProps({
  secondsLeft: { type: Number, default: 0 },
  renewing:    { type: Boolean, default: false },
  failed:      { type: Boolean, default: false },
})
defineEmits(['extend', 'signout'])

const countdown = computed(() => formatCountdown(props.secondsLeft))
</script>

<style scoped>
.sw-wrap { position: fixed; top: 14px; left: 0; right: 0; z-index: 3000; display: flex; justify-content: center; padding: 0 16px; pointer-events: none; }
.sw-card {
  pointer-events: auto; display: flex; align-items: center; gap: 14px;
  background: #fff8e1; border: 1px solid #f0c36d; border-left: 5px solid #b5740a;
  border-radius: 12px; padding: 13px 18px; max-width: 760px; width: 100%;
  box-shadow: 0 10px 30px rgba(16, 24, 40, .22);
}
.sw-icon { font-size: 24px; line-height: 1; }
.sw-text { flex: 1; min-width: 0; }
.sw-title { font-size: 14.5px; font-weight: 800; color: #5c3a00; }
.sw-count { color: #b5740a; font-variant-numeric: tabular-nums; }
.sw-sub { font-size: 12.5px; color: #7a5a1c; margin-top: 2px; line-height: 1.45; }
.sw-fail { display: block; color: var(--danger, #c62828); font-weight: 600; margin-top: 2px; }
.sw-actions { display: flex; gap: 8px; flex-shrink: 0; }
.sw-stay { background: #b5740a; color: #fff; border: none; border-radius: 8px; padding: 9px 16px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.sw-stay:hover:not(:disabled) { background: #8f5a07; }
.sw-stay:disabled { opacity: .6; cursor: wait; }
.sw-out { background: transparent; color: #7a5a1c; border: 1px solid #e0c07a; border-radius: 8px; padding: 9px 13px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.sw-out:hover { background: #fdebc4; }
@media (max-width: 640px) {
  .sw-card { flex-wrap: wrap; }
  .sw-actions { width: 100%; }
  .sw-stay, .sw-out { flex: 1; }
}
</style>
