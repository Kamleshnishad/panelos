# Phase 5 — Accounting & GST

**Status**: 📋 PLANNING
**Estimated Duration**: 2-3 weeks (can be accelerated)
**Target Start Date**: 2026-05-18
**Target Completion**: 2026-06-08 (if 3 weeks) or faster (if accelerated)

---

## Overview

Phase 5 completes the ERP system by adding financial management: invoices, payments, GST/Tax calculations, accounts receivable, and financial reporting. It bridges dispatch (Phase 4) with accounting operations.

**Data Flow**:
```
Dispatch (COMPLETED)
    ↓
Create Invoice from Dispatch
    ↓
GST/Tax Calculation
    ↓
Accounts Receivable Tracking
    ↓
Payment Recording
    ↓
Financial Reporting (P&L, Balance Sheet)
```

---

## Architecture

### Entities & Relationships

```
Invoice (1) ─── hasMany ─── InvoiceItem
        ─── hasMany ─── PaymentTransaction
        ─── belongsTo ─── Dispatch
        ─── hasOne ─── TaxCalculation

Company (1) ─── hasMany ─── Invoice
         ─── hasMany ─── PaymentTransaction
         ─── hasOne ─── TaxConfiguration

TaxConfiguration (1) ─── hasMany ─── TaxRate
                  ─── hasMany ─── Invoice
```

### Key Principles

1. **Immutable Invoices** — Once created, cannot be deleted (soft delete only)
2. **Complete Audit Trail** — All payment transactions tracked with timestamps
3. **Multi-tenant GST** — Each company has own tax configuration
4. **Financial Integrity** — Amount consistency across invoice → payment
5. **Reporting Ready** — Data structured for P&L and Balance Sheet generation

---

## Task Breakdown

### Task 5.1: Invoice Models & Migrations

**Objective**: Define financial record structures

**Models to Create** (5):
1. **Invoice** — Master invoice record
   - Properties: invoice_no (unique per company), dispatch_id, order_id
   - status (draft, sent, accepted, paid, cancelled)
   - subtotal, tax_amount, total_amount
   - invoice_date, due_date, paid_date
   - notes, terms
   - Soft deletes

2. **InvoiceItem** — Line items on invoice
   - Properties: invoice_id, panel_type_id, quantity, unit_price, amount
   - tax_amount, total_with_tax
   - Immutable snapshot (like OrderItem, DispatchItem)

3. **TaxConfiguration** — Company-specific tax rules
   - Properties: company_id, gst_number, tax_rate (%)
   - tax_type (inclusive, exclusive)
   - default_tax_rate, is_active

4. **TaxCalculation** — Detailed tax breakdown
   - Properties: invoice_id, tax_rate, taxable_amount, tax_amount
   - gst_component_sgst, gst_component_cgst (if India)
   - Immutable

5. **PaymentTransaction** — Payment recording (immutable log)
   - Properties: invoice_id, company_id, amount, payment_method
   - reference_no, transaction_date
   - created_by_user_id
   - No updates (append-only)

**Migrations** (5):
- create_invoices_table
- create_invoice_items_table
- create_tax_configurations_table
- create_tax_calculations_table
- create_payment_transactions_table

**Effort**: 2-3 hours

---

### Task 5.2: Invoice & Tax Services

**Objective**: Business logic for invoicing and tax calculations

**Services to Create** (3):

#### InvoiceService (250 lines)
Methods:
- `createFromDispatch(dispatchId)` — Generate invoice from dispatch
- `createFromOrder(orderId)` — Generate invoice from order (non-dispatch)
- `addItem(invoiceId, panelTypeId, quantity, unitPrice)` — Line item
- `calculateTotals(invoiceId)` — Recalculate subtotal/total
- `sendInvoice(invoiceId)` — Mark as sent
- `acceptInvoice(invoiceId)` — Customer acceptance
- `getInvoiceDetails(invoiceId)` — Full invoice info
- `list(filters)` — Query with pagination
- `duplicate(invoiceId)` — Clone for new invoice
- `generateInvoiceNumber(companyId)` — Auto-numbering (INV-YYYY-000001)

