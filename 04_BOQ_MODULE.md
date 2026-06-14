# 04 — BOQ Module

> Most critical module. Every field, every fix, every validation, PDF layout.

---

## 1. Overview

BOQ (Bill of Quantities) = primary sales document.
Creates Proforma Invoice → converts to Order.

### Screen Flow
```
List BOQ → [New BOQ] → Fill Header → Add Panels → Add Accessories
         → Summary/Totals → [Save Draft] → [Preview PDF] → [Send] → [Accept] → Order
```

---

## 2. BOQ Header — CORRECTED Fields

```
ONLY these fields in header (order-level):
┌─────────────────────────────────────────────────────────┐
│ [Logo]              BOQ              Date: DD-MM-YYYY   │
│ SCP-028 │ Customer* [dropdown] [+Add] │ Quality: ●H ○M ○S │
│ Project Name [text]    │ Project Location [text]         │
└─────────────────────────────────────────────────────────┘

REMOVED from header (was wrong in old software):
❌ Surface Top    → moved to each panel row
❌ Surface Bottom → moved to each panel row
❌ Cello Tap      → moved to each panel row
❌ Guard Film     → moved to each panel row
```

---

## 3. Panel Row — Every Field

| # | Field | Type | Rule |
|---|---|---|---|
| 1 | PUF Panel Type | Dropdown | Required. On change: auto-set surface, filter thickness |
| 2 | PUF Thickness | Dropdown | Required. Options from panel_type |
| 3 | Density Type | Dropdown | PUF / PIR. Auto from panel type |
| 4 | Density kg/m³ | Dropdown | PUF:[38,40,42] PIR:[40,42,48] |
| 5 | Top Skin Material | Dropdown | PPGI/PPGL/SS304/GI/Aluminium |
| 6 | Top Thickness | Dropdown | 0.30/0.35/0.40/0.45/0.50/0.60mm |
| 7 | Top Color | Dropdown | From color master |
| 8 | Top Surface | Dropdown | **AUTO-SET. Roof=RIBBED, others=PLAIN** |
| 9 | Bottom Skin Material | Dropdown | Same options as top |
| 10 | Bottom Thickness | Dropdown | Same options |
| 11 | Bottom Color | Dropdown | From color master |
| 12 | Bottom Surface | Dropdown | Always default PLAIN |
| 13 | Guard Film | Checkbox | Per row (NOT header) |
| 14 | Cello Tap | Checkbox | Per row (NOT header) |
| 15 | Length (mm) | Number | Min:500 Max:14000. Show ⚠️ if <2000 |
| 16 | Width (mm) | Number | **READ-ONLY. Default 1000** |
| 17 | Nos | Number | Integer only |
| 18 | SQM | Display | AUTO = (L/1000)×(W/1000)×Nos |
| 19 | Rate ₹/SQM | Number | Auto from pricing, editable |
| 20 | Amount ₹ | Display | AUTO = SQM × Rate |
| 21 | HSN Code | Display | Auto from panel type |
| 22 | Action | Button | Delete row |

---

## 4. Surface Auto-Set Logic

```javascript
// Fires ONLY when panel type changes — NOT on thickness/skin/color change
watch(() => row.panel_type_id, (newId) => {
  const panel = panelTypes.find(p => p.id === newId)
  if (!panel || row.surface_manually_changed) return

  row.top_surface = panel.category === 'roof' ? 'RIBBED' : 'PLAIN'
  row.bottom_surface = 'PLAIN'  // always plain
})

// Track manual override
function onSurfaceChange() {
  row.surface_manually_changed = true
}
// On load/edit: do NOT re-trigger auto-set
```

---

## 5. Accessories Section

**FIXED: Dynamic — not hardcoded**

```
OLD (Wrong): Only C_Channel, L_Inner, L_Outer hardcoded
OLD (Wrong): Two separate tables (Accessories + Other Accessories)
NEW (Correct): One unified table, all accessories from master
```

### Accessory Row Fields
- Accessory dropdown (from accessories_master, searchable)
- Sub-type/Description (free text)
- Unit (auto from master)
- Qty (number)
- Rate (auto from master, editable)
- Amount (auto = qty × rate)
- HSN Code (auto, read-only)
- Delete button

### Item Types
1. Standard Accessory → from dropdown
2. Door/Window → special fields (size, type, NOS-based)
3. Installation → SAC code 994568
4. Custom → free text

### Door Item Special Fields
```
Width (mm) × Height (mm)
Type: Single / Double
Skin: [PCGI ▼] Thickness: [0.80mm ▼]
View Panel: ☑ YES  Size: [400×600]
Accessories: ☑ Hinges ☑ Lock ☑ Handle ☑ Closer
Unit: NOS (NOT SQM)
Rate: per NOS
```

---

## 6. Summary Section

```
Panel Subtotal:      ₹ 3,28,577
Accessories:         ₹    29,250
Installation:        ₹         0
─────────────────────────────────
Subtotal:            ₹ 3,57,827
Discount [0]%:       ₹         0
Taxable Amount:      ₹ 3,57,827
GST @18%:            ₹    64,409
  (CGST 9% + SGST 9% OR IGST 18%)
Transportation: ○ Extra as Actual ● Fixed [₹ 18,000]
Round Off:           ₹         0
─────────────────────────────────
GRAND TOTAL:         ₹ 4,22,236
─────────────────────────────────
Advance [₹ 1,50,000 or 50%]:
Balance Due:         ₹ 2,72,236
```

### GST State Detection
```
Company GSTIN: 24AAFFU9050M1ZS → state code 24 (Gujarat)
Customer same state → CGST 9% + SGST 9%
Customer different state → IGST 18%

State codes: 24=GJ, 27=MH, 08=RJ, 09=UP, 07=DL, 29=KA, 33=TN
```

