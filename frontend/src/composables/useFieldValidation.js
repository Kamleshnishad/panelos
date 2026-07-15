import { ref, watch, computed } from 'vue'

// ── Built-in rule checkers ────────────────────────────────────────
const VALIDATORS = {
  textOnly: (v) => {
    if (!v) return null
    if (/\d/.test(v)) return 'Only letters and symbols allowed.'
    return null
  },
  numberOnly: (v) => {
    if (!v && v !== 0) return null
    if (!/^\d*\.?\d*$/.test(String(v))) return 'Only numbers allowed.'
    return null
  },
  alphanumeric: (v) => {
    if (!v) return null
    if (!/^[a-zA-Z0-9]+$/.test(v)) return 'Only letters and numbers allowed.'
    return null
  },
  email: (v) => {
    if (!v) return null
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email address.'
    return null
  },
  phone: (v) => {
    if (!v) return null
    const d = String(v).replace(/\D/g, '')
    if (d.length < 10) return `Phone must be 10 digits. (${d.length}/10)`
    if (d.length > 10) return 'Phone cannot be more than 10 digits.'
    if (!/^[6-9]\d{9}$/.test(d)) return 'Enter a valid Indian mobile number (starts with 6-9).'
    return null
  },
  gstin: (v) => {
    if (!v) return null
    const u = v.toUpperCase().replace(/\s/g, '')
    if (u.length > 0 && u.length < 15) return null
    if (u.length === 15 && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/.test(u))
      return 'Enter a valid 15-character GSTIN.'
    return null
  },
  pan: (v) => {
    if (!v) return null
    const u = v.toUpperCase().replace(/\s/g, '')
    if (u.length > 0 && u.length < 10) return null
    if (u.length === 10 && !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(u))
      return 'Enter a valid PAN (e.g. ABCDE1234F).'
    return null
  },
  pincode: (v) => {
    if (!v) return null
    const d = String(v).replace(/\D/g, '')
    if (d.length < 6) return `Pincode must be 6 digits. (${d.length}/6)`
    if (d.length > 6) return 'Pincode cannot be more than 6 digits.'
    return null
  },
  ifsc: (v) => {
    if (!v) return null
    const u = v.toUpperCase().replace(/\s/g, '')
    if (u.length > 0 && u.length < 11) return null
    if (u.length === 11 && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(u))
      return 'Enter a valid IFSC code (e.g. HDFC0001234).'
    return null
  },
  hsn: (v) => {
    if (!v) return null
    const d = String(v).replace(/\D/g, '')
    if (d.length > 0 && d.length < 4) return null
    if (!/^\d{4,8}$/.test(d)) return 'HSN must be 4 to 8 digits.'
    return null
  },
  vehicle: (v) => {
    if (!v) return null
    const u = v.toUpperCase().replace(/\s/g, '')
    if (!/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{1,4}$/.test(u))
      return 'Enter a valid vehicle number (e.g. MH12AB1234).'
    return null
  },
}

function checkRule(rule, value) {
  if (rule.required && !value && value !== 0) {
    return `${rule.label || 'This field'} is required.`
  }
  if (!value && value !== 0) return null
  if (rule.type && VALIDATORS[rule.type]) {
    return VALIDATORS[rule.type](value)
  }
  if (rule.pattern) {
    if (!new RegExp(rule.pattern).test(String(value)))
      return rule.message || 'Invalid format.'
  }
  if (rule.min !== undefined) {
    if (Number(value) < Number(rule.min))
      return rule.minMessage || `Must be at least ${rule.min}.`
  }
  if (rule.max !== undefined) {
    if (Number(value) > Number(rule.max))
      return rule.maxMessage || `Cannot exceed ${rule.max}.`
  }
  if (rule.minLength) {
    if (String(value).length < rule.minLength)
      return rule.minLengthMessage || `Must be at least ${rule.minLength} characters.`
  }
  if (rule.maxLength) {
    if (String(value).length > rule.maxLength)
      return rule.maxLengthMessage || `Maximum ${rule.maxLength} characters allowed.`
  }
  if (rule.custom) {
    return rule.custom(value)
  }
  return null
}