#### TaxService (200 lines)
Methods:
- `calculateTax(invoiceId, taxRate, companyId)` — GST calculation
- `breakdownGST(amount)` — SGST/CGST split (if India)
- `getTaxConfiguration(companyId)` — Tax rules
- `updateTaxRate(configId, newRate)` — Change tax rate
- `applyTaxToInvoice(invoiceId)` — Calculate and save tax
- `validateTaxNumber(gstNumber, companyId)` — GST validation
- `getTaxReport(companyId, monthYear)` — Tax summary by month

#### PaymentService (180 lines)
Methods:
- `recordPayment(invoiceId, amount, method, reference)` — Log payment
- `getPaymentHistory(invoiceId)` — All payments for invoice
- `reconcilePayment(invoiceId)` — Match with receipt
- `calculateRemainingDue(invoiceId)` — Outstanding amount
- `issueReminder(invoiceId)` — Generate payment reminder
- `writeOff(invoiceId, writeOffAmount, reason)` — Bad debt handling
- `getPaymentStatus(invoiceId)` — paid, partial, overdue, due

**Features**:
- Atomic operations for invoice creation
- Tax calculation with multiple jurisdictions support
- Payment tracking with overdue detection
- Reconciliation workflow

**Effort**: 3-4 hours

---

### Task 5.3: Accounting Controllers & Routes

**Objective**: RESTful endpoints for accounting operations

**Controllers** (2):

#### InvoiceController (240 lines)
Endpoints:
- GET /invoices — List invoices with pagination
- POST /invoices — Create manual invoice
- POST /dispatches/{id}/invoice — Create from dispatch
- GET /invoices/{id} — Invoice detail
- PUT /invoices/{id} — Update draft invoice
- DELETE /invoices/{id} — Cancel invoice
- POST /invoices/{id}/send — Send to customer
- POST /invoices/{id}/accept — Mark accepted
- GET /invoices/{id}/pdf — Download PDF
- GET /invoices/{id}/payments — Payment history

#### PaymentController (200 lines)
Endpoints:
- POST /invoices/{id}/payments — Record payment
- GET /invoices/{id}/payments — Payment list
- PUT /payments/{id} — Update payment
- DELETE /payments/{id} — Reverse payment
- GET /invoices/{id}/overdue — Overdue status
- POST /invoices/{id}/remind — Send reminder

**Routes** (20+ endpoints):
All endpoints added to routes/api.php with proper grouping

**Effort**: 2-3 hours

---

### Task 5.4: Financial Reporting Services

**Objective**: Generate P&L and Balance Sheet reports

**Services** (1):

#### ReportingService (300 lines)
Methods:
- `getProfitLossStatement(companyId, startDate, endDate)` — P&L report
- `getBalanceSheet(companyId, asOfDate)` — Balance sheet
- `getCashFlowStatement(companyId, startDate, endDate)` — Cash flow
- `getAccountsReceivable(companyId)` — AR summary
- `getSalesReport(companyId, startDate, endDate)` — Revenue analysis
- `getTaxReport(companyId, monthYear)` — Tax summary
- `getAccountingDashboard(companyId)` — KPIs and metrics
- `reconcileInvoices(companyId)` — Verify all invoices matched

**P&L Components**:
```
Revenue (from invoices)
- COGS (from dispatch costs)
= Gross Profit
- Operating Expenses
= Operating Income
+/- Other Income/Expenses
= Pre-Tax Income
- Tax (GST if applicable)
= Net Income
```

**AR Components**:
```
Total Invoices
- Paid Amount
= Outstanding Receivables
Split by: Current, 30 days, 60 days, 90+ days
```

**Effort**: 4-5 hours

---

### Task 5.5: Invoice & Payment APIs

**Objective**: RESTful endpoints for accounting

**Routes** (25+ endpoints):
- Invoice CRUD (5 endpoints)
- Invoice actions (4 endpoints)
- Payment CRUD (4 endpoints)
- Reporting (6 endpoints)
- PDF generation (2 endpoints)

**Response Format** (Standard ApiResponse):
```json
{
  "success": true,
  "data": { ... },
  "message": "Invoice created",
  "timestamp": "2026-05-18T10:30:00Z"
}
```

**Effort**: 2 hours

---

### Task 5.6: Accounting Test Suite

**Objective**: Test coverage for all accounting operations

**Test Files** (6):

