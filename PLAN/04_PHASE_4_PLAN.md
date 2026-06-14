# Phase 4 — Inventory & Dispatch

**Status**: 📋 PLANNING
**Estimated Duration**: 3-4 weeks (can be accelerated)
**Target Start Date**: 2026-05-18
**Target Completion**: 2026-06-08 (if 3 weeks) or 2026-06-15 (if 4 weeks)

---

## Overview

Phase 4 manages physical stock of raw materials (coils and chemicals) and fulfillment of completed production batches. It bridges production (Phase 3) and accounting (Phase 5):

**Data Flow**:
```
Production Batch (COMPLETED)
    ↓
Move to Dispatch stage
    ↓
Create Dispatch Order
    ↓
Allocate from Stock
    ↓
Generate Challan (PDF)
    ↓
Mark Dispatched
    ↓
Update Accounts Receivable (Phase 5)
```

**Key Principle**: Stock transactions are immutable (append-only log). All inventory changes tracked with timestamps and user audit trails.

---

## Architecture

### High-Level System Design

```
┌─────────────────────────────────────────────────────┐
│                                                       │
│  STOCK MANAGEMENT                 DISPATCH           │
│  ├─ Coil Inventory                ├─ Dispatch Order  │
│  ├─ Chemical Inventory            ├─ Challan PDF     │
│  ├─ Stock Transactions            └─ Dispatch Notes  │
│  └─ Low Stock Alerts                                 │
│                                                       │
└─────────────────────────────────────────────────────┘
     ↑                                    ↑
     │                                    │
  Production Batches              Accounting Module
  (Phase 3)                        (Phase 5)
```

### Entities & Relationships

**Stock Entities**:
```
CoilStock (1) ─── hasMany ─── StockTransaction
              ─── hasMany ─── StockAllocation

ChemicalStock (1) ─── hasMany ─── StockTransaction
                  ─── hasMany ─── StockAllocation

Dispatch (1) ─── belongsTo ─── ProductionBatch
           ─── hasMany ─── DispatchItems
           ─── hasMany ─── StockTransaction (allocations)
```

### Multi-Tenancy

All stock entities include `company_id` for tenant isolation:
- Companies cannot see each other's inventory
- Stock transactions scoped by company
- Dispatch orders scoped by company

---

## Task Breakdown

### Task 4.1: Stock Models & Migrations

**Objective**: Define inventory data structures

**Models to Create** (5):
1. **CoilStock** — Raw material (coils) inventory
   - Properties: coil_id, company_id, quantity_in_stock, reorder_level, last_stock_in, last_stock_out
   - Soft deletes
   - Relationships: coil (belongsTo), transactions (hasMany)

2. **ChemicalStock** — Chemical materials inventory
   - Properties: chemical_id, company_id, quantity_in_stock, unit, reorder_level, expiry_date, batch_number
   - Soft deletes
   - Relationships: chemical (belongsTo), transactions (hasMany)

3. **StockTransaction** — Immutable log of all inventory changes
   - Properties: id, company_id, transactionable_id, transactionable_type (polymorphic)
   - type (in, out, adjustment, allocation, return), quantity, unit, reference_no
   - notes, transaction_date, created_by_user_id
   - Immutable (no updates, soft deletes only for corrections)

4. **StockAllocation** — Track reserved stock for dispatch
   - Properties: id, company_id, dispatch_id, coil_id/chemical_id, quantity_allocated
   - status (allocated, used, released), allocated_at, used_at
   - Relationship: dispatch (belongsTo), transactionable

5. **LowStockAlert** — Alert log for stock below reorder level
   - Properties: id, company_id, item_type (coil/chemical), item_id, quantity, alert_sent_at
   - Soft deletes (mark as resolved)

**Migrations** (5):
- create_coil_stocks_table
- create_chemical_stocks_table
- create_stock_transactions_table
- create_stock_allocations_table
- create_low_stock_alerts_table

**Validation Rules**:
- Quantity >= 0
- Reorder level must be <= current quantity (initial)
- Expiry date must be future date (for chemicals)
- Cannot create stock for non-existent coil/chemical

**Effort**: 2-3 hours

---

### Task 4.2: Stock Management Services

