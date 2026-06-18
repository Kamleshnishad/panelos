<template>
  <div class="dm-wrap">
    <dispatch-list
      v-if="view === 'list'"
      ref="listRef"
      @view="openDetail"
      @create="showCreate = true"
    />

    <dispatch-detail
      v-else-if="view === 'detail'"
      :key="detailId"
      :dispatch-id="detailId"
      @back="backToList"
      @view-batch="$emit('view-batch', $event)"
    />

    <dispatch-create
      v-if="showCreate"
      @created="onCreated"
      @cancel="showCreate = false"
    />
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import DispatchList   from './DispatchList.vue'
import DispatchDetail from './DispatchDetail.vue'
import DispatchCreate from './DispatchCreate.vue'

const props = defineProps({ openId: { type: Number, default: null } })
const emit = defineEmits(['view-batch'])

const view       = ref('list')
const detailId   = ref(null)
const showCreate = ref(false)
const listRef    = ref(null)

// Deep-link from another module: jump straight into the detail view.
watch(() => props.openId, (id) => { if (id) openDetail(id) })
onMounted(() => { if (props.openId) openDetail(props.openId) })

function openDetail(id) { detailId.value = id; view.value = 'detail' }
function backToList() { view.value = 'list'; detailId.value = null; listRef.value?.reload() }
function onCreated(id) {
  showCreate.value = false
  if (id) openDetail(typeof id === 'object' ? id.id : id)
  else listRef.value?.reload()
}
</script>

<style scoped>
.dm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