1. **InvoiceTest.php** (12 tests, 60 assertions)
   - Create invoice from dispatch
   - Create invoice from order
   - Auto-numbering (INV-YYYY-000001)
   - Item addition and removal
   - Total calculation
   - Status workflow (draft → sent → paid)
   - Immutability after creation
   - Multi-tenant isolation

2. **TaxTest.php** (10 tests, 50 assertions)
   - Calculate GST (all rates)
   - SGST/CGST split
   - Tax configuration management
   - Tax rate updates
   - Tax validation
   - Multi-jurisdiction support

3. **PaymentTest.php** (8 tests, 40 assertions)
   - Record payment
   - Partial payments
   - Overdue detection
   - Payment reversal
   - Reconciliation
   - Write-off handling

4. **ReportingTest.php** (10 tests, 50 assertions)
   - P&L statement generation
   - Balance sheet accuracy
   - Cash flow calculation
   - AR aging report
   - Tax report by month
   - Dashboard KPIs

5. **InvoicePdfTest.php** (6 tests, 30 assertions)
   - PDF generation
   - Invoice details in PDF
   - GST breakdown in PDF
   - Payment terms display

6. **AccountingIntegrationTest.php** (8 tests, 40 assertions)
   - Full workflow: dispatch → invoice → payment
   - Multi-invoice scenarios
   - Tax calculation consistency
   - Reporting accuracy with multiple invoices

**Total Tests**: 54 tests with 270 assertions

**Effort**: 4-5 hours

---

### Task 5.7: Vue 3 Components for Accounting

**Objective**: Frontend UI for invoicing and payments

**Components** (8):

1. **InvoiceDashboard.vue** (200 lines)
   - KPI cards: Total revenue, unpaid invoices, overdue amount
   - Recent invoices list
   - Payment status summary
   - Quick action buttons

2. **InvoiceList.vue** (220 lines)
   - Paginated invoice list
   - Filter: status, date range, customer
   - Sort: invoice number, date, amount
   - Status badges (draft, sent, paid, overdue)
   - Quick actions: view, send, download PDF

3. **InvoiceDetail.vue** (300 lines)
   - Full invoice display
   - Customer and payment terms
   - Line items table with tax breakdown
   - Total calculation with GST
   - Payment history section
   - Action buttons (send, mark paid, cancel)

4. **InvoiceForm.vue** (280 lines)
   - Create/edit invoice
   - Select dispatch or order
   - Add line items dynamically
   - Tax calculation real-time
   - GST configuration selector
   - Terms and notes

5. **PaymentForm.vue** (200 lines)
   - Record payment for invoice
   - Payment method selector
   - Reference number input
   - Partial payment option
   - Confirmation dialog

6. **TaxConfiguration.vue** (180 lines)
   - Company tax settings
   - GST number entry
   - Tax rate configuration
   - Tax type (inclusive/exclusive)
   - Update form

7. **FinancialReports.vue** (250 lines)
   - Report selector (P&L, Balance Sheet, AR Aging)
   - Date range filters
   - Report display (table format)
   - Export to PDF/CSV buttons

8. **AccountsReceivable.vue** (200 lines)
   - AR aging analysis
   - Overdue invoices highlighting
   - Collection status
   - Reminder buttons
   - Payment tracking

**Component Features**:
- Real-time GST calculation
- PDF preview/download
- Print-friendly formats
- Responsive tables
- Status-based styling
- Form validation

**Effort**: 6-8 hours

---

## Data Models Detail

### Invoice
```php
Model: Invoice
- id: bigint (PK)
- company_id: bigint (FK, multi-tenant)
- dispatch_id: bigint (FK, nullable)
- order_id: bigint (FK, nullable)
- invoice_no: string (unique per company)
- status: enum (draft, sent, accepted, paid, cancelled)
- subtotal: decimal(12,2)
- tax_amount: decimal(12,2)
- total_amount: decimal(12,2)
- invoice_date: date
- due_date: date
- paid_date: date (nullable)
- notes: text
- terms: text
- created_at, updated_at, deleted_at (soft deletes)

Relationships:
- belongsTo(Company)
- belongsTo(Dispatch, nullable)
- belongsTo(Order, nullable)
- hasMany(InvoiceItem)
- hasMany(PaymentTransaction)
- hasOne(TaxCalculation)
```

