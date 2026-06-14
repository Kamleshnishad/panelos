# 05 — Production Module

> Fully configurable production tracking. Stages, machines, cutting schedule, QC.

---

## 1. Overview

```
Order Confirmed
    ↓
Production Supervisor creates Batch
    ↓
Assign Machine + Shift + Operator
    ↓
Floor Worker completes each Stage
(checklist + parameters + photos)
    ↓
Cutting Schedule generated
    ↓
Quality Check logged
    ↓
Batch Complete → Order item = PRODUCED
    ↓
Ready for Dispatch
```

---

## 2. Production Stage Builder (Super Admin)

### Key Principle
Every manufacturer has different process. Stages are NOT hardcoded.
Super Admin configures stages via drag-drop builder.

### Stage Config Screen
```
Settings → Production → Stages

☰ 1. Coil Loading          [Mandatory] [Edit][Del]
☰ 2. Film Application      [Mandatory] [Edit][Del]
☰ 3. Forming & Bending     [Mandatory] [Edit][Del]
☰ 4. Chemical Injection    [Mandatory] [Edit][Del]
☰ 5. Oven Curing           [Mandatory] [Edit][Del]
☰ 6. Quality Check         [Mandatory] [Edit][Del]
☰ 7. Auto Cutting          [Mandatory] [Edit][Del]
☰ 8. Branding              [Optional]  [Edit][Del]
☰ 9. Packing               [Mandatory] [Edit][Del]

☰ = drag handle for reordering
[+ Add Stage]
```

### Per Stage Configuration
```
Stage Name*:          [Chemical Injection     ]
Stage Code*:          [CHEM_INJECT            ] (auto-slug)
Stage Order:          [4                      ] (auto from position)
Responsible Role*:    [Floor Worker ▼         ]
Is Mandatory:         ● YES  ○ NO
Requires Photo:       ○ YES  ● NO
Requires QC Signoff:  ○ YES  ● NO
Estimated Duration:   [30] minutes

CHECKLIST ITEMS: (add/remove)
[Polyol drum connected           ] [×]
[Isocyanate drum connected       ] [×]
[Mixing head cleaned             ] [×]
[Temperature checked             ] [×]
[+ Add Checklist Item]

PARAMETERS TO LOG:
Key           | Label           | Type   | Required | Min | Max
polyol_ratio  | Polyol Ratio %  | number | YES      | 45  | 65
iso_ratio     | Isocyanate %    | number | YES      | 35  | 55
mix_temp_c    | Mix Temp °C     | number | YES      | 18  | 30
ambient_temp  | Ambient Temp °C | number | NO       | 10  | 45
[+ Add Parameter]

Parameter Types: number | text | dropdown | checkbox | temperature | ratio
```

### Default Stages — Signature PUF (Seed Data)

```javascript
const stages = [
  {
    name: "Coil Loading", code: "COIL_LOAD", order: 1, mandatory: true,
    checklist: ["Top coil loaded", "Bottom coil loaded",
                "Color verified", "Width set to 1000mm"],
    parameters: []
  },
  {
    name: "Protective Film Application", code: "FILM_APPLY", order: 2, mandatory: true,
    checklist: ["Guard film loaded", "Film on top skin", "Film on bottom skin"],
    parameters: []
  },
  {
    name: "Forming & Bending", code: "FORMING", order: 3, mandatory: true,
    checklist: ["Profile rollers set", "Corrugation checked", "Test run done"],
    parameters: [
      {key:"profile_type", label:"Profile Type", type:"dropdown",
       options:["5-Rib","Micro-Rib","Flat","Corrugated"], required:true}
    ]
  },
  {
    name: "Chemical Injection", code: "CHEM_INJECT", order: 4, mandatory: true,
    checklist: ["Polyol connected", "Isocyanate connected",
                "Mixing head cleaned", "Temperature checked"],
    parameters: [
      {key:"polyol_ratio",     label:"Polyol Ratio %",    type:"number", required:true,  min:45, max:65},
      {key:"isocyanate_ratio", label:"Isocyanate Ratio %",type:"number", required:true,  min:35, max:55},
      {key:"mix_temp_c",       label:"Mix Temperature °C",type:"number", required:true,  min:18, max:30},
      {key:"ambient_temp_c",   label:"Ambient Temp °C",   type:"number", required:false, min:10, max:45}
    ]
  },
  {
    name: "Oven Curing", code: "OVEN_CURE", order: 5, mandatory: true,
    checklist: ["Oven temperature stable", "Belt speed set", "Exit temp checked"],
    parameters: [
      {key:"oven_temp_c",        label:"Oven Temperature °C",  type:"number", required:true, min:35, max:65},
      {key:"curing_duration_min",label:"Curing Duration (min)", type:"number", required:true, min:5,  max:30}
    ]
  },
  {
    name: "Quality Check", code: "QC_CHECK", order: 6, mandatory: true,
    requires_qc_signoff: true,
    checklist: ["Thickness measured", "Density checked", "Surface inspected"],
    parameters: [
      {key:"thickness_actual", label:"Actual Thickness mm", type:"number", required:true},
      {key:"density_actual",   label:"Actual Density kg/m³",type:"number", required:true},
      {key:"rejected_count",   label:"Rejected Count",       type:"number", required:true, min:0}
    ]
  },
  {
    name: "Auto Cutting", code: "AUTO_CUT", order: 7, mandatory: true,
    checklist: ["Length set on machine", "Test cut done", "Count verified"],
    parameters: [
      {key:"length_set_mm",    label:"Length Set (mm)",  type:"number", required:true},
      {key:"actual_length_mm", label:"Actual Length (mm)",type:"number", required:true}
    ]
  },
  {
    name: "Packing", code: "PACKING", order: 8, mandatory: true,
    checklist: ["Panels stacked correctly", "Bundle count verified",
                "Stretch film applied", "Order number marked"],
    parameters: [
      {key:"bundle_count",       label:"Number of Bundles",  type:"number", required:true},
      {key:"panels_per_bundle",  label:"Panels per Bundle",  type:"number", required:true}
    ]
  }
]
```

