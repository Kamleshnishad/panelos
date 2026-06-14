# 09 — Form Builder (Dynamic Fields System)

> Super Admin controls every field on every form.
> Show/hide, required/optional, custom fields, conditional logic, PDF visibility.

---

## 1. Overview

### Problem It Solves
```
Every manufacturer needs different fields:
→ Signature PUF needs: Architect Name, Site Engineer
→ Cold room specialist needs: Required Temperature, Humidity %
→ Govt project specialist needs: GEM Reference No
→ Export manufacturer needs: Port of Delivery, Country

Without Form Builder:
→ Developer changes code for each client = weeks of work
→ Client waits + extra cost

With Form Builder:
→ Super Admin adds field in 2 minutes
→ Client sees it immediately
→ Zero code changes needed
```

### What Can Be Configured Per Field
```
✅ Show or hide on screen
✅ Mark as required or optional
✅ Show or hide on PDF
✅ Change display label
✅ Set placeholder text
✅ Set default value
✅ Reorder position (drag-drop)
✅ Add conditional show/hide logic
✅ Field validation (min/max/pattern)
✅ Create new custom fields (any type)
✅ Define dropdown options
```

---

## 2. Forms Available for Configuration

| Form Type | Where Used |
|---|---|
| boq_header | BOQ/Quotation header section |
| panel_row | Each panel line item in BOQ |
| customer | Customer create/edit form |
| order | Order creation form |
| dispatch | Dispatch creation form |
| invoice | Invoice creation form |
| production_batch | Production batch form |
| quality_log | Quality check form |

---

## 3. System Fields vs Custom Fields

```
SYSTEM FIELDS (is_system_field = true):
→ Pre-defined by developer
→ Stored in main tables (quotations, orders etc)
→ Cannot be deleted
→ Label CAN be changed
→ Can be shown/hidden/required-toggled

CUSTOM FIELDS (is_custom_field = true):
→ Created by Super Admin
→ Stored in custom_field_values table
→ Can be deleted
→ Fully configurable
→ Any field type
```

---

## 4. BOQ Header — System Fields

| Field Key | Label | Type | Default Required | Default Visible |
|---|---|---|---|---|
| customer_id | Customer | dropdown | ✅ YES | ✅ YES |
| project_name | Project Name | text | ❌ NO | ✅ YES |
| project_location | Project Location | textarea | ❌ NO | ✅ YES |
| quality_grade | Quality Grade | radio | ✅ YES | ✅ YES |
| validity_days | Validity Days | number | ✅ YES | ✅ YES |
| notes | Notes | textarea | ❌ NO | ✅ YES |
| internal_notes | Internal Notes | textarea | ❌ NO | ❌ NO |
| payment_terms | Payment Terms | textarea | ❌ NO | ❌ NO |

## 5. Panel Row — System Fields

| Field Key | Label | Required | Visible |
|---|---|---|---|
| panel_type_id | PUF Panel | ✅ | ✅ |
| thickness_mm | Thickness | ✅ | ✅ |
| density_type | Density Type | ✅ | ✅ |
| density_kg_m3 | Density kg/m³ | ✅ | ✅ |
| top_skin_material | Top Skin | ✅ | ✅ |
| top_skin_thickness_mm | Top Thickness | ✅ | ✅ |
| top_skin_color | Top Color | ❌ | ✅ |
| top_surface | Top Surface | ✅ | ✅ |
| bottom_skin_material | Bottom Skin | ✅ | ✅ |
| bottom_skin_thickness_mm | Bottom Thickness | ✅ | ✅ |
| bottom_skin_color | Bottom Color | ❌ | ✅ |
| bottom_surface | Bottom Surface | ✅ | ✅ |
| guard_film | Guard Film | ❌ | ✅ |
| cello_tap | Cello Tap | ❌ | ✅ |
| length_mm | Length (mm) | ✅ | ✅ |
| width_mm | Width (mm) | ✅ | ✅ |
| nos | Nos | ✅ | ✅ |
| sqm | SQM | auto | ✅ |
| final_rate | Rate ₹/SQM | ✅ | ✅ |
| amount | Amount ₹ | auto | ✅ |
| hsn_code | HSN Code | auto | ✅ |
| remarks | Remarks | ❌ | ❌ |

