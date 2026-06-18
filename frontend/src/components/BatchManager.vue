<template>
  <div class="bm-wrap">
    <!-- List view -->
    <batch-list
      v-if="view === 'list'"
      ref="listRef"
      @view="openDetail"
      @create="showCreate = true"
    />

    <!-- Detail view -->
    <batch-detail
      v-else-if="view === 'detail'"
      :key="detailId"
      :batch-id="detailId"
      @back="backToList"
      @view-order="$emit('view-order', $event)"
    />

    <!-- Create modal (overlays list) -->
    <batch-create
      v-if="showCreate"
      @created="onCreated"
      @cancel="showCreate = false"
    />
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import BatchList   from './BatchList.vue'
import BatchDetail from './BatchDetail.vue'
import BatchCreate from './BatchCreate.vue'

const props = defineProps({ openId: { type: Number, default: null } })
const emit = defineEmits(['view-order'])

const view       = ref('list')
const detailId   = ref(null)
const showCreate = ref(false)
const listRef    = ref(null)

// Deep-link from another module: jump straight into the detail view.
watch(() => props.openId, (id) => { if (id) openDetail(id) })
onMounted(() => { if (props.openId) openDetail(props.openId) })

function openDetail(id) {
  detailId.value = id
  view.value     = 'detail'
}

function backToList() {
  view.value     = 'list'
  detailId.value = null
  listRef.value?.reload()
}

function onCreated(batchId) {
  showCreate.value = false
  if (batchId) {
    openDetail(typeof batchId === 'object' ? batchId.id : batchId)
  } else {
    listRef.value?.reload()
  }
}
</script>

<style scoped>
.bm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
