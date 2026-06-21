<template>
  <div class="cs-wrap">
    <div class="cs-header">
      <h2>Company Settings</h2>
      <button class="btn btn-primary" :disabled="saving || !canEdit" @click="save">{{ saving ? 'Saving…' : 'Save Changes' }}</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="success" class="success-banner">{{ success }}</div>
    <div v-if="!canEdit && form" class="info-banner">You have read-only access. Only company admins can edit these settings.</div>

    <div v-if="loading" class="loading-state">Loading company…</div>

    <div v-else-if="form" class="cs-body">
      <!-- Logo + identity -->
      <section class="card">
        <h3>Identity &amp; Branding</h3>
        <div class="logo-row">
          <div class="logo-box">
            <img v-if="logoUrl" :src="logoUrl" alt="logo" class="logo-img" />
            <div v-else class="logo-placeholder">No Logo</div>
          </div>
          <div class="logo-actions">
            <label class="btn btn-secondary" :class="{ disabled: !canEdit }">
              {{ uploadingLogo ? 'Uploading…' : 'Upload Logo' }}
              <input type="file" accept="image/*" hidden :disabled="!canEdit || uploadingLogo" @change="onLogoChange" />
            </label>
            <span class="hint">PNG/JPG up to 2 MB. Shown on quotations, invoices &amp; challans.</span>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group full">
            <label>Company Name *</label>
            <input v-model="form.name" :disabled="!canEdit" />
          </div>
          <div class="form-group">
            <label>Primary Color</label>
            <div class="color-row">
              <input type="color" v-model="form.primary_color" :disabled="!canEdit" />
              <input v-model="form.primary_color" :disabled="!canEdit" placeholder="#2B50E0" />
            </div>
          </div>
          <div class="form-group">
            <label>Secondary Color</label>
            <div class="color-row">
              <input type="color" v-model="form.secondary_color" :disabled="!canEdit" />
              <input v-model="form.secondary_color" :disabled="!canEdit" placeholder="#3949ab" />
            </div>
          </div>
        </div>
      </section>

      <!-- Address & contact -->
      <section class="card">
        <h3>Address &amp; Contact</h3>
        <div class="form-grid">
          <div class="form-group full"><label>Address Line</label><input v-model="form.address_line1" :disabled="!canEdit" /></div>
          <div class="form-group"><label>City</label><input v-model="form.city" :disabled="!canEdit" /></div>
          <div class="form-group"><label>State</label><input v-model="form.state" :disabled="!canEdit" /></div>
          <div class="form-group"><label>State Code (GST)</label><input v-model="form.state_code" :disabled="!canEdit" placeholder="e.g. GJ" /></div>
          <div class="form-group"><label>Pincode</label><input v-model="form.pincode" :disabled="!canEdit" /></div>
          <div class="form-group"><label>Phone</label><input v-model="form.phone" :disabled="!canEdit" /></div>
          <div class="form-group"><label>Email</label><input v-model="form.email" :disabled="!canEdit" /></div>
        </div>
      </section>

      <!-- Tax -->
      <section class="card">
        <h3>Tax &amp; Compliance</h3>
        <div class="form-grid">
          <div class="form-group"><label>GSTIN</label><input v-model="form.gstin" :disabled="!canEdit" /></div>
          <div class="form-group"><label>PAN</label><input v-model="form.pan" :disabled="!canEdit" /></div>
          <div class="form-group">
            <label>Financial Year Start</label>
            <select v-model.number="form.financial_year_start" :disabled="!canEdit">
              <option v-for="(m, i) in months" :key="i" :value="i + 1">{{ m }}</option>
            </select>
          </div>
          <div class="form-group toggles">
            <label class="toggle"><input type="checkbox" v-model="form.e_invoice_applicable" :disabled="!canEdit" /> E-Invoice Applicable</label>
            <label class="toggle"><input type="checkbox" v-model="form.tcs_applicable" :disabled="!canEdit" /> TCS Applicable</label>
          </div>
        </div>
      </section>

      <!-- Bank -->
      <section class="card">
        <h3>Bank Details</h3>
        <div class="form-grid">
          <div class="form-group"><label>Bank Name</label><input v-model="form.bank_name" :disabled="!canEdit" /></div>
          <div class="form-group"><label>Account No</label><input v-model="form.bank_account_no" :disabled="!canEdit" /></div>
          <div class="form-group"><label>IFSC</label><input v-model="form.bank_ifsc" :disabled="!canEdit" /></div>
          <div class="form-group"><label>Branch</label><input v-model="form.bank_branch" :disabled="!canEdit" /></div>
          <div class="form-group"><label>Authorized Signatory</label><input v-model="form.authorized_signatory" :disabled="!canEdit" /></div>
          <div class="form-group"><label>Signatory Phone</label><input v-model="form.signatory_phone" :disabled="!canEdit" /></div>
        </div>
      </section>

      <!-- Document prefixes -->
      <section class="card">
        <h3>Document Number Prefixes</h3>
        <div class="form-grid">
          <div class="form-group"><label>Quotation</label><input v-model="form.quotation_prefix" :disabled="!canEdit" placeholder="SCP" /></div>
          <div class="form-group"><label>Order</label><input v-model="form.order_prefix" :disabled="!canEdit" placeholder="ORD" /></div>
          <div class="form-group"><label>Invoice</label><input v-model="form.invoice_prefix" :disabled="!canEdit" placeholder="INV" /></div>
          <div class="form-group"><label>Challan</label><input v-model="form.challan_prefix" :disabled="!canEdit" placeholder="DISP" /></div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import companyService from '../services/companyService.js'

