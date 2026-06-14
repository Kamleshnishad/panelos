<template>
  <div class="om-wrap">
    <order-list
      v-if="view === 'list'"
      ref="listRef"
      @view="openDetail"
    />

    <order-detail
      v-else-if="view === 'detail'"
      :key="detailId"
      :order-id="detailId"
      @back="backToList"
      @view-quotation="$emit('view-quotation', $event)"
      @view-batch="$emit('view-batch', $event)"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import OrderList   from './OrderList.vue'
import OrderDetail from './OrderDetail.vue'

const emit = defineEmits(['view-quotation', 'view-batch'])

const view     = ref('list')
const detailId = ref(null)
const listRef  = ref(null)

function openDetail(id) {
  detailId.value = id
  view.value     = 'detail'
}

function backToList() {
  view.value     = 'list'
  detailId.value = null
  listRef.value?.reload()
}
</script>

<style scoped>
.om-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
