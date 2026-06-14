<template>
  <div>
    <div class="page-header">
      <div><div class="page-title">Customers</div><div class="page-subtitle">Manage your customer database</div></div>
      <button class="btn btn-primary" @click="openCreate">+ New Customer</button>
    </div>

    <div class="card">
      <div class="filters-bar">
        <input v-model="search" class="form-control" placeholder="Search by name, email, phone…" style="max-width:300px" @input="load" />
        <button class="btn btn-outline" @click="load">🔄 Refresh</button>
      </div>

      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Type</th><th>Contact</th><th>Phone</th><th>City / State</th><th>GSTIN</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in items" :key="c.id">
              <td><strong>{{ c.name }}</strong><div style="font-size:11px;color:#888">{{ c.contact_person }}</div></td>
              <td><span class="badge badge-sent">{{ c.type }}</span></td>
              <td>{{ c.email }}</td>
              <td>{{ c.phone }}</td>
              <td>{{ c.city }}<span v-if="c.state_code" style="color:#888"> ({{ c.state_code }})</span></td>
              <td style="font-size:11px">{{ c.gstin || '—' }}</td>
              <td style="display:flex;gap:6px">
                <button class="btn btn-sm btn-outline" @click="openEdit(c)">Edit</button>
              </td>
            </tr>
            <tr v-if="!items.length">
              <td colspan="7"><div class="empty-state"><div class="icon">👥</div><p>No customers yet. Add your first customer.</p></div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create / Edit Modal -->
    <div v-if="modal.show" class="modal-overlay" @click.self="modal.show=false">
      <div class="modal" style="max-width:700px">
        <div class="modal-header">
          <div class="modal-title">{{ modal.isEdit ? 'Edit Customer' : 'New Customer' }}</div>
          <button class="modal-close" @click="modal.show=false">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Company Name *</label><input v-model="form.name" class="form-control" /></div>
            <div class="form-group"><label class="form-label">Type</label>
              <select v-model="form.type" class="form-control">
                <option>retail</option><option>wholesale</option><option>distributor</option><option>corporate</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Contact Person</label><input v-model="form.contact_person" class="form-control" /></div>
            <div class="form-group"><label class="form-label">Phone *</label><input v-model="form.phone" class="form-control" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Email</label><input v-model="form.email" class="form-control" type="email" /></div>
            <div class="form-group"><label class="form-label">WhatsApp No</label><input v-model="form.whatsapp_no" class="form-control" /></div>
          </div>
          <div class="form-group"><label class="form-label">Address</label><input v-model="form.address_line1" class="form-control" /></div>
          <div class="form-row-3">
            <div class="form-group"><label class="form-label">City</label><input v-model="form.city" class="form-control" /></div>
            <div class="form-group"><label class="form-label">State</label><input v-model="form.state" class="form-control" /></div>
            <div class="form-group"><label class="form-label">State Code</label><input v-model="form.state_code" class="form-control" maxlength="2" placeholder="MH" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">GSTIN</label><input v-model="form.gstin" class="form-control" placeholder="27XXXXX1234X1ZX" /></div>
            <div class="form-group"><label class="form-label">Pincode</label><input v-model="form.pincode" class="form-control" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Credit Limit (₹)</label><input v-model.number="form.credit_limit" type="number" class="form-control" /></div>
            <div class="form-group"><label class="form-label">Payment Terms (days)</label><input v-model.number="form.payment_terms_days" type="number" class="form-control" /></div>
          </div>
          <div v-if="errorMsg" class="alert alert-error">{{ errorMsg }}</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" @click="modal.show=false">Cancel</button>
          <button class="btn btn-primary" @click="save" :disabled="saving">{{ saving ? 'Saving…' : 'Save Customer' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

const items   = ref([])
const loading = ref(true)
const saving  = ref(false)
const search  = ref('')
const errorMsg = ref('')

const modal = reactive({ show: false, isEdit: false, id: null })
const form  = reactive({
  name:'', type:'retail', contact_person:'', phone:'', email:'', whatsapp_no:'',
  address_line1:'', city:'', state:'', state_code:'', pincode:'', gstin:'',
  credit_limit: 0, payment_terms_days: 30,
})

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/customers', { params: { search: search.value, per_page: 100 } })
    items.value = data.data?.data ?? data.data ?? []
  } finally { loading.value = false }
}

const openCreate = () => {
  Object.assign(form, { name:'', type:'retail', contact_person:'', phone:'', email:'', whatsapp_no:'',
    address_line1:'', city:'', state:'', state_code:'', pincode:'', gstin:'', credit_limit:0, payment_terms_days:30 })
  modal.isEdit = false; modal.id = null; modal.show = true; errorMsg.value = ''
}

const openEdit = (c) => {
  Object.assign(form, c)
  modal.isEdit = true; modal.id = c.id; modal.show = true; errorMsg.value = ''
}

const save = async () => {
  if (!form.name) { errorMsg.value = 'Company name is required.'; return }
  saving.value = true; errorMsg.value = ''
  try {
    if (modal.isEdit) {
      await api.put(`/customers/${modal.id}`, form)
    } else {
      await api.post('/customers', form)
    }
    modal.show = false
    load()
  } catch (e) {
    errorMsg.value = e.response?.data?.message || 'Save failed'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