### InvoiceItem
```php
Model: InvoiceItem
- id: bigint (PK)
- invoice_id: bigint (FK)
- panel_type_id: bigint (FK)
- quantity: integer
- unit_price: decimal(12,2)
- amount: decimal(12,2)
- tax_rate: decimal(5,2) (%)
- tax_amount: decimal(12,2)
- total_with_tax: decimal(12,2)
- created_at: timestamp (immutable)

Purpose: Historical snapshot of items
Cannot be edited after creation

Relationships:
- belongsTo(Invoice)
- belongsTo(PanelType)
```

### TaxConfiguration
```php
Model: TaxConfiguration
- id: bigint (PK)
- company_id: bigint (FK, unique)
- gst_number: string
- tax_type: enum (inclusive, exclusive)
- default_tax_rate: decimal(5,2) (%)
- is_active: boolean
- created_at, updated_at

Relationships:
- belongsTo(Company)
- hasMany(TaxRate) (for multiple tax brackets)
```

### TaxCalculation
```php
Model: TaxCalculation
- id: bigint (PK)
- invoice_id: bigint (FK)
- tax_rate: decimal(5,2)
- taxable_amount: decimal(12,2)
- tax_amount: decimal(12,2)
- sgst_amount: decimal(12,2) (if India)
- cgst_amount: decimal(12,2) (if India)
- igst_amount: decimal(12,2) (if India)
- created_at: timestamp (immutable)

Purpose: Detailed tax breakdown
Cannot be updated

Relationships:
- belongsTo(Invoice)
```

### PaymentTransaction
```php
Model: PaymentTransaction
- id: bigint (PK)
- company_id: bigint (FK)
- invoice_id: bigint (FK)
- amount: decimal(12,2)
- payment_method: enum (bank_transfer, cash, cheque, upi)
- reference_no: string (bank receipt, cheque number, etc.)
- transaction_date: timestamp
- created_by_user_id: bigint (FK)
- created_at: timestamp (immutable)

Purpose: Immutable payment log
No updates, reverse with negative payment if needed

Relationships:
- belongsTo(Company)
- belongsTo(Invoice)
- belongsTo(User) created_by
```

---

## API Response Examples

### Create Invoice from Dispatch
```json
POST /api/dispatches/{id}/invoice

Request:
{
  "due_date": "2026-06-18",
  "notes": "Net 30 terms",
  "terms": "2/10 net 30"
}

Response:
{
  "success": true,
  "data": {
    "id": 42,
    "invoice_no": "INV-2026-000042",
    "dispatch_no": "DISP-2026-000018",
    "status": "draft",
    "subtotal": 5000.00,
    "tax_amount": 900.00,
    "total_amount": 5900.00,
    "due_date": "2026-06-18",
    "items": [...]
  },
  "message": "Invoice created from dispatch"
}
```

### Record Payment
```json
POST /api/invoices/{id}/payments

Request:
{
  "amount": 5900.00,
  "payment_method": "bank_transfer",
  "reference_no": "TRX123456789"
}

Response:
{
  "success": true,
  "data": {
    "id": 1,
    "invoice_id": 42,
    "amount": 5900.00,
    "payment_method": "bank_transfer",
    "remaining_due": 0.00,
    "transaction_date": "2026-05-18T10:30:00Z"
  },
  "message": "Payment recorded successfully"
}
```

### Financial Report
```json
GET /api/reports/profit-loss?start_date=2026-01-01&end_date=2026-05-31

Response:
{
  "success": true,
  "data": {
    "period": "2026-01-01 to 2026-05-31",
    "revenue": 250000.00,
    "cogs": 150000.00,
    "gross_profit": 100000.00,
    "operating_expenses": 30000.00,
    "operating_income": 70000.00,
    "tax": 12600.00,
    "net_income": 57400.00
  }
}
```

---

## Validation Rules

### Invoice
- invoice_no must be unique per company
- total_amount must equal sum of items
- invoice_date cannot be future date
- due_date must be >= invoice_date
- status transitions only allowed (draft → sent → accepted → paid)

### Payments
- amount > 0 and <= remaining_due
- payment_method required
- reference_no required (for audit)
- Cannot pay after invoice cancelled

