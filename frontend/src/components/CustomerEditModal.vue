<template>
  <div v-if="open" class="modal-overlay" @click.self="close">
    <div class="modal-box">
      <header class="modal-head">
        <h3>{{ customer ? 'Edit Customer' : 'New Customer' }}</h3>
        <button class="btn-close" @click="close">×</button>
      </header>

      <div class="modal-body">
        <div v-if="error" class="error-banner">{{ error }}</div>

        <div class="form-grid">
          <FormInput
            v-model="form.name"
            name="name"
            label="Customer Name"
            required
            placeholder="e.g. ABC Cold Storage"
            :rules="[{ minLength: 2, maxLength: 255 }]"
          />

          <FormSelect
            v-model="form.type"
            name="type"
            label="Customer Type"
            :options="['retail', 'wholesale', 'distributor', 'corporate']"
            placeholder="Select type"
          />

          <FormInput
            v-model="form.contact_person"
            name="contact_person"
            label="Contact Person"
            placeholder="Optional"
            :rules="[{ maxLength: 100 }]"
          />

          <FormInput
            v-model="form.phone"
            name="phone"
            type="phone"
            label="Phone"
            placeholder="10-digit mobile"
            :rules="[{ required: true }]"
            :async-check="customer ? { table: 'customers', column: 'phone', ignoreId: customer.id } : { table: 'customers', column: 'phone' }"
          />

          <FormInput
            v-model="form.whatsapp_no"
            name="whatsapp_no"
            type="phone"
            label="WhatsApp"
            placeholder="Optional"
          />

          <FormInput
            v-model="form.email"
            name="email"
            type="email"
            label="Email"
            placeholder="Optional"
            :async-check="customer ? { table: 'customers', column: 'email', ignoreId: customer.id } : { table: 'customers', column: 'email' }"
          />

          <FormInput
            v-model="form.gstin"
            name="gstin"
            type="gstin"
            label="GSTIN"
            placeholder="15-char optional"
            :async-check="customer ? { table: 'customers', column: 'gstin', ignoreId: customer.id } : { table: 'customers', column: 'gstin' }"
          />

          <FormInput
            v-model="form.pan"
            name="pan"
            type="pan"
            label="PAN"
            placeholder="Optional"
          />

          <FormInput
            v-model="form.address_line1"
            name="address_line1"
            label="Address Line 1"
            placeholder="Optional"
            :rules="[{ maxLength: 255 }]"
          />

          <FormInput
            v-model="form.city"
            name="city"
            label="City"
            placeholder="Optional"
            :rules="[{ maxLength: 100 }]"
          />

          <FormInput
            v-model="form.state"
            name="state"
            label="State"
            placeholder="Optional"
            :rules="[{ maxLength: 100 }]"
          />

          <FormInput
            v-model="form.state_code"
            name="state_code"
            label="State Code"
            placeholder="e.g. GJ"
            :rules="[{ maxLength: 2 }]"
          />

          <FormInput
            v-model="form.pincode"
            name="pincode"
            type="pincode"
            label="Pincode"
            placeholder="6 digits"
          />

          <FormTextarea
            v-model="form.notes"
            name="notes"
            label="Notes"
            placeholder="Optional"
            :rows="2"
            :max-length="2000"
          />
        </div>
      </div>

      <footer class="modal-foot">
        <button class="btn btn-ghost" @click="close">Cancel</button>
        <button class="btn btn-primary" :disabled="saving || !canSubmit" @click="save">
          {{ customer ? 'Save Changes' : 'Add Customer' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue'
import FormInput from './Form/FormInput.vue'
import FormSelect from './Form/FormSelect.vue'
import FormTextarea from './Form/FormTextarea.vue'
import customerService from '../services/customerService.js'
import { toastError, toastSuccess } from '../services/ui.js'
import { useFieldValidation } from '../composables/useFieldValidation.js'

const props = defineProps({
  open: Boolean,
  customer: Object, // null for create, populated for edit
})
const emit = defineEmits(['close', 'saved'])

const form = reactive({
  name: '', type: 'retail', contact_person: '', phone: '', whatsapp_no: '',
  email: '', gstin: '', pan: '', address_line1: '', city: '', state: '',
  state_code: '', pincode: '', notes: '',
})
const saving = ref(false)
const error = ref(null)

watch(() => [props.open, props.customer], ([isOpen, cust]) => {
  if (isOpen) {
    error.value = null
    if (cust) {
      Object.assign(form, {
        name: cust.name || '',
        type: cust.type || 'retail',
        contact_person: cust.contact_person || '',
        phone: cust.phone || '',
        whatsapp_no: cust.whatsapp_no || '',
        email: cust.email || '',
        gstin: cust.gstin || '',
        pan: cust.pan || '',
        address_line1: cust.address_line1 || '',
        city: cust.city || '',
        state: cust.state || '',
        state_code: cust.state_code || '',
        pincode: cust.pincode || '',
        notes: cust.notes || '',
      })
    } else {
      Object.keys(form).forEach(k => form[k] = '')
      form.type = 'retail'
    }
  }
}, { immediate: true })

const validationRules = computed(() => ({
  name: [{ required: true, minLength: 2, maxLength: 255 }],
  phone: [{ required: true, type: 'phone' }],
  email: [{ type: 'email' }],
  gstin: [{ type: 'gstin' }],
  pincode: [{ type: 'pincode' }],
}))
const fv = useFieldValidation({
  rules: validationRules,
  getValues: () => form,
})

const canSubmit = computed(() => {
  // Required: name and phone must have no error
  if (fv.errors.value.name) return false
  if (fv.errors.value.phone) return false
  if (fv.uniqueStatus.value.phone === 'taken') return false
  if (fv.uniqueStatus.value.email === 'taken') return false
  if (fv.uniqueStatus.value.gstin === 'taken') return false
  if (!form.name || form.name.length < 2) return false
  if (!form.phone) return false
  return true
})

function close() {
  error.value = null
  emit('close')
}

async function save() {
  // Validate everything; touch all so errors show
  for (const name of Object.keys(validationRules.value)) fv.touch(name)
  const ok = fv.validateAll()
  if (!ok) return

  saving.value = true
  error.value = null
  try {
    const payload = { ...form }
    if (props.customer) {
      await customerService.update(props.customer.id, payload)
      toastSuccess('Customer updated.')
    } else {
      await customerService.create(payload)
      toastSuccess('Customer added.')
    }
    emit('saved')
    close()
  } catch (e) {
    const errs = e?.response?.data?.errors || e?.response?.data
    if (errs && typeof errs === 'object') {
      error.value = Object.values(errs).flat().join(' • ')
    } else {
      error.value = e?.response?.data?.message || 'Save failed.'
    }
    toastError(error.value)
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.modal-overlay {
  position: fixed; inset: 0; background: rgba(20, 24, 32, 0.4);
  display: flex; align-items: center; justify-content: center; z-index: 200;
  backdrop-filter: blur(2px);
}
.modal-box {
  background: var(--surface); border-radius: 12px; width: 90%; max-width: 800px;
  max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 16px 40px rgba(0,0,0,0.2);
}
.modal-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 20px; border-bottom: 1px solid var(--border);
}
.modal-head h3 { margin: 0; font-size: 16px; color: var(--ink); }
.btn-close {
  background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-3);
  padding: 0 6px; line-height: 1;
}
.modal-body {
  padding: 18px 22px; overflow-y: auto;
}
.modal-foot {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 12px 20px; border-top: 1px solid var(--border); background: var(--surface-2);
}
.form-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 4px 18px;
}
.form-grid > :nth-child(odd) { grid-column: 1; }
.form-grid > :nth-child(even) { grid-column: 2; }
.form-grid > :nth-last-child(2):nth-child(odd),
.form-grid > :last-child { grid-column: 1 / -1; }
.error-banner {
  background: var(--danger-bg); color: var(--danger); padding: 8px 12px;
  border-radius: 8px; font-size: 12.5px; margin-bottom: 12px; border: 1px solid var(--danger-bd);
}
@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
}
</style>
