<template>
  <div>
    <div class="page-header">
      <div>
        <div class="page-title">Settings</div>
        <div class="page-subtitle">Manage panel types, accessories, and master data</div>
      </div>
    </div>

    <!-- Tab nav -->
    <div class="tab-bar">
      <button :class="['tab-btn', { active: tab === 'panel-types' }]" @click="tab = 'panel-types'">Panel Types</button>
      <button :class="['tab-btn', { active: tab === 'accessories' }]" @click="tab = 'accessories'">Accessories</button>
    </div>

    <!-- ── PANEL TYPES ─────────────────────────────────────────────────── -->
    <div v-if="tab === 'panel-types'">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Panel Types</div>
          <button class="btn btn-primary btn-sm" @click="openPanelTypeForm()">+ New Panel Type</button>
        </div>

        <div v-if="ptLoading" class="loading"><div class="spinner"></div></div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Category</th>
                <th>HSN Code</th>
                <th class="text-right">Base Price (₹/SQM)</th>
                <th>Default Surface</th>
                <th>Thicknesses</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pt in panelTypes" :key="pt.id">
                <td style="font-weight:600">{{ pt.name }}</td>
                <td style="font-family:monospace">{{ pt.code }}</td>
                <td><span :class="'cat-badge cat-'+pt.category">{{ pt.category }}</span></td>
                <td style="font-size:12px;color:#888">{{ pt.hsn_code }}</td>
                <td class="text-right">₹ {{ Number(pt.base_price).toLocaleString('en-IN') }}</td>
                <td style="font-size:12px">{{ pt.category === 'roof' ? 'RIBBED' : 'PLAIN' }}</td>
                <td style="font-size:12px;color:#555">
                  {{ pt.available_thicknesses ? pt.available_thicknesses.join(', ') + ' mm' : 'Default (all)' }}
                </td>
                <td><span :class="pt.is_active ? 'badge badge-success' : 'badge badge-rejected'">{{ pt.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>
                  <div style="display:flex;gap:4px">
                    <button class="btn btn-sm btn-outline" @click="openPanelTypeForm(pt)">Edit</button>
                    <button v-if="pt.is_active" class="btn btn-sm btn-outline" style="color:#c62828" @click="deactivatePt(pt)">Deactivate</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!panelTypes.length">
                <td colspan="9"><div class="empty-state"><div class="icon">📐</div><p>No panel types configured. Add your first panel type.</p></div></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── ACCESSORIES ────────────────────────────────────────────────── -->
    <div v-if="tab === 'accessories'">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Accessories Master</div>
          <button class="btn btn-primary btn-sm" @click="openAccForm()">+ New Accessory</button>
        </div>

        <div v-if="accLoading" class="loading"><div class="spinner"></div></div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Unit</th>
                <th>HSN Code</th>
                <th class="text-right">Rate (₹)</th>
                <th>Description</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="acc in accessories" :key="acc.id">
                <td style="font-weight:600">{{ acc.name }}</td>
                <td style="font-family:monospace">{{ acc.code }}</td>
                <td>{{ acc.unit }}</td>
                <td style="font-size:12px;color:#888">{{ acc.hsn_code }}</td>
                <td class="text-right">₹ {{ Number(acc.rate || acc.unit_price).toLocaleString('en-IN') }}</td>
                <td style="font-size:12px;color:#555;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ acc.description || '—' }}</td>
                <td><span :class="acc.is_active ? 'badge badge-success' : 'badge badge-rejected'">{{ acc.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>
                  <div style="display:flex;gap:4px">
                    <button class="btn btn-sm btn-outline" @click="openAccForm(acc)">Edit</button>
                    <button class="btn btn-sm btn-outline" style="color:#c62828" @click="deleteAcc(acc)">Delete</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!accessories.length">
                <td colspan="8"><div class="empty-state"><div class="icon">🔩</div><p>No accessories configured. Add GI accessories, ridges, etc.</p></div></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── PANEL TYPE FORM MODAL ───────────────────────────────────────── -->
    <div v-if="ptModal" class="modal-overlay" @click.self="ptModal=false">
      <div class="modal" style="max-width:640px">
        <div class="modal-header">
          <div class="modal-title">{{ ptForm.id ? 'Edit Panel Type' : 'New Panel Type' }}</div>
          <button class="modal-close" @click="ptModal=false">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Name *</label>
              <input v-model="ptForm.name" class="form-control" placeholder="e.g. PUF Roof Panel" />
            </div>
            <div class="form-group">
              <label class="form-label">Code *</label>
              <input v-model="ptForm.code" class="form-control" placeholder="e.g. ROOF-PUF" :disabled="!!ptForm.id" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Category *</label>
              <select v-model="ptForm.category" class="form-control">
                <option value="roof">Roof Panel</option>
                <option value="wall">Wall Panel</option>
                <option value="ceiling">Ceiling Panel</option>
                <option value="cold_room">Cold Room Panel</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">HSN Code</label>
              <input v-model="ptForm.hsn_code" class="form-control" placeholder="39259010" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Base Price (₹/SQM) *</label>
              <input v-model.number="ptForm.base_price" type="number" min="0" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label">Status</label>
              <select v-model="ptForm.is_active" class="form-control">
                <option :value="true">Active</option>
                <option :value="false">Inactive</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Available Thicknesses (mm) — select all that apply</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:6px">
              <label v-for="t in allThicknesses" :key="t" style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer">
                <input type="checkbox" :value="t" v-model="ptForm.available_thicknesses" />
                {{ t }}
              </label>
            </div>
            <div style="font-size:11px;color:#888;margin-top:4px">Leave all unchecked to allow all standard thicknesses.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea v-model="ptForm.description" class="form-control" rows="2" placeholder="Optional description"></textarea>
          </div>
          <div v-if="ptError" class="alert alert-error">{{ ptError }}</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" @click="ptModal=false">Cancel</button>
          <button class="btn btn-primary" :disabled="ptSaving" @click="savePanelType">{{ ptSaving ? 'Saving…' : 'Save Panel Type' }}</button>
        </div>
      </div>
    </div>

    <!-- ── ACCESSORY FORM MODAL ───────────────────────────────────────── -->
    <div v-if="accModal" class="modal-overlay" @click.self="accModal=false">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title">{{ accForm.id ? 'Edit Accessory' : 'New Accessory' }}</div>
          <button class="modal-close" @click="accModal=false">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Name *</label>
              <input v-model="accForm.name" class="form-control" placeholder="e.g. GI Ridge Cap" />
            </div>
            <div class="form-group">
              <label class="form-label">Code *</label>
              <input v-model="accForm.code" class="form-control" placeholder="e.g. RIDGE-GI" :disabled="!!accForm.id" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Unit *</label>
              <select v-model="accForm.unit" class="form-control">
                <option value="NOS">NOS (Numbers)</option>
                <option value="RMT">RMT (Running Metre)</option>
                <option value="SQM">SQM (Square Metre)</option>
                <option value="KG">KG (Kilogram)</option>
                <option value="SET">SET</option>
                <option value="LS">LS (Lump Sum)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">HSN Code</label>
              <input v-model="accForm.hsn_code" class="form-control" placeholder="73089090" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Rate (₹) *</label>
              <input v-model.number="accForm.rate" type="number" min="0" class="form-control" />
            </div>
            <div class="form-group" v-if="accForm.id">
              <label class="form-label">Status</label>
              <select v-model="accForm.is_active" class="form-control">
                <option :value="true">Active</option>
                <option :value="false">Inactive</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea v-model="accForm.description" class="form-control" rows="2" placeholder="Optional description"></textarea>
          </div>
          <div v-if="accError" class="alert alert-error">{{ accError }}</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" @click="accModal=false">Cancel</button>
          <button class="btn btn-primary" :disabled="accSaving" @click="saveAccessory">{{ accSaving ? 'Saving…' : 'Save Accessory' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const tab = ref('panel-types')

// ── Panel Types ────────────────────────────────────────────────────
const panelTypes = ref([])
const ptLoading = ref(false)
const ptModal = ref(false)
const ptSaving = ref(false)
const ptError = ref('')
const allThicknesses = [30, 40, 50, 60, 75, 80, 100, 120, 150, 200]

const defaultPtForm = () => ({
  id: null, name: '', code: '', category: 'wall', hsn_code: '39259010',
  base_price: 850, description: '', is_active: true, available_thicknesses: [],
})
const ptForm = ref(defaultPtForm())

async function loadPanelTypes() {
  ptLoading.value = true
  try {
    const { data } = await api.get('/panel-types', { params: { all: true } })
    panelTypes.value = data.data ?? []
  } finally { ptLoading.value = false }
}

function openPanelTypeForm(pt = null) {
  ptError.value = ''
  if (pt) {
    ptForm.value = { ...pt, available_thicknesses: pt.available_thicknesses ?? [] }
  } else {
    ptForm.value = defaultPtForm()
  }
  ptModal.value = true
}

async function savePanelType() {
  ptError.value = ''
  if (!ptForm.value.name || !ptForm.value.code || !ptForm.value.category || !ptForm.value.base_price) {
    ptError.value = 'Name, Code, Category and Base Price are required.'; return
  }
  ptSaving.value = true
  try {
    const payload = { ...ptForm.value }
    if (!payload.available_thicknesses?.length) payload.available_thicknesses = null
    if (ptForm.value.id) {
      await api.put(`/panel-types/${ptForm.value.id}`, payload)
    } else {
      await api.post('/panel-types', payload)
    }
    ptModal.value = false
    loadPanelTypes()
  } catch (e) {
    const errs = e.response?.data?.errors
    ptError.value = errs ? Object.values(errs).flat().join(' | ') : (e.response?.data?.message || 'Save failed')
  } finally { ptSaving.value = false }
}

async function deactivatePt(pt) {
  if (!confirm(`Deactivate panel type "${pt.name}"?`)) return
  try {
    await api.delete(`/panel-types/${pt.id}`)
    loadPanelTypes()
  } catch (e) { alert(e.response?.data?.message || 'Failed') }
}

// ── Accessories ────────────────────────────────────────────────────
const accessories = ref([])
const accLoading = ref(false)
const accModal = ref(false)
const accSaving = ref(false)
const accError = ref('')

const defaultAccForm = () => ({
  id: null, name: '', code: '', unit: 'NOS', hsn_code: '73089090',
  rate: 0, description: '', is_active: true,
})
const accForm = ref(defaultAccForm())

async function loadAccessories() {
  accLoading.value = true
  try {
    const { data } = await api.get('/accessories', { params: { per_page: 200 } })
    accessories.value = data.data?.data ?? data.data ?? []
  } finally { accLoading.value = false }
}

function openAccForm(acc = null) {
  accError.value = ''
  accForm.value = acc ? { ...acc } : defaultAccForm()
  accModal.value = true
}

async function saveAccessory() {
  accError.value = ''
  if (!accForm.value.name || !accForm.value.code) {
    accError.value = 'Name and Code are required.'; return
  }
  accSaving.value = true
  try {
    const payload = { ...accForm.value, unit_price: accForm.value.rate }
    if (accForm.value.id) {
      await api.put(`/accessories/${accForm.value.id}`, payload)
    } else {
      await api.post('/accessories', payload)
    }
    accModal.value = false
    loadAccessories()
  } catch (e) {
    const errs = e.response?.data?.errors
    accError.value = errs ? Object.values(errs).flat().join(' | ') : (e.response?.data?.message || 'Save failed')
  } finally { accSaving.value = false }
}

async function deleteAcc(acc) {
  if (!confirm(`Delete accessory "${acc.name}"?`)) return
  try {
    await api.delete(`/accessories/${acc.id}`)
    loadAccessories()
  } catch (e) { alert(e.response?.data?.message || 'Failed to delete') }
}

onMounted(() => { loadPanelTypes(); loadAccessories() })
</script>

<style scoped>
.tab-bar { display:flex; gap:4px; margin-bottom:16px; border-bottom:2px solid #e0e0e0; }
.tab-btn { padding:10px 20px; border:none; background:none; font-size:14px; font-weight:600; color:#888; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; }
.tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); }
.tab-btn:hover { color:var(--primary); }

.cat-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; text-transform:uppercase; }
.cat-roof     { background:#e3f2fd; color:#1565c0; }
.cat-wall     { background:#e8f5e9; color:#2e7d32; }
.cat-ceiling  { background:#fff8e1; color:#f57f17; }
.cat-cold_room{ background:#f3e5f5; color:#6a1b9a; }

.text-right { text-align:right; }
</style>
