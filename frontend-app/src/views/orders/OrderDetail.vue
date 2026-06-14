<template>
  <div>
    <div class="page-header">
      <div><button class="btn btn-outline btn-sm" @click="$router.back()">← Back</button><div class="page-title" style="margin-top:8px">Order {{ order?.order_no }}</div></div>
    </div>
    <div v-if="loading" class="loading"><div class="spinner"></div></div>
    <template v-else-if="order">
      <div class="card">
        <div class="card-header"><div class="card-title">Order Details</div><span :class="'badge badge-'+order.status">{{ order.status }}</span></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Customer</label><div>{{ order.customer?.name }}</div></div>
          <div class="form-group"><label class="form-label">Total Amount</label><div style="font-size:20px;font-weight:700">₹{{ fmt(order.total_amount) }}</div></div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">Order Items</div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Panel Type</th><th>Quantity</th><th>Unit Price</th><th>Total</th></tr></thead>
            <tbody>
              <tr v-for="item in order.items" :key="item.id">
                <td>{{ item.panel_type?.type ?? item.description }}</td>
                <td>{{ item.quantity }}</td>
                <td>₹{{ fmt(item.unit_price) }}</td>
                <td>₹{{ fmt(item.total_price) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
const route = useRoute()
const order = ref(null)
const loading = ref(true)
const fmt = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
onMounted(async () => {
  try { const { data } = await api.get(`/orders/${route.params.id}`); order.value = data.data }
  finally { loading.value = false }
})
</script>
