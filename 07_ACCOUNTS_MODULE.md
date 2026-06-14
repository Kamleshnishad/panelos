# 07 — Accounts & GST Module

> Invoicing, payment tracking, GST compliance, e-invoice, reports.

---

## 1. Module Flow

```
BOQ Accepted
    ↓
Proforma Invoice (PI) → Customer pays advance
    ↓
Production + Dispatch done
    ↓
Tax Invoice → Customer pays balance
    ↓
Invoice → PAID → GST Return data ready
```

---

## 2. Invoice Types

| Type | When | GST Filing | Sequential No |
|---|---|---|---|
| Proforma Invoice | Before dispatch, for advance | NO | Not required |
| Tax Invoice | At/after dispatch | YES | Mandatory |
| Credit Note | Return/rate revision | Reduces GST | Mandatory |
| Debit Note | Under-billing correction | Increases GST | Mandatory |

### Number Format
```
Proforma:     PFI-2025-001
Tax Invoice:  INV-2025-001
Credit Note:  CN-2025-001
Debit Note:   DN-2025-001
Financial year reset: April 1 (configurable)
All prefixes configurable by Super Admin
```

---

## 3. Proforma Invoice

### What It Is
```
NOT a legal GST document
Used by Signature PUF to:
→ Show customer exact amount before dispatch
→ Collect advance payment (50% at order)
→ Collect balance payment (50% before dispatch)

Same format as BOQ PDF — just different header title
Auto-generates when order is confirmed
```

### PDF Layout (Same as BOQ Invoice)
```
Page 1: Items + Summary (PROFORMA INVOICE header)
Page 2: Terms & Conditions
Page 3: BOQ Sheet / Cutting List
```

---

## 4. Tax Invoice

### Create Screen
```
ACCOUNTS → INVOICES → CREATE TAX INVOICE

Invoice No:     [INV-2025-028  ] (auto, read-only)
Invoice Date*:  [10-Jun-2025   ]
Supply Date:    [10-Jun-2025   ] (dispatch date)
Due Date:       [12-Jun-2025   ]

Order:          [ORD-2025-022 ▼]
Dispatch:       [DISP-2025-015 ▼]
Customer:       Kwality Business Associate LLP

GST Type: ● CGST+SGST (GJ→GJ Intra-State)
           ○ IGST (Inter-State)
           (Auto-detected, overridable)

ITEMS (auto-filled from order):
Sr | Description           | HSN     | Qty   | Unit | Rate | Amount
1  | R PUF Panel Roof 30MM | 39259010|312.93 | SQM  | 1050 | 328,577
2  | Roof Side Cap         | 73089090|  55.0 | MTR  |  150 |   8,250
3  | Ridges Inner & Outer  | 73089090|  60.0 | MTR  |  350 |  21,000

Subtotal:        ₹ 3,57,827
IGST @18%:       ₹    64,409   (inter-state: customer in RJ)
Transportation:  Extra as actual
GRAND TOTAL:     ₹ 4,22,236

[Save Draft] [Preview PDF] [Finalize Invoice]
```

### Finalization Rules
```
Once FINALIZED:
→ Invoice number locked
→ Items locked (no editing)
→ Corrections only via Credit/Debit Note
→ If e-invoice required → generate IRN

After finalization status flow:
DRAFT → SENT → PARTIALLY_PAID → PAID
                              → OVERDUE (due date passed)
```

