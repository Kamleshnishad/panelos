<template>
  <section class="rq" aria-label="What they want">
    <div class="rq-head">
      <label>What they want</label>
      <button v-if="!editing && !closed" class="link" type="button" @click="startEdit">{{ lines.length ? 'Edit' : '+ Add the panels and accessories' }}</button>
    </div>
    <p v-if="error" class="rq-error" role="alert">{{ error }}</p>

    <template v-if="!editing">
      <ul v-if="lines.length" class="rq-list">
        <li v-for="(l, i) in lines" :key="i">
          <template v-if="l.kind === 'panel'"><strong>{{ l.panel_type_name }}</strong> · {{ l.thickness }} mm<template v-if="l.application"> · {{ l.application }}</template> · {{ l.area_sqm }} sqm</template>
          <template v-else>{{ l.accessory_name || l.description }} × {{ l.qty }}<template v-if="l.unit"> {{ l.unit }}</template></template>
        </li>
      </ul>
      <p v-else class="rq-empty">{{ closed ? 'Nothing recorded.' : 'Not recorded yet. List the panel types, thickness and area, and any accessories, so the quotation can start from them.' }}</p>

      <div v-if="hasPanels && !closed && canQuote" class="rq-quote">
        <button class="btn btn-primary" type="button" :disabled="busy" @click="$emit('quote', { asBoq: false })">Quote this lead</button>
        <button class="btn btn-ghost" type="button" :disabled="busy" @click="$emit('quote', { asBoq: true })">Make a BOQ instead</button>
        <small>Makes the quotation from these lines. The sizes start as {{ standardLength }} mm lengths covering the area and the specification starts from the usual defaults; rates are left for you to enter. Check them on the quotation.</small>
      </div>
    </template>

    <form v-else class="rq-form" @submit.prevent="save">
      <h4>Panels</h4>
      <div v-for="(l, i) in draft.filter(x => x.kind === 'panel')" :key="l._k" class="rq-row">
        <select v-model="l.panel_type_id" :aria-label="'Panel type, line ' + (i + 1)" required><option value="">Panel type…</option><option v-for="p in panelTypes" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        <select v-model="l.application" aria-label="Application"><option value="">Application…</option><option v-for="a in applications" :key="a" :value="a">{{ a }}</option></select>
        <select v-model="l.thickness" aria-label="Thickness" required><option value="">mm…</option><option v-for="t in thicknesses" :key="t" :value="t">{{ t }} mm</option></select>
        <input v-model.number="l.area_sqm" type="number" min="0" step="0.01" placeholder="Area, sqm" aria-label="Area in square metres" required />
        <button class="x" type="button" aria-label="Remove this panel line" @click="remove(l)">✕</button>
      </div>
      <button class="link" type="button" @click="addPanel">+ Panel line</button>

      <h4>Accessories</h4>
      <div v-for="l in draft.filter(x => x.kind === 'accessory')" :key="l._k" class="rq-row">
        <select v-model="l.accessory_id" aria-label="Accessory"><option value="">Other (type it)…</option><option v-for="a in accessories" :key="a.id" :value="a.id">{{ a.name }}</option></select>
        <input v-if="!l.accessory_id" v-model="l.description" maxlength="150" placeholder="Describe it" aria-label="Describe the accessory" />
        <input v-model.number="l.qty" type="number" min="0" step="0.01" placeholder="Qty" aria-label="Quantity" required />
        <button class="x" type="button" aria-label="Remove this accessory line" @click="remove(l)">✕</button>
      </div>
      <button class="link" type="button" @click="addAccessory">+ Accessory</button>

      <div class="rq-btns"><button class="btn btn-primary" type="submit" :disabled="busy">{{ busy ? 'Saving…' : 'Save' }}</button><button class="link" type="button" @click="editing = false">Cancel</button></div>
    </form>
  </section>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import masterData from '../services/masterDataService.js'
import { toastSuccess } from '../services/ui.js'

const props = defineProps({ leadId: { type: Number, required: true }, closed: { type: Boolean, default: false }, busy: { type: Boolean, default: false } })
const emit = defineEmits(['changed', 'quote'])

