<template>
  <FormField
    :label="label"
    :required="required"
    :hint="hint"
    :field-state="computedState"
  >
    <div class="form-input-wrap" :class="{ 'has-error': computedState?.error }">
      <select
        :ref="inputRef"
        :value="modelValue"
        :required="required"
        :disabled="disabled"
        class="form-select__native"
        @change="onChange"
        @blur="onBlur"
        @focus="onFocus"
      >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <option v-for="opt in options" :key="opt.value ?? opt" :value="opt.value ?? opt">
          {{ opt.label ?? opt }}
        </option>
      </select>
    </div>
  </FormField>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import FormField from './FormField.vue'
import { useFieldValidation } from '../../composables/useFieldValidation.js'

const props = defineProps({
  modelValue: [String, Number],
  label: String,
  type: { type: String, default: 'select' },
  required: Boolean,
  placeholder: String,
  hint: String,
  disabled: Boolean,
  name: { type: String, required: true },
  rules: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'validation'])
const inputRef = ref(null)
const localTouched = ref(false)

const allRules = computed(() => {
  const r = [...props.rules]
  if (props.required) {
    const existing = r.find(x => x.required)
    if (existing) existing.required = true
    else r.push({ required: true, label: props.label || props.name })
  }
  return r
})

const getValues = computed(() => ({ [props.name]: props.modelValue }))
const fv = useFieldValidation({ rules: allRules, getValues })

const computedState = computed(() => fv.fieldState(props.name))

function onChange(e) {
  emit('update:modelValue', e.target.value)
  fv.touch(props.name)
  fv.validate(props.name)
  emit('validation', { name: props.name, ...fv.fieldState(props.name) })
}

function onBlur() {
  fv.validate(props.name)
  emit('validation', { name: props.name, ...fv.fieldState(props.name) })
}

function onFocus() {
  fv.clearError(props.name)
}

defineExpose({ validate: () => fv.validate(props.name), fieldState: computedState, fv })
</script>

<style scoped>
.form-select__native {
  width: 100%;
  padding: 8px 11px;
  border: 1px solid var(--border-2);
  border-radius: var(--r-sm);
  font-family: inherit;
  font-size: 13px;
  background: #fff;
  color: var(--ink);
  box-sizing: border-box;
  transition: border-color var(--t-fast), box-shadow var(--t-fast);
  cursor: pointer;
  appearance: auto;
}
.form-select__native:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-tint);
}
.has-error .form-select__native {
  border-color: var(--danger);
  background: var(--danger-bg);
}
</style>