**Objective**: Business logic for inventory operations

**Services to Create** (2):

#### StockService (210 lines)
Methods:
- `addStock(itemType, itemId, quantity, notes)` — Stock in (creates transaction)
- `removeStock(itemType, itemId, quantity, notes)` — Stock out (creates transaction)
- `adjustStock(itemType, itemId, newQuantity, reason)` — Inventory correction
- `allocateStock(dispatchId, itemType, itemId, quantity)` — Reserve for dispatch
- `releaseAllocation(allocationId)` — Undo allocation
- `getStockLevel(itemType, itemId)` — Get current stock
- `getStockHistory(itemId, days = 30)` — Recent transactions
- `checkLowStock()` — Scan all items, trigger alerts
- `getReorderSuggestions()` — Items below reorder level

#### DispatchService (280 lines)
Methods:
- `createDispatch(batchId, data)` — Create dispatch from batch
- `addDispatchItem(dispatchId, itemType, itemId, quantity)` — Add item to dispatch
- `allocateStockForDispatch(dispatchId)` — Auto-allocate from inventory
- `completeDispatch(dispatchId, data)` — Mark as dispatched (update batch status)
- `cancelDispatch(dispatchId)` — Release allocations, revert status
- `getDispatchDetails(dispatchId)` — Full dispatch info
- `list(filters)` — Query dispatches with pagination
- `generateChallan(dispatchId)` — Return PDF content/file path

**Features**:
- Atomic operations (transactions for allocate → stock update → dispatch update)
- Validation: cannot dispatch more than produced
- Multi-tenant isolation at query level
- Automatic status transitions (batch: completed → dispatched)

**Effort**: 3-4 hours

---

### Task 4.3: Dispatch & Challan

**Objective**: Fulfillment workflow and documentation

**Models to Create** (2):

1. **Dispatch** — Fulfillment order
   - Properties: dispatch_no (unique per company), batch_id, company_id, status (pending, in_transit, delivered)
   - dispatch_date, expected_delivery_date, actual_delivery_date
   - customer_address (denormalized), tracking_number, notes
   - created_at, updated_at, deleted_at (soft deletes)

2. **DispatchItem** — Individual items in dispatch
   - Properties: dispatch_id, panel_type_id, quantity, unit_price, amount
   - Immutable snapshot (like OrderItem)

**Controllers** (1):

DispatchController (220 lines):
- `index(filters)` — List dispatches with pagination
- `store(data)` — Create dispatch from batch
- `show(id)` — Dispatch details
- `update(id, data)` — Update notes, address
- `destroy(id)` — Cancel dispatch (releases allocations)
- `complete(id)` — Mark as dispatched
- `getChallan(id)` — Generate/download challan PDF
- `getTracking(id)` — Get tracking info
- `getDispatchesByBatch(batchId)` — Batch dispatch history

**Challan Generation**:
- Invoice-style PDF with:
  - Header: Company logo, dispatch number, date
  - Recipient: Customer details, delivery address
  - Items table: Panel type, quantity, dimensions
  - Footer: Terms, signature lines, tracking number
  - Use Blade template + dompdf/snappy for PDF

**Validation**:
- Cannot dispatch unless batch status = completed
- Cannot dispatch without allocated stock
- Cannot re-dispatch same batch
- Delivery address required

**Effort**: 3-4 hours

---

### Task 4.4: Stock Alerts & Dashboard Service

**Objective**: Monitoring and visibility

**Services** (1):

StockDashboardService (150 lines):
- `getTotalStockValue()` — Aggregate stock value
- `getLowStockItems()` — Items below reorder level
- `getExpiringSoon()` — Chemicals expiring in next 30 days
- `getStockMovement(days)` — In/out trends
- `getDispatchPipeline()` — Batches pending dispatch
- `getAlertSummary()` — Count of alerts by type

**Alert Triggers**:
- Quantity < reorder_level → create LowStockAlert
- Expiry date within 30 days → create ExpiryAlert
- No stock available for dispatch → create AllocationFailureAlert

**Effort**: 2 hours

---

### Task 4.5: Stock & Dispatch APIs

**Objective**: RESTful endpoints for backend services

**Routes** (30+ endpoints):

