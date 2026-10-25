<template>
  <section class="at" aria-label="Files">
    <div class="at-head">
      <label>Files</label>
      <span v-if="canManage" class="at-add">
        <select v-model="kind" aria-label="Kind of file"><option value="">Kind: automatic</option><option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option></select>
        <label class="btn btn-ghost sm" :class="{ disabled: busy }">{{ busy ? 'Uploading…' : '+ Add a file' }}
          <input ref="picker" type="file" :accept="accept" :disabled="busy" hidden @change="pick" />
        </label>
      </span>
    </div>
    <p v-if="error" class="at-error" role="alert">{{ error }}</p>

    <ul v-if="files.length" class="at-list">
      <li v-for="f in files" :key="f.id">
        <span class="at-kind">{{ f.kind_label }}</span>
        <button class="at-name" type="button" :title="f.viewable ? 'Open' : 'Download'" @click="open(f)">{{ f.name }}</button>
        <span class="at-meta">{{ size(f.size_bytes) }} · {{ f.uploaded_by || 'someone' }}, {{ when(f.uploaded_at) }}</span>
        <button v-if="f.viewable" class="link" type="button" @click="download(f)">Download</button>
        <button v-if="canManage" class="x" type="button" :aria-label="'Remove ' + f.name" @click="remove(f)">✕</button>
      </li>
    </ul>
    <p v-else class="at-empty">No files yet. Keep the customer's drawings, site photos and BOQ sheets here.</p>
    <p v-if="files.length" class="at-note">{{ files.length }} of {{ maxFiles }} files. Up to {{ maxMb }} MB each. Files are private to your company.</p>
  </section>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import { fetchBlob, saveBlob } from '../services/transfers.js'
import { toastSuccess, toastError, confirmDialog } from '../services/ui.js'

const props = defineProps({ leadId: { type: Number, required: true } })
const emit = defineEmits(['changed'])

const files = ref([])
const kinds = ref({})
const accept = ref('')
const maxMb = ref(15)
const maxFiles = ref(30)
const kind = ref('')
const busy = ref(false)
const error = ref('')
const picker = ref(null)

const me = (() => { try { return JSON.parse(localStorage.getItem('user') || 'null') } catch { return null } })()
const canManage = !!me && (me.is_admin || me.is_company_admin || me.is_super_admin || (me.permissions || []).includes('*') || (me.permissions || []).includes('leads.manage'))
const MIME = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' }

const size = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'
const when = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : ''

async function load() {
  error.value = ''
  try {
    const d = (await leadService.attachments(props.leadId))?.data ?? {}
    files.value = d.files ?? []; kinds.value = d.kinds ?? {}; accept.value = d.accept ?? ''; maxMb.value = d.max_mb ?? 15; maxFiles.value = d.max_files ?? 30
  } catch (e) { error.value = e?.response?.data?.message ?? 'Could not load the files.' }
}

async function pick(e) {
  const file = e.target.files?.[0]
  if (!file) return
  busy.value = true; error.value = ''
  try {
    await leadService.addAttachment(props.leadId, file, kind.value)
    toastSuccess('File added.')
    await load(); emit('changed')
  } catch (err) { error.value = err?.response?.data?.message ?? 'The file could not be added.' }
  finally { busy.value = false; if (picker.value) picker.value.value = '' }
}

async function open(f) {
  if (!f.viewable) return download(f)
  const tab = window.open('', '_blank')                       // opened now, while the click still counts
  try {
    const blob = await fetchBlob(`/api/leads/attachments/${f.id}`, { label: 'Opening file', kind: 'lead-file', params: { inline: 1 }, type: MIME[f.extension] || 'application/octet-stream' })
    const url = URL.createObjectURL(blob)
    if (tab) { tab.location.href = url; setTimeout(() => URL.revokeObjectURL(url), 60000) } else saveBlob(blob, f.name)
  } catch (e) { tab?.close(); toastError(e?.response?.data?.message ?? 'Could not open the file.') }
}

async function download(f) {
  try { saveBlob(await fetchBlob(`/api/leads/attachments/${f.id}`, { label: 'Downloading file', kind: 'lead-file', type: 'application/octet-stream' }), f.name) }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not download the file.') }
}

async function remove(f) {
  const ok = await confirmDialog({ title: `Remove ${f.name}?`, message: 'The file is deleted from the server. This cannot be undone.', confirmLabel: 'Remove', cancelLabel: 'Keep', danger: true })
  if (!ok) return
  try { await leadService.removeAttachment(f.id); await load(); emit('changed') }
  catch (e) { toastError(e?.response?.data?.message ?? 'Could not remove the file.') }
}

watch(() => props.leadId, load)
onMounted(load)
</script>

<style scoped>
.at { margin: 14px 0; }
.at-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.at-head > label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: .4px; }
.at-add { display: inline-flex; gap: 8px; align-items: center; }
.at-add select { padding: 5px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 12.5px; }
.at-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; margin: 6px 0; }
.at-list { list-style: none; margin: 6px 0 0; padding: 0; }
.at-list li { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.at-kind { font-size: 10.5px; font-weight: 700; text-transform: uppercase; background: #eef1f5; color: #455a64; border-radius: 8px; padding: 1px 8px; min-width: 70px; text-align: center; }
.at-name { background: none; border: 0; padding: 0; font: inherit; color: var(--primary); cursor: pointer; text-align: left; flex: 1; min-width: 140px; }
.at-name:hover { text-decoration: underline; }
.at-meta { color: #888; font-size: 12px; }
.at-empty, .at-note { font-size: 12.5px; color: #888; margin: 6px 0 0; }
.link { background: none; border: 0; color: var(--primary); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0; }
.x { background: none; border: 0; color: #b0b0b0; cursor: pointer; font-size: 14px; }
.x:hover { color: #c62828; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-block; }
.btn.sm { padding: 5px 12px; font-size: 12px; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
.btn.disabled { opacity: .5; cursor: wait; }
</style>
