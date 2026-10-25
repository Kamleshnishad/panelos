<template>
  <div class="rk-box tally-set" aria-label="Tally ledger names">
    <div class="rk-head"><h3>Your ledger names in Tally</h3></div>
    <p class="rk-note">An import fails when a ledger in the file is not in your Tally company, so write here exactly what your Tally calls them. Leave a box empty to use the standard name. In the sales ledger, <b>{rate}</b> is replaced by the GST rate of each invoice (18, 12.5…), so one pattern names one ledger per rate. Your customers' own names are used as the debtor ledgers.</p>
    <p v-if="error" class="rk-error" role="alert">{{ error }}</p>

    <template v-if="form">
      <div class="rk-field"><label for="tl-sales">Sales ledger</label><input id="tl-sales" v-model.trim="form.sales" maxlength="80" :placeholder="defaults.sales" :disabled="!editable" /><small>Example for an 18 % sale: <b>{{ example }}</b></small></div>
      <div class="rk-field"><label for="tl-cgst">CGST ledger</label><input id="tl-cgst" v-model.trim="form.cgst" maxlength="80" :placeholder="defaults.cgst" :disabled="!editable" /></div>
      <div class="rk-field"><label for="tl-sgst">SGST ledger</label><input id="tl-sgst" v-model.trim="form.sgst" maxlength="80" :placeholder="defaults.sgst" :disabled="!editable" /></div>
      <div class="rk-field"><label for="tl-igst">IGST ledger</label><input id="tl-igst" v-model.trim="form.igst" maxlength="80" :placeholder="defaults.igst" :disabled="!editable" /></div>
      <div class="rk-field"><label for="tl-hsn">HSN code for a panel type that has none</label><input id="tl-hsn" v-model.trim="form.hsn" maxlength="8" inputmode="numeric" :placeholder="defaults.hsn" :disabled="!editable" /><small>4 to 8 digits.</small></div>
      <p v-if="missing.length" class="rk-warn" role="status">These panel types have no HSN code yet and will use the code above (set theirs in Master Data → Panel types): {{ missing.join(', ') }}.</p>
      <div class="rk-actions">
        <button v-if="editable" class="rk-btn primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save ledger names' }}</button>
        <span v-else class="rk-note">Only a user who can change settings can edit these.</span>
        <span v-if="saved" class="rk-note" role="status">Saved.</span>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import reportService from '../services/reportService.js'
import { can } from '../services/can.js'
import './report-kit.css'

const form = ref(null)
const defaults = ref({ sales: 'Sales @ {rate}%', cgst: 'CGST', sgst: 'SGST', igst: 'IGST', hsn: '39259010' })
const missing = ref([])
const error = ref('')
const saving = ref(false)
const saved = ref(false)
const editable = can('settings.manage')

const example = computed(() => (form.value?.sales || defaults.value.sales).replace('{rate}', '18'))

function take(d) {
  defaults.value = d.defaults ?? defaults.value
  missing.value = d.panel_types_without_hsn ?? []
  // A field that equals its standard name is shown empty, so "empty = standard" stays true on screen.
  const l = d.ledgers ?? {}
  form.value = Object.fromEntries(Object.keys(defaults.value).map(k => [k, l[k] === defaults.value[k] ? '' : (l[k] ?? '')]))
}

async function load() {
  error.value = ''
  try { take((await reportService.tallyLedgers())?.data ?? {}) }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the ledger names.' }
}

async function save() {
  saving.value = true; saved.value = false; error.value = ''
  try { take((await reportService.saveTallyLedgers({ ...form.value }))?.data ?? {}); saved.value = true }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not save.' }
  finally { saving.value = false }
}

onMounted(load)
</script>
