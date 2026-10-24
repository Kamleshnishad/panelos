<template>
  <div class="mdm-wrap">
    <h2>Master Data</h2>
    <div class="md-tabs">
      <button :class="['md-tab', { active: tab === 'panels' }]"      @click="tab = 'panels'">Panel Types</button>
      <button :class="['md-tab', { active: tab === 'accessories' }]" @click="tab = 'accessories'">Accessories</button>
      <button :class="['md-tab', { active: tab === 'stages' }]"      @click="tab = 'stages'">Production Stages</button>
      <button :class="['md-tab', { active: tab === 'tax' }]"         @click="tab = 'tax'">Tax / GST</button>
      <button :class="['md-tab', { active: tab === 'gst' }]"         @click="tab = 'gst'">GST Compliance</button>
      <button :class="['md-tab', { active: tab === 'leadlists' }]"   @click="tab = 'leadlists'">Lead lists</button>
    </div>

    <panel-type-master       v-if="tab === 'panels'" />
    <accessory-master        v-else-if="tab === 'accessories'" />
    <production-stage-master  v-else-if="tab === 'stages'" />
    <tax-configuration       v-else-if="tab === 'tax'" />
    <gst-config-manager      v-else-if="tab === 'gst'" />
    <lead-lists-settings     v-else-if="tab === 'leadlists'" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import PanelTypeMaster       from './PanelTypeMaster.vue'
import AccessoryMaster       from './AccessoryMaster.vue'
import ProductionStageMaster from './ProductionStageMaster.vue'
import TaxConfiguration      from './TaxConfiguration.vue'
import GstConfigManager      from './GstConfigManager.vue'
import LeadListsSettings     from './LeadListsSettings.vue'

const tab = ref('panels')
</script>

<style scoped>
.mdm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; font-family: inherit; }
.mdm-wrap h2 { margin: 0 0 16px; font-size: 22px; color: var(--primary); }
.md-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
.md-tab { padding: 9px 20px; border: none; background: none; font-size: 14px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; }
.md-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.md-tab:hover:not(.active) { color: #333; }
</style>
