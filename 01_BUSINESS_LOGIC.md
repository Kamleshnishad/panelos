# 01 — Business Logic & Rules

> Every business rule, calculation, edge case developers MUST implement exactly.

---

## 1. BOQ & Quotation Rules

### 1.1 Quotation Number Format
```
Format:  {PREFIX}-{YEAR}-{SEQUENCE}
Example: SCP-2025-001
Prefix:  Configurable (default: SCP)
Reset:   Every April 1 (financial year)
```

### 1.2 Header Fields — CORRECTED
```
HEADER LEVEL (order-wide only):
✅ Customer (required)
✅ Project Name (optional)
✅ Project Location (optional)
✅ Quality Grade: High / Medium / Standard
✅ Validity Days (default 10)

REMOVED FROM HEADER (was wrong in old software):
❌ Surface Top    → now per panel row
❌ Surface Bottom → now per panel row
❌ Cello Tap      → now per panel row
❌ Guard Film     → now per panel row
```

### 1.3 Panel Row Fields
Every row must have:
1. Panel Type (dropdown from panel_types)
2. Thickness (filtered by panel type)
3. Density Type: PUF / PIR
4. Density kg/m³ (auto from density type)
5. Top Skin Material
6. Top Skin Thickness
7. Top Skin Color
8. Top Surface: RIBBED / PLAIN (auto-set, overridable)
9. Bottom Skin Material
10. Bottom Skin Thickness
11. Bottom Skin Color
12. Bottom Surface: PLAIN (default always)
13. Guard Film: YES/NO per row
14. Cello Tap: YES/NO per row
15. Length (mm) — required
16. Width (mm) — default 1000, READ-ONLY
17. Nos — integer
18. SQM — AUTO-CALCULATED
19. Rate (₹/SQM) — auto from pricing, overridable
20. Amount — AUTO-CALCULATED
21. HSN Code — auto from panel type

### 1.4 Surface Auto-Set Logic (CRITICAL)
```javascript
function getDefaultSurface(panelCategory) {
  const map = {
    'roof':      { top: 'RIBBED', bottom: 'PLAIN' },
    'wall':      { top: 'PLAIN',  bottom: 'PLAIN' },
    'ceiling':   { top: 'PLAIN',  bottom: 'PLAIN' },
    'cold_room': { top: 'PLAIN',  bottom: 'PLAIN' },
  }
  return map[panelCategory] || { top: 'PLAIN', bottom: 'PLAIN' }
}
// Fires ONLY on panel type change
// User CAN override
// Does NOT re-fire on thickness/skin change
```

### 1.5 SQM Calculation
```
SQM = (Length_mm ÷ 1000) × (Width_mm ÷ 1000) × Nos

Examples from actual invoices:
3660 × 1000 × 60 = 219.60 SQM ✅
3355 × 1000 × 24 = 80.52 SQM  ✅
4270 × 1000 × 3  = 12.81 SQM  ✅
Total = 312.93 SQM             ✅
```

### 1.6 BOQ Summary Calculation
```
Panel Subtotal    = Σ (SQM × Rate)
Accessory Subtotal= Σ (Qty × Rate)
Subtotal          = Panel + Accessory + Installation
Discount Amount   = Subtotal × (discount_pct / 100)
Taxable Amount    = Subtotal - Discount

GST (Intra-state — same state):
  CGST = Taxable × 9%
  SGST = Taxable × 9%

GST (Inter-state — different state):
  IGST = Taxable × 18%

State from GSTIN: First 2 digits = state code
24 = Gujarat | 27 = Maharashtra | 08 = Rajasthan

Transportation: Fixed amount OR "Extra as Actual"
Grand Total = Taxable + GST + Transportation
Advance = 50% (configurable)
Balance = Grand Total - Advance
```

### 1.7 Quotation Status Flow
```
DRAFT → SENT → ACCEPTED → (Order Created)
              → REJECTED
              → REVISED → (new version)
DRAFT/SENT → EXPIRED (after validity_days)
```

---

## 2. Pricing Engine Rules

### 2.1 Rate Formula
```
Final Rate = Base Rate
           + Thickness Premium
           + Top Skin Thickness Premium
           + Bottom Skin Thickness Premium
           + Density Premium (PIR > PUF)
           + Surface Premium
           + Finish Premium
           × Customer Type Multiplier
           × Region Multiplier
           × Quality Grade Multiplier
           - Quantity Slab Discount
```

