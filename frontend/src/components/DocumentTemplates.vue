<template>
  <div class="dt-wrap">
    <div class="dt-header">
      <div>
        <h2>Document Templates</h2>
        <p class="dt-sub">Choose the PDF design for each document. Preview before applying — the chosen template is used for all new PDFs.</p>
      </div>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
    </div>

    <div v-if="loading" class="dt-loading">Loading…</div>

    <div v-else v-for="grp in groups" :key="grp.doc_type" class="dt-group">
      <h3>{{ label(grp.doc_type) }}</h3>
      <div class="tpl-grid">
        <div v-for="t in grp.templates" :key="t.key" class="tpl-card" :class="{ current: t.is_current }">
          <div class="tpl-top">
            <div class="tpl-name">{{ t.name }}</div>
            <span v-if="t.is_current" class="cur-badge">In use</span>
          </div>
          <p class="tpl-desc">{{ t.description || '—' }}</p>
          <div class="tpl-actions">
            <button class="btn btn-ghost sm" :disabled="previewing === grp.doc_type + t.key" @click="preview(grp.doc_type, t.key)">
              {{ previewing === grp.doc_type + t.key ? 'Opening…' : '👁 Preview' }}
            </button>
            <button class="btn btn-primary sm" :disabled="t.is_current || applying" @click="apply(grp.doc_type, t.key)">
              {{ t.is_current ? 'Applied' : 'Apply' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <p class="dt-note">More designs can be added in code (a Blade template + one registry line) and will appear here automatically.</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import documentTemplateService from '../services/documentTemplateService.js'
import { toastSuccess, toastError } from '../services/ui.js'

const groups = ref([])
const loading = ref(false)
const applying = ref(false)
const previewing = ref(null)

const LABELS = { quotation: 'Quotation', boq: 'BOQ Cutting Sheet', invoice: 'Invoice' }
function label(k) { return LABELS[k] || k }

async function load() {
  loading.value = true
  try {
    const res = await documentTemplateService.list()
    groups.value = res?.data ?? res ?? []
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not load templates.') }
  finally { loading.value = false }
}

async function apply(docType, key) {
  applying.value = true
  try {
    const res = await documentTemplateService.apply({ doc_type: docType, template_key: key })
    groups.value = res?.data ?? res ?? groups.value
    toastSuccess(`${label(docType)} template applied.`)
  } catch (e) { toastError(e?.response?.data?.message ?? 'Could not apply template.') }
  finally { applying.value = false }
}

async function preview(docType, key) {
  previewing.value = docType + key
  try {
    const url = await documentTemplateService.preview(docType, key)
    window.open(url, '_blank')
  } catch (e) { toastError(e.message || 'Preview not available.') }
  finally { previewing.value = null }
}

onMounted(load)
</script>

<style scoped>
.dt-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
.dt-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; gap: 16px; }
.dt-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.dt-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); max-width: 640px; }
.dt-loading { text-align: center; padding: 60px; color: #888; }

.dt-group { margin-bottom: 26px; }
.dt-group h3 { font-size: 15px; color: var(--primary); margin: 0 0 12px; border-bottom: 1px solid var(--border); padding-bottom: 6px; }
.tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.tpl-card { background: var(--surface); border: 2px solid var(--border); border-radius: 10px; padding: 16px 18px; }
.tpl-card.current { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.tpl-top { display: flex; align-items: center; justify-content: space-between; }
.tpl-name { font-size: 15px; font-weight: 700; color: var(--ink); }
.cur-badge { font-size: 10px; font-weight: 700; background: #e8f5e9; color: #2e7d32; border-radius: 8px; padding: 2px 8px; text-transform: uppercase; }
.tpl-desc { font-size: 12.5px; color: var(--text-2); line-height: 1.5; margin: 8px 0 14px; min-height: 34px; }
.tpl-actions { display: flex; gap: 8px; justify-content: flex-end; }

.dt-note { font-size: 12px; color: var(--text-3); font-style: italic; margin-top: 8px; }

.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn.sm { padding: 6px 12px; font-size: 12px; }
.btn-primary { background: var(--primary); color: #fff; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }
</style>
