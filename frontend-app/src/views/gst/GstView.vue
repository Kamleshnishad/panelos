<template>
  <div>
    <div class="page-header"><div><div class="page-title">GST Configuration</div><div class="page-subtitle">Multi-state GST management & compliance</div></div></div>

    <div style="display:flex;gap:8px;margin-bottom:20px">
      <button v-for="t in tabs" :key="t" :class="['btn btn-sm', activeTab===t?'btn-primary':'btn-outline']" @click="activeTab=t">{{ t }}</button>
    </div>

    <!-- Register -->
    <div v-if="activeTab==='Register GST'" class="card">
      <div class="card-header"><div class="card-title">Register GST Configuration</div></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">State</label>
          <select v-model="gstForm.state_code" class="form-control">
            <option value="">Select State</option>
            <option v-for="(name, code) in states" :key="code" :value="code">{{ name }} ({{ code }})</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">GSTIN</label><input v-model="gstForm.gstin" class="form-control" placeholder="27AABCT1234H1Z0" /></div>
        <div class="form-group"><label class="form-label">Registration Type</label>
          <select v-model="gstForm.registration_type" class="form-control">
            <option>regular</option><option>composition</option><option>exempted</option>
          </select>
        </div>
      </div>
      <div v-if="msg" :class="'alert alert-'+msgType">{{ msg }}</div>
      <button class="btn btn-primary" @click="register" :disabled="saving">{{ saving ? 'Registering...' : 'Register GST' }}</button>
      <div class="card" style="margin-top:16px" v-if="configs.length">
        <div class="card-header"><div class="card-title">Registered Configurations</div></div>
        <div class="table-wrap"><table><thead><tr><th>State</th><th>GSTIN</th><th>Type</th><th>Primary</th></tr></thead>
          <tbody><tr v-for="c in configs" :key="c.id"><td>{{ c.state_name }} ({{ c.state_code }})</td><td>{{ c.gstin }}</td><td>{{ c.registration_type }}</td><td>{{ c.is_primary ? '⭐ Yes' : '—' }}</td></tr></tbody>
        </table></div>
      </div>
    </div>

    <!-- Compliance -->
    <div v-if="activeTab==='Compliance'" class="card">
      <div class="card-header"><div class="card-title">GST Compliance Summary</div><button class="btn btn-sm btn-outline" @click="loadCompliance">🔄 Refresh</button></div>
      <div v-if="compliance" class="kpi-grid">
        <div class="kpi-card blue"><div class="kpi-label">SGST Payable</div><div class="kpi-value">₹{{ fmt(compliance.sgst_payable) }}</div></div>
        <div class="kpi-card blue"><div class="kpi-label">CGST Payable</div><div class="kpi-value">₹{{ fmt(compliance.cgst_payable) }}</div></div>
        <div class="kpi-card orange"><div class="kpi-label">IGST Payable</div><div class="kpi-value">₹{{ fmt(compliance.igst_payable) }}</div></div>
        <div class="kpi-card green"><div class="kpi-label">Total GST Payable</div><div class="kpi-value">₹{{ fmt(compliance.total_gst_payable) }}</div></div>
        <div class="kpi-card"><div class="kpi-label">Intra-State Txns</div><div class="kpi-value">{{ compliance.intra_state_count ?? 0 }}</div></div>
        <div class="kpi-card"><div class="kpi-label">Inter-State Txns</div><div class="kpi-value">{{ compliance.inter_state_count ?? 0 }}</div></div>
      </div>
      <div v-else class="empty-state"><div class="icon">🏛️</div><p>Click Refresh to load compliance data</p></div>
    </div>

    <!-- Validate -->
    <div v-if="activeTab==='Validate GSTIN'" class="card">
      <div class="card-header"><div class="card-title">Validate GSTIN</div></div>
      <div class="form-row" style="grid-template-columns:1fr 1fr auto">
        <div class="form-group"><label class="form-label">GSTIN</label><input v-model="validateForm.gstin" class="form-control" placeholder="27AABCT1234H1Z0" /></div>
        <div class="form-group"><label class="form-label">State Code (optional)</label><input v-model="validateForm.state_code" class="form-control" placeholder="MH" /></div>
        <div class="form-group" style="display:flex;align-items:flex-end"><button class="btn btn-primary" @click="validateGstin">Validate</button></div>
      </div>
      <div v-if="validateResult" :class="'alert alert-'+(validateResult.success?'success':'error')">{{ validateResult.message }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
const tabs = ['Register GST', 'Compliance', 'Validate GSTIN']
const activeTab = ref('Register GST')
const states = ref({}); const configs = ref([]); const compliance = ref(null)
const saving = ref(false); const msg = ref(''); const msgType = ref('success')
const gstForm = ref({ state_code: '', gstin: '', registration_type: 'regular' })
const validateForm = ref({ gstin: '', state_code: '' }); const validateResult = ref(null)
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const register = async () => {
  saving.value = true; msg.value = ''
  try { await api.post('/gst/register', gstForm.value); msg.value = '✅ GST registered!'; msgType.value = 'success'; await loadConfigs() }
  catch (e) { msg.value = e.response?.data?.message || 'Failed'; msgType.value = 'error' }
  finally { saving.value = false }
}
const loadConfigs = async () => { try { const { data } = await api.get('/gst/configurations'); configs.value = data.data ?? [] } catch {} }
const loadCompliance = async () => { try { const { data } = await api.get('/gst/compliance'); compliance.value = data.data } catch {} }
const validateGstin = async () => {
  try { const { data } = await api.post('/gst/validate-gstin', validateForm.value); validateResult.value = data }
  catch (e) { validateResult.value = { success: false, message: e.response?.data?.message || 'Invalid' } }
}
onMounted(async () => {
  try { const { data } = await api.get('/gst/states'); states.value = data.data } catch {}
  await loadConfigs()
})
</script>