#### Stock Endpoints
- GET /api/stock/coils — List coil inventory
- GET /api/stock/coils/{id} — Coil details + transaction history
- POST /api/stock/coils/{id}/add — Add coil stock (transaction)
- POST /api/stock/coils/{id}/remove — Remove coil stock
- POST /api/stock/coils/{id}/adjust — Inventory correction
- GET /api/stock/chemicals — List chemical inventory
- POST /api/stock/chemicals/{id}/add — Add chemical stock
- POST /api/stock/chemicals/{id}/remove — Remove chemical stock
- GET /api/stock/transactions — List all transactions (audit log)
- GET /api/stock/transactions/{id} — Transaction details
- GET /api/stock/alerts — List current alerts
- GET /api/stock/dashboard — Stock summary dashboard

#### Dispatch Endpoints
- GET /api/dispatches — List dispatches
- POST /api/batches/{batchId}/dispatch — Create dispatch from batch
- GET /api/dispatches/{id} — Dispatch details
- PUT /api/dispatches/{id} — Update dispatch
- DELETE /api/dispatches/{id} — Cancel dispatch
- POST /api/dispatches/{id}/add-item — Add item to dispatch
- POST /api/dispatches/{id}/allocate — Auto-allocate stock
- POST /api/dispatches/{id}/complete — Mark as dispatched
- GET /api/dispatches/{id}/challan — Download challan PDF
- GET /api/dispatches/{id}/challan/preview — Preview challan
- GET /api/batches/{batchId}/dispatches — Batch dispatch history

**Response Format** (Standard ApiResponse wrapper):
```json
{
  "success": true,
  "data": { ... },
  "message": "Stock added successfully",
  "timestamp": "2026-05-18T10:30:00Z"
}
```

**Effort**: 2-3 hours

---

### Task 4.6: Comprehensive Testing

**Objective**: Test coverage for all stock & dispatch operations

**Test Files** (5):

1. **StockTest.php** (12 tests, 60 assertions)
   - test_add_coil_stock
   - test_remove_coil_stock
   - test_cannot_remove_more_than_available
   - test_adjust_stock
   - test_stock_transaction_immutable
   - test_get_stock_level
   - test_chemical_stock_with_expiry
   - test_multi_tenant_isolation_stock
   - test_reorder_level_validation
   - test_low_stock_alert_trigger
   - test_stock_history_retrieval
   - test_stock_audit_trail

2. **DispatchTest.php** (10 tests, 50 assertions)
   - test_create_dispatch_from_batch
   - test_cannot_dispatch_incomplete_batch
   - test_dispatch_number_generation
   - test_add_dispatch_item
   - test_allocate_stock_for_dispatch
   - test_cannot_allocate_insufficient_stock
   - test_complete_dispatch
   - test_cancel_dispatch_releases_allocations
   - test_cannot_dispatch_twice
   - test_multi_tenant_dispatch_isolation

3. **StockAllocationTest.php** (8 tests, 40 assertions)
   - test_allocate_stock
   - test_release_allocation
   - test_use_allocation_on_dispatch
   - test_cannot_allocate_more_than_available
   - test_allocation_prevents_duplicate_reserve
   - test_expired_allocation_auto_release
   - test_allocation_audit_trail
   - test_allocation_multi_tenant_isolation

4. **ChallanTest.php** (6 tests, 30 assertions)
   - test_generate_challan_pdf
   - test_challan_contains_dispatch_items
   - test_challan_includes_customer_address
   - test_challan_tracking_number
   - test_challan_generation_creates_file
   - test_challan_preview_returns_html

5. **StockDashboardTest.php** (8 tests, 40 assertions)
   - test_get_total_stock_value
   - test_get_low_stock_items
   - test_get_expiring_soon_chemicals
   - test_get_stock_movement
   - test_get_dispatch_pipeline
   - test_alert_summary_calculation
   - test_dashboard_multi_tenant_isolation
   - test_dashboard_performance_with_large_data

**Total Tests**: 44 tests with 220 assertions
**Coverage**: All CRUD operations, validations, workflows, multi-tenant isolation, edge cases

**Effort**: 3-4 hours

---

### Task 4.7: Vue 3 Components

