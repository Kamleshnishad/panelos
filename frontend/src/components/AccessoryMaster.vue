<template>
  <div class="md-section">
    <div class="md-head">
      <div class="md-title"><h3>Accessories</h3></div>
      <button class="btn btn-primary btn-sm" @click="openCreate">+ Add Accessory</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="success" class="success-banner">{{ success }}</div>

    <div v-if="loading" class="loading-row">Loading…</div>
    <table v-else class="md-table">
      <thead>
        <tr><th style="width:52px">Image</th><th>Code</th><th>Name</th><th>Unit</th><th>HSN</th><th class="text-right">Rate (₹)</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-if="rows.length === 0"><td colspan="8" class="empty-row">No accessories.</td></tr>
        <tr v-for="r in rows" :key="r.id" :class="{ inactive: !r.is_active }">
          <td><img v-if="r.image_url" :src="r.image_url" class="thumb" alt="" /><span v-else class="thumb-ph">—</span></td>
          <td class="mono bold">{{ r.code }}</td>
          <td>{{ r.name }}</td>
          <td>{{ r.unit }}</td>
          <td class="muted">{{ r.hsn_code }}</td>
          <td class="text-right bold">{{ fmtNum(r.rate ?? r.unit_price) }}</td>
          <td><span :class="['status-dot', r.is_active ? 'on' : 'off']">{{ r.is_active ? 'Active' : 'Inactive' }}</span></td>
          <td class="actions">
            <button class="btn-icon edit" @click="openEdit(r)">Edit</button>
            <button v-if="r.is_active" class="btn-icon del" @click="confirmDelete(r)">Deactivate</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="pagination" v-if="pagination.last_page > 1">
      <button class="pg-btn" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">← Prev</button>
      <span class="page-info">Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} total</span>
      <button class="pg-btn" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next →</button>
    </div>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>{{ editing ? 'Edit Accessory' : 'New Accessory' }}</h3>
          <button class="btn-close" @click="showModal = false">✕</button>
        </div>
        <div class="form-grid">
          <div class="form-group"><label>Code *</label><input v-model="form.code" placeholder="ACC-001" /></div>
          <div class="form-group"><label>Name *</label><input v-model="form.name" placeholder="GI Flashing" /></div>
          <div class="form-group">
            <label>Unit</label>
            <select v-model="form.unit">
              <option value="NOS">NOS</option><option value="MTR">MTR</option><option value="SQM">SQM</option>
              <option value="KG">KG</option><option value="SET">SET</option>
            </select>
          </div>
          <div class="form-group"><label>HSN Code</label><input v-model="form.hsn_code" placeholder="73089090" /></div>
          <div class="form-group"><label>Rate (₹)</label><input v-model.number="form.rate" type="number" min="0" step="0.01" /></div>
          <div class="form-group full"><label>Description</label><input v-model="form.description" /></div>
          <div class="form-group" v-if="editing">
            <label>Status</label>
            <select v-model="form.is_active"><option :value="true">Active</option><option :value="false">Inactive</option></select>
          </div>
        </div>

        <div class="image-block" v-if="editing">
          <div class="image-preview"><img v-if="imageUrl" :src="imageUrl" alt="" /><span v-else>No image</span></div>
          <div class="image-actions">
            <label class="btn btn-secondary">
              {{ uploadingImg ? 'Uploading…' : 'Upload Image' }}
              <input type="file" accept="image/*" hidden :disabled="uploadingImg" @change="onImageChange" />
            </label>
            <span class="img-hint">Shown on the quotation / proforma PDF. PNG/JPG up to 3 MB.</span>
          </div>
        </div>
        <div class="image-block hint-only" v-else>
          <span class="img-hint">Save the accessory first, then re-open it to upload an image.</span>
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
        <h3>Deactivate Accessory?</h3>
        <p><strong>{{ deleteTarget.name }}</strong> will be hidden from new quotations.</p>
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
const imageUrl = ref(null)
const uploadingImg = ref(false)

const form = reactive({ code: '', name: '', unit: 'NOS', hsn_code: '73089090', rate: null, description: '', is_active: true })

const pagination = reactive({ current_page: 1, last_page: 1, total: 0 })

async function load(page = 1) {
  loading.value = true; error.value = null
  try {
    const res  = await masterDataService.accessories({ page, per_page: 50 })
    const body = res?.data ?? {}
    rows.value = body.data ?? (Array.isArray(body) ? body : [])
    // Handle both paginatedResponse (meta.pagination) and raw-paginator shapes
    const pg = body.meta?.pagination ?? body
    const per = pg.per_page ?? 50
    pagination.current_page = pg.current_page ?? 1
    pagination.total        = pg.total ?? rows.value.length
    pagination.last_page    = pg.last_page ?? (per ? Math.max(1, Math.ceil((pg.total ?? 0) / per)) : 1)
  } catch (e) { error.value = e?.response?.data?.message ?? 'Failed to load.' }
  finally { loading.value = false }
}

function goPage(p) { if (p < 1 || p > pagination.last_page) return; load(p) }

