<template>
  <div>
    <div class="page-header"><div><div class="page-title">Payments</div><div class="page-subtitle">Payment transactions & reconciliation</div></div></div>

    <div class="card">
      <div class="card-header"><div class="card-title">Record Payment</div></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Invoice ID</label><input v-model="form.invoice_id" type="number" class="form-control" placeholder="Invoice ID" /></div>
        <div class="form-group"><label class="form-label">Amount (₹)</label><input v-model="form.amount" type="number" class="form-control" placeholder="0.00" /></div>
        <div class="form-group"><label class="form-label">Method</label>
          <select v-model="form.payment_method" class="form-control">
            <option>bank_transfer</option><option>cash</option><option>cheque</option><option>upi</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Reference No</label><input v-model="form.reference_no" class="form-control" placeholder="UTR / Cheque No" /></div>
      </div>
      <div v-if="msg" :class="'alert alert-'+msgType">{{ msg }}</div>
      <button class="btn btn-success" @click="record" :disabled="saving">{{ saving ? 'Recording...' : '💳 Record Payment' }}</button>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Unpaid Invoices</div><button class="btn btn-sm btn-outline" @click="loadUnpaid">🔄 Refresh</button></div>
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Invoice #</th><th>Due Date</th><th>Total</th><th>Paid</th><th>Balance</th></tr></thead>
          <tbody>
            <tr v-for="inv in unpaid" :key="inv.id">
              <td><a @click="$router.push('/invoices/'+inv.id)" style="cursor:pointer;color:var(--primary)">{{ inv.invoice_no }}</a></td>
              <td>{{ fmtDate(inv.due_date) }}</td>
              <td>₹{{ fmt(inv.subtotal) }}</td>
              <td>₹{{ fmt(inv.paid_amount) }}</td>
              <td style="color:var(--danger);font-weight:600">₹{{ fmt((inv.subtotal||0)-(inv.paid_amount||0)) }}</td>
            </tr>
            <tr v-if="!unpaid.length"><td colspan="5"><div class="empty-state"><div class="icon">✅</div><p>No unpaid invoices</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
const unpaid = ref([]); const loading = ref(true); const saving = ref(false); const msg = ref(''); const msgType = ref('success')
const form = ref({ invoice_id: '', amount: '', payment_method: 'bank_transfer', reference_no: '' })
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const loadUnpaid = async () => { loading.value = true; try { const { data } = await api.get('/payments/unpaid'); unpaid.value = data.data?.data ?? data.data ?? [] } finally { loading.value = false } }
const record = async () => {
  saving.value = true; msg.value = ''
  try {
    await api.post('/payments/record', form.value)
    msg.value = '✅ Payment recorded!'; msgType.value = 'success'
    form.value = { invoice_id: '', amount: '', payment_method: 'bank_transfer', reference_no: '' }
    loadUnpaid()
  } catch (e) { msg.value = e.response?.data?.message || 'Failed'; msgType.value = 'error' }
  finally { saving.value = false }
}
onMounted(loadUnpaid)
</script>