**Objective**: Frontend UI for stock management and dispatch

**Components** (9):

1. **StockDashboard.vue** (200 lines)
   - Summary cards: Total value, low stock count, expiring items, pending dispatch
   - Quick access buttons to inventory views
   - Alert badges with color coding
   - Real-time data refresh

2. **CoilInventoryList.vue** (220 lines)
   - Table: Coil type, quantity in stock, reorder level, last update
   - Filter by reorder status, search by coil type
   - Stock in/out quick buttons
   - History button → transaction log
   - Color-code low stock items

3. **ChemicalInventoryList.vue** (220 lines)
   - Table: Chemical name, quantity, unit, expiry date, batch number
   - Filter by expiry status (soon, expired, OK)
   - Add/remove/adjust buttons
   - Expiry date countdown
   - Color warnings for expiring items

4. **StockTransactionForm.vue** (180 lines)
   - Item type selector (coil/chemical)
   - Item selector (dropdown)
   - Transaction type (in/out/adjustment)
   - Quantity input with validation
   - Notes textarea
   - Submit and success feedback

5. **DispatchList.vue** (200 lines)
   - Table: Dispatch #, batch reference, status, items count, dispatch date
   - Filter by status (pending, in_transit, delivered)
   - Sort by dispatch date
   - Pagination
   - Action buttons: View, Complete, Cancel, Download Challan

6. **DispatchDetail.vue** (280 lines)
   - Dispatch header: number, status, dates, batch reference
   - Items table: Panel type, quantity, unit price, amount, total
   - Customer address (editable)
   - Allocation status (showing which items are allocated)
   - Action buttons based on status
   - Challan preview/download
   - Tracking number display

7. **DispatchForm.vue** (250 lines)
   - Batch selector (only completed batches)
   - Auto-populate items from batch
   - Quantity adjustments per item
   - Customer address selector/edit
   - Expected delivery date picker
   - Auto-allocate checkbox
   - Notes textarea
   - Submit button with validation

8. **ChallanPreview.vue** (180 lines)
   - Display challan as it appears in PDF
   - Header with company logo
   - Dispatch details section
   - Items table
   - Totals section
   - Print button
   - Download PDF button
   - Close button

9. **StockAlerts.vue** (150 lines)
   - List all alerts: low stock, expiring, allocation failures
   - Group by type with color coding
   - Mark as resolved button
   - Filter by status (active, resolved)
   - Timeline view with dates
   - Action links (go to inventory, create PO)

**Service Addition**:
- Extend productionService.js with 25+ stock/dispatch methods
- Create separate stockService.js (optional, for better organization)

**Styling**:
- Professional dashboard colors
- Status-based color indicators
- Responsive grid layouts
- Loading states and error messages
- Print-friendly styles for challan

**Effort**: 5-6 hours

---

## Data Models Detail

### CoilStock
```php
Model: CoilStock
- id: bigint (PK)
- company_id: bigint (FK, multi-tenant)
- coil_id: bigint (FK, references to material database)
- quantity_in_stock: integer (kg/meters depending on measurement)
- reorder_level: integer (triggers alert when breached)
- last_stock_in: timestamp (when last added)
- last_stock_out: timestamp (when last used)
- created_at, updated_at, deleted_at (soft deletes)

Relationships:
- belongsTo(Company)
- belongsTo(Coil) — reference to material definition
- hasMany(StockTransaction)
- hasMany(StockAllocation) — for active allocations
```

### ChemicalStock
```php
Model: ChemicalStock
- id: bigint (PK)
- company_id: bigint (FK, multi-tenant)
- chemical_id: bigint (FK)
- quantity_in_stock: decimal (liters/kg depending on chemical)
- unit: enum (liter, kg, bottle, etc.)
- reorder_level: integer
- batch_number: string (tracking for chemicals)
- expiry_date: date (critical for chemicals)
- last_stock_in: timestamp
- last_stock_out: timestamp
- created_at, updated_at, deleted_at

Relationships:
- belongsTo(Company)
- belongsTo(Chemical) — reference to material definition
- hasMany(StockTransaction)
- hasMany(StockAllocation)
```

