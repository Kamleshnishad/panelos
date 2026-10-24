<template>
  <div ref="root" class="search gs" role="search">
    <svg class="s-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="m20 20-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    <input
      ref="input"
      v-model="q"
      type="search"
      role="combobox"
      autocomplete="off"
      spellcheck="false"
      aria-label="Search quotations, orders, invoices, customers and screens"
      aria-autocomplete="list"
      :aria-expanded="open"
      aria-controls="gs-list"
      :aria-activedescendant="open && flat.length ? 'gs-opt-' + active : undefined"
      placeholder="Search quotations, orders, customers…"
      @focus="open = !!q.trim()"
      @keydown="onKey"
    />
    <span v-if="!q" class="kbd" aria-hidden="true">/</span>

    <div v-if="open" id="gs-list" class="gs-panel" role="listbox" aria-label="Search results">
      <div v-if="state === 'loading' && !flat.length" class="gs-note" role="status">Searching…</div>
      <div v-else-if="state === 'error' && !flat.length" class="gs-note gs-err" role="status">Search is unavailable right now. Try again in a moment.</div>
      <div v-else-if="!flat.length" class="gs-note" role="status">Nothing matches “{{ q.trim() }}”.</div>

      <template v-for="g in view" :key="g.label">
        <div class="gs-group">{{ g.label }}</div>
        <div
          v-for="o in g.options"
          :id="'gs-opt-' + o.index"
          :key="g.label + o.index"
          role="option"
          :aria-selected="o.index === active"
          :class="['gs-opt', { on: o.index === active }]"
          @mousedown.prevent="choose(o)"
          @mousemove="active = o.index"
        >
          <span class="gs-title">{{ o.title }}</span>
          <span v-if="o.subtitle" class="gs-sub">{{ o.subtitle }}</span>
          <span v-if="o.meta" class="gs-meta">{{ o.meta }}</span>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import searchService from '../services/searchService.js'

// Records come from the server (and only the kinds the role may open). The screens the user may open are matched here,
// so one box replaces the old "jump to a section" field without losing it.
const props = defineProps({ sections: { type: Array, default: () => [] } })   // [{ key, label }]
const emit = defineEmits(['section', 'open'])

const root = ref(null)
const input = ref(null)
const q = ref('')
const open = ref(false)
const active = ref(0)
const state = ref('idle')          // idle | loading | ready | error
const groups = ref([])
let timer = null
let seq = 0

const sectionHits = computed(() => {
  const t = q.value.trim().toLowerCase()
  if (t.length < 2) return []
  return props.sections.filter(s => s.label.toLowerCase().includes(t) || s.key.includes(t)).slice(0, 3)
})

// One flat list drives both the picture and the keyboard.
const view = computed(() => {
  let index = 0
  const out = []
  if (sectionHits.value.length) {
    out.push({ label: 'Go to', options: sectionHits.value.map(s => ({ index: index++, kind: 'section', key: s.key, title: s.label, subtitle: '', meta: '' })) })
  }
  for (const g of groups.value) {
    out.push({ label: g.label, options: g.items.map(i => ({ index: index++, kind: 'record', section: g.section, id: i.id, title: i.title, subtitle: i.subtitle, meta: i.meta })) })
  }
  return out
})
const flat = computed(() => view.value.flatMap(g => g.options))

watch(q, (value) => {
  clearTimeout(timer)
  const term = value.trim()
  open.value = !!term
  active.value = 0
  if (term.length < 2) { groups.value = []; state.value = 'idle'; seq++; return }
  state.value = 'loading'
  timer = setTimeout(run, 250)
})

async function run() {
  const mine = ++seq
  const term = q.value.trim()
  try {
    const res = await searchService.search(term)
    if (mine !== seq) return                       // a newer keystroke already asked again
    groups.value = res?.data?.groups ?? []
    state.value = 'ready'
  } catch {
    if (mine !== seq) return
    groups.value = []
    state.value = 'error'
  }
  active.value = 0
}

function close() { open.value = false }
function reset() { q.value = ''; groups.value = []; state.value = 'idle'; open.value = false }

function choose(o) {
  if (!o) return
  if (o.kind === 'section') emit('section', o.key)
  else emit('open', o.section, o.id)
  reset()
  input.value?.blur()
}

function onKey(e) {
  if (e.key === 'Escape') { if (open.value) { close() } else { input.value?.blur() } return }
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    if (!flat.value.length) return
    e.preventDefault()
    open.value = true
    const n = flat.value.length
    active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n
    return
  }
  if (e.key === 'Enter') {
    e.preventDefault()
    if (state.value === 'loading' && !flat.value.length) return   // results are on their way; Enter again
    choose(flat.value[active.value])
  }
}

// "/" or Ctrl/Cmd+K from anywhere outside a text field puts the cursor in the box.
function onGlobalKey(e) {
  const t = e.target
  const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)
  if ((e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
    e.preventDefault()
    input.value?.focus()
    input.value?.select()
  }
}
function onDocClick(e) { if (root.value && !root.value.contains(e.target)) close() }

onMounted(() => { document.addEventListener('keydown', onGlobalKey); document.addEventListener('mousedown', onDocClick) })
onBeforeUnmount(() => { document.removeEventListener('keydown', onGlobalKey); document.removeEventListener('mousedown', onDocClick); clearTimeout(timer) })
</script>

<style scoped>
.gs { position: relative; }
.s-icon { color: var(--text-3); flex-shrink: 0; }
.gs input { border: none; background: none; outline: none; font-size: 13.5px; flex: 1; color: var(--text); min-width: 0; }
.gs input::-webkit-search-cancel-button { display: none; }
.kbd { font-size: 11px; color: var(--text-3); background: var(--surface); border: 1px solid var(--border-2); border-radius: 5px; padding: 1px 6px; font-weight: 600; }

.gs-panel { position: absolute; top: calc(100% + 6px); left: 0; width: 480px; max-width: 92vw; max-height: 70vh; overflow-y: auto; background: #fff; border: 1px solid var(--border-2, #dfe3ea); border-radius: 10px; box-shadow: 0 12px 32px rgba(15, 23, 42, .16); padding: 6px; z-index: 60; }
.gs-note { padding: 14px 12px; font-size: 13px; color: var(--text-2); }
.gs-err { color: #b42318; }
.gs-group { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-3); padding: 8px 10px 4px; }
.gs-opt { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 2px 10px; align-items: baseline; padding: 8px 10px; border-radius: 7px; cursor: pointer; }
.gs-opt.on { background: var(--accent-50, #eef2ff); }
.gs-title { font-weight: 600; font-size: 13.5px; color: var(--text); white-space: nowrap; }
.gs-sub { font-size: 12.5px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gs-meta { font-size: 12px; color: var(--text-3); white-space: nowrap; }
</style>
