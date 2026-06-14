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

const props = defineProps({ openId: { type: Number, default: null } })
const emit = defineEmits(['order-created'])

const view = ref('list')
const editId = ref(null)
const detailId = ref(null)
const listRef = ref(null)

// Deep-link: open a specific quotation when navigated here (e.g. from BOQ Register)
watch(() => props.openId, (id) => { if (id) openDetail(id) })
onMounted(() => { if (props.openId) openDetail(props.openId) })

function openCreate() {
  editId.value = null
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
