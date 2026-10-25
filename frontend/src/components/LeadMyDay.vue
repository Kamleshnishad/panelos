<template>
  <div class="md" aria-label="My day">
    <div class="md-bar">
      <label v-if="!ownOnly">Whose day
        <select v-model="who" aria-label="Whose day" @change="load">
          <option value="me">Mine</option><option value="all">Everyone</option><option value="none">Nobody (unassigned)</option>
          <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
        </select>
      </label>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
    </div>
    <p v-if="error" class="md-error" role="alert">{{ error }}</p>
    <div v-if="loading && !day" class="md-empty">Loading…</div>

    <template v-else-if="day">
      <p v-if="!total" class="md-empty">Nothing planned for {{ who === 'me' ? 'you' : 'this view' }}. Open a lead and plan its next action so it does not go quiet.</p>
      <section v-for="b in buckets" v-show="day[b.key].length" :key="b.key" :class="['md-sec', b.key]" :aria-label="b.label">
        <h4>{{ b.label }} <span class="md-count">{{ day[b.key].length }}</span></h4>
        <ul>
          <li v-for="t in day[b.key]" :key="t.id">
            <span class="md-when">{{ b.key === 'today' ? (t.due_time || 'Any time') : shortDate(t.due_date) + (t.due_time ? ', ' + t.due_time : '') }}</span>
            <span class="md-type">{{ t.type_label }}</span>
            <button class="md-lead" type="button" @click="$emit('open-lead', t.lead.id)">
              <strong>{{ t.lead.lead_no }}</strong> {{ t.lead.contact_name }}<small v-if="t.lead.company_name"> · {{ t.lead.company_name }}</small>
            </button>
            <span class="md-what">{{ t.title || '' }}<small v-if="who !== 'me' && t.assignee"> · {{ t.assignee }}</small></span>
            <a v-if="t.lead.phone" class="md-tel" :href="'tel:' + t.lead.phone" :aria-label="'Call ' + t.lead.contact_name">{{ t.lead.phone }}</a>
            <button class="btn btn-primary sm" type="button" @click="finishing = t">Done</button>
          </li>
        </ul>
      </section>
      <p v-if="day.later" class="md-later">{{ day.later }} more planned beyond the next 7 days.</p>
    </template>

    <lead-task-done-dialog v-if="finishing" :task="finishing" :types="day?.types || {}" @close="finishing = null" @done="onDone" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import { toastSuccess } from '../services/ui.js'
import LeadTaskDoneDialog from './LeadTaskDoneDialog.vue'

defineProps({ users: { type: Array, default: () => [] }, ownOnly: { type: Boolean, default: false } })
const emit = defineEmits(['open-lead', 'changed', 'counted'])

const who = ref('me')
const day = ref(null)
const loading = ref(false)
const error = ref('')
const finishing = ref(null)
const buckets = [{ key: 'overdue', label: 'Overdue' }, { key: 'today', label: 'Today' }, { key: 'upcoming', label: 'Next 7 days' }]
const total = computed(() => day.value ? day.value.overdue.length + day.value.today.length + day.value.upcoming.length : 0)
const shortDate = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })

async function load() {
  loading.value = true; error.value = ''
  try {
    day.value = (await leadService.myDay({ assigned_to: who.value }))?.data ?? null
    if (who.value === 'me' && day.value) emit('counted', day.value.overdue.length + day.value.today.length)
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the list.' }
  finally { loading.value = false }
}

async function onDone() { finishing.value = null; toastSuccess('Done.'); await load(); emit('changed') }

defineExpose({ load })
onMounted(load)
</script>

<style scoped>
.md-bar { display: flex; gap: 12px; align-items: center; margin-bottom: 14px; font-size: 13px; color: #555; }
.md-bar select { margin-left: 6px; padding: 6px 9px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.md-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
.md-empty { text-align: center; color: #999; font-style: italic; padding: 40px; }
.md-sec { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 14px; overflow: hidden; }
.md-sec h4 { margin: 0; padding: 9px 14px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; background: var(--surface-2); color: #555; }
.md-sec.overdue h4 { background: #fdecea; color: #b3261e; }
.md-sec.today h4 { background: #fff4e0; color: #8a5300; }
.md-count { margin-left: 6px; font-weight: 600; }
.md-sec ul { list-style: none; margin: 0; padding: 0; }
.md-sec li { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 9px 14px; border-top: 1px solid #f0f0f0; font-size: 13px; }
.md-when { width: 92px; color: #555; font-variant-numeric: tabular-nums; }
.md-type { font-weight: 700; color: var(--primary); width: 96px; }
.md-lead { background: none; border: 0; padding: 0; font: inherit; color: #222; text-align: left; cursor: pointer; }
.md-lead:hover strong { text-decoration: underline; }
.md-lead small, .md-what small { color: #888; }
.md-what { flex: 1; color: #555; min-width: 120px; }
.md-tel { color: var(--primary); font-size: 12.5px; text-decoration: none; }
.md-later { color: #888; font-size: 12.5px; text-align: center; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 4px 12px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