---

## 6. Field Types

```
text        → Single line text. Props: min/max length, regex pattern
textarea    → Multi-line text. Props: min/max length, rows
number      → Integer. Props: min, max, step
decimal     → Decimal number. Props: min, max, decimal_places
date        → Date picker. Props: min_date, max_date
datetime    → Date + Time picker
checkbox    → Yes/No toggle. Default: false
dropdown    → Single select. Props: options array
multi_select→ Multiple selections. Props: options array
file        → File upload. Props: allowed_types, max_size_mb
phone       → Phone with Indian format validation
email       → Email format validation
url         → URL format validation
```

---

## 7. Field Properties (Complete)

```javascript
{
  id: 15,
  company_id: 1,
  form_config_id: 1,
  field_key: "architect_name",         // unique, snake_case
  field_label: "Architect / Consultant",
  field_type: "text",

  is_system_field: false,
  is_custom_field: true,
  is_visible: true,                    // show on screen
  is_required: false,                  // mandatory to fill
  is_printable: true,                  // show on PDF
  is_readonly: false,

  placeholder_text: "Enter architect name",
  help_text: "Name of project architect",
  default_value: null,
  sort_order: 8,

  validation_rules: {
    "min_length": 2,
    "max_length": 100
  },

  // For dropdown type:
  dropdown_options: [
    { "value": "industrial", "label": "Industrial" },
    { "value": "commercial", "label": "Commercial" },
    { "value": "cold_storage","label": "Cold Storage" }
  ],

  conditional_logic: null,             // null = always show
}
```

---

## 8. Custom Field Examples

### Example 1: Project Type (Dropdown)
```
Label:    Project Type
Key:      project_type
Type:     dropdown
Options:  Industrial, Commercial, Cold Storage, Residential, Defence, Govt
Required: YES
Printable:YES
```

### Example 2: Architect Name (Text)
```
Label:    Architect / Consultant Name
Key:      architect_name
Type:     text
Required: NO
Printable:YES — printed on quotation PDF
```

### Example 3: Required Temperature (Conditional Number)
```
Label:    Required Temperature °C
Key:      required_temp_c
Type:     number
Validation: min: -30, max: 25
Condition: Show ONLY if Panel Category = cold_room
Required: YES (when visible)
Printable: YES
```

### Example 4: GEM Reference (Conditional Text)
```
Label:    GEM Reference Number
Key:      gem_reference_no
Type:     text
Condition: Show ONLY if Customer Type = government
Required: NO
Printable: YES
```

### Example 5: Site Plan Upload
```
Label:    Site Plan / Drawing
Key:      site_plan_file
Type:     file
Allowed:  pdf, jpg, png
Max Size: 10 MB
Required: NO
Printable: NO
```

---

## 9. Conditional Logic

### Format
```json
// Single condition:
{
  "show_if": {
    "field": "panel_category",
    "operator": "equals",
    "value": "cold_room"
  }
}

// Multiple conditions (AND):
{
  "show_if": {
    "logic": "AND",
    "conditions": [
      { "field": "customer_type", "operator": "equals", "value": "government" },
      { "field": "project_location", "operator": "is_not_empty", "value": null }
    ]
  }
}

// Multiple conditions (OR):
{
  "show_if": {
    "logic": "OR",
    "conditions": [
      { "field": "customer_type", "operator": "equals", "value": "government" },
      { "field": "customer_type", "operator": "equals", "value": "export" }
    ]
  }
}
```

### Supported Operators
```
equals          → field == value
not_equals      → field != value
contains        → field includes value
greater_than    → field > value
less_than       → field < value
is_empty        → field has no value
is_not_empty    → field has a value
```

### Required When Visible Rule
```
If field has conditional_logic AND is_required = true:
→ Required validation ONLY applies when field is VISIBLE
→ Hidden field = not validated (frontend + backend)
```

---

## 10. PDF Print Control