### StockTransaction
```php
Model: StockTransaction (Immutable Log)
- id: bigint (PK)
- company_id: bigint (FK)
- transactionable_id: bigint (FK, polymorphic)
- transactionable_type: string (CoilStock, ChemicalStock)
- type: enum (in, out, adjustment, allocation, allocation_release)
- quantity: decimal (positive value, type determines direction)
- unit: string (matched to stock item)
- reference_no: string (PO#, Dispatch#, Invoice#, etc.)
- notes: text (reason for transaction)
- transaction_date: timestamp (when transaction occurred)
- created_by_user_id: bigint (FK, who recorded)
- created_at: timestamp (immutable record creation time)

Features:
- NO UPDATE after creation (immutable)
- Soft deletes only for corrections (create reverse transaction instead)
- Every stock change tracked with full audit trail
- polymorphic to support both coil and chemical stocks

Relationships:
- belongsTo(Company)
- belongsToMany via transactionable (CoilStock, ChemicalStock)
- belongsTo(User) — who created transaction
```

### StockAllocation
```php
Model: StockAllocation
- id: bigint (PK)
- company_id: bigint (FK)
- dispatch_id: bigint (FK) — linked to dispatch
- allocatable_id: bigint (polymorphic)
- allocatable_type: string (CoilStock, ChemicalStock)
- quantity_allocated: decimal
- status: enum (allocated, used, released)
- allocated_at: timestamp
- used_at: timestamp (when dispatch completed)
- released_at: timestamp (if allocation cancelled)
- created_at, updated_at

Relationships:
- belongsTo(Company)
- belongsTo(Dispatch)
- belongsToMany via allocatable
```

### LowStockAlert
```php
Model: LowStockAlert
- id: bigint (PK)
- company_id: bigint (FK)
- item_type: enum (coil, chemical)
- item_id: bigint (FK to CoilStock or ChemicalStock)
- current_quantity: decimal (quantity when alert triggered)
- reorder_level: decimal (threshold crossed)
- alert_type: enum (low_stock, expiring_soon, out_of_stock)
- status: enum (active, resolved)
- alert_sent_at: timestamp
- resolved_at: timestamp
- created_at, updated_at, deleted_at

Relationships:
- belongsTo(Company)
```

### Dispatch
```php
Model: Dispatch
- id: bigint (PK)
- company_id: bigint (FK)
- batch_id: bigint (FK) — links to production batch
- dispatch_no: string (unique per company, format: DISP-YYYY-000001)
- status: enum (pending, in_transit, delivered, cancelled)
- dispatch_date: date
- expected_delivery_date: date
- actual_delivery_date: date (nullable until delivered)
- customer_address: text (denormalized for historical record)
- tracking_number: string (courier/shipping reference)
- notes: text
- created_at, updated_at, deleted_at (soft deletes)

Relationships:
- belongsTo(Company)
- belongsTo(ProductionBatch)
- hasMany(DispatchItem)
- hasMany(StockAllocation) — for allocated items
```

### DispatchItem
```php
Model: DispatchItem (Immutable Snapshot)
- id: bigint (PK)
- dispatch_id: bigint (FK)
- panel_type_id: bigint (FK)
- quantity: integer
- unit_price: decimal
- amount: decimal (quantity * unit_price)
- created_at

Purpose: Historical record of what was dispatched
Cannot be edited after creation (create new dispatch if changes needed)

Relationships:
- belongsTo(Dispatch)
- belongsTo(PanelType)
```

---

## API Response Examples

### Stock In (Add Stock)
```json
POST /api/stock/coils/{id}/add

Request:
{
  "quantity": 500,
  "unit": "kg",
  "reference_no": "PO-2026-001",
  "notes": "Raw material delivery from supplier"
}

Response:
{
  "success": true,
  "data": {
    "id": 42,
    "item_type": "coil",
    "coil_id": 5,
    "type": "in",
    "quantity": 500,
    "unit": "kg",
    "new_stock_level": 1500,
    "transaction_id": 156,
    "created_at": "2026-05-18T10:30:00Z"
  },
  "message": "Stock added successfully"
}
```