### Tax
- tax_rate >= 0 and <= 100
- GST number format validation (country-specific)
- tax_amount must match calculation
- SGST + CGST = total tax (if India)

---

## Success Criteria

Phase 5 is complete when:
- ✅ 5 invoice/tax models created with migrations
- ✅ 3 services for invoices, tax, payments (630 lines)
- ✅ 2 controllers for invoices and payments (440 lines)
- ✅ 25+ API endpoints for accounting operations
- ✅ 54 tests passing (270 assertions)
- ✅ 8 Vue components for UI
- ✅ Multi-jurisdiction tax support (at least India + generic)
- ✅ Financial reports (P&L, Balance Sheet) working
- ✅ Full integration with Phase 3 & 4 (dispatch → invoice → payment)
- ✅ Ready for Phase 6 (optional: Inventory forecasting & Analytics)

---

## Files to Create

### Models (5)
- Invoice.php
- InvoiceItem.php
- TaxConfiguration.php
- TaxCalculation.php
- PaymentTransaction.php

### Services (3)
- InvoiceService.php (250 lines)
- TaxService.php (200 lines)
- PaymentService.php (180 lines)
- ReportingService.php (300 lines)

### Controllers (2)
- InvoiceController.php (240 lines)
- PaymentController.php (200 lines)

### Migrations (5)
- create_invoices_table
- create_invoice_items_table
- create_tax_configurations_table
- create_tax_calculations_table
- create_payment_transactions_table

### Tests (6)
- InvoiceTest.php (12 tests, 60 assertions)
- TaxTest.php (10 tests, 50 assertions)
- PaymentTest.php (8 tests, 40 assertions)
- ReportingTest.php (10 tests, 50 assertions)
- InvoicePdfTest.php (6 tests, 30 assertions)
- AccountingIntegrationTest.php (8 tests, 40 assertions)

### Frontend (8)
- InvoiceDashboard.vue
- InvoiceList.vue
- InvoiceDetail.vue
- InvoiceForm.vue
- PaymentForm.vue
- TaxConfiguration.vue
- FinancialReports.vue
- AccountsReceivable.vue

### Documentation (1)
- PHASE_5_FRONTEND_GUIDE.md

---

## Timeline (Accelerated)

| Task | Hours | Days |
|------|-------|------|
| 5.1 Models & Migrations | 2-3 | Day 1 morning |
| 5.2 Services | 3-4 | Day 1 afternoon |
| 5.3 Controllers & Routes | 2-3 | Day 2 morning |
| 5.4 Reporting | 4-5 | Day 2 afternoon |
| 5.5 API Endpoints | 2 | Day 2 evening |
| 5.6 Test Suite | 4-5 | Day 3 |
| 5.7 Vue Components | 6-8 | Day 4 |
| **TOTAL** | **23-28** | **4 days** |

---

## Dependencies

- ✅ Phase 1 (Foundation) — COMPLETE
- ✅ Phase 2 (BOQ) — COMPLETE
- ✅ Phase 3 (Production) — COMPLETE
- ✅ Phase 4 (Dispatch) — COMPLETE
- Dispatch system must be complete (invoices created from dispatch)
- Tax rates configured per company

---

## Integration Points

### With Phase 4 (Dispatch)
- Invoice triggered from completed dispatch
- Dispatch amount automatically becomes invoice total
- Dispatch items copied to invoice items

### With Phase 3 (Production)
- Production batch completion triggers dispatch
- Dispatch completion triggers invoice creation
- Full order-to-cash workflow

### With External Systems
- Tax authority API for GST validation (India)
- Bank APIs for payment reconciliation (optional)
- Email service for invoice delivery

---

## Future Enhancements (Post-Phase 5)

- Recurring invoices for subscriptions
- Invoice payment plans (installments)
- Multi-currency support
- Automated payment reminders (email/SMS)
- Credit memo and debit note generation
- Supplier invoice management (AP)
- Budget vs actual analysis
- Financial forecasting

---

## Related Documents

- [Phase 4 Completion](PHASE_4_COMPLETION.md)
- [Project Tracker](00_PROJECT_TRACKER.md)

---

**Last Updated**: 2026-05-18  
**Status**: 📋 PLANNING (ready for implementation approval)
