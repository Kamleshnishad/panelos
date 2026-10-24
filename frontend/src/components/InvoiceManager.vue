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
import { ref, watch, onMounted } from 'vue'
import InvoiceList   from './InvoiceList.vue'
import InvoiceDetail from './InvoiceDetail.vue'
import InvoiceCreate from './InvoiceCreate.vue'

const props = defineProps({ openId: { type: Number, default: null } })
const emit = defineEmits(['detail'])

const view       = ref('list')
const detailId   = ref(null)
const showCreate = ref(false)
const listRef    = ref(null)

// Deep-link (search result, page address): open a record by id; no id means the list.
watch(() => props.openId, (id) => { if (id) openDetail(id); else if (view.value === 'detail') backToList() })
onMounted(() => { if (props.openId) openDetail(props.openId) })

function openDetail(id) { detailId.value = id; view.value = 'detail'; emit('detail', id) }
function backToList() { view.value = 'list'; detailId.value = null; listRef.value?.reload(); emit('detail', null) }
function onCreated(id) {
  showCreate.value = false
  if (id) openDetail(typeof id === 'object' ? id.id : id)
  else listRef.value?.reload()
}
</script>

<style scoped>
.im-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