### Create Dispatch
```json
POST /api/batches/{batchId}/dispatch

Request:
{
  "customer_address": "123 Main St, City",
  "expected_delivery_date": "2026-05-25",
  "tracking_number": "TRK123456",
  "auto_allocate": true,
  "notes": "Urgent delivery"
}

Response:
{
  "success": true,
  "data": {
    "id": 18,
    "dispatch_no": "DISP-2026-000018",
    "batch_id": 5,
    "status": "pending",
    "items": [
      {
        "panel_type_id": 2,
        "quantity": 10,
        "unit_price": 500.00,
        "amount": 5000.00,
        "allocated": true
      }
    ],
    "total_items": 10,
    "total_amount": 5000.00,
    "dispatch_date": "2026-05-18",
    "expected_delivery_date": "2026-05-25"
  },
  "message": "Dispatch created successfully"
}
```

### Allocate Stock
```json
POST /api/dispatches/{id}/allocate

Response:
{
  "success": true,
  "data": {
    "dispatch_id": 18,
    "allocations": [
      {
        "id": 42,
        "item_type": "coil",
        "item_id": 3,
        "quantity_allocated": 500,
        "status": "allocated"
      }
    ],
    "all_items_allocated": true,
    "ready_to_dispatch": true
  },
  "message": "Stock allocated successfully"
}
```

### Get Stock Dashboard
```json
GET /api/stock/dashboard

Response:
{
  "success": true,
  "data": {
    "total_stock_value": 450000.00,
    "low_stock_items": 5,
    "expiring_soon_chemicals": 2,
    "pending_dispatch_batches": 3,
    "recent_transactions": [
      {
        "id": 156,
        "type": "in",
        "item": "Coil Type A",
        "quantity": 500,
        "transaction_date": "2026-05-18T09:00:00Z"
      }
    ],
    "alerts": [
      {
        "type": "low_stock",
        "item": "Chemical X",
        "current": 20,
        "reorder_level": 50
      }
    ]
  },
  "message": "Dashboard data retrieved"
}
```

---

## Validation Rules

### Stock Operations
```php
// Stock In
- quantity > 0
- unit matches stock item unit
- reference_no unique per month
- Created by active user

// Stock Out
- quantity > 0 AND <= current_stock
- unit matches stock item unit
- Cannot remove more than available
- Created by active user

// Stock Adjustment
- new_quantity >= 0
- reason required (audit trail)
- Only allowed for corrections (logged specially)

// Chemical Stock
- expiry_date must be >= today
- batch_number required for chemicals
- Cannot add expired chemicals
```

### Dispatch Operations
```php
// Create Dispatch
- batch_id exists and status = completed
- Cannot create dispatch if batch already dispatched
- customer_address required
- expected_delivery_date must be >= today

// Allocate Stock
- dispatch items exist
- Stock available for all items
- Cannot over-allocate (1 allocation per dispatch per item)
- Allocation must be exact or partial per item

// Complete Dispatch
- All items must be allocated
- status must be pending or in_transit
- actual_delivery_date can be backdated if needed
```

### Challan Generation
```php
- Dispatch must exist
- All items must be allocated
- Customer address must be provided
- Generates PDF or HTML
```

---

## Success Criteria

Phase 4 is complete when:
- ✅ Coil and chemical stock models created
- ✅ Stock transaction log (immutable) working
- ✅ Stock allocation system preventing over-allocation
- ✅ Dispatch creation from completed batches
- ✅ Challan PDF generation working
- ✅ Low stock alerts triggering automatically
- ✅ 44 tests passing (220 assertions)
- ✅ Vue components for stock/dispatch UI complete
- ✅ Multi-tenant isolation enforced throughout
- ✅ All CRUD operations tested
- ✅ Audit trail complete for all stock changes
- ✅ Ready for Phase 5 (Accounting)

---

## Files to Create

### Models (5)
- CoilStock.php
- ChemicalStock.php
- StockTransaction.php
- StockAllocation.php
- LowStockAlert.php
- Dispatch.php (new, not ProductionDispatch)
- DispatchItem.php

### Services (3)
- StockService.php (210 lines)
- DispatchService.php (280 lines)
- StockDashboardService.php (150 lines)

### Controllers (2)
- StockController.php (240 lines)
- DispatchController.php (220 lines)