```
is_printable = true:
  Field value shown on PDF under project details block:
  ┌─────────────────────────────────┐
  │ Project Name: Factory Shed      │
  │ Location:     Rajsamand, RJ     │
  │ Architect:    Rajesh Kumar      │  ← custom printable
  │ Project Type: Industrial        │  ← custom printable
  │ GEM Ref:      GEM/2025/456      │  ← custom printable
  └─────────────────────────────────┘

is_printable = false:
  Field used internally ONLY, never on PDF
  Example: internal_ref_code, site_plan_file
```

---

## 11. Form Builder UI (Super Admin)

### Fields List Screen
```
EDIT FIELDS: BOQ / Quotation Header
                                       [+ Add Custom Field]

SYSTEM FIELDS:
☰  Customer*           [Req ✅] [Vis ✅] [PDF ✅]
☰  Project Name        [Req ❌] [Vis ✅] [PDF ✅]
☰  Project Location    [Req ❌] [Vis ✅] [PDF ✅]
☰  Quality Grade       [Req ✅] [Vis ✅] [PDF ❌]
☰  Validity Days       [Req ✅] [Vis ✅] [PDF ❌]
☰  Notes               [Req ❌] [Vis ✅] [PDF ❌]
☰  Internal Notes      [Req ❌] [Vis ❌] [PDF ❌]
☰  Payment Terms       [Req ❌] [Vis ❌] [PDF ✅]

CUSTOM FIELDS:
☰  Architect Name      [Req ❌] [Vis ✅] [PDF ✅] [Edit][Del]
☰  Project Type        [Req ✅] [Vis ✅] [PDF ✅] [Edit][Del]
☰  GEM Reference       [Req ❌] [Vis ✅] [PDF ✅] [Edit][Del]
   └── Condition: Show if Customer Type = Government

☰ = drag handle for reorder

[Save Field Order]  [Preview Form]
```

### Toggle Interaction
```
Click any toggle → instant save → show toast "Field updated"
[Req ✅] ↔ [Req ❌]   toggle required
[Vis ✅] ↔ [Vis ❌]   toggle visibility
[PDF ✅] ↔ [PDF ❌]   toggle printable
```

### Add Custom Field Form
```
ADD CUSTOM FIELD TO: BOQ Header

Field Label*:   [Architect Name             ]
Field Key*:     [architect_name             ] (auto-slug from label)
Field Type*:    [Text ▼                     ]

Placeholder:    [Enter architect name       ]
Help Text:      [Name of project architect  ]
Default Value:  [                           ]

Is Visible:     ● YES  ○ NO
Is Required:    ○ YES  ● NO
Show on PDF:    ● YES  ○ NO

Position:       [After "Project Location" ▼]

CONDITIONAL LOGIC (optional):
Show this field when:
Field:    [customer_type ▼]
Operator: [equals ▼       ]
Value:    [government      ]

[Save Field]
```

---

## 12. Frontend Implementation

### Vue Composable: useFormConfig

```javascript
// composables/useFormConfig.js
export function useFormConfig(formType) {
  const fields = ref([])

  async function loadFields() {
    const res = await axios.get(`/api/v1/settings/form-configs/${formType}/fields`)
    fields.value = res.data.data.fields
  }

  const visibleFields = computed(() =>
    fields.value
      .filter(f => f.is_visible)
      .sort((a, b) => a.sort_order - b.sort_order)
  )

  // Check if field should show (conditional logic)
  function isFieldVisible(field, formData) {
    if (!field.is_visible) return false
    if (!field.conditional_logic) return true

    return evaluateCondition(field.conditional_logic.show_if, formData)
  }

  function evaluateCondition(condition, formData) {
    if (condition.logic === 'AND') {
      return condition.conditions.every(c => evalSingle(c, formData))
    }
    if (condition.logic === 'OR') {
      return condition.conditions.some(c => evalSingle(c, formData))
    }
    return evalSingle(condition, formData)
  }

  function evalSingle(c, data) {
    const val = data[c.field]
    switch (c.operator) {
      case 'equals':       return val == c.value
      case 'not_equals':   return val != c.value
      case 'contains':     return String(val).includes(c.value)
      case 'is_empty':     return !val
      case 'is_not_empty': return !!val
      case 'greater_than': return Number(val) > Number(c.value)
      case 'less_than':    return Number(val) < Number(c.value)
      default:             return true
    }
  }

  return { fields, visibleFields, loadFields, isFieldVisible }
}
```