function openCreate() {
  editing.value = false; editId.value = null; modalError.value = null
  imageUrl.value = null
  Object.assign(form, { code: '', name: '', unit: 'NOS', hsn_code: '73089090', rate: null, description: '', is_active: true })
  showModal.value = true
}
function openEdit(r) {
  editing.value = true; editId.value = r.id; modalError.value = null
  imageUrl.value = r.image_url ?? null
  Object.assign(form, { code: r.code, name: r.name, unit: r.unit ?? 'NOS', hsn_code: r.hsn_code ?? '', rate: Number(r.rate ?? r.unit_price ?? 0), description: r.description ?? '', is_active: !!r.is_active })
  showModal.value = true
}

async function onImageChange(e) {
  const file = e.target.files?.[0]
  if (!file) return
  uploadingImg.value = true; modalError.value = null
  try {
    const res = await masterDataService.uploadAccessoryImage(editId.value, file)
    imageUrl.value = (res?.data ?? res)?.image_url ?? imageUrl.value
    await load()
  } catch (err) {
    modalError.value = err?.response?.data?.message ?? 'Failed to upload image.'
  } finally {
    uploadingImg.value = false
    e.target.value = ''
  }
}

async function save() {
  saving.value = true; modalError.value = null
  try {
    const payload = { ...form, unit_price: form.rate }
    if (editing.value) await masterDataService.updateAccessory(editId.value, payload)
    else await masterDataService.createAccessory(payload)
    showModal.value = false
    success.value = editing.value ? 'Accessory updated.' : 'Accessory created.'
    await load()
  } catch (e) {
    modalError.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Failed to save.'
  } finally { saving.value = false }
}

function confirmDelete(r) { deleteTarget.value = r }
async function doDelete() {
  saving.value = true
  try {
    await masterDataService.deleteAccessory(deleteTarget.value.id)
    deleteTarget.value = null
    success.value = 'Accessory deactivated.'
    await load()
  } catch (e) { error.value = e?.response?.data?.message ?? 'Failed.' }
  finally { saving.value = false }
}

function fmtNum(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

onMounted(load)
</script>

<style scoped>
.md-section { }
.md-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
.md-title h3 { margin: 0; font-size: 16px; color: var(--primary); }
.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 10px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 10px; }
.loading-row { padding: 30px; text-align: center; color: #888; }
.md-table { width: 100%; border-collapse: collapse; font-size: 13px; background: white; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; }
.md-table th { background: var(--primary); color: white; padding: 9px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
.md-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; }
.md-table tr:last-child td { border-bottom: none; }
.md-table tr.inactive td { opacity: 0.55; }
.mono { font-family: monospace; } .bold { font-weight: 700; } .muted { color: #999; } .text-right { text-align: right; }
.empty-row { text-align: center; padding: 30px; color: #aaa; font-style: italic; }
.status-dot { font-size: 11px; font-weight: 700; } .status-dot.on { color: #2e7d32; } .status-dot.off { color: #aaa; }
.actions { display: flex; gap: 6px; }
.btn-icon { padding: 4px 10px; border: 1px solid #ddd; background: white; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer; }
.btn-icon.edit { color: var(--primary); border-color: #bbdefb; } .btn-icon.del { color: #c62828; border-color: #ef9a9a; }
.btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-sm { padding: 6px 13px; font-size: 12px; }
.btn-primary { background: var(--primary); color: white; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; } .btn-danger { background: #c62828; color: white; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-box { background: white; border-radius: 12px; padding: 24px 28px; width: 100%; max-width: 560px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); }
.modal-box.sm { max-width: 400px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.modal-header h3 { margin: 0; font-size: 17px; color: var(--primary); }
.modal-box h3 { color: var(--primary); }
.modal-box p { color: #555; font-size: 14px; margin: 8px 0 16px; line-height: 1.5; }
.btn-close { background: none; border: none; font-size: 18px; color: #aaa; cursor: pointer; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 560px) { .form-grid { grid-template-columns: 1fr; } }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.form-group input, .form-group select { padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.error-msg { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }

/* Image thumbnails + upload */
.thumb { width: 38px; height: 38px; object-fit: cover; border-radius: 6px; border: 1px solid #e0e0e0; display: block; }
.thumb-ph { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 6px; background: #f2f4f7; color: #b0b7c3; font-size: 14px; }
.image-block { display: flex; align-items: center; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
.image-block.hint-only { color: #999; font-size: 12px; }
.image-preview { width: 76px; height: 76px; border: 2px dashed #ddd; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; background: #fafafa; color: #bbb; font-size: 11px; }
.image-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.image-actions { display: flex; flex-direction: column; gap: 6px; }
.btn-secondary { background: var(--primary-tint); color: var(--primary); display: inline-block; padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.img-hint { font-size: 11px; color: #aaa; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 14px 0 4px; }
.page-info  { font-size: 12px; color: #666; font-variant-numeric: tabular-nums; }
.pg-btn { padding: 5px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
.pg-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
