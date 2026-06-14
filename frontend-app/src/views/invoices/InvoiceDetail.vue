<template>
  <div>
    <div class="page-header">
      <div><button class="btn btn-outline btn-sm" @click="$router.back()">← Back</button><div class="page-title" style="margin-top:8px">Invoice {{ inv?.invoice_no }}</div></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap" v-if="inv">
        <button v-if="inv.status==='draft'" class="btn btn-primary" @click="doAction('send')">Send</button>
        <button v-if="inv.status==='sent'" class="btn btn-success" @click="doAction('accept')">Accept</button>
        <button v-if="inv.status==='accepted'" class="btn btn-success" @click="doAction('mark-paid')">Mark Paid</button>
        <button v-if="inv.status!=='draft'&&inv.status!=='cancelled'" class="btn btn-outline" @click="downloadPdf">📥 PDF</button>
        <button v-if="inv.status!=='draft'&&inv.status!=='cancelled'" class="btn btn-outline" @click="sendEmail">✉️ Email</button>
        <button v-if="inv.status!=='cancelled'&&inv.status!=='paid'" class="btn btn-danger" @click="doAction('cancel')">Cancel</button>
      </div>
    </div>
    <div v-if="loading" class="loading"><div class="spinner"></div></div>
    <template v-else-if="inv">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <div class="card">
          <div class="card-header"><div class="card-title">Invoice Details</div><span :class="'badge badge-'+inv.status">{{ inv.status }}</span></div>
          <div class="form-group"><label class="form-label">Invoice Number</label><div>{{ inv.invoice_no }}</div></div>
          <div class="form-group"><label class="form-label">Invoice Date</label><div>{{ fmtDate(inv.invoice_date) }}</div></div>
          <div class="form-group"><label class="form-label">Due Date</label><div>{{ fmtDate(inv.due_date) }}</div></div>
          <div class="form-group" v-if="inv.notes"><label class="form-label">Notes</label><div>{{ inv.notes }}</div></div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Summary</div></div>
          <div class="form-group"><label class="form-label">Subtotal</label><div>₹{{ fmt(inv.subtotal) }}</div></div>
          <div class="form-group" v-if="inv.taxCalculation"><label class="form-label">Tax ({{ inv.taxCalculation.tax_rate }}%)</label><div>₹{{ fmt(inv.taxCalculation.tax_amount) }}</div></div>
          <div class="form-group"><label class="form-label">Total</label><div style="font-size:22px;font-weight:700;color:var(--primary)">₹{{ fmt(getTotal()) }}</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">Line Items</div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Panel Type</th><th>Qty</th><th>Unit Price</th><th>Amount</th><th>Tax %</th><th>Total</th></tr></thead>
            <tbody>
              <tr v-for="item in inv.items" :key="item.id">
                <td>{{ item.panelType?.type ?? '—' }}</td>
                <td>{{ item.quantity }}</td>
                <td>₹{{ fmt(item.unit_price) }}</td>
                <td>₹{{ fmt(item.amount) }}</td>
                <td>{{ item.tax_rate }}%</td>
                <td>₹{{ fmt(item.total_with_tax) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="msg" :class="'alert alert-'+msgType" style="margin-top:8px">{{ msg }}</div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
const route = useRoute()
const inv = ref(null); const loading = ref(true); const msg = ref(''); const msgType = ref('success')
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const getTotal = () => { const tax = inv.value?.taxCalculation?.tax_amount || 0; return (inv.value?.subtotal || 0) + tax }
const load = async () => { loading.value = true; try { const { data } = await api.get(`/invoices/${route.params.id}`); inv.value = data.data } finally { loading.value = false } }
const doAction = async (act) => { await api.post(`/invoices/${route.params.id}/${act}`); load() }
const downloadPdf = async () => {
  const { data } = await api.get(`/invoices/${route.params.id}/pdf`, { responseType: 'blob' })
  const url = URL.createObjectURL(data); const a = document.createElement('a'); a.href = url; a.download = `invoice_${inv.value.invoice_no}.pdf`; a.click()
}
const sendEmail = async () => {
  try { await api.post(`/invoices/${route.params.id}/send-email`); msg.value = '✅ Email sent!'; msgType.value = 'success' }
  catch { msg.value = '❌ Email failed (check SMTP config)'; msgType.value = 'error' }
}
onMounted(load)
</script>