### Tax Invoice PDF Layout
```
┌──────────────────────────────────────────────────────┐
│ [LOGO]          TAX INVOICE           [Date]         │
│                                                      │
│ UMA SIGNATURE PUF PANEL LLP     Invoice No: INV-028  │
│ [Address]                        Invoice Date: 10-Jun│
│ GSTIN: 24AAFFU9050M1ZS          Place of Supply: RJ  │
├──────────────────────────────────────────────────────┤
│ BILL TO:                   SHIP TO:                  │
│ Kwality Business           Kwality Business          │
│ [Full Address]             [Delivery Address]        │
│ State: Rajasthan (08)      GSTIN: (if available)     │
├──────────────────────────────────────────────────────┤
│ [ITEMS TABLE with HSN codes]                         │
├──────────────────────────────────────────────────────┤
│ Taxable Value:    ₹ 3,57,827                         │
│ IGST @18%:        ₹    64,409  (inter-state)         │
│ Transportation:   Extra as actual                    │
│ GRAND TOTAL:      ₹ 4,22,236                         │
│ Amount in Words: Four Lakh Twenty Two Thousand...    │
├──────────────────────────────────────────────────────┤
│ BANK DETAILS:                                        │
│ HDFC Bank | A/c: 59200000008899 | IFSC: HDFC0003001  │
├──────────────────────────────────────────────────────┤
│ E & O.E.          For UMA SIGNATURE PUF PANEL LLP    │
│                   [Signature Image]                  │
│                   PRATIK PATEL / Partner             │
└──────────────────────────────────────────────────────┘

If E-Invoice:
IRN: abc123...
ACK No: 11234567890 | ACK Date: 10-Jun-2025
[QR CODE]
```

---

## 5. GST Calculation Logic

### State Detection
```php
function determineGSTType($companyGSTIN, $customerStateCode): string {
    // Company GSTIN first 2 digits = company state code
    // 24AAFFU9050M1ZS → state code = 24 (Gujarat)
    $companyStateCode = substr($companyGSTIN, 0, 2);

    if ($companyStateCode === $customerStateCode) {
        return 'intrastate';  // CGST 9% + SGST 9%
    }
    return 'interstate';      // IGST 18%
}

// State codes:
// 24=GJ, 27=MH, 08=RJ, 09=UP, 07=DL, 29=KA, 33=TN
// 36=TG, 32=KL, 19=WB, 06=HR, 03=PB, 10=BR, 21=OD
```

### HSN-wise GST
```
Every invoice line item needs:
HSN Code:      auto from product master
Taxable Value: (qty × rate) - discount
GST Rate %:    18% for panels and accessories
CGST:          taxable × 9% (intra-state)
SGST:          taxable × 9% (intra-state)
IGST:          taxable × 18% (inter-state)

HSN Code Master:
39259010 → PUF/PIR Panel → 18% GST
73089090 → GI Accessories → 18% GST
76169990 → Aluminium → 18% GST
994568   → Installation (SAC) → 18% GST
996511   → Transport (SAC) → 5% GST
```

### GSTR-1 Data Export
```
Accounts → GST → GSTR-1 Report → Period: June 2025

SECTION B2B:
Customer | GSTIN | Invoice | Date | Taxable | IGST | CGST | SGST

HSN SUMMARY:
HSN      | Description   | UoM | Qty    | Taxable Value | Tax
39259010 | PUF Panels    | SQM | 1250.5 | ₹15,00,000   | ₹2,70,000
73089090 | GI Accessories| MTR | 550.0  | ₹ 2,00,000   | ₹  36,000

[Export JSON for GST Portal] [Export Excel]
```

---

## 6. E-Invoice (IRN)

### When Required
```
Company turnover > ₹5 crore (configurable in settings)
For: Tax Invoices
NOT for: Proforma Invoice
```

### E-Invoice Flow
```
1. Create Tax Invoice (DRAFT)
2. Validate mandatory fields:
   → Buyer GSTIN (if B2B)
   → HSN codes on all items
   → Place of Supply
3. Click "Generate E-Invoice"
4. System calls IRP API with JSON
5. IRP returns:
   → IRN (64-char hash)
   → Acknowledgement Number
   → Signed QR Code
6. System stores IRN + QR
7. Printed on invoice PDF
```

---

## 7. Credit Note & Debit Note

### Credit Note — When to Use
```
→ Customer returns damaged panels
→ Rate was overcharged
→ GST charged incorrectly
→ Discount given after invoice

Must reference original Tax Invoice
Reduces customer outstanding
Reduces company GST liability
```

### Credit Note Screen
```
Reference Invoice*: [INV-2025-028 ▼]
                    Kwality | ₹4,22,236
Credit Note Date*:  [15-Jun-2025  ]
Reason*:            [Rate correction ]

ITEMS TO CREDIT:
Description:  Rate correction      Amt: ₹5,000
Taxable:      ₹5,000
IGST @18%:    ₹  900
CREDIT TOTAL: ₹5,900

[Save Credit Note]
```

