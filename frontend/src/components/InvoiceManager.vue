<template>
  <div class="im-wrap">
    <invoice-list
      v-if="view === 'list'"
      ref="listRef"
      @view="openDetail"
      @create="showCreate = true"
    />

    <invoice-detail
      v-else-if="view === 'detail'"
      :key="detailId"
      :invoice-id="detailId"
      @back="backToList"
      @view="openDetail"
    />

    <invoice-create
      v-if="showCreate"
      @created="onCreated"
      @cancel="showCreate = false"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import InvoiceList   from './InvoiceList.vue'
import InvoiceDetail from './InvoiceDetail.vue'
import InvoiceCreate from './InvoiceCreate.vue'

const view       = ref('list')
const detailId   = ref(null)
const showCreate = ref(false)
const listRef    = ref(null)

function openDetail(id) { detailId.value = id; view.value = 'detail' }
function backToList() { view.value = 'list'; detailId.value = null; listRef.value?.reload() }
function onCreated(id) {
  showCreate.value = false
  if (id) openDetail(typeof id === 'object' ? id.id : id)
  else listRef.value?.reload()
}
</script>

<style scoped>
.im-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
