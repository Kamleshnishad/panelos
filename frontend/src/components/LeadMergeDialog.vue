<template>
  <div class="modal-overlay mg-overlay" role="dialog" aria-modal="true" aria-label="Merge leads" @click.self="$emit('close')">
    <div class="modal-box">
      <div class="modal-header"><h3>Merge another lead into {{ lead.lead_no }}</h3><button class="btn-close" aria-label="Close" @click="$emit('close')">✕</button></div>
      <p class="mg-note">{{ lead.lead_no }} ({{ lead.contact_name }}) is the lead you <strong>keep</strong>. The one you choose is folded into it: its timeline, actions, files and messages move here, and blanks are filled in. It is then removed. <strong>This cannot be undone.</strong></p>
      <p v-if="error" class="mg-error" role="alert">{{ error }}</p>

      <label class="mg-label" for="mg-q">Look for it by number, name, company or phone</label>
      <input id="mg-q" v-model.trim="q" maxlength="60" placeholder="e.g. LEAD-2026-0042" @input="search" />

      <p v-if="loading" class="mg-empty">Looking…</p>
      <p v-else-if="!list.length" class="mg-empty">No other open lead matches this buyer. Search above to find one.</p>
      <ul v-else class="mg-list">
        <li v-for="c in list" :key="c.id" :class="{ blocked: c.blocked }">
          <div>
            <strong>{{ c.lead_no }}</strong> {{ c.contact_name }}<span v-if="c.company_name"> · {{ c.company_name }}</span><span v-if="c.phone"> · {{ c.phone }}</span>
            <span class="mg-why">{{ c.status.replace('_', ' ') }} · {{ c.why }}</span>
            <span v-if="c.blocked" class="mg-block">{{ c.blocked }}</span>
          </div>
          <button class="btn btn-primary sm" type="button" :disabled="busy || !!c.blocked" @click="pick(c)">Merge this into {{ lead.lead_no }}</button>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import leadService from '../services/leadService.js'
import { confirmDialog, toastSuccess } from '../services/ui.js'

const props = defineProps({ lead: { type: Object, required: true } })
const emit = defineEmits(['close', 'merged'])

const q = ref('')
const list = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
let timer = null

async function load() {
  loading.value = true; error.value = ''
  try { list.value = (await leadService.mergeCandidates(props.lead.id, q.value))?.data?.candidates ?? [] }
  catch (e) { error.value = e?.response?.data?.message ?? 'Could not look for leads.' }
  finally { loading.value = false }
}
function search() { clearTimeout(timer); timer = setTimeout(load, 350) }

async function pick(c) {
  const ok = await confirmDialog({
    title: `Merge ${c.lead_no} into ${props.lead.lead_no}?`,
    message: `${c.lead_no} (${c.contact_name}) is folded into ${props.lead.lead_no} and removed. Its timeline, actions, files and messages move across. This cannot be undone.`,
    confirmLabel: 'Merge', cancelLabel: 'Cancel', danger: true,
  })
  if (!ok) return
  busy.value = true; error.value = ''
  try {
    const d = (await leadService.mergeLead(props.lead.id, c.id))?.data ?? {}
    toastSuccess(`${d.merged} merged into ${d.kept}.`)
    emit('merged')
  } catch (e) { error.value = e?.response?.data?.message ?? 'The merge did not happen.' }
  finally { busy.value = false }
}

onMounted(load)
</script>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 16px; }
.modal-overlay.mg-overlay { z-index: 1200; }   /* above the lead drawer (1000) it is opened from; the parent's own .modal-overlay rule also reaches this root element */
.modal-box { background: white; border-radius: 12px; padding: 22px 26px; width: 100%; max-width: 640px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.modal-header h3 { margin: 0; font-size: 16px; color: var(--primary); }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.mg-note { font-size: 13px; color: #555; line-height: 1.5; margin: 0 0 12px; }
.mg-error { background: #ffebee; color: #c62828; border-radius: 8px; padding: 8px 12px; font-size: 13px; margin: 0 0 10px; }
.mg-label { display: block; font-size: 12px; font-weight: 600; color: #666; margin: 8px 0 4px; }
#mg-q { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font: inherit; font-size: 13px; }
.mg-empty { color: #888; font-size: 13px; margin: 12px 0 0; }
.mg-list { list-style: none; margin: 12px 0 0; padding: 0; }
.mg-list li { display: flex; gap: 12px; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.mg-list li.blocked { opacity: .75; }
.mg-why { display: block; color: #888; font-size: 12px; text-transform: capitalize; }
.mg-block { display: block; color: #b3261e; font-size: 12px; margin-top: 2px; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 6px 12px; font-size: 12px; white-space: nowrap; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:disabled { opacity: .45; cursor: not-allowed; }
</style>
