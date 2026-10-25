<template>
  <div class="bd" aria-label="Lead board">
    <p v-if="error" class="bd-error" role="alert">{{ error }}</p>
    <div class="bd-cols">
      <section v-for="c in columns" :key="c.status" class="bd-col" :class="[c.status, { over: overCol === c.status }]" :aria-label="label(c.status)"
               @dragover.prevent="overCol = c.status" @dragleave="overCol = ''" @drop.prevent="drop(c.status)">
        <header>
          <h4>{{ label(c.status) }} <span class="bd-n">{{ c.count }}</span></h4>
          <small v-if="c.probability != null" class="bd-p" :title="'Chance of winning a lead in this stage'">{{ c.probability }}%</small>
          <small v-if="c.est_qty_sqm || c.est_value">{{ c.est_qty_sqm ? fmt(c.est_qty_sqm) + ' sqm' : '' }}{{ c.est_qty_sqm && c.est_value ? ' · ' : '' }}{{ c.est_value ? '₹ ' + fmt(c.est_value) : '' }}</small>
        </header>
        <div v-for="l in c.leads" :key="l.id" class="bd-card" draggable="true" tabindex="0" role="button" :aria-label="'Open ' + l.lead_no + ' ' + l.contact_name"
             @dragstart="dragstart($event, l)" @dragend="dragging = null; overCol = ''" @click="$emit('open', l)" @keyup.enter="$emit('open', l)">
          <div class="bd-top"><span class="mono">{{ l.lead_no }}</span><span class="bd-src">{{ l.source }}</span></div>
          <div class="bd-name">{{ l.contact_name }}</div>
          <div v-if="l.company_name" class="bd-co">{{ l.company_name }}</div>
          <div class="bd-meta">
            <span v-if="l.est_qty_sqm">{{ fmt(l.est_qty_sqm) }} sqm</span>
            <span v-if="l.est_value">₹ {{ fmt(l.est_value) }}</span>
            <span v-if="l.next_follow_up_date" :class="{ late: isLate(l) }">{{ day(l.next_follow_up_date) }}</span>
          </div>
          <div class="bd-foot">
            <span class="bd-owner" :title="l.assigned_user?.name || 'Nobody has this lead'">{{ l.assigned_user ? initials(l.assigned_user.name) : '—' }}</span>
            <span v-if="l.quiet_days != null && l.quiet_days >= quietDays" class="bd-chip quiet">quiet {{ l.quiet_days }} d</span>
            <span v-else-if="l.response_waiting_minutes != null && l.response_waiting_minutes >= 60" class="bd-chip wait">waiting</span>
            <select class="bd-move" :aria-label="'Move ' + l.lead_no + ' to'" @click.stop @change="move(l, $event)">
              <option value="">Move…</option>
              <option v-for="s in targets(l.status)" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
          </div>
        </div>
        <p v-if="!c.leads.length" class="bd-empty">{{ dragging ? 'Drop here' : 'Nothing here' }}</p>
        <button v-if="c.more" class="bd-more" type="button" @click="$emit('open-list', c.status)">+ {{ c.more }} more: show them in the list</button>
      </section>
    </div>

    <div v-if="dragging" class="bd-drops" aria-hidden="true">
      <div v-for="d in DROPS" :key="d.key" class="bd-drop" :class="[d.key, { over: overCol === d.key }]" @dragover.prevent="overCol = d.key" @dragleave="overCol = ''" @drop.prevent="drop(d.key)">{{ d.label }}</div>
    </div>
    <p class="bd-hint">Drag a card to another stage, or use its Move menu. Won leads come from orders, so they are not on the board.</p>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import leadService from '../services/leadService.js'

const props = defineProps({ filters: { type: Object, default: () => ({}) }, quietDays: { type: Number, default: 7 } })
const emit = defineEmits(['open', 'move', 'open-list'])

const columns = ref([])
const error = ref('')
const dragging = ref(null)
const overCol = ref('')

// The columns are the company's own stages (name and chance of winning come from the server), then on hold.
const label = (s) => columns.value.find(c => c.status === s)?.name ?? s
const DROPS = [{ key: 'lost', label: 'Drop here: mark lost' }, { key: 'invalid', label: 'Drop here: junk' }]
const targets = (from) => [
  ...columns.value.filter(c => c.status !== 'on_hold').map(c => ({ key: c.status, label: c.name })),
  { key: 'on_hold', label: 'On hold…' }, { key: 'lost', label: 'Lost…' }, { key: 'invalid', label: 'Junk…' },
].filter(m => m.key !== from)