// ── Composable ────────────────────────────────────────────────────
export function useFieldValidation({ rules, getValues }) {
  const errors = ref({})
  const warnings = ref({})
  const touched = ref({})
  const dirty = ref({})
  const uniqueStatus = ref({})

  const debounceTimers = {}

  async function runUniqueCheck(name, value, rule) {
    if (!value || String(value).trim().length === 0) {
      uniqueStatus.value[name] = 'idle'
      return
    }
    uniqueStatus.value[name] = 'checking'
    clearTimeout(debounceTimers[`unique_${name}`])
    debounceTimers[`unique_${name}`] = setTimeout(async () => {
      try {
        const { checkUnique } = await import('../services/validationService.js')
        const res = await checkUnique({
          table: rule.unique.table,
          column: rule.unique.column,
          value: String(value).trim(),
          ignoreId: rule.unique.ignoreId || null,
        })
        if (res.data.available) {
          uniqueStatus.value[name] = 'available'
          warnings.value[name] = null
        } else {
          uniqueStatus.value[name] = 'taken'
          warnings.value[name] = rule.unique.message || `This ${rule.label || name} is already used.`
        }
      } catch {
        uniqueStatus.value[name] = 'idle'
      }
    }, rule.unique.debounce || 400)
  }

  function validate(name) {
    const ruleSet = rules[name]
    if (!ruleSet) return true
    const value = getValues ? getValues()[name] : undefined
    const err = checkAllRules(ruleSet, value)
    errors.value[name] = err
    touched.value[name] = true
    dirty.value[name] = true

    const uniqueRule = ruleSet.find(r => r.unique)
    if (uniqueRule && uniqueRule.unique.table) {
      runUniqueCheck(name, value, uniqueRule)
    }
    return !err
  }

  function checkAllRules(ruleSet, value) {
    for (const rule of ruleSet) {
      const err = checkRule(rule, value)
      if (err) return err
    }
    return null
  }

  function validateAll() {
    let allOk = true
    for (const name of Object.keys(rules)) {
      if (!validate(name)) allOk = false
    }
    return allOk
  }

  function clearError(name) {
    errors.value[name] = null
    warnings.value[name] = null
  }

  function touch(name) {
    touched.value[name] = true
  }

  function fieldState(name) {
    return {
      error: touched.value[name] ? errors.value[name] : null,
      warning: warnings.value[name] || null,
      uniqueStatus: uniqueStatus.value[name] || 'idle',
    }
  }

  const isValid = computed(() => {
    for (const name of Object.keys(rules)) {
      if (errors.value[name]) return false
    }
    for (const name of Object.keys(rules)) {
      if (uniqueStatus.value[name] === 'taken') return false
    }
    return true
  })

  watch(
    () => getValues ? getValues() : null,
    (newVals) => {
      if (!newVals) return
      for (const name of Object.keys(rules)) {
        if (touched.value[name]) {
          const err = checkAllRules(rules[name], newVals[name])
          errors.value[name] = err
          const uniqueRule = rules[name].find(r => r.unique)
          if (uniqueRule && uniqueRule.unique.table && dirty.value[name]) {
            runUniqueCheck(name, newVals[name], uniqueRule)
          }
        }
      }
    },
    { deep: true }
  )

  return {
    errors, warnings, touched, dirty, uniqueStatus, isValid,
    validate, validateAll, clearError, touch, fieldState,
    hasError: (n) => touched.value[n] && !!errors.value[n],
    hasWarning: (n) => !!warnings.value[n],
    isTouched: (n) => touched.value[n],
    isUniqueAvailable: (n) => uniqueStatus.value[n] === 'available',
    isUniqueTaken: (n) => uniqueStatus.value[n] === 'taken',
    isUniqueChecking: (n) => uniqueStatus.value[n] === 'checking',
  }
}
