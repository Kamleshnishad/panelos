# 06 — Inventory Module

> Complete raw material and stock management.
> Coils, chemicals, accessories, consumables, purchase orders, alerts.

---

## 1. Overview

```
RAW MATERIALS:
├── PPGI / PPGL Coils    → Top and bottom skin of panel
├── Polyol               → Chemical A for PUF foam
└── Isocyanate (MDI)     → Chemical B for PUF foam

ACCESSORIES:
├── GI Flashing (Ridge Cap, Side Cap, L-Angle etc)
├── Aluminium channels and corners
├── Doors and windows
└── Fasteners, screws

CONSUMABLES:
├── Guard Film           → Applied on coil during production
├── Stretch Film         → For packing bundles
└── Cello Tape

RULES:
1. Every stock IN must be recorded (Purchase In)
2. Every stock OUT must be recorded (Production/Dispatch)
3. No negative stock — system alerts but tracks
4. All transactions linked to source (batch/order/PO)
5. Transactions are IMMUTABLE — no edit, no delete
```

---

## 2. Coil (PPGI) Inventory

### Coil Stock List
```
Inventory → Coils

FILTERS: Material | Thickness | Color | Status

┌──────────┬──────┬────────┬───────────┬──────────┬──────────┬────────┐
│ Coil Tag │ Mat  │ Thick  │ Color     │ Remaining│ Location │ Status │
├──────────┼──────┼────────┼───────────┼──────────┼──────────┼────────┤
│ COIL-045 │ PPGI │ 0.40mm │ Off White │ 1,155 kg │ Bay A-R2 │ ✅ OK  │
│ COIL-046 │ PPGI │ 0.40mm │ Off White │    45 kg │ Bay A-R3 │ ⚠️ Low│
│ COIL-047 │ PPGL │ 0.50mm │ Off White │   820 kg │ Bay B-R1 │ ✅ OK  │
└──────────┴──────┴────────┴───────────┴──────────┴──────────┴────────┘
[+ Add New Coil]
```

### Add Coil Form
```
Coil Tag No:      [COIL-2025-049   ] (auto or manual)
Supplier*:        [ABC Steel ▼     ]
Material Type*:   [PPGI ▼          ] PPGI/PPGL/GI/SS304
Thickness (mm)*:  [0.40 ▼          ] 0.30/0.35/0.40/0.45/0.50/0.60
Width (mm)*:      [1000            ]
Color Code*:      [9002 ▼          ]
Color Name:       [Off White       ] (auto from color master)
Coating Type:     [Polyester ▼     ] Polyester/PVDF/Plastisol
Zinc GSM:         [90              ]

Total Weight (kg)*:   [1200        ]
Purchase Rate/kg:     [95.50       ]
Total Value:          ₹1,14,600    (auto)

Warehouse Location:   [Bay A       ]
Rack Position:        [Rack-2      ]
Minimum Alert (kg):   [100         ]

Received Date*:       [01-Jun-2025 ]
Purchase Invoice No:  [SI/2025/1234]
[Save Coil]
```

### Coil Detail — Consumption Tracking
```
COIL-2025-045 | PPGI | 0.40mm | Off White | Bay A Rack 2

Total Received:  1,200 kg
Consumed:           45 kg
Remaining:       1,155 kg ✅ (above minimum 100kg)
Remaining (Mtr): ~2,888 mtr

CONSUMPTION HISTORY:
Date         | Batch     | Consumed | Order
10-Jun-2025  | BATCH-045 | 25 kg    | ORD-022
08-Jun-2025  | BATCH-043 | 20 kg    | ORD-020

Value: ₹95.50/kg | Remaining Value: ₹1,10,302
```

---

## 3. Chemical Inventory