### Dynamic Field Renderer Component

```vue
<!-- components/FormBuilder/DynamicField.vue -->
<template>
  <div v-if="isVisible" class="field mb-4">
    <label class="block text-sm font-medium mb-1">
      {{ field.field_label }}
      <span v-if="field.is_required" class="text-red-500">*</span>
    </label>

    <InputText v-if="field.field_type === 'text'"
               v-model="modelValue" :placeholder="field.placeholder_text"
               class="w-full" />

    <Textarea v-else-if="field.field_type === 'textarea'"
              v-model="modelValue" :rows="3" class="w-full" />

    <InputNumber v-else-if="field.field_type === 'number'"
                 v-model="modelValue"
                 :min="field.validation_rules?.min"
                 :max="field.validation_rules?.max"
                 class="w-full" />

    <Dropdown v-else-if="field.field_type === 'dropdown'"
              v-model="modelValue"
              :options="field.dropdown_options"
              option-label="label" option-value="value"
              :placeholder="`Select ${field.field_label}`"
              class="w-full" />

    <div v-else-if="field.field_type === 'checkbox'" class="flex items-center gap-2">
      <Checkbox v-model="modelValue" :binary="true" />
      <label>{{ field.field_label }}</label>
    </div>

    <DatePicker v-else-if="field.field_type === 'date'"
                v-model="modelValue" date-format="dd-mm-yy" class="w-full" />

    <small v-if="field.help_text" class="text-gray-500 mt-1 block">
      {{ field.help_text }}
    </small>
    <small v-if="showError" class="text-red-500 mt-1 block">
      {{ field.field_label }} is required
    </small>
  </div>
</template>

<script setup>
const props = defineProps({
  field: Object,
  modelValue: [String, Number, Boolean, Array],
  formData: Object,
  showError: Boolean
})
const emit = defineEmits(['update:modelValue'])

const isVisible = computed(() => {
  if (!props.field.is_visible) return false
  if (!props.field.conditional_logic) return true
  // use useFormConfig.isFieldVisible
  return true
})
</script>
```

### Saving Custom Field Values

```javascript
// When saving quotation, pass custom fields:
await axios.post('/api/v1/quotations', {
  customer_id: form.customer_id,
  project_name: form.project_name,
  // ... system fields
  custom_fields: {
    "architect_name": "Rajesh Kumar",
    "project_type": "industrial",
    "gem_reference_no": ""
  }
})
// Backend saves to custom_field_values table
```

---

## 13. Backend Implementation

### FormFieldService.php