const fmt = (n) => Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })
const initials = (n) => String(n).split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase()
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' })
const day = (d) => new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
const isLate = (l) => String(l.next_follow_up_date).slice(0, 10) < today

async function load() {
  error.value = ''
  try { columns.value = (await leadService.board(props.filters))?.data?.columns ?? [] }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the board.' }
}

function dragstart(e, lead) {
  dragging.value = lead
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', String(lead.id))        // Firefox will not start a drag without data
}

function drop(to) {
  const lead = dragging.value
  dragging.value = null; overCol.value = ''
  if (lead && lead.status !== to) emit('move', { lead, to })
}

function move(lead, e) {
  const to = e.target.value
  e.target.value = ''
  if (to) emit('move', { lead, to })
}

watch(() => JSON.stringify(props.filters), load)
defineExpose({ load })
onMounted(load)
</script>

<style scoped>
.bd-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
.bd-cols { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(230px, 1fr); gap: 12px; overflow-x: auto; padding-bottom: 8px; align-items: start; }
.bd-col { background: #f3f5f8; border-radius: 10px; padding: 10px; min-height: 140px; border: 2px solid transparent; }
.bd-col.over { border-color: var(--primary); background: #eaf0ff; }
.bd-col header { margin-bottom: 8px; }
.bd-col h4 { margin: 0; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: #555; }
.bd-col.on_hold h4 { color: #8a5300; }
.bd-n { margin-left: 6px; background: #fff; border-radius: 9px; padding: 0 7px; font-size: 11px; }
.bd-col header small { color: #888; font-size: 11.5px; }
.bd-col header small.bd-p { margin-right: 8px; font-weight: 700; color: #5e6b7d; }
.bd-card { background: #fff; border: 1px solid #e3e6ec; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; cursor: grab; font-size: 12.5px; }
.bd-card:hover, .bd-card:focus { border-color: var(--primary); outline: none; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.bd-card:active { cursor: grabbing; }
.bd-top { display: flex; justify-content: space-between; color: #888; font-size: 11px; }
.bd-src { background: #eef1f5; border-radius: 8px; padding: 0 7px; }
.bd-name { font-weight: 700; margin-top: 3px; }
.bd-co { color: #666; }
.bd-meta { display: flex; gap: 8px; flex-wrap: wrap; color: #555; margin-top: 4px; font-size: 12px; }
.bd-meta .late { color: #c62828; font-weight: 600; }
.bd-foot { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
.bd-owner { width: 22px; height: 22px; border-radius: 50%; background: #dfe6f7; color: #3b5bdb; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
.bd-chip { font-size: 10.5px; font-weight: 700; border-radius: 9px; padding: 1px 7px; }
.bd-chip.quiet { background: #fdecea; color: #b3261e; }
.bd-chip.wait { background: #fff4e0; color: #8a5300; }
.bd-move { margin-left: auto; font-size: 11px; padding: 2px 4px; border: 1px solid #ddd; border-radius: 5px; background: #fff; max-width: 84px; }
.bd-empty { color: #aaa; font-size: 12px; text-align: center; margin: 14px 0; }
.bd-more { width: 100%; background: none; border: 1px dashed #c5cbd6; border-radius: 6px; padding: 6px; font-size: 12px; color: var(--primary); cursor: pointer; }
.bd-drops { position: sticky; bottom: 12px; display: flex; gap: 12px; margin-top: 10px; }
.bd-drop { flex: 1; text-align: center; padding: 16px; border: 2px dashed #c5cbd6; border-radius: 10px; background: #fff; font-weight: 600; color: #777; }
.bd-drop.lost.over { border-color: #c62828; background: #fdecea; color: #b3261e; }
.bd-drop.invalid.over { border-color: #6a1b9a; background: #f3e5f5; color: #6a1b9a; }
.bd-hint { color: #888; font-size: 12px; margin: 8px 0 0; }
.mono { font-variant-numeric: tabular-nums; }
@media (max-width: 1100px) { .bd-cols { grid-template-columns: repeat(5, 240px); } }
</style>