### Chemical Stock List
```
Inventory → Chemicals

┌──────────┬─────────┬───────────┬──────────┬─────────────┬────────┐
│ Chemical │ Supplier│ Batch No  │ Remaining│ Expiry Date │ Status │
├──────────┼─────────┼───────────┼──────────┼─────────────┼────────┤
│ Polyol   │ BASF    │ PL-2025-3 │  850 kg  │ 31-Dec-2025 │ ✅ OK  │
│ Isocyanate│ Covestro│ MD-2025-8 │  120 kg  │ 30-Nov-2025 │ ⚠️ Low│
└──────────┴─────────┴───────────┴──────────┴─────────────┴────────┘
```

### Add Chemical Form
```
Chemical Type*:   [Polyol ▼          ] Polyol/Isocyanate(MDI)/Other
Brand Name:       [BASF Lupranat      ]
Supplier*:        [BASF India ▼       ]
Batch Number:     [PL-2025-004        ]

Number of Drums*: [10                 ]
Weight/Drum (kg): [100                ]
Total Qty (kg)*:  [1000               ] (auto)
Purchase Rate/kg: [145.00             ]

Received Date*:   [01-Jun-2025        ]
Expiry Date:      [31-Dec-2025        ]
Storage Temp:     Min [15] °C  Max [30] °C
Minimum Alert kg: [150                ]
[Save]
```

### Chemical Consumption Formula
```
Per production batch:
  Foam Volume = length × width × thickness (panel dimensions)
  Total Foam = foam_volume × batch_quantity

  From stage log (Chemical Injection):
  Polyol consumed = total_foam × density × (polyol_pct/100)
  Isocyanate consumed = total_foam × density × (iso_pct/100)

  Actual: operator logs drum weight before/after
  → Consumed = before_weight - after_weight
  → system updates remaining_qty automatically
```

### Chemical Expiry Alerts
```
30 days before expiry → Alert Store Manager + Owner
7 days before → URGENT alert
Expired → Status: EXPIRED → blocked from production
```

---

## 4. Accessories Stock

### Stock List with Reserved Logic
```
┌──────────────────┬──────┬──────────┬──────────┬──────────┬────────┐
│ Accessory        │ Unit │ Available│ Reserved │ Minimum  │ Status │
├──────────────────┼──────┼──────────┼──────────┼──────────┼────────┤
│ Roof Side Cap    │ MTR  │  250 mtr │   55 mtr │  50 mtr  │ ✅ OK  │
│ Ridge Cap Outer  │ MTR  │   30 mtr │   30 mtr │  50 mtr  │ ⚠️ Low│
│ C Channel        │ MTR  │  500 mtr │  100 mtr │ 100 mtr  │ ✅ OK  │
│ Aluminium Coving │ MTR  │  200 mtr │  130 mtr │  50 mtr  │ ✅ OK  │
│ 3D Corner        │ NOS  │   45 nos │   25 nos │  20 nos  │ ✅ OK  │
└──────────────────┴──────┴──────────┴──────────┴──────────┴────────┘

Available for new orders = total_stock - reserved_qty

When order confirmed:
  reserved_qty += order_accessory_qty

When dispatched:
  reserved_qty -= dispatched_qty
  total_stock  -= dispatched_qty
```

---

## 5. Inventory Transactions

### Transaction Log
```
Inventory → Transactions

Date         | Type             | Material         | Qty    | Reference
10-Jun-2025  | Production Use   | PPGI 0.40 OW     | -25 kg | BATCH-045
10-Jun-2025  | Production Use   | Polyol           | -28 kg | BATCH-045
01-Jun-2025  | Purchase In      | PPGI 0.40 OW     | +1200kg| PO-2025-045
05-Jun-2025  | Wastage          | PPGI 0.40 OW     | -2 kg  | BATCH-043
08-Jun-2025  | Adjustment Out   | Stretch Film     | -5 rolls| Damaged
```

### Transaction Types
```
purchase_in       → increases stock (from PO receipt)
production_consume → decreases (linked to batch)
wastage           → decreases (linked to batch)
return_to_supplier → decreases
adjustment_in     → manual increase (requires reason)
adjustment_out    → manual decrease (requires reason)

RULES:
→ Transactions IMMUTABLE (no edit, no delete)
→ Corrections via new adjustment entry only
→ Cannot go below zero (warns, allows with reason)
→ Each transaction has: user, timestamp, IP, reason
```

