<template>
  <FormField
    :label="label"
    :required="required"
    :hint="hint"
    :field-state="computedState"
  >
    <div class="form-input-wrap" :class="{ 'has-error': computedState?.error, 'has-warning': computedState?.warning }">
      <input
        :ref="inputRef"
        :type="effectiveType"
        :value="modelValue"
        :placeholder="placeholder"
        :maxlength="effectiveMaxLength"
        :min="min"
        :max="max"
        :step="step"
        :required="required"
        :disabled="disabled"
        :autocomplete="autocomplete"
        class="form-input__native"
        @input="onInput"
        @blur="onBlur"
        @focus="onFocus"
        @paste="onPaste"
      />
      <!-- Unique status indicator -->
      <span v-if="showUniqueIndicator" class="form-input__unique-icon" :class="uniqueIconClass">
        {{ uniqueIconText }}
      </span>
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
  type: { type: String, default: 'text' },
  required: Boolean,
  placeholder: String,
  hint: String,
  min: [Number, String],
  max: [Number, String],
  step: [Number, String],
  disabled: Boolean,
  maxLength: [Number, String],
  autocomplete: String,
  name: { type: String, required: true },
  rules: { type: Array, default: () => [] },
  asyncCheck: { type: Object, default: null }, // { table, column, message, ignoreId, debounce }
  // For parent to pass reactive form state for validation
  formState: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue', 'validation'])

const inputRef = ref(null)
const focused = ref(false)
const localTouched = ref(false)

const effectiveMaxLength = computed(() => {
  // If field type implies a max length, enforce it via maxlength attr
  if (props.maxLength != null) return Number(props.maxLength)
  const fieldMax = { phone: 10, pincode: 6, gstin: 15, pan: 10, ifsc: 11, hsn: 8, vehicle: 10 }
  return fieldMax[props.type] || null
})

const effectiveType = computed(() => {
  // Number fields use type="text" so we can control stripping of non-numeric chars
  if (props.type === 'number' || props.type === 'phone' || props.type === 'pincode' || props.type === 'hsn') {
    return 'text'
  }
  if (props.type === 'gstin' || props.type === 'pan' || props.type === 'ifsc' || props.type === 'vehicle') {
    return 'text'
  }
  if (props.type === 'alpha') return 'text'
  return props.type
})

// Build validation rules from props
const allRules = computed(() => {
  const r = [...props.rules]
  if (props.required) {
    const existing = r.find(x => x.type === 'required' || (x.required && !x.type))
    if (existing) existing.required = true
    else r.push({ required: true, label: props.label || props.name })
  }
  // Add maxLength from type defaults
  const typeDefaults = {
    phone: { maxLength: 10, maxLengthMessage: 'Phone cannot be more than 10 digits.' },
    pincode: { maxLength: 6, maxLengthMessage: 'Pincode must be 6 digits.' },
    gstin: { maxLength: 15 },
    pan: { maxLength: 10, maxLengthMessage: 'PAN must be 10 characters.' },
    ifsc: { maxLength: 11 },
    hsn: { maxLength: 8 },
    vehicle: { maxLength: 10, maxLengthMessage: 'Vehicle number max 10 characters.' },
  }
  if (typeDefaults[props.type]) {
    const td = typeDefaults[props.type]
    const existing = r.find(x => x.maxLength !== undefined)
    if (!existing) r.push(td)
  }
  // Add async unique check
  if (props.asyncCheck) {
    r.push({
      unique: {
        table: props.asyncCheck.table,
        column: props.asyncCheck.column,
        message: props.asyncCheck.message || `This ${props.label || props.name} already exists.`,
        ignoreId: props.asyncCheck.ignoreId || null,
        debounce: props.asyncCheck.debounce || 400,
      }
    })
  }
  return r
})

// Expose getValues for the composable
const getValues = computed(() => ({ [props.name]: props.modelValue }))

const fv = useFieldValidation({
  rules: allRules,
  getValues,
})

const computedState = computed(() => fv.fieldState(props.name))

// Unique indicator
const showUniqueIndicator = computed(() => {
  return !!props.asyncCheck && fv.isTouched(props.name) && fv.uniqueStatus[props.name] !== 'idle'
})
const uniqueIconClass = computed(() => {
  const s = fv.uniqueStatus[props.name]
  if (s === 'available') return 'is-available'
  if (s === 'taken') return 'is-taken'
  if (s === 'checking') return 'is-checking'
  return ''
})
const uniqueIconText = computed(() => {
  const s = fv.uniqueStatus[props.name]
  if (s === 'available') return '✓'
  if (s === 'taken') return '✗'
  if (s === 'checking') return '…'
  return ''
})

// Format value based on type
function formatValue(raw, type) {
  if (type === 'gstin' || type === 'pan' || type === 'ifsc' || type === 'vehicle') {
    return raw.toUpperCase()
  }
  if (type === 'number' || type === 'phone' || type === 'pincode' || type === 'hsn') {
    return raw.replace(/[^\d.]/g, '')
  }
  if (type === 'alpha') {
    return raw.replace(/[0-9]/g, '')
  }
  return raw
}

function clampValue(raw, type) {
  if (!raw) return raw
  const max = effectiveMaxLength.value
  if (max && raw.length > max) return raw.slice(0, max)
  return raw
}

function onInput(e) {
  let val = e.target.value
  const formatted = formatValue(val, props.type)
  const clamped = clampValue(formatted, props.type)
  // If we had to strip or clamp, write back to the input
  if (clamped !== val) {
    e.target.value = clamped
  }
  emit('update:modelValue', props.type === 'number' ? (clamped ? Number(clamped) : '') : clamped)
  fv.touch(props.name)
  // Re-validate the field immediately for cheap rules
  fv.validate(props.name)
  // Emit validation event so parent can disable submit buttons
  emit('validation', { name: props.name, ...fv.fieldState(props.name) })
}

function onBlur() {
  focused.value = false
  localTouched.value = true
  fv.validate(props.name)
  // For asyncCheck, force an immediate check
  if (props.asyncCheck && fv.uniqueStatus[props.name] === 'idle') {
    const uniqueRule = allRules.value.find(r => r.unique)
    if (uniqueRule) {
      fv.clearError(props.name)
      fv.touch(props.name)
      // bypass debounce on blur
      fv['validate'](props.name)
    }
  }
  emit('validation', { name: props.name, ...fv.fieldState(props.name) })
}

function onFocus() {
  focused.value = true
  fv.clearError(props.name)
}

function onPaste(e) {
  // Let the input handler deal with the pasted value — the @input fires after paste
}

// Expose fv for parent components that want to call validateAll()
defineExpose({
  validate: () => fv.validate(props.name),
  fieldState: computedState,
  fv,
})
</script>

<style scoped>
.form-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.form-input__native {
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
}
.form-input__native:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-tint);
}
.has-error .form-input__native {
  border-color: var(--danger);
  background: var(--danger-bg);
}
.has-error .form-input__native:focus {
  box-shadow: 0 0 0 3px var(--danger-bg);
}
.has-warning .form-input__native {
  border-color: #B5740A;
}
.form-input__unique-icon {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 14px;
  font-weight: 700;
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
}
.form-input__unique-icon.is-available { color: var(--success); background: var(--success-bg); }
.form-input__unique-icon.is-taken { color: var(--danger); background: var(--danger-bg); }
.form-input__unique-icon.is-checking { color: var(--text-3); animation: pulse 1s infinite; }
@keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
</style>