```php
class FormFieldService
{
    // Get all fields for a form
    public function getFieldsForForm(int $companyId, string $formType): array
    {
        return FormField::where('company_id', $companyId)
            ->whereHas('formConfig', fn($q) => $q->where('form_type', $formType))
            ->orderBy('sort_order')
            ->get()->toArray();
    }

    // Validate form submission against field config
    public function validateFormData(array $data, int $companyId, string $formType): array
    {
        $fields = $this->getFieldsForForm($companyId, $formType);
        $errors = [];

        foreach ($fields as $field) {
            if (!$field['is_visible']) continue;
            if (!$this->evaluateCondition($field['conditional_logic'], $data)) continue;

            $value = $data[$field['field_key']] ?? null;

            if ($field['is_required'] && empty($value)) {
                $errors[$field['field_key']][] = "{$field['field_label']} is required";
            }
        }
        return $errors;
    }

    // Save custom field values for a record
    public function saveCustomFieldValues(
        int $companyId, string $recordType,
        int $recordId, array $customFieldData
    ): void {
        foreach ($customFieldData as $fieldKey => $value) {
            $field = FormField::where('company_id', $companyId)
                ->where('field_key', $fieldKey)
                ->where('is_custom_field', true)
                ->first();
            if (!$field) continue;

            CustomFieldValue::updateOrCreate(
                ['company_id' => $companyId, 'form_field_id' => $field->id,
                 'record_type' => $recordType, 'record_id' => $recordId],
                ['field_value' => is_array($value) ? json_encode($value) : (string)$value]
            );
        }
    }

    // Get custom field values for a record
    public function getCustomFieldValues(
        int $companyId, string $recordType, int $recordId
    ): array {
        return CustomFieldValue::where('company_id', $companyId)
            ->where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->with('formField')
            ->get()
            ->mapWithKeys(fn($cfv) => [$cfv->formField->field_key => $cfv->field_value])
            ->toArray();
    }

    // Evaluate conditional logic
    private function evaluateCondition(?array $condition, array $data): bool
    {
        if (!$condition) return true;
        $showIf = $condition['show_if'];

        if (isset($showIf['logic'])) {
            $results = array_map(
                fn($c) => $this->evalSingle($c, $data),
                $showIf['conditions']
            );
            return $showIf['logic'] === 'AND'
                ? !in_array(false, $results)
                : in_array(true, $results);
        }
        return $this->evalSingle($showIf, $data);
    }

    private function evalSingle(array $c, array $data): bool
    {
        $val = $data[$c['field']] ?? null;
        return match($c['operator']) {
            'equals'       => $val == $c['value'],
            'not_equals'   => $val != $c['value'],
            'contains'     => str_contains((string)$val, $c['value']),
            'is_empty'     => empty($val),
            'is_not_empty' => !empty($val),
            'greater_than' => (float)$val > (float)$c['value'],
            'less_than'    => (float)$val < (float)$c['value'],
            default        => true,
        };
    }
}
```

### PDF — Print Custom Fields

```php
// In PDF generation:
$customFields = $formFieldService->getCustomFieldValues($companyId, 'quotation', $id);

$printableFields = FormField::where('company_id', $companyId)
    ->where('form_type', 'boq_header')
    ->where('is_printable', true)
    ->where('is_custom_field', true)
    ->orderBy('sort_order')
    ->get();

$pdfData['custom_printable'] = [];
foreach ($printableFields as $field) {
    $value = $customFields[$field->field_key] ?? null;
    if ($value) {
        $pdfData['custom_printable'][] = [
            'label' => $field->field_label,
            'value' => $value
        ];
    }
}
// In Blade template: loop $custom_printable and render label: value
```

---

## 14. Database Tables

### form_configs
```sql
CREATE TABLE form_configs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id  BIGINT UNSIGNED NOT NULL,
  form_type   VARCHAR(50) NOT NULL,
  form_name   VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_form_company (form_type, company_id)
);
```

### form_fields
```sql
CREATE TABLE form_fields (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  form_config_id    BIGINT UNSIGNED NOT NULL,
  field_key         VARCHAR(100) NOT NULL,
  field_label       VARCHAR(255) NOT NULL,
  field_type        ENUM('text','textarea','number','decimal','date','datetime',
                          'checkbox','dropdown','multi_select','file','phone','email') NOT NULL,
  is_system_field   BOOLEAN DEFAULT FALSE,
  is_custom_field   BOOLEAN DEFAULT FALSE,
  is_visible        BOOLEAN DEFAULT TRUE,
  is_required       BOOLEAN DEFAULT FALSE,
  is_printable      BOOLEAN DEFAULT TRUE,
  is_readonly       BOOLEAN DEFAULT FALSE,
  default_value     TEXT NULL,
  placeholder_text  VARCHAR(255) NULL,
  help_text         VARCHAR(500) NULL,
  sort_order        INT DEFAULT 0,
  dropdown_options  JSON NULL,
  validation_rules  JSON NULL,
  conditional_logic JSON NULL,
  depends_on_field  VARCHAR(100) NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_field_form (form_config_id, field_key)
);
```

### custom_field_values
```sql
CREATE TABLE custom_field_values (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  form_field_id BIGINT UNSIGNED NOT NULL,
  record_type   VARCHAR(50) NOT NULL,   -- 'quotation','order','customer'
  record_id     BIGINT UNSIGNED NOT NULL,
  field_value   TEXT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_field_record (form_field_id, record_type, record_id)
);
```