---

## 7. Validations

```
REQUIRED: Customer, Panel Type, Thickness, Density, Top/Bottom Skin,
          Top/Bottom Surface, Length, Nos, Rate
          At least 1 panel row

WARNINGS (not blocking):
- Length < 2000mm: "Panel will be produced at doubled length"
- Rate > 15% below calculated: "Rate is X% below standard"

BLOCKED:
- SQM = 0 (length or nos = 0)
- Missing HSN on panel type
- No panel rows on save
```

---

## 8. Actions & Status

```
DRAFT:    [Edit ✅] [Delete ✅] [Send ✅]
SENT:     [Edit ✅] [Revise ✅] [Accept ✅] [Reject ✅]
ACCEPTED: [Revise ✅]  ← locked otherwise
REJECTED: [Revise ✅]
REVISED:  ← locked (new version is active)
```

---

## 9. Revision System

```
Original: SCP-025 (v1) → status: REVISED (locked, read-only)
Revision: SCP-025-v2   → status: DRAFT (editable)
parent_quotation_id links revision to original
Show revision history panel on detail view
```

---

## 10. PDF Layout (Exact — from actual Signature PUF invoices)

### Page 1
```
[Logo]           PROFORMA INVOICE        Date: 27/11/23

UMA SIGNATURE PUF PANEL LLP             info@signaturepufpanel.com
Survey No 158/1, Dhanora...             GSTIN: 24AAFFU9050M1ZS
                                         AN ISO 9001:2015 COMPANY

BUYER'S: KWALITY BUSINESS ASSOCIATE LLP  DATE: 27/11/23
[Address]                                REF NO: KWALITY...
GSTIN: [if available]                    PFI NO: SCP-027

[ITEMS TABLE]
Sr.No | Description          | Image | Qty  | UoM | Rate | Amount
  1   | SUPPLY OF SIGNATURE  |[img]  |312.93| SQM |1050  |328577
      | MAKE CONTINUOUS LINE |       |      |     |      |
      | R PUF PANEL ROOF     |       |      |     |      |
      | of 30MM Thick.       |       |      |     |      |
      | PUF DENSITY: 40(+/-2)|       |      |     |      |
      | TOP SKIN: 0.40 PPGL  |       |      |     |      |
      | OFF WHITE            |       |      |     | HSN: 39259010
      | SIZE sub-table:      |       |      |     |      |
      | LENGTH|WIDTH|NOS|SQM |       |      |     |      |
      | 3660  |1000 | 60|219.6       |      |     |      |
      | 3355  |1000 | 24|80.52       |      |     |      |
      | 4270  |1000 |  3|12.81       |      |     |      |
  2   | ROOF SIDE CAP        |       | 55.0 | MTR | 150  | 8,250
  3   | RIDGES INNER & OUTER |       | 60.0 | MTR | 350  | 21,000

                              TOTAL:            ₹ 3,57,827
                     TRANSPORTATION EXTRA
                              GST@18%:          ₹    64,409
PTO  [ONLY SUPPLY]    GRAND TOTAL:             ₹ 4,22,235
                              ADVANCE:          ₹ 1,50,000
                              BALANCE:          ₹ 2,72,235
```

### Page 2 — Terms & Conditions
Full T&C text from settings (configurable per company):
- Bank Details
- Scope of Work
- Price Basis (Ex-works)
- Payment Terms (50%+50%)
- Delivery Terms
- Offer Validity (10 days)
- Wastage note
- Exclusions (1-7)
- Authorized signatory + company seal

### Page 3 — BOQ Sheet (Cutting List)
```
[Logo]   BOQ              DATE: 27/11/23
TAP: [SIGNATURE]    GUARD FILM: YES
ORDER NO: 0         Customer: KWALITY...

TOP SKIN: 0.40 PPGL OFF WHITE
BOTTOM SKIN: 0.40 PPGL OFF WHITE   30MM ROOF

Sr.No | LENGTH | WIDTH | NOS | SQM   | ACCESSORIES | MTR
  1   |  3660  |  1000 |  60 | 219.60| ROOF SIDE CAP| 55
  2   |  3355  |  1000 |  24 |  80.52| OUTER RIDGES | 30
  3   |  4270  |  1000 |   3 |  12.81| INNER RIDGES | 30
      | TOTAL  |       |  87 | 312.93|              |

TRANSPORTER NAME & VEHICLE NO: _____ DRIVER SIGN & MOBILE: _____
DISPATCH BY: _____  TIME: _____  DATE: _____
```

---

## 11. All 20 Bugs Fixed from Old Software

| # | Old Bug | Fix |
|---|---|---|
| 1 | Surface in header (global) | Moved to per panel row |
| 2 | Cello Tap in header | Moved to per panel row |
| 3 | Guard Film in header | Moved to per panel row |
| 4 | Width editable (should be 1000) | READ-ONLY by default |
| 5 | Rate field missing | Added per row |
| 6 | Amount per row missing | Added auto-calculate |
| 7 | Accessories hardcoded | Dynamic from master |
| 8 | Two accessory sections | Unified one table |
| 9 | No installation field | Added as accessory type |
| 10 | HSN code not visible | Show in row + PDF |
| 11 | GST missing | Full summary section |
| 12 | Grand total missing | Complete summary |
| 13 | Quality grade does nothing | Connected to pricing |
| 14 | No density field | Added Density Type + kg/m³ |
| 15 | No color field | Added Top + Bottom Color |
| 16 | No validation | Full validation suite |
| 17 | SQM not auto-calculating | Fixed real-time formula |
| 18 | No door/window support | Special item type |
| 19 | No revision tracking | Version system |
| 20 | No duplicate feature | Copy/Duplicate button |

