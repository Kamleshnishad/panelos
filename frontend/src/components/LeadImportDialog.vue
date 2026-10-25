<template>
  <div class="modal-overlay li-overlay" role="dialog" aria-modal="true" aria-label="Import leads" @click.self="$emit('close')">
    <div class="modal-box li-box">
      <div class="modal-header"><h3>Import leads from a spreadsheet</h3><button class="btn-close" aria-label="Close" @click="$emit('close')">✕</button></div>

      <p class="li-intro">
        In Excel choose <strong>File → Save As → CSV</strong>. The first row must have headings such as <em>Name, Phone, Email, City, Source, Requirement</em>.
        <button class="link" type="button" @click="template">Download the template</button>
      </p>

      <div v-if="last" class="li-last" role="status">
        <span>Last import: <strong>{{ last.file_name }}</strong>, {{ last.created }} new lead{{ last.created === 1 ? '' : 's' }}, {{ when(last.imported_at) }}.</span>
        <button v-if="last.status !== 'undone'" class="btn btn-ghost" :disabled="busy" @click="undo">Undo this import</button>
        <span v-else-if="last.undo_result">Undone: {{ last.undo_result.removed }} removed<template v-if="last.undo_result.kept">, {{ last.undo_result.kept }} kept because they were already worked on</template>.</span>
      </div>
      <p v-if="undoResult" class="li-ok" role="status">
        Undone. {{ undoResult.removed }} lead{{ undoResult.removed === 1 ? '' : 's' }} removed<template v-if="undoResult.kept">; {{ undoResult.kept }} kept because someone had already worked on {{ undoResult.kept === 1 ? 'it' : 'them' }}</template>.
        <template v-if="undoResult.added_to_existing_leads"> {{ undoResult.added_to_existing_leads }} enquir{{ undoResult.added_to_existing_leads === 1 ? 'y' : 'ies' }} added to existing leads stay as notes on those leads.</template>
      </p>
      <p v-if="error" class="li-error" role="alert">{{ error }}</p>

      <!-- 1. choose the file -->
      <form v-if="step === 'pick'" class="li-pick" @submit.prevent="check">
        <label>File <input ref="fileInput" type="file" accept=".csv,text/csv" required @change="file = $event.target.files[0] || null" /></label>
        <label>Leads without a Source column are recorded as
          <select v-model="source"><option v-for="s in sources" :key="s" :value="s">{{ s }}</option></select>
        </label>
        <button class="btn btn-primary" type="submit" :disabled="busy || !file">{{ busy ? 'Checking…' : 'Check file' }}</button>
        <small>Nothing is imported yet: you see what would happen first.</small>
      </form>

      <!-- 2. what would happen -->
      <template v-else-if="step === 'checked' && res">
        <div class="li-chips">
          <span class="chip created">New leads: <strong>{{ res.counts.created }}</strong></span>
          <span class="chip merged">Added to a lead the buyer already has: <strong>{{ res.counts.merged }}</strong></span>
          <span class="chip already">Already had: <strong>{{ res.counts.already_received }}</strong></span>
          <span class="chip rejected">Cannot import: <strong>{{ res.counts.rejected }}</strong></span>
        </div>
        <p v-if="res.ignored_columns.length" class="li-note">Columns not used: {{ res.ignored_columns.join(', ') }}.</p>
        <div class="li-scroll">
          <table class="li-table">
            <thead><tr><th>Row</th><th>Name</th><th>Phone</th><th>E-mail</th><th>What happens</th></tr></thead>
            <tbody>
              <tr v-for="r in res.rows" :key="r.n">
                <td>{{ r.n }}</td><td>{{ r.name }}</td><td>{{ r.phone }}</td><td>{{ r.email }}</td>
                <td>
                  <span class="chip" :class="chip(r.outcome)">{{ label(r.outcome) }}</span>
                  <span v-if="r.reason" class="why">{{ r.reason }}</span>
                  <span v-for="n in r.notes" :key="n" class="why warn">{{ n }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="res.total_rows > res.rows_shown" class="li-note">Showing the first {{ res.rows_shown }} of {{ res.total_rows }} rows; all of them are imported.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" :disabled="busy" @click="reset">Choose another file</button>
          <button class="btn btn-primary" :disabled="busy || (res.counts.created + res.counts.merged) === 0" @click="commit">
            {{ busy ? 'Importing…' : `Import ${res.counts.created} new lead${res.counts.created === 1 ? '' : 's'}${res.counts.merged ? ` and add ${res.counts.merged} to existing` : ''}` }}
          </button>
        </div>
      </template>

      <!-- 3. done -->
      <template v-else-if="step === 'done' && done">
        <p class="li-ok" role="status">
          Imported: <strong>{{ done.counts.created }}</strong> new lead{{ done.counts.created === 1 ? '' : 's' }}, <strong>{{ done.counts.merged }}</strong> added to existing leads,
          {{ done.counts.already_received }} already had, {{ done.counts.rejected }} could not be imported<template v-if="done.counts.errors">, {{ done.counts.errors }} failed</template>.
        </p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="reset">Import another file</button>
          <button class="btn btn-primary" @click="$emit('close')">Done</button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import { downloadFile } from '../services/transfers.js'
import { toastError, confirmDialog } from '../services/ui.js'

const emit = defineEmits(['close', 'changed'])

const step = ref('pick')
const file = ref(null)
const fileInput = ref(null)
const sources = ref([])
const source = ref('')
const res = ref(null)
const done = ref(null)
const last = ref(null)
const undoResult = ref(null)
const busy = ref(false)
const error = ref('')

const OUTCOMES = { created: ['New lead', 'created'], merged: ['Added to existing lead', 'merged'], already_received: ['Already had', 'already'], rejected: ['Cannot import', 'rejected'] }
const label = (o) => OUTCOMES[o]?.[0] ?? o
const chip = (o) => OUTCOMES[o]?.[1] ?? ''
const when = (d) => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
const msg = (e, fallback) => e?.response?.data?.message ?? fallback

async function load() {
  try {
    const [o, l] = await Promise.all([leadService.options(), leadService.importLast()])
    sources.value = o?.data?.sources ?? []
    source.value = sources.value.includes('Exhibition') ? 'Exhibition' : (sources.value.includes('Other') ? 'Other' : (sources.value[0] ?? ''))
    last.value = l?.data ?? null
  } catch (e) { error.value = msg(e, 'Could not load.') }
}

async function template() {
  try { await downloadFile('/api/leads/import/template', 'leads_import_template.csv', { label: 'Preparing template', kind: 'leads-template', type: 'text/csv' }) }
  catch (e) { toastError(msg(e, 'Could not download the template.')) }
}

async function check() {
  busy.value = true; error.value = ''; undoResult.value = null
  try { res.value = (await leadService.importCheck(file.value, source.value)).data; step.value = 'checked' }
  catch (e) { error.value = msg(e, 'Could not read that file.') }
  finally { busy.value = false }
}

async function commit() {
  busy.value = true; error.value = ''
  try {
    done.value = { counts: (await leadService.importCommit(res.value.id)).data.counts }
    step.value = 'done'
    emit('changed')
    last.value = (await leadService.importLast())?.data ?? null
  } catch (e) { error.value = msg(e, 'The import did not finish.') }
  finally { busy.value = false }
}

async function undo() {
  const ok = await confirmDialog({
    title: 'Undo the last import?',
    message: `The leads created from ${last.value.file_name} are removed, except any that someone has already worked on. Enquiries that were added to leads you already had stay as notes there.`,
    confirmLabel: 'Undo import', cancelLabel: 'Cancel', danger: true,
  })
  if (!ok) return
  busy.value = true; error.value = ''
  try {
    undoResult.value = (await leadService.importUndo(last.value.id)).data.undo_result
    emit('changed')
    last.value = (await leadService.importLast())?.data ?? null
  } catch (e) { error.value = msg(e, 'Could not undo.') }
  finally { busy.value = false }
}

function reset() { step.value = 'pick'; res.value = null; done.value = null; file.value = null; error.value = '' }

onMounted(load)
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 880px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.li-intro { font-size: 13px; color: #555; margin: 0 0 12px; line-height: 1.5; }
.li-last { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; background: #f5f7fa; border-radius: 8px; padding: 8px 12px; font-size: 13px; margin-bottom: 12px; }
.li-ok { background: #e8f5e9; color: #2e7d32; border-radius: 8px; padding: 9px 12px; font-size: 13px; margin: 0 0 12px; }
.li-error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; border-radius: 8px; padding: 9px 12px; font-size: 13px; margin: 0 0 12px; }
.li-pick { display: flex; flex-direction: column; gap: 12px; align-items: flex-start; font-size: 13px; }
.li-pick label { display: flex; flex-direction: column; gap: 4px; color: #555; font-weight: 600; }
.li-pick select { padding: 7px 9px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.li-pick small, .li-note { color: #777; font-size: 12px; }
.li-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.chip { font-size: 12px; padding: 3px 10px; border-radius: 12px; background: #eceff1; color: #455a64; white-space: nowrap; }
.chip.created { background: #e8f5e9; color: #2e7d32; }
.chip.merged { background: #e3f2fd; color: #1565c0; }
.chip.already { background: #eceff1; color: #546e7a; }
.chip.rejected { background: #ffebee; color: #c62828; }
.li-scroll { max-height: 340px; overflow: auto; border: 1px solid #eee; border-radius: 8px; }
.li-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.li-table th { position: sticky; top: 0; background: #fafafa; text-align: left; font-size: 11px; text-transform: uppercase; color: #666; padding: 6px; border-bottom: 1px solid #ddd; }
.li-table td { padding: 5px 6px; border-bottom: 1px solid #f2f2f2; vertical-align: top; }
.why { display: block; color: #777; font-size: 11.5px; margin-top: 2px; }
.why.warn { color: #b26a00; }
.link { background: none; border: 0; color: var(--primary); font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0 0 0 6px; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
