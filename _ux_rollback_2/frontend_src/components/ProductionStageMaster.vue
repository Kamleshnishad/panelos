<template>
  <div class="md-section">
    <div class="md-head">
      <div class="md-title">
        <h3>Production Stages</h3>
        <span class="hint">Order defines the shop-floor sequence</span>
      </div>
      <button class="btn btn-primary btn-sm" @click="openCreate">+ Add Stage</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="success" class="success-banner">{{ success }}</div>

    <div v-if="loading" class="loading-row">Loading…</div>
    <table v-else class="md-table">
      <thead>
        <tr><th style="width:60px">Seq</th><th>Stage Name</th><th>Description</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-if="rows.length === 0"><td colspan="5" class="empty-row">No production stages.</td></tr>
        <tr v-for="r in rows" :key="r.id" :class="{ inactive: !r.is_active }">
          <td><span class="seq-badge">{{ r.sequence }}</span></td>
          <td class="bold">{{ r.name }}</td>
          <td class="muted">{{ r.description || '—' }}</td>
          <td><span :class="['status-dot', r.is_active ? 'on' : 'off']">{{ r.is_active ? 'Active' : 'Inactive' }}</span></td>
          <td class="actions">
            <button class="btn-icon edit" @click="openEdit(r)">Edit</button>
            <button v-if="r.is_active" class="btn-icon del" @click="confirmDelete(r)">Deactivate</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>{{ editing ? 'Edit Stage' : 'New Stage' }}</h3>
          <button class="btn-close" @click="showModal = false">✕</button>
        </div>
        <div class="form-grid">
          <div class="form-group"><label>Name *</label><input v-model="form.name" placeholder="Foam Injection" /></div>
          <div class="form-group"><label>Sequence</label><input v-model.number="form.sequence" type="number" min="1" /></div>
          <div class="form-group full"><label>Description</label><input v-model="form.description" /></div>
          <div class="form-group" v-if="editing">
            <label>Status</label>
            <select v-model="form.is_active"><option :value="true">Active</option><option :value="false">Inactive</option></select>
          </div>
        </div>
        <div v-if="modalError" class="error-msg">{{ modalError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </div>

    <div v-if="deleteTarget" class="modal-overlay" @click.self="deleteTarget = null">
      <div class="modal-box sm">
        <h3>Deactivate Stage?</h3>
        <p><strong>{{ deleteTarget.name }}</strong> will no longer appear in new batch timelines.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="deleteTarget = null">Cancel</button>
          <button class="btn btn-danger" :disabled="saving" @click="doDelete">Deactivate</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import masterDataService from '../services/masterDataService.js'

const rows = ref([])
const loading = ref(false)
const error = ref(null)
const success = ref(null)
const showModal = ref(false)
const editing = ref(false)
const editId = ref(null)
const saving = ref(false)
const modalError = ref(null)
const deleteTarget = ref(null)

const form = reactive({ name: '', sequence: null, description: '', is_active: true })

async function load() {
  loading.value = true; error.value = null
  try {
    const res = await masterDataService.stages()
    const data = res?.data ?? []
    rows.value = [...data].sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))
  } catch (e) { error.value = e?.response?.data?.message ?? 'Failed to load.' }
  finally { loading.value = false }
}

function openCreate() {
  editing.value = false; editId.value = null; modalError.value = null
  const nextSeq = rows.value.length ? Math.max(...rows.value.map(r => r.sequence ?? 0)) + 1 : 1
  Object.assign(form, { name: '', sequence: nextSeq, description: '', is_active: true })
  showModal.value = true
}
function openEdit(r) {
  editing.value = true; editId.value = r.id; modalError.value = null
  Object.assign(form, { name: r.name, sequence: r.sequence, description: r.description ?? '', is_active: !!r.is_active })
  showModal.value = true
}

async function save() {
  saving.value = true; modalError.value = null
  try {
    if (editing.value) await masterDataService.updateStage(editId.value, form)
    else await masterDataService.createStage(form)
    showModal.value = false
    success.value = editing.value ? 'Stage updated.' : 'Stage created.'
    await load()
  } catch (e) {
    modalError.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Failed to save.'
  } finally { saving.value = false }
}

function confirmDelete(r) { deleteTarget.value = r }
async function doDelete() {
  saving.value = true
  try {
    await masterDataService.deleteStage(deleteTarget.value.id)
    deleteTarget.value = null
    success.value = 'Stage deactivated.'
    await load()
  } catch (e) { error.value = e?.response?.data?.message ?? 'Failed.' }
  finally { saving.value = false }
}

onMounted(load)
</script>

<style scoped>
.md-section { }
.md-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
.md-title { display: flex; align-items: baseline; gap: 12px; }
.md-title h3 { margin: 0; font-size: 16px; color: var(--primary); }
.hint { font-size: 11px; color: #aaa; }
.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 10px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 10px; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.md-table { width: 100%; border-collapse: collapse; font-size: 13px; background: white; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; }
.md-table th { background: var(--primary); color: white; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
.md-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; }
.md-table tr:last-child td { border-bottom: none; }
.md-table tr.inactive td { opacity: 0.55; }
.bold { font-weight: 700; } .muted { color: #999; }
.empty-row { text-align: center; padding: 30px; color: #aaa; font-style: italic; }
.seq-badge { background: var(--primary); color: white; border-radius: 50%; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.status-dot { font-size: 11px; font-weight: 700; } .status-dot.on { color: #2e7d32; } .status-dot.off { color: #aaa; }
.actions { display: flex; gap: 6px; }
.btn-icon { padding: 4px 10px; border: 1px solid #ddd; background: white; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer; }
.btn-icon.edit { color: var(--primary); border-color: #bbdefb; } .btn-icon.del { color: #c62828; border-color: #ef9a9a; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-sm { padding: 6px 13px; font-size: 12px; }
.btn-primary { background: var(--primary); color: white; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; } .btn-danger { background: #c62828; color: white; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 24px 28px; width: 100%; max-width: 520px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); }
.modal-box.sm { max-width: 400px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.modal-box h3 { color: var(--primary); }
.modal-box p { color: #555; font-size: 14px; margin: 8px 0 16px; line-height: 1.5; }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input, .form-group select { padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }
</style>