const loading       = ref(false)
const saving        = ref(false)
const uploadingLogo = ref(false)
const error         = ref(null)
const success       = ref(null)
const logoUrl       = ref(null)
const canEdit       = ref(true)

const months = ['January','February','March','April','May','June','July','August','September','October','November','December']

const form = ref(null)

const fields = [
  'name','gstin','pan','address_line1','city','state','state_code','pincode','phone','email',
  'bank_name','bank_account_no','bank_ifsc','bank_branch','authorized_signatory','signatory_phone',
  'primary_color','secondary_color','quotation_prefix','invoice_prefix','order_prefix','challan_prefix',
  'financial_year_start','e_invoice_applicable','tcs_applicable',
]

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await companyService.get()
    const c = res?.data ?? res
    const f = {}
    fields.forEach(k => { f[k] = c[k] ?? (k === 'financial_year_start' ? 4 : '') })
    f.e_invoice_applicable = !!c.e_invoice_applicable
    f.tcs_applicable       = !!c.tcs_applicable
    f.primary_color        = c.primary_color || '#2B50E0'
    f.secondary_color      = c.secondary_color || '#2140C0'
    form.value  = f
    logoUrl.value = c.logo_url ?? null
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Failed to load company.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = null; success.value = null
  try {
    const res = await companyService.update(form.value)
    success.value = 'Company settings saved.'
    const c = res?.data ?? res
    logoUrl.value = c.logo_url ?? logoUrl.value
  } catch (e) {
    if (e?.response?.status === 403) { canEdit.value = false; error.value = 'You do not have permission to edit company settings.' }
    else error.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Failed to save.'
  } finally {
    saving.value = false
  }
}

async function onLogoChange(e) {
  const file = e.target.files?.[0]
  if (!file) return
  uploadingLogo.value = true
  error.value = null; success.value = null
  try {
    const res = await companyService.uploadLogo(file)
    const c = res?.data ?? res
    logoUrl.value = c.logo_url ?? null
    success.value = 'Logo updated.'
  } catch (err) {
    error.value = err?.response?.data?.message ?? 'Failed to upload logo.'
  } finally {
    uploadingLogo.value = false
    e.target.value = ''
  }
}

onMounted(load)
</script>

<style scoped>
.cs-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.cs-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.cs-header h2 { margin: 0; font-size: 22px; color: var(--primary); }

.error-banner   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.info-banner    { background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.loading-state  { text-align: center; padding: 60px; color: #888; }

.cs-body { display: flex; flex-direction: column; gap: 16px; }
.card { background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px 22px; }
.card h3 { margin: 0 0 16px; font-size: 14px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }

.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 18px; }
@media (max-width: 900px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .form-grid { grid-template-columns: 1fr; } }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.4px; }
.form-group input, .form-group select { padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.form-group input:disabled, .form-group select:disabled { background: #f7f7f7; color: #888; }

.color-row { display: flex; gap: 8px; align-items: center; }
.color-row input[type=color] { width: 40px; height: 36px; padding: 2px; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; }
.color-row input[type=text], .color-row input:not([type=color]) { flex: 1; }

.toggles { justify-content: center; gap: 8px; }
.toggle { display: flex; align-items: center; gap: 7px; font-size: 13px; color: #444; cursor: pointer; text-transform: none; font-weight: 500; }
.toggle input { width: 15px; height: 15px; }

.logo-row { display: flex; gap: 20px; align-items: center; margin-bottom: 18px; }
.logo-box { width: 110px; height: 110px; border: 2px dashed #ddd; border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; background: #fafafa; }
.logo-img { max-width: 100%; max-height: 100%; object-fit: contain; }
.logo-placeholder { color: #bbb; font-size: 12px; }
.logo-actions { display: flex; flex-direction: column; gap: 8px; }
.hint { font-size: 11px; color: #aaa; }

.btn { padding: 8px 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: var(--primary-tint); color: var(--primary); display: inline-block; }
.btn-secondary.disabled { opacity: 0.5; cursor: not-allowed; }
</style>
