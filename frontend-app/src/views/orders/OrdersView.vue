<template>
  <div>
    <div class="page-header"><div><div class="page-title">Orders</div><div class="page-subtitle">Customer orders from accepted quotations</div></div></div>
    <div class="card">
      <div class="filters-bar">
        <select v-model="statusFilter" class="form-control" style="max-width:160px" @change="load">
          <option value="">All Status</option><option>pending</option><option>in_production</option><option>completed</option>
        </select>
      </div>
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Order #</th><th>Customer</th><th>Status</th><th>Amount</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="o in items" :key="o.id">
              <td><strong>{{ o.order_no }}</strong></td>
              <td>{{ o.customer?.name ?? '—' }}</td>
              <td><span :class="'badge badge-'+o.status">{{ o.status }}</span></td>
              <td>₹{{ fmt(o.total_amount) }}</td>
              <td>{{ fmtDate(o.created_at) }}</td>
              <td><button class="btn btn-sm btn-outline" @click="$router.push('/orders/'+o.id)">View</button></td>
            </tr>
            <tr v-if="!items.length"><td colspan="6"><div class="empty-state"><div class="icon">🛒</div><p>No orders yet</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
const items = ref([])
const loading = ref(true)
const statusFilter = ref('')
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN') : '—'
const load = async () => {
  loading.value = true
  try { const { data } = await api.get('/orders', { params: { status: statusFilter.value } }); items.value = data.data?.data ?? data.data ?? [] }
  finally { loading.value = false }
}
onMounted(load)
</script>
