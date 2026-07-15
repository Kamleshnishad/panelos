<template>
  <FormField
    :label="label"
    :required="required"
    :hint="hint"
    :field-state="computedState"
  >
    <textarea
      :ref="inputRef"
      :value="modelValue"
      :placeholder="placeholder"
      :rows="rows"
      :maxlength="maxLength"
      :required="required"
      :disabled="disabled"
      class="form-textarea__native"
      @input="onInput"
      @blur="onBlur"
      @focus="onFocus"
    ></textarea>
  </FormField>
</template>

<script setup>
import { ref, computed } from 'vue'
import FormField from './FormField.vue'
import { useFieldValidation } from '../../composables/useFieldValidation.js'

const props = defineProps({
  modelValue: String,
  label: String,
  required: Boolean,
  placeholder: String,
  hint: String,
  rows: { type: Number, default: 3 },
  maxLength: [Number, String],
  disabled: Boolean,
  name: { type: String, required: true },
  rules: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'validation'])
const inputRef = ref(null)

const allRules = computed(() => {
  const r = [...props.rules]
  if (props.required) {
    const existing = r.find(x => x.required)
    if (existing) existing.required = true
    else r.push({ required: true, label: props.label || props.name })
  }
  if (props.maxLength) {
    const existing = r.find(x => x.maxLength !== undefined)
    if (!existing) r.push({ maxLength: Number(props.maxLength), maxLengthMessage: `Maximum ${props.maxLength} characters.` })
  }
  return r
})

const getValues = computed(() => ({ [props.name]: props.modelValue }))
const fv = useFieldValidation({ rules: allRules, getValues })
const computedState = computed(() => fv.fieldState(props.name))

function onInput(e) {
  let val = e.target.value
  const max = props.maxLength ? Number(props.maxLength) : null
  if (max && val.length > max) val = val.slice(0, max)
  emit('update:modelValue', val)
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
.form-textarea__native {
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
  resize: vertical;
  min-height: 60px;
}
.form-textarea__native:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-tint);
}
.has-error .form-textarea__native {
  border-color: var(--danger);
  background: var(--danger-bg);
}
</style>
