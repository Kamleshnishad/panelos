<template>
  <div class="bm-wrap">
    <!-- List view -->
    <boq-register
      v-if="view === 'list'"
      ref="listRef"
      @add="openCreate"
      @edit="openEdit"
      @converted="onConverted"
    />

    <!-- Create / Edit BOQ (rate-less) -->
    <quotation-create
      v-else-if="view === 'create' || view === 'edit'"
      mode="boq"
      :edit-id="editId"
      @cancel="backToList"
      @saved="onSaved"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import BoqRegister from './BoqRegister.vue'
import QuotationCreate from './QuotationCreate.vue'

const emit = defineEmits(['open-quotation'])

const view    = ref('list')
const editId  = ref(null)
const listRef = ref(null)

function openCreate() { editId.value = null; view.value = 'create' }
function openEdit(id) { editId.value = id;   view.value = 'edit' }

function backToList() {
  view.value = 'list'
  editId.value = null
}

function onSaved() {
  backToList()
}

// A BOQ was converted to a draft quotation — hand off to the Quotations module
function onConverted(id) {
  emit('open-quotation', id)
}
</script>

<style scoped>
.bm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }
</style>