### 2.2 Reference Rates (from actual invoices)
```
Roof 30MM, 0.40 PPGL:    ₹1,050/SQM
Ceiling 30MM, 0.50 PPGL: ₹1,075/SQM
Wall 50MM, 0.50 PPGL:    ₹1,275/SQM
Wall 50MM, 0.50 PPGI:    ₹1,200/SQM
```

### 2.3 Customer Type Multipliers
```
Direct:      1.00×
Dealer:      0.85× (15% below)
Contractor:  0.90×
Government:  1.00×
Export:      1.20×
```

---

## 3. Panel Production Rules

### 3.1 Minimum Panel Length (CRITICAL)
```
Rule: Panel length < 2000mm → produce at double length

IF order_length < 2000mm:
  production_length = order_length × 2
  Produce one panel at doubled length, cut to get 2 pieces

Examples:
  Order: 1500mm × 1 → Produce: 3000mm × 1 → Cut → 1500mm × 2
  Order: 1800mm × 4 → Produce: 3600mm × 2 → Cut → 1800mm × 4
  Order: 2500mm × 1 → Produce: 2500mm × 1 (no change)

Threshold configurable: min_production_length_mm (default 2000)
Show warning in BOQ when length < 2000mm
```

---

## 4. Production Rules

### 4.1 Batch Rules
```
1. Batch only from CONFIRMED order
2. Cannot exceed remaining order quantity
3. Stages must complete in configured sequence
4. Mandatory stages cannot be skipped
5. Each stage requires: operator, checklist, parameters
6. QC stage requires QC-role user
7. Rejected panels blocked from dispatch
```

### 4.2 Stage Parameter Requirements
```
Chemical Injection stage must log:
- Polyol ratio % (required)
- Isocyanate ratio % (required)
- Mix temperature °C (required)

Oven Curing stage must log:
- Oven temperature °C (required)
- Curing duration minutes (required)

Auto Cutting stage must log:
- Length set on machine mm (required, must match order)
```

---

## 5. Inventory Rules

### 5.1 Stock Movement
```
Every movement must be logged:
Purchase In → increases stock
Production Consume → decreases (linked to batch)
Wastage → decreases (linked to batch)
Adjustment → any change (requires reason)

Transactions are IMMUTABLE — no edit, no delete
Cannot go below zero (system alerts, allows with reason)
```

### 5.2 Alerts
```
LOW STOCK:  remaining < minimum_alert_qty
STOCKOUT:   remaining = 0 (blocks new batches)
EXPIRY:     chemical expiry within 30 days
```

---

## 6. Dispatch Rules

### 6.1 Pre-conditions
```
1. Order must be READY status
2. All panels must be QC PASSED
3. E-way bill required if invoice > ₹50,000 + interstate
```

### 6.2 Partial Dispatch
```
Multiple dispatches per order allowed
Each dispatch = own challan number
First dispatch → status: PARTIAL_DISPATCH
Final dispatch → status: DISPATCHED
```

---

## 7. GST Invoice Rules

### 7.1 Invoice Types
```
Proforma:    Not a GST document, for advance collection
Tax Invoice: Legal, sequential number mandatory
Credit Note: References original invoice
Debit Note:  References original invoice
```

### 7.2 HSN Code List
```
PUF/PIR Panel:    39259010 → 18% GST
GI Accessories:   73089090 → 18% GST
Aluminium:        76169990 → 18% GST
Installation:     994568   → 18% GST (SAC code)
Transportation:   996511   → 5% GST
```

---

## 8. Payment Rules

### 8.1 Standard Terms (from actual invoices)
```
Advance:  50% at order confirmation
Balance:  50% minimum 2 days before dispatch
Offer validity: 10 days
Delivery standard: 14 days
Delivery non-standard: 21 days
```

### 8.2 Outstanding Aging
```
0-30 days:  Current
31-60 days: 30+
61-90 days: 60+
90+ days:   Overdue → daily alert to owner
```

---

## 9. Notification Events

| Event | Recipients | Channel |
|---|---|---|
| Order Confirmed | Owner, Production | WhatsApp |
| Quotation Accepted | Owner, Sales | WhatsApp |
| Batch Started | Owner | WhatsApp |
| QC Failed | Owner, Supervisor | WhatsApp |
| Stock Low | Owner, Store | WhatsApp |
| Dispatch Done | Customer, Owner | WhatsApp+Email |
| Payment Received | Owner, Accountant | WhatsApp |
| Payment Overdue 30d | Owner | WhatsApp |
| Cheque Due | Accountant | WhatsApp |
| Chemical Expiring | Store Manager | WhatsApp |

