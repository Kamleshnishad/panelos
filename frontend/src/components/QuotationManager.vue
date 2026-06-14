<template>
  <div class="qm-wrap">
    <!-- List view -->
    <quotation-list
      v-if="view === 'list'"
      @create="openCreate"
      @view="openDetail"
      @edit="openEdit"
      @create-order="onOrderCreated"
      ref="listRef"
    />

    <!-- Create / Edit view -->
    <quotation-create
      v-else-if="view === 'create' || view === 'edit'"
      :edit-id="editId"
      :prefill-customer-id="createPrefill.customer_id"
      :lead-id="createPrefill.lead_id"
      @cancel="backToList"
      @saved="onSaved"
    />

    <!-- Detail view -->
    <quotation-detail
      v-else-if="view === 'detail'"
      :key="detailId"
      :quotation-id="detailId"
      @back="backToList"
      @edit="openEdit"
      @view="openDetail"
      @order-created="onOrderCreated"
    />
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import QuotationList from './QuotationList.vue'
import QuotationCreate from './QuotationCreate.vue'
import QuotationDetail from './QuotationDetail.vue'

const props = defineProps({
  openId: { type: Number, default: null },
  prefill: { type: Object, default: null },   // { customer_id, lead_id } from a converted Lead
})
const emit = defineEmits(['order-created'])

const view = ref('list')
const editId = ref(null)
const detailId = ref(null)
const listRef = ref(null)
const createPrefill = ref({ customer_id: null, lead_id: null })

// Deep-link: open a specific quotation when navigated here (e.g. from BOQ Register)
watch(() => props.openId, (id) => { if (id) openDetail(id) })
// Lead → quotation: open a prefilled create form
watch(() => props.prefill, (p) => { if (p && p.customer_id) openCreate(p) }, { immediate: true })
onMounted(() => { if (props.openId) openDetail(props.openId) })

function openCreate(prefill = null) {
  editId.value = null
  createPrefill.value = (prefill && prefill.customer_id) ? { customer_id: prefill.customer_id, lead_id: prefill.lead_id } : { customer_id: null, lead_id: null }
  view.value = 'create'
}

function openEdit(id) {
  editId.value = id
  view.value = 'edit'
}

function openDetail(id) {
  detailId.value = id
  view.value = 'detail'
}

function backToList() {
  view.value = 'list'
  editId.value = null
  detailId.value = null
}

function onSaved(id) {
  if (id) {
    openDetail(id)
  } else {
    backToList()
  }
}

function onOrderCreated(orderId) {
  emit('order-created', orderId)
  backToList()
}
</script>

<style scoped>
.qm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
