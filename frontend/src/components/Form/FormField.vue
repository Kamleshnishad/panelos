<template>
  <div class="form-field" :class="{ 'has-error': fieldState?.error, 'has-warning': fieldState?.warning, 'is-required': required }">
    <label v-if="label" class="form-field__label">
      {{ label }}<span v-if="required" class="req"> *</span>
    </label>
    <slot />
    <div class="form-field__messages">
      <small v-if="fieldState?.error && fieldState.error !== 'This field is required.'" class="form-field__error">{{ fieldState.error }}</small>
      <small v-if="fieldState?.warning && !fieldState.error" class="form-field__warning">{{ fieldState.warning }}</small>
      <small v-if="hint && !fieldState?.error && !fieldState?.warning" class="form-field__hint">{{ hint }}</small>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineSlots } from 'vue'
defineProps({
  label: String,
  required: Boolean,
  hint: String,
  fieldState: Object,
})
</script>

<style scoped>
.form-field {
  margin-bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.form-field__label {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-2);
  letter-spacing: 0.01em;
}
.form-field__label .req {
  color: var(--danger);
  font-weight: 700;
}
/* Only takes up room when it has something to say. This used to reserve 18px
   under EVERY field whether or not there was an error, which on a 16-field form
   added ~250px of blank space and pushed half the form below the fold. */
.form-field__messages {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.form-field__messages:empty {
  display: none;
}
.form-field__error {
  color: var(--danger);
  font-size: 11.5px;
  font-weight: 500;
}
.form-field__warning {
  color: #B5740A;
  font-size: 11.5px;
  font-weight: 500;
}
.form-field__hint {
  color: var(--text-3);
  font-size: 11px;
}
</style>
