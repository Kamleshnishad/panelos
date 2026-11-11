<template>
  <div class="gd">
    <!-- Index -->
    <aside class="gd-index">
      <input v-model.trim="q" class="gd-search" type="search" placeholder="Search the guide…" aria-label="Search the guide" />
      <p v-if="q" class="gd-count">{{ shown.length }} {{ shown.length === 1 ? 'page' : 'pages' }} mention “{{ q }}”</p>
      <nav aria-label="Guide contents">
        <template v-for="g in groups" :key="g.name">
          <div class="gd-group">{{ g.name }}</div>
          <a v-for="s in g.items" :key="s.key" :href="'#g-' + s.key" :class="['gd-link', { on: current === s.key }]" @click.prevent="jump(s.key)">{{ s.title }}</a>
        </template>
      </nav>
    </aside>

    <!-- Content -->
    <div class="gd-body">
      <p v-if="!shown.length" class="gd-empty">Nothing in the guide matches “{{ q }}”.</p>

      <section v-for="s in shown" :id="'g-' + s.key" :key="s.key" class="gd-sec">
        <header class="gd-head">
          <div>
            <div class="gd-kicker">{{ s.group }}</div>
            <h2>{{ s.title }}</h2>
            <p class="gd-summary"><Rt :t="s.summary" /></p>
          </div>
          <button v-if="canOpen(s.key)" class="gd-open" @click="$emit('navigate', s.key)">Open this page →</button>
        </header>

        <div class="gd-meta">
          <div v-if="s.fits"><span>Where it fits</span><Rt :t="s.fits" /></div>
          <div v-if="s.prereqs?.length"><span>Before you start</span>
            <ul><li v-for="(p, i) in s.prereqs" :key="i"><Rt :t="p" /></li></ul>
          </div>
        </div>

        <figure v-if="s.img" class="gd-shot">
          <img :src="'/guide/' + s.img + '.png'" :alt="s.title + ' screen'" loading="lazy" @click="zoom = '/guide/' + s.img + '.png'" @error="e => (e.target.closest('figure').style.display = 'none')" />
          <figcaption>The {{ s.title }} screen · click to enlarge</figcaption>
        </figure>

        <template v-if="s.tasks?.length">
          <h3>How to</h3>
          <details v-for="(t, ti) in s.tasks" :key="ti" class="gd-task" :open="ti === 0 || !!q">
            <summary>{{ t.title }}</summary>
            <ol><li v-for="(st, si) in t.steps" :key="si"><Rt :t="st" /></li></ol>
          </details>
        </template>

        <template v-if="s.fields?.length">
          <h3>Fields and rules</h3>
          <div class="gd-table"><table>
            <thead><tr><th>Field</th><th>Rule</th><th>Notes</th></tr></thead>
            <tbody><tr v-for="(f, i) in s.fields" :key="i"><td class="b">{{ f.name }}</td><td><Rt :t="f.rule" /></td><td><Rt :t="f.note" /></td></tr></tbody>
          </table></div>
        </template>

        <template v-if="s.statuses?.length">
          <h3>Statuses</h3>
          <div class="gd-table"><table>
            <thead><tr><th>Status</th><th>Meaning</th><th>Can move to</th></tr></thead>
            <tbody><tr v-for="(st, i) in s.statuses" :key="i"><td class="b">{{ st.name }}</td><td><Rt :t="st.meaning" /></td><td><Rt :t="st.next" /></td></tr></tbody>
          </table></div>
        </template>

        <template v-if="s.cases?.length">
          <h3>Common cases</h3>
          <div class="gd-cases">
            <div v-for="(c, i) in s.cases" :key="i" class="gd-case"><b>{{ c.title }}</b><p><Rt :t="c.body" /></p></div>
          </div>
        </template>

        <template v-if="s.errors?.length">
          <h3>Messages you may see</h3>
          <div class="gd-table"><table>
            <thead><tr><th>Message</th><th>What to do</th></tr></thead>
            <tbody><tr v-for="(e, i) in s.errors" :key="i"><td class="gd-msg">{{ e.message }}</td><td><Rt :t="e.fix" /></td></tr></tbody>
          </table></div>
        </template>

        <template v-if="s.tips?.length">
          <h3>Tips</h3>
          <ul class="gd-tips"><li v-for="(t, i) in s.tips" :key="i"><Rt :t="t" /></li></ul>
        </template>

        <p v-if="s.access" class="gd-access"><b>Who can use it:</b> <Rt :t="s.access" /></p>
      </section>
    </div>

    <div v-if="zoom" class="gd-zoom" @click="zoom = null"><img :src="zoom" alt="Screenshot, enlarged" /></div>
  </div>
</template>

<script setup>
import { ref, computed, h, onMounted, watch, nextTick } from 'vue'
import { guideSections } from '../guide/index.js'

const props = defineProps({
  focus:   { type: String, default: null },        // page key to scroll to (from the header's ? button)
  canOpen: { type: Function, default: () => true }, // whether this user may open a page
})
defineEmits(['navigate'])