---

## 3. Machine Management

### Machine Status
```
ACTIVE      → can accept new batches
MAINTENANCE → scheduled, no new batches for that period
BREAKDOWN   → emergency → alert Owner + Supervisor immediately
               → active batches go ON_HOLD automatically
IDLE        → available, no batch assigned

On BREAKDOWN alert:
WhatsApp: "🚨 Machine [name] breakdown at [time]. Action needed."
```

### Machine Config
```
Name:             Continuous Line 1
Machine Code:     ML-01
Type:             Continuous PUF Line
Capacity/Day:     1500 SQM
Day Shift:        07:00 → 19:00
Night Shift:      19:00 → 07:00
Last Maintenance: 01-May-2025
Next Maintenance: 01-Aug-2025
```

---

## 4. Production Batch Lifecycle

### Status Flow
```
SCHEDULED → IN_PROGRESS → COMPLETED
               ↓
            ON_HOLD (machine breakdown / material shortage)
               ↓
            IN_PROGRESS (issue resolved)

SCHEDULED/IN_PROGRESS → CANCELLED
```

### Create Batch Screen
```
Order*:          [ORD-2025-022 ▼]  Kwality | Roof 30MM | 312.93 SQM
Order Item*:     [R PUF Roof 30MM ▼]  Pending: 312.93 SQM
Machine*:        [Continuous Line 1 ▼]  Capacity: 750 SQM/shift
Shift*:          ● Day  ○ Night  ○ General
Planned Date*:   [10-Jun-2025]
Planned Qty*:    [87] panels = 312.93 SQM
Operator*:       [Ramesh Kumar ▼]
Supervisor:      [Suresh Patel ▼]
Notes:           [                ]
[Create Batch]
```

### Batch Detail View
```
BATCH-2025-045 | ORD-022 | Kwality | Roof 30MM | Day | 10-Jun

Status: IN_PROGRESS

Progress: ██████████████░░  6/8 stages

✅ 1. Coil Loading         Done 08:15 by Ramesh
✅ 2. Film Application     Done 08:45 by Ramesh
✅ 3. Forming & Bending    Done 09:30 by Ramesh
✅ 4. Chemical Injection   Done 10:30 by Ramesh
      Polyol:55% | Iso:45% | Temp:22°C
✅ 5. Oven Curing          Done 11:45 by Ramesh
      Temp:45°C | Duration:12min
🔄 6. Quality Check        IN PROGRESS
⏳ 7. Auto Cutting         Pending
⏳ 8. Packing              Pending

Produced: 85 panels | Rejected: 2
```

---

## 5. Stage Completion — Floor Worker View

```
STAGE 4: CHEMICAL INJECTION
Batch: BATCH-2025-045

CHECKLIST:
☑ Polyol drum connected
☑ Isocyanate drum connected
☑ Mixing head cleaned
☑ Temperature checked

PARAMETERS:
Polyol Ratio %*:        [55]
Isocyanate Ratio %*:    [45]
Mix Temperature °C*:    [22]
Ambient Temperature:    [28]

REMARKS: [         ]

[Complete Stage]  [Skip (reason required — only if optional)]

RULES:
→ Complete stages in ORDER
→ Cannot skip MANDATORY stages
→ All REQUIRED params must be filled
→ All checklist items must be checked
→ QC stages need QC-role user
```

---

## 6. Cutting Schedule

### Generate Schedule
```
Date: [10-Jun-2025]  Machine: [Line 1 ▼]  Shift: [Day ▼]

Pending Orders:
☑ ORD-022 | Kwality | Roof 30MM | 312.93 SQM | Due: 11-Jun
☑ ORD-023 | Ideal Agri | Wall 50MM | 231 SQM | Due: 15-Jun

Sort By: ● Delivery Date  ○ FIFO  ○ Manual

[Generate Schedule]
```

