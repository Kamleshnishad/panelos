<template>
  <div class="nb" ref="root">
    <button class="icon-btn" :aria-label="count ? `Notifications, ${count} unread` : 'Notifications'"
            aria-haspopup="true" :aria-expanded="open" title="Notifications" @click="toggle">
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span v-if="count" class="nb-badge">{{ count > 99 ? '99+' : count }}</span>
    </button>

    <div v-if="open" class="nb-panel" role="dialog" aria-label="Notifications">
      <div class="nb-head">
        <span class="nb-title">Notifications</span>
        <button class="nb-link" :disabled="!count" @click="readAll">Mark all read</button>
      </div>

      <div v-if="loading && !items.length" class="nb-empty">Loading…</div>
      <div v-else-if="failed" class="nb-empty nb-fail">Could not load notifications. <button class="nb-link" @click="load">Retry</button></div>
      <div v-else-if="!items.length" class="nb-empty">
        <div class="nb-empty-icon" aria-hidden="true">✓</div>
        You're all caught up.
      </div>
      <ul v-else class="nb-list">
        <li v-for="n in items" :key="n.id">
          <button class="nb-item" :class="{ unread: !n.read }" @click="openItem(n)">
            <span class="nb-sev" :class="n.severity" aria-hidden="true"></span>
            <span class="nb-main">
              <span class="nb-item-title">{{ n.title }}</span>
              <span v-if="n.body" class="nb-item-body">{{ n.body }}</span>
              <span class="nb-time">{{ ago(n.created_at) }}</span>
            </span>
            <span v-if="!n.read" class="nb-unread" aria-label="Unread"></span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import appNotificationService from '../services/appNotificationService.js'

const emit = defineEmits(['navigate'])

const POLL_MS = 60_000
const root    = ref(null)
const open    = ref(false)
const count   = ref(0)
const items   = ref([])
const loading = ref(false)
const failed  = ref(false)
let timer = null

async function refreshCount() {
  try { count.value = await appNotificationService.unreadCount() } catch { /* keep the last value */ }
}

async function load() {
  loading.value = true; failed.value = false
  try {
    items.value = (await appNotificationService.list({ per_page: 20 }))?.data ?? []
    await refreshCount()
  } catch { failed.value = true }
  finally { loading.value = false }
}

function toggle() {
  open.value = !open.value
  if (open.value) load()
}

async function openItem(n) {
  open.value = false
  if (!n.read) {
    n.read = true
    count.value = Math.max(0, count.value - 1)
    try { await appNotificationService.markRead(n.id) } catch { refreshCount() }
  }
  if (n.link_section) emit('navigate', n.link_section, n.link_id)
}

async function readAll() {
  items.value.forEach(n => { n.read = true })
  count.value = 0
  try { await appNotificationService.markAllRead() } catch { load() }
}

function ago(iso) {
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)} min ago`
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`
  return `${Math.floor(s / 86400)} d ago`
}

// Polling is paused while the tab is hidden and catches up the moment it returns.
function start() { stop(); timer = setInterval(() => { if (!document.hidden) { refreshCount(); if (open.value) load() } }, POLL_MS) }
function stop()  { if (timer) { clearInterval(timer); timer = null } }
function onVisible() { if (!document.hidden) refreshCount() }
function onDocClick(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
function onKey(e) { if (e.key === 'Escape') open.value = false }

onMounted(() => {
  refreshCount(); start()
  document.addEventListener('visibilitychange', onVisible)
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  stop()
  document.removeEventListener('visibilitychange', onVisible)
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKey)
})
</script>

<style scoped>
.nb { position: relative; }
.icon-btn { position: relative; width: 40px; height: 40px; border: 1px solid var(--border); background: var(--surface); border-radius: var(--r); color: var(--text-2); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all var(--t-fast); }
.icon-btn:hover { background: var(--surface-3); color: var(--text); }
.nb-badge { position: absolute; top: -6px; right: -6px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--danger); color: #fff; font-size: 10.5px; font-weight: 800; line-height: 18px; text-align: center; border: 2px solid var(--surface); box-sizing: content-box; }

.nb-panel { position: absolute; top: 48px; right: 0; width: 380px; max-width: calc(100vw - 24px); background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: var(--shadow-lg); z-index: 2500; overflow: hidden; }
.nb-head { display: flex; align-items: center; justify-content: space-between; padding: 13px 16px; border-bottom: 1px solid var(--border); }
.nb-title { font-size: 14px; font-weight: 800; color: var(--ink); }
.nb-link { background: none; border: none; color: var(--primary); font-size: 12.5px; font-weight: 700; cursor: pointer; padding: 0; }
.nb-link:disabled { color: var(--text-faint); cursor: default; }
.nb-link:not(:disabled):hover { text-decoration: underline; }

.nb-list { list-style: none; margin: 0; padding: 0; max-height: 420px; overflow-y: auto; }
.nb-item { width: 100%; display: flex; align-items: flex-start; gap: 11px; text-align: left; background: var(--surface); border: none; border-bottom: 1px solid var(--row); padding: 12px 16px; cursor: pointer; font-family: inherit; }
.nb-item:hover { background: var(--surface-2); }
.nb-item.unread { background: var(--primary-tint); }
.nb-item.unread:hover { background: #E3E9FD; }
.nb-sev { width: 8px; height: 8px; border-radius: 50%; margin-top: 6px; flex-shrink: 0; background: var(--info); }
.nb-sev.warning { background: var(--warning); }
.nb-sev.danger { background: var(--danger); }
.nb-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.nb-item-title { font-size: 13.5px; font-weight: 700; color: var(--ink); }
.nb-item-body { font-size: 12.5px; color: var(--text-2); line-height: 1.4; }
.nb-time { font-size: 11.5px; color: var(--text-3); margin-top: 2px; }
.nb-unread { width: 8px; height: 8px; border-radius: 50%; background: var(--primary); margin-top: 6px; flex-shrink: 0; }

.nb-empty { padding: 34px 16px; text-align: center; color: var(--text-2); font-size: 13px; }
.nb-empty-icon { width: 36px; height: 36px; margin: 0 auto 8px; border-radius: 50%; background: var(--success-bg); color: var(--success); font-weight: 800; display: flex; align-items: center; justify-content: center; }
.nb-fail { color: var(--danger); }
</style>