---

## 6. Purchase Orders

### PO List
```
┌──────────┬────────────┬────────────┬──────────┬──────────┐
│ PO No    │ Supplier   │ Date       │ Amount   │ Status   │
├──────────┼────────────┼────────────┼──────────┼──────────┤
│ PO-045   │ ABC Steel  │ 28-May-25  │ ₹1,14,600│ Received │
│ PO-047   │ ABC Steel  │ 10-Jun-25  │ ₹95,000  │ Pending  │
└──────────┴────────────┴────────────┴──────────┴──────────┘
[+ Create PO]
```

### PO Status Flow
```
DRAFT → SENT → PARTIALLY_RECEIVED → RECEIVED → CLOSED

On Receive:
→ Click "Receive Material" on PO
→ Enter actual received qty
→ Auto creates inventory transaction (Purchase In)
→ Coil/Chemical stock updated immediately
```

---

## 7. Stock Alerts System

### Alert Types
```
CRITICAL (Red — immediate):
→ Stock = 0 (stockout)
→ Stock < 25% of minimum
→ Chemical expired
→ Production blocked due to shortage

WARNING (Yellow — order soon):
→ Stock < minimum alert level
→ Chemical expiry within 30 days
→ No PO raised for low stock item
```

### Alert Messages
```
Low Stock WhatsApp:
"⚠️ Low Stock Alert: PPGI 0.40mm Off White
 Remaining: 45 kg (Minimum: 100 kg)
 Action: Place purchase order."

Stockout WhatsApp (URGENT):
"🚨 STOCKOUT: PPGI 0.40mm Off White = 0 kg
 Production batch creation blocked.
 Immediate action required."

Cheque expiry WhatsApp:
"⚠️ Chemical Expiring: Polyol batch PL-2025-003
 Expiry: 31-Dec-2025 (30 days remaining)
 Use this batch first in upcoming production."
```

---

## 8. Inventory Dashboard

```
INVENTORY DASHBOARD

┌────────────┬────────────┬────────────┬────────────┐
│ TOTAL COIL │ COIL LOW   │ CHEMICAL   │ ACCESSORIES│
│   2,620 kg │ 1 alert    │ 2 items OK │ 2 low stock│
└────────────┴────────────┴────────────┴────────────┘

LOW STOCK ALERTS:
⚠️ PPGI 0.40mm OW (COIL-046) — 45 kg (min: 100 kg)
⚠️ Ridge Cap Outer — 0 mtr free (all reserved)
⚠️ Isocyanate MDI — 120 kg (min: 150 kg)

PENDING PURCHASE ORDERS:
→ PO-047: PPGI 0.40mm — 1000 kg | Expected: 20-Jun-2025

STOCK VALUATION:
Coils:        ₹ 2,49,900
Chemicals:    ₹ 1,70,750
Accessories:  ₹    45,200
──────────────────────────
Total:        ₹ 4,65,850

CONSUMPTION THIS MONTH:
PPGI Coils:  2,450 kg | Polyol: 380 kg | Isocyanate: 320 kg
```

---

## 9. Reports

### Inventory Valuation
```
As of: 10-Jun-2025

COILS:
PPGI 0.40 OW  | 1,200 kg | ₹95.50  | ₹1,14,600
PPGL 0.50 OW  |   820 kg | ₹110.00 | ₹90,200
Total Coil:   ₹2,49,900

CHEMICALS:
Polyol        | 1,050 kg | ₹145 | ₹1,52,250
Isocyanate    |   120 kg | ₹155 | ₹18,600
Total Chemical:₹1,70,850

GRAND TOTAL:  ₹4,65,850
```

### Consumption Report
```
Period: Jun 2025

Material      | Opening | Purchased | Consumed | Closing | Wastage%
PPGI 0.40 OW  | 1800 kg | 1200 kg   | 2450 kg  | 550 kg  | 2.1%
Polyol        |  500 kg | 1000 kg   |  380 kg  | 1120 kg | 1.8%
```