const lines = ref([])
const applications = ref([])
const thicknesses = ref([])
const standardLength = ref(3000)
const panelTypes = ref([])
const accessories = ref([])
const editing = ref(false)
const draft = ref([])
const error = ref('')
let seq = 1

const me = (() => { try { return JSON.parse(localStorage.getItem('user') || 'null') } catch { return null } })()
const canQuote = !!me && (me.is_admin || me.is_company_admin || me.is_super_admin || (me.permissions || []).includes('*') || (me.permissions || []).includes('quotations.manage'))
const hasPanels = computed(() => lines.value.some(l => l.kind === 'panel'))
const list = (res) => { const d = res?.data; return Array.isArray(d) ? d : (d?.data ?? []) }

async function load() {
  error.value = ''
  try {
    const d = (await leadService.requirement(props.leadId))?.data ?? {}
    lines.value = d.lines ?? []; applications.value = d.applications ?? []; thicknesses.value = d.thicknesses ?? []; standardLength.value = d.standard_length_mm ?? 3000
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the requirement.' }
}

async function loadLists() {
  try { panelTypes.value = list(await masterData.panelTypes()).filter(p => p.is_active !== false) } catch { panelTypes.value = [] }
  try { accessories.value = list(await masterData.accessories()).filter(a => a.is_active !== false) } catch { accessories.value = [] }
}

const blank = (kind) => ({ _k: seq++, kind, panel_type_id: '', application: '', thickness: '', area_sqm: null, accessory_id: '', description: '', qty: null })
function addPanel() { draft.value.push(blank('panel')) }
function addAccessory() { draft.value.push(blank('accessory')) }
function remove(l) { draft.value = draft.value.filter(x => x !== l) }
function startEdit() {
  draft.value = lines.value.map(l => ({ ...blank(l.kind), panel_type_id: l.panel_type_id ?? '', application: l.application ?? '', thickness: l.thickness ?? '', area_sqm: l.area_sqm, accessory_id: l.accessory_id ?? '', description: l.description ?? '', qty: l.qty }))
  if (!draft.value.length) addPanel()
  editing.value = true
}

async function save() {
  error.value = ''
  const body = draft.value.map(l => l.kind === 'panel'
    ? { kind: 'panel', panel_type_id: l.panel_type_id || null, application: l.application || null, thickness: l.thickness || null, area_sqm: l.area_sqm }
    : { kind: 'accessory', accessory_id: l.accessory_id || null, description: l.accessory_id ? null : (l.description || null), qty: l.qty })
  try {
    await leadService.saveRequirement(props.leadId, body)
    toastSuccess('Saved.')
    editing.value = false
    await load(); emit('changed')
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not save.' }
}

watch(() => props.leadId, load)
onMounted(() => { load(); loadLists() })
</script>

<style scoped>
.rq { margin: 14px 0; }
.rq-head { display: flex; align-items: center; justify-content: space-between; }
.rq-head label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: .4px; }
.rq-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; margin: 6px 0; }
.rq-list { list-style: none; margin: 6px 0 0; padding: 0; font-size: 13px; }
.rq-list li { padding: 5px 0; border-bottom: 1px solid #f0f0f0; }
.rq-empty { font-size: 12.5px; color: #888; margin: 6px 0 0; }
.rq-quote { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
.rq-quote small { flex: 1 1 100%; color: #777; font-size: 12px; line-height: 1.4; }
.rq-form { background: #f7f9fb; border-radius: 8px; padding: 10px 12px; margin-top: 8px; }
.rq-form h4 { margin: 8px 0 6px; font-size: 12px; text-transform: uppercase; color: #666; }
.rq-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 6px; }
.rq-row select, .rq-row input { padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff; }
.rq-row input[type=number] { width: 110px; }
.rq-btns { display: flex; gap: 12px; align-items: center; margin-top: 10px; }
.link { background: none; border: 0; color: var(--primary); font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0; }
.x { background: none; border: 0; color: #b0b0b0; cursor: pointer; font-size: 14px; }
.x:hover { color: #c62828; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