### Generated Schedule View (Operator Screen)
```
CUTTING SCHEDULE | Line 1 | Day | 10-Jun-2025

Sl | Panel Spec          | Prod.Length | Width | Nos | Order  | Status
1  | Roof 30MM 0.40 PPGI | 3660mm      | 1000  |  60 | ORD-022| ⬜ Pending
2  | Roof 30MM 0.40 PPGI | 3355mm      | 1000  |  24 | ORD-022| ⬜ Pending
3  | Roof 30MM 0.40 PPGI | 4270mm      | 1000  |   3 | ORD-022| ⬜ Pending
─── CHANGEOVER: 30MM → 50MM ────────────────────────────────────────────
4  | Wall 50MM 0.50 PPGI | 2750mm      | 1000  |  84 | ORD-023| ⬜ Pending
5  | Wall 50MM 0.50 PPGI | 3000mm*     | 1000  |   1 | ORD-023| ⬜ Pending
   ⚠️ *Order: 1500mm → Produce at 3000mm → Cut to 1500mm × 2

[Print Sheet]  [Activate Schedule]
```

### Under 2000mm Logic in Schedule
```javascript
function getProductionLength(orderLength, minLength = 2000) {
  if (orderLength < minLength) {
    return {
      production_length: orderLength * 2,
      needs_doubling: true,
      note: `Produce at ${orderLength*2}mm. Cut → ${orderLength}mm × 2 pcs.`
    }
  }
  return { production_length: orderLength, needs_doubling: false }
}

// Odd quantity:
// Order: 1500mm × 3 nos
// Produce: 3000mm × 2 (gives 4 pcs, 1 extra = customer wastage)
```

### Operator Marks Cuts
```
Sl 1: Roof 30MM 3660mm × 60 nos
[✅ Mark Cut]  Actual Length: [3660] mm  Wastage: [12] mm/panel
```

---

## 7. Quality Control

### QC Entry Screen
```
QUALITY CHECK — BATCH-2025-045 | Roof 30MM | 87 panels

THICKNESS:
  Specified: [30] mm   Actual: [30.5] mm   Tolerance ±2mm → ✅ PASS

FOAM DENSITY:
  Specified: [40] kg/m³  Actual: [39.5] kg/m³  Tolerance ±2 → ✅ PASS

QUALITY:
  Foam Quality:    ● Pass  ○ Fail  ○ Marginal
  Surface Quality: ● Pass  ○ Fail  ○ Marginal
  Skin Adhesion:   ● Pass  ○ Fail  ○ Marginal
  Edge Quality:    ● Pass  ○ Fail  ○ Marginal

OVERALL: ● PASS  ○ FAIL  ○ REWORK

REJECTIONS:
  Total Checked: [87]   Rejected: [2]
  Reason:  [Surface scratch on 2 panels  ]
  Defects: ☑ Scratch ☐ Dent ☐ Delamination ☐ Density Var
  Corrective: [Check guard film tension  ]

[Submit QC]
```

### QC Result Actions
```
PASS:     → Batch moves to next stage
           → Panels available for dispatch

FAIL:     → Alert to Owner + Supervisor
           → Panels BLOCKED from dispatch
           Options:
           A. SCRAP → wastage entry, cost write-off
           B. REWORK → new replacement batch
           C. DOWNGRADE → reduced rate (needs approval)

MARGINAL: → Supervisor review required
           → Supervisor approves or rejects
```

### QC Alerts
```
Rejection rate per shift > 5%:
  "⚠️ High rejection in Day Shift today: 8.2%"

Foam density variance:
  "Chemical batch producing lower density"

Most common defect tracking
Operator-wise rejection tracking (internal only)
```

---

## 8. Production Dashboard

```
PRODUCTION DASHBOARD                    10-Jun-2025

┌──────────┬──────────┬──────────┬──────────┐
│ TODAY    │ IN       │ READY    │ REJECTION│
│ 1,120SQM │ PROGRESS │ DISPATCH │    2.3%  │
│ of 1,500 │ 2 batches│ 3 orders │          │
└──────────┴──────────┴──────────┴──────────┘

ACTIVE BATCHES:
● BATCH-045 | Line 1 | Day | Kwality → Roof 30MM
  Stage: Quality Check | Progress: ██████░░ 6/8
  [View Details] [Mark Stage Done]

● BATCH-046 | Line 2 | Day | Ideal Agri → Wall 50MM
  Stage: Packing | Progress: ████████░ 7/8

ALERTS:
⚠️ Low Stock: PPGI 0.40mm OW — 45kg remaining
⚠️ BATCH-043: QC FAILED — 3 panels rejected
```

---

## 9. Vue Component Structure

```
views/Production/
├── Dashboard.vue
├── Schedule.vue         ← Weekly calendar
├── Batches/
│   ├── Index.vue
│   ├── Create.vue
│   └── Detail.vue       ← Stage-by-stage tracking
│       └── components/
│           ├── StageProgress.vue
│           ├── StageCard.vue
│           ├── StageForm.vue
│           ├── ChecklistForm.vue
│           └── ParameterForm.vue
├── CuttingSchedule/
│   ├── Index.vue
│   ├── Generate.vue
│   └── Detail.vue       ← Mark cuts complete
└── Quality/
    ├── Index.vue
    └── Create.vue
```

