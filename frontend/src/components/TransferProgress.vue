<template>
  <!-- Top bar: any request that is taking a while. Cards: files being prepared. -->
  <div v-if="activity.busy" class="gp" role="progressbar" aria-label="Loading" aria-busy="true"><span class="gp-bar"></span></div>

  <div v-if="transfers.length" class="tp-stack" role="status" aria-live="polite">
    <div v-for="t in transfers" :key="t.id" class="tp-card" :class="{ done: t.phase === 'done', failed: t.failed }">
      <div class="tp-row">
        <span class="tp-label">{{ t.label }}</span>
        <span class="tp-status">{{ statusText(t) }}</span>
        <button v-if="t.failed" class="tp-x" aria-label="Dismiss" @click="dismissTransfer(t.id)">×</button>
      </div>
      <div class="tp-track" role="progressbar" :aria-valuenow="Math.round(t.percent)" aria-valuemin="0" aria-valuemax="100" :aria-label="t.label">
        <div class="tp-fill" :style="{ width: t.percent + '%' }"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { transfers, dismissTransfer } from '../services/transfers.js'
import { activity } from '../services/activity.js'

function statusText(t) {
  if (t.failed) return 'Failed'
  if (t.phase === 'done') return 'Ready'
  if (t.phase === 'downloading') return `Downloading ${Math.round(t.percent)}%`
  return `Preparing… ${Math.round(t.percent)}%`
}
</script>

<style scoped>
.gp { position: fixed; top: 0; left: 0; right: 0; height: 3px; z-index: 10001; overflow: hidden; background: var(--primary-bd); pointer-events: none; }
.gp-bar { position: absolute; top: 0; bottom: 0; width: 40%; background: var(--primary); border-radius: 2px; animation: gp-slide 1.1s ease-in-out infinite; }
@keyframes gp-slide { 0% { left: -40%; } 100% { left: 100%; } }

.tp-stack { position: fixed; right: 16px; bottom: 16px; z-index: 9998; display: flex; flex-direction: column; gap: 10px; width: 320px; max-width: calc(100vw - 32px); }
.tp-card { background: var(--surface); border: 1px solid var(--border); border-left: 4px solid var(--primary); border-radius: var(--r); box-shadow: var(--shadow-lg); padding: 11px 14px 12px; }
.tp-card.done { border-left-color: var(--success); }
.tp-card.failed { border-left-color: var(--danger); }
.tp-row { display: flex; align-items: baseline; gap: 10px; margin-bottom: 8px; }
.tp-label { flex: 1; min-width: 0; font-size: 13px; font-weight: 700; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tp-status { font-size: 12px; color: var(--text-2); font-variant-numeric: tabular-nums; white-space: nowrap; }
.tp-card.done .tp-status { color: var(--success); font-weight: 700; }
.tp-card.failed .tp-status { color: var(--danger); font-weight: 700; }
.tp-x { background: none; border: none; color: var(--text-3); font-size: 18px; line-height: 1; cursor: pointer; padding: 0 2px; }
.tp-track { height: 6px; border-radius: 3px; background: var(--surface-3); overflow: hidden; }
.tp-fill { height: 100%; border-radius: 3px; background: var(--primary); transition: width 160ms linear; }
.tp-card.done .tp-fill { background: var(--success); }
.tp-card.failed .tp-fill { background: var(--danger); }

@media (prefers-reduced-motion: reduce) {
  .gp-bar { animation: none; width: 100%; left: 0; opacity: .6; }
  .tp-fill { transition: none; }
}
</style>