---

## 8. Payment Recording

### Record Payment Screen
```
Customer*:      [Kwality Business ▼     ]
Against:        ● Invoice  ○ Order  ○ Advance
Invoice*:       [INV-2025-028 ▼         ]
                Outstanding: ₹2,72,236

Payment Date*:  [12-Jun-2025            ]
Amount*:        [₹2,72,236              ]
Mode*:          [NEFT ▼                 ]
                Cash/Cheque/NEFT/RTGS/UPI/DD
Reference No:   [UTR202506121234567     ]
Is Advance:     ○ YES  ● NO

[Save Payment]
```

### Payment Allocation Logic
```javascript
// When payment recorded against invoice:
invoice.amount_paid += payment.amount
invoice.balance_due  = invoice.grand_total - invoice.amount_paid

if (invoice.balance_due <= 0) {
  invoice.status = 'paid'
} else if (invoice.amount_paid > 0) {
  invoice.status = 'partially_paid'
}
```

### Cheque Payment — Special Handling
```
Cheque No:     [123456     ]
Cheque Date:   [15-Jun-2025] (future = post-dated)
Bank Name:     [SBI        ]

Status flow: PENDING → DEPOSITED → CLEARED / BOUNCED

3 days before cheque date → Alert Accountant:
"Deposit cheque from Kwality | ₹2,72,236 | Due: 15-Jun"

If BOUNCED:
→ Payment reversal entry
→ Outstanding restored
→ Owner alert
```

---

## 9. Outstanding Management

### Outstanding Screen
```
SUMMARY:
Total Outstanding:  ₹8,50,000
0-30 days:          ₹4,20,000
31-60 days:         ₹2,80,000
61-90 days:         ₹1,00,000
90+ days:           ₹   50,000 ⚠️

CUSTOMER-WISE:
Customer         | Billed     | Paid      | Outstanding | Oldest Inv | Days
Kwality Business | ₹12,00,000 | ₹9,27,765 | ₹2,72,235   | 27-Nov-23  | 195
Aadesh Air Tech  | ₹2,13,958  | ₹80,000   | ₹1,33,958   | 23-Feb-24  | 107
```

### Auto Reminder Schedule
```
Day 30: First reminder → WhatsApp to customer
Day 45: Second reminder
Day 60: Third reminder + Owner alert
Day 90+: Daily alert to Owner until paid
Monthly statement sent to customer
```

---

## 10. TCS Rules

```
Applicable when company turnover > ₹10 crore
Rate: 0.1% on invoice value
Added to invoice total as separate line
Customer can claim TCS credit

Super Admin config:
  tcs_applicable: true/false
  tcs_rate: 0.100 (default)
```

---

## 11. Financial Reports

### Sales Register
```
Date    | Invoice     | Customer | Taxable  | GST    | Total
10-Jun  | INV-2025-028| Kwality  | 357,827  | 64,409 | 422,236
12-Jun  | INV-2025-029| Ideal    | 772,438  | 139,039| 911,477
─────────────────────────────────────────────────────────────
Total   |             |          | 3,450,000| 621,000| 4,071,000
```

### GST Summary (Monthly)
```
Period: June 2025

OUTPUT GST:
  IGST Collected:  ₹2,84,500
  CGST Collected:  ₹       0
  SGST Collected:  ₹       0
  Total Output:    ₹2,84,500

INPUT GST (ITC):
  Total Input:     ₹  39,000

NET GST PAYABLE:   ₹2,45,500
```

### Profitability (Order-level)
```
Order: ORD-2025-022

Revenue:
  Panels (312.93 SQM × ₹1050): ₹3,28,577
  Accessories:                  ₹  29,250
  Total Revenue:                ₹3,57,827

Cost (estimated):
  PPGI Coil:   ₹ 42,975
  Chemicals:   ₹  6,000
  Labour:      ₹  8,000
  Overhead 15%:₹ 56,700
  Total Cost:  ₹1,13,675

Gross Profit: ₹2,44,152 (68.2% margin)
```