/** Plain text with **bold** for on-screen labels. */
const Rt = ({ t }) => String(t ?? '').split(/\*\*(.+?)\*\*/g).map((p, i) => (i % 2 ? h('b', p) : p))
Rt.props = ['t']

const q       = ref('')
const zoom    = ref(null)
const current = ref(null)

const haystack = s => JSON.stringify(s).toLowerCase()
const shown = computed(() => (q.value ? guideSections.filter(s => haystack(s).includes(q.value.toLowerCase())) : guideSections))
const groups = computed(() => {
  const out = []
  for (const s of shown.value) {
    let g = out.find(x => x.name === s.group)
    if (!g) out.push((g = { name: s.group, items: [] }))
    g.items.push(s)
  }
  return out
})

async function jump(key) {
  current.value = key
  await nextTick()
  document.getElementById('g-' + key)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  history.replaceState(history.state, '', '/guide#' + key)
}

onMounted(() => {
  const key = props.focus || decodeURIComponent(window.location.hash.replace(/^#/, ''))
  if (key && guideSections.some(s => s.key === key)) jump(key)
})
watch(() => props.focus, key => { if (key) jump(key) })
</script>

<style scoped>
.gd { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 28px; padding: 24px 32px 64px; max-width: 1400px; margin: 0 auto; }
.gd-index { position: sticky; top: 16px; align-self: start; max-height: calc(100vh - 140px); overflow-y: auto; }
.gd-search { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 13px; margin-bottom: 10px; }
.gd-count { font-size: 12px; color: var(--text-3); margin: 0 0 8px; }
.gd-group { font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-3); margin: 14px 0 4px; }
.gd-link { display: block; padding: 6px 10px; border-radius: 6px; font-size: 13px; color: var(--text-2); text-decoration: none; }
.gd-link:hover { background: var(--surface-2); color: var(--text); }
.gd-link.on { background: var(--primary-tint); color: var(--primary); font-weight: 600; }
.gd-body { min-width: 0; }
.gd-empty { color: var(--text-3); font-style: italic; }
.gd-sec { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 24px 28px; margin-bottom: 22px; box-shadow: var(--shadow-xs); scroll-margin-top: 16px; }
.gd-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.gd-kicker { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--primary); }
.gd-head h2 { margin: 2px 0 6px; font-size: 22px; color: var(--ink); letter-spacing: -.01em; }
.gd-summary { margin: 0; font-size: 14.5px; color: var(--text-2); line-height: 1.55; max-width: 760px; }
.gd-open { flex-shrink: 0; background: var(--primary); color: #fff; border: none; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer; }
.gd-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin: 18px 0 4px; }
.gd-meta > div { background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; font-size: 13px; color: var(--text-2); line-height: 1.5; }
.gd-meta span { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-3); margin-bottom: 4px; }
.gd-meta ul { margin: 0; padding-left: 18px; }
.gd-shot { margin: 18px 0 6px; }
.gd-shot img { width: 100%; border: 1px solid var(--border); border-radius: 10px; cursor: zoom-in; box-shadow: var(--shadow-xs); }
.gd-shot figcaption { font-size: 11.5px; color: var(--text-3); margin-top: 4px; }
h3 { font-size: 15px; color: var(--ink); margin: 22px 0 10px; }
.gd-task { border: 1px solid var(--border); border-radius: 8px; margin-bottom: 8px; background: #fff; }
.gd-task summary { cursor: pointer; padding: 10px 14px; font-weight: 600; font-size: 13.5px; color: var(--text); }
.gd-task[open] summary { border-bottom: 1px solid var(--border); }
.gd-task ol { margin: 10px 0 12px; padding-left: 34px; font-size: 13.5px; line-height: 1.6; color: var(--text-2); }
.gd-table { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-3); padding: 8px 10px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; color: var(--text-2); line-height: 1.5; }
td.b { font-weight: 600; color: var(--text); white-space: nowrap; }
td.gd-msg { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #b3261e; min-width: 220px; }
.gd-cases { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
.gd-case { border: 1px solid var(--border); border-left: 3px solid var(--primary); border-radius: 8px; padding: 10px 14px; background: #fff; }
.gd-case b { font-size: 13.5px; color: var(--text); }
.gd-case p { margin: 4px 0 0; font-size: 13px; color: var(--text-2); line-height: 1.5; }
.gd-tips { margin: 0; padding-left: 20px; font-size: 13.5px; color: var(--text-2); line-height: 1.6; }
.gd-access { margin: 18px 0 0; padding-top: 12px; border-top: 1px solid var(--border); font-size: 12.5px; color: var(--text-3); }
.gd-zoom { position: fixed; inset: 0; background: rgba(0,0,0,.75); display: flex; align-items: center; justify-content: center; z-index: 1200; cursor: zoom-out; padding: 24px; }
.gd-zoom img { max-width: 100%; max-height: 100%; border-radius: 8px; }
@media (max-width: 900px) {
  .gd { grid-template-columns: 1fr; padding: 16px; }
  .gd-index { position: static; max-height: none; }
  .gd-head { flex-direction: column; }
}
</style>