### Migrations (5)
- create_coil_stocks_table
- create_chemical_stocks_table
- create_stock_transactions_table
- create_stock_allocations_table
- create_low_stock_alerts_table
- create_dispatches_table
- create_dispatch_items_table

### Routes
- Add 30+ endpoints to routes/api.php

### Tests (5)
- StockTest.php (12 tests, 60 assertions)
- DispatchTest.php (10 tests, 50 assertions)
- StockAllocationTest.php (8 tests, 40 assertions)
- ChallanTest.php (6 tests, 30 assertions)
- StockDashboardTest.php (8 tests, 40 assertions)

### Frontend (9)
- StockDashboard.vue
- CoilInventoryList.vue
- ChemicalInventoryList.vue
- StockTransactionForm.vue
- DispatchList.vue
- DispatchDetail.vue
- DispatchForm.vue
- ChallanPreview.vue
- StockAlerts.vue

### Documentation (1)
- PHASE_4_FRONTEND_GUIDE.md

---

## Timeline

| Task | Hours | Estimated Completion |
|------|-------|---------------------|
| 4.1 Stock Models | 2-3 | Day 1 morning |
| 4.2 Stock Services | 3-4 | Day 1 afternoon |
| 4.3 Dispatch System | 3-4 | Day 2 morning |
| 4.4 Alerts & Dashboard | 2 | Day 2 afternoon |
| 4.5 API Endpoints | 2-3 | Day 2 evening |
| 4.6 Test Suite | 3-4 | Day 3 |
| 4.7 Vue Components | 5-6 | Day 4 |
| **TOTAL** | **20-28** | **4 days** |

---

## Dependencies

- ✅ Phase 1 (Foundation) — COMPLETE
- ✅ Phase 2 (BOQ) — COMPLETE
- ✅ Phase 3 (Production) — COMPLETE
- Production batches must exist and be completed before dispatch
- Material/chemical definitions must exist (from data setup)

---

## Known Constraints

1. Stock transactions are immutable (no updates, only adds)
2. Cannot dispatch incomplete batches
3. Cannot allocate more stock than available
4. Challan generation requires complete dispatch info
5. Stock adjustments logged specially for audit purposes
6. Chemical expiry dates enforced at stock in

---

## Integration Points

### With Phase 3 (Production)
- Dispatch triggered when batch = completed
- Batch status updated to "dispatched" after dispatch complete
- Batch quantity_completed copied to dispatch items

### With Phase 5 (Accounting)
- Dispatch creates invoice (Phase 5)
- Dispatch amount feeds into Accounts Receivable
- Dispatch tracking used for delivery confirmation

### With Inventory Database
- CoilStock and ChemicalStock reference material definitions
- Stock movement audited through transactions
- Low stock alerts can trigger PO creation (Phase 5)

---

## Risk Mitigation

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Over-allocation bug | Medium | High | Atomic DB transactions + allocation validation tests |
| Challan generation failure | Low | Medium | PDF library fallback + preview feature |
| Stock sync issues | Medium | High | Immutable transaction log + reconciliation feature |
| Multi-tenant data leak | Low | Critical | Scope all queries with company_id, test coverage |
| Dispatch stuck pending | Low | Medium | Timeout alerts + manual complete option |

---

## Performance Considerations

- Index on (company_id, item_id) for stock lookups
- Index on (company_id, status) for dispatch queries
- Index on (transactionable_id, transactionable_type, created_at) for audit
- Paginate all list endpoints (default 20 per page)
- Cache dashboard metrics (5-minute TTL)
- Archive old transactions after 1 year (optional)

---

## Future Enhancements (Post-Phase 4)

- Barcode scanning for stock in/out
- Stock movement forecasting
- Supplier integration for auto-PO
- Courier API integration for tracking
- Multi-warehouse support
- Stock transfer between warehouses
- Serial number tracking for high-value items

---

## Related Documents

- [Phase 3 Plan](03_PHASE_3_PLAN.md) — Production module
- [Phase 5 Plan](05_PHASE_5_PLAN.md) — Accounting module
- [Project Tracker](00_PROJECT_TRACKER.md) — Overall progress

---

**Last Updated**: 2026-05-18  
**Status**: 📋 PLANNING (ready for implementation approval)
