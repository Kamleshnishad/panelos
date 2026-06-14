<template>
  <div class="gst-config-manager">
    <div class="config-header">
      <h3>⚙️ GST Configuration & Compliance</h3>
      <button @click="activeTab = 'register'" :class="['tab-btn', { active: activeTab === 'register' }]">
        Register GST
      </button>
      <button @click="activeTab = 'hsn'" :class="['tab-btn', { active: activeTab === 'hsn' }]">
        HSN Codes
      </button>
      <button @click="activeTab = 'report'" :class="['tab-btn', { active: activeTab === 'report' }]">
        GST Report
      </button>
      <button @click="activeTab = 'compliance'" :class="['tab-btn', { active: activeTab === 'compliance' }]">
        Compliance
      </button>
    </div>

    <!-- Register GST Section -->
    <div v-if="activeTab === 'register'" class="tab-content">
      <h4>Register GST Configuration</h4>

      <div class="form-group">
        <label>State</label>
        <select v-model="gstForm.stateCode" class="form-input">
          <option value="">-- Select State --</option>
          <option v-for="(name, code) in statesList" :key="code" :value="code">
            {{ name }} ({{ code }})
          </option>
        </select>
      </div>

      <div class="form-group">
        <label>GSTIN</label>
        <input
          v-model="gstForm.gstin"
          type="text"
          class="form-input"
          placeholder="27AABCT1234H1Z0"
          @blur="validateGstin"
        />
        <div v-if="gstinValidation" :class="['validation-result', gstinValidation.valid ? 'valid' : 'invalid']">
          {{ gstinValidation.message }}
        </div>
      </div>

      <div class="form-group">
        <label>Registration Type</label>
        <select v-model="gstForm.registrationType" class="form-input">
          <option value="regular">Regular</option>
          <option value="composition">Composition</option>
          <option value="exempted">Exempted</option>
        </select>
      </div>

      <button @click="registerGst" :disabled="loading || !gstinValidation?.valid" class="btn-primary">
        {{ loading ? 'Registering...' : 'Register GST' }}
      </button>

      <div v-if="configurations.length > 0" class="configurations-list">
        <h5>Registered Configurations</h5>
        <div v-for="config in configurations" :key="config.id" class="config-item">
          <div class="config-info">
            <strong>{{ config.state_name }} ({{ config.state_code }})</strong><br />
            GSTIN: {{ config.gstin }}<br />
            Type: {{ config.registration_type }}
            <span v-if="config.is_primary" class="primary-badge">Primary</span>
          </div>
          <div class="config-status" :class="{ active: config.is_active }">
            {{ config.is_active ? 'Active' : 'Inactive' }}
          </div>
        </div>
      </div>
    </div>

    <!-- HSN Codes Section -->
    <div v-if="activeTab === 'hsn'" class="tab-content">
      <h4>HSN/SAC Codes</h4>

      <div class="form-group">
        <label>HSN Code</label>
        <input v-model="hsnForm.code" type="text" class="form-input" placeholder="7308" />
      </div>

      <div class="form-group">
        <label>Description</label>
        <input v-model="hsnForm.description" type="text" class="form-input" placeholder="Structural Steel" />
      </div>

      <div class="form-group">
        <label>Category</label>
        <input v-model="hsnForm.category" type="text" class="form-input" placeholder="Steel Products" />
      </div>

      <div class="form-group">
        <label>GST Rate (%)</label>
        <select v-model="hsnForm.gstRate" class="form-input">
          <option value="">-- Select Rate --</option>
          <option value="0">0%</option>
          <option value="5">5%</option>
          <option value="12">12%</option>
          <option value="18">18%</option>
          <option value="28">28%</option>
        </select>
      </div>

      <div class="form-group">
        <label>Cess Rate (%) - Optional</label>
        <input v-model="hsnForm.cessRate" type="number" class="form-input" placeholder="0" />
      </div>

      <button @click="addHsnCode" :disabled="loading || !hsnForm.code" class="btn-primary">
        {{ loading ? 'Adding...' : 'Add HSN Code' }}
      </button>
    </div>

    <!-- GST Report Section -->
    <div v-if="activeTab === 'report'" class="tab-content">
      <h4>GST Report</h4>

      <div class="date-filters">
        <div class="form-group">
          <label>Start Date</label>
          <input v-model="reportFilters.startDate" type="date" class="form-input" />
        </div>
        <div class="form-group">
          <label>End Date</label>
          <input v-model="reportFilters.endDate" type="date" class="form-input" />
        </div>
        <button @click="generateReport" :disabled="loading" class="btn-primary">
          {{ loading ? 'Generating...' : 'Generate Report' }}
        </button>
      </div>

      <div v-if="gstReport" class="report-container">
        <div class="report-grid">
          <div class="report-card">
            <div class="report-label">Total Invoices</div>
            <div class="report-value">{{ gstReport.total_invoices }}</div>
          </div>
          <div class="report-card">
            <div class="report-label">Total SGST</div>
            <div class="report-value">₹{{ formatNumber(gstReport.total_sgst) }}</div>
          </div>
          <div class="report-card">
            <div class="report-label">Total CGST</div>
            <div class="report-value">₹{{ formatNumber(gstReport.total_cgst) }}</div>
          </div>
          <div class="report-card">
            <div class="report-label">Total IGST</div>
            <div class="report-value">₹{{ formatNumber(gstReport.total_igst) }}</div>
          </div>
          <div class="report-card highlight">
            <div class="report-label">Total GST</div>
            <div class="report-value">₹{{ formatNumber(gstReport.total_tax) }}</div>
          </div>
          <div class="report-card">
            <div class="report-label">Intra-State</div>
            <div class="report-value">{{ gstReport.intra_state_invoices }}</div>
          </div>
          <div class="report-card">
            <div class="report-label">Inter-State</div>
            <div class="report-value">{{ gstReport.inter_state_invoices }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Compliance Section -->
    <div v-if="activeTab === 'compliance'" class="tab-content">
      <h4>GST Compliance Summary</h4>

      <button @click="loadCompliance" :disabled="loading" class="btn-secondary">
        {{ loading ? 'Loading...' : 'Refresh Compliance' }}
      </button>

      <div v-if="complianceData" class="compliance-summary">
        <div class="compliance-card">
          <h5>Tax Payable</h5>
          <div class="tax-breakdown">
            <div class="tax-item">
              <span>SGST Payable:</span>
              <strong>₹{{ formatNumber(complianceData.sgst_payable) }}</strong>
            </div>
            <div class="tax-item">
              <span>CGST Payable:</span>
              <strong>₹{{ formatNumber(complianceData.cgst_payable) }}</strong>
            </div>
            <div class="tax-item">
              <span>IGST Payable:</span>
              <strong>₹{{ formatNumber(complianceData.igst_payable) }}</strong>
            </div>
            <div class="tax-item">
              <span>Cess Payable:</span>
              <strong>₹{{ formatNumber(complianceData.cess_payable) }}</strong>
            </div>
            <div class="tax-item total">
              <span>Total GST Payable:</span>
              <strong>₹{{ formatNumber(complianceData.total_gst_payable) }}</strong>
            </div>
          </div>
        </div>

        <div class="compliance-card">
          <h5>Transaction Summary</h5>
          <div class="transaction-summary">
            <div class="summary-item">
              <span>Intra-State Transactions:</span>
              <strong>{{ complianceData.intra_state_count }}</strong>
            </div>
            <div class="summary-item">
              <span>Inter-State Transactions:</span>
              <strong>{{ complianceData.inter_state_count }}</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="message" :class="['alert', messageType]">
      {{ message }}
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/services/api'

const activeTab = ref('register')
const loading = ref(false)
const message = ref(null)
const messageType = ref('success')
const gstinValidation = ref(null)
const statesList = ref({})
const configurations = ref([])
const gstReport = ref(null)
const complianceData = ref(null)

const gstForm = ref({
  stateCode: '',
  gstin: '',
  registrationType: 'regular'
})

const hsnForm = ref({
  code: '',
  description: '',
  category: '',
  gstRate: '',
  cessRate: '0'
})

const reportFilters = ref({
  startDate: '',
  endDate: ''
})

const formatNumber = (num) => {
  return parseFloat(num).toFixed(2)
}

const validateGstin = async () => {
  if (!gstForm.value.gstin) return

  try {
    const response = await api.post('/gst/validate-gstin', {
      gstin: gstForm.value.gstin,
      state_code: gstForm.value.stateCode
    })

    gstinValidation.value = {
      valid: response.data.success,
      message: response.data.message
    }
  } catch (e) {
    gstinValidation.value = {
      valid: false,
      message: 'Validation failed'
    }
  }
}

const registerGst = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await api.post('/gst/register', {
      state_code: gstForm.value.stateCode,
      gstin: gstForm.value.gstin,
      registration_type: gstForm.value.registrationType
    })

    if (response.data.success) {
      message.value = '✅ GST configuration registered successfully!'
      messageType.value = 'success'
      gstForm.value = { stateCode: '', gstin: '', registrationType: 'regular' }
      gstinValidation.value = null
      await loadConfigurations()
    }
  } catch (e) {
    message.value = 'Error: ' + (e.response?.data?.message || e.message)
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const addHsnCode = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await api.post('/gst/hsn-code', {
      code: hsnForm.value.code,
      description: hsnForm.value.description,
      category: hsnForm.value.category,
      gst_rate: parseFloat(hsnForm.value.gstRate),
      cess_rate: parseFloat(hsnForm.value.cessRate || 0)
    })

    if (response.data.success) {
      message.value = '✅ HSN code added successfully!'
      messageType.value = 'success'
      hsnForm.value = { code: '', description: '', category: '', gstRate: '', cessRate: '0' }
    }
  } catch (e) {
    message.value = 'Error: ' + (e.response?.data?.message || e.message)
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const generateReport = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await api.get('/gst/report', {
      params: {
        start_date: reportFilters.value.startDate,
        end_date: reportFilters.value.endDate
      }
    })

    if (response.data.success) {
      gstReport.value = response.data.data
    }
  } catch (e) {
    message.value = 'Error generating report: ' + e.message
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const loadCompliance = async () => {
  loading.value = true

  try {
    const response = await api.get('/gst/compliance')
    if (response.data.success) {
      complianceData.value = response.data.data
    }
  } catch (e) {
    message.value = 'Error loading compliance data: ' + e.message
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const loadConfigurations = async () => {
  try {
    const response = await api.get('/gst/configurations')
    if (response.data.success) {
      configurations.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to load configurations:', e)
  }
}

const loadStatesList = async () => {
  try {
    const response = await api.get('/gst/states')
    if (response.data.success) {
      statesList.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to load states:', e)
  }
}

onMounted(() => {
  loadStatesList()
  loadConfigurations()
  loadCompliance()
})
</script>

<style scoped>
.gst-config-manager {
  padding: 20px;
  background: white;
  border: 1px solid #ddd;
  border-radius: 8px;
  margin: 20px 0;
}

.config-header {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  align-items: center;
  padding-bottom: 15px;
  border-bottom: 2px solid #f0f0f0;
}

.config-header h3 {
  margin: 0;
  flex: 1;
}

.tab-btn {
  padding: 8px 16px;
  border: 1px solid #ddd;
  background: white;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  transition: all 0.2s;
}

.tab-btn:hover {
  border-color: #1976d2;
  background: #f5f5f5;
}

.tab-btn.active {
  border-color: #1976d2;
  background: #1976d2;
  color: white;
}

.tab-content {
  animation: fadeIn 0.3s ease-in;
}

.tab-content h4 {
  margin-top: 0;
  margin-bottom: 15px;
  font-size: 16px;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 6px;
  font-size: 13px;
}

.form-input {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 13px;
  box-sizing: border-box;
}

.form-input:focus {
  outline: none;
  border-color: #1976d2;
  box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

.validation-result {
  margin-top: 6px;
  padding: 6px;
  border-radius: 3px;
  font-size: 12px;
}

.validation-result.valid {
  background-color: #e8f5e9;
  color: #2e7d32;
}

.validation-result.invalid {
  background-color: #ffebee;
  color: #c62828;
}

.btn-primary,
.btn-secondary {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
  margin-top: 10px;
}

.btn-primary:hover:not(:disabled) {
  background-color: var(--primary);
}

.btn-primary:disabled {
  background-color: #bdbdbd;
  cursor: not-allowed;
}

.btn-secondary {
  background-color: #757575;
  color: white;
}

.btn-secondary:hover:not(:disabled) {
  background-color: #616161;
}

.configurations-list {
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid #ddd;
}

.configurations-list h5 {
  margin: 0 0 10px 0;
  font-size: 14px;
}

.config-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px;
  background: #f9f9f9;
  border-radius: 4px;
  margin-bottom: 10px;
  font-size: 13px;
}

.config-info {
  flex: 1;
}

.primary-badge {
  display: inline-block;
  background: #1976d2;
  color: white;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 11px;
  margin-left: 10px;
}

.config-status {
  padding: 4px 10px;
  background: #f5f5f5;
  border-radius: 3px;
  font-weight: 600;
}

.config-status.active {
  background: #e8f5e9;
  color: #2e7d32;
}

.date-filters {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 15px;
  margin-bottom: 20px;
}

.report-container {
  margin-top: 20px;
}

.report-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
}

.report-card {
  padding: 15px;
  background: #f9f9f9;
  border: 1px solid #ddd;
  border-radius: 4px;
  text-align: center;
}

.report-card.highlight {
  border: 2px solid #1976d2;
  background: var(--primary-tint);
}

.report-label {
  font-size: 12px;
  color: #666;
  margin-bottom: 8px;
}

.report-value {
  font-size: 20px;
  font-weight: 600;
  color: #333;
}

.compliance-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.compliance-card {
  padding: 15px;
  background: #f9f9f9;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.compliance-card h5 {
  margin: 0 0 12px 0;
  font-size: 14px;
}

.tax-breakdown,
.transaction-summary {
  display: grid;
  gap: 10px;
}

.tax-item,
.summary-item {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #e0e0e0;
}

.tax-item:last-child,
.summary-item:last-child {
  border-bottom: none;
}

.tax-item.total {
  font-weight: 600;
  font-size: 14px;
  border-top: 2px solid #333;
  padding-top: 10px;
}

.alert {
  padding: 12px;
  border-radius: 4px;
  margin-top: 15px;
  font-size: 14px;
}

.alert.success {
  background-color: #e8f5e9;
  border: 1px solid #81c784;
  color: #2e7d32;
}

.alert.error {
  background-color: #ffebee;
  border: 1px solid #ef5350;
  color: #c62828;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(-5px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 768px) {
  .config-header {
    flex-wrap: wrap;
  }

  .date-filters {
    grid-template-columns: 1fr;
  }

  .report-grid {
    grid-template-columns: 1fr;
  }
}
</style>
