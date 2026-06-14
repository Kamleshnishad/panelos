# 03 — API Endpoints

> All routes prefix: /api/v1/
> All routes protected by: auth:sanctum + tenant middleware

---

## Standard Response Format

### Success
```json
{ "success": true, "message": "...", "data": {...}, "meta": {...} }
```

### Error
```json
{ "success": false, "message": "...", "errors": {...}, "error_code": "VALIDATION_ERROR" }
```

---

## Authentication

POST   /auth/login
POST   /auth/logout
GET    /auth/me
POST   /auth/refresh-token
POST   /auth/change-password

---

## Super Admin (/superadmin)

GET    /superadmin/companies
POST   /superadmin/companies
GET    /superadmin/companies/{id}
PUT    /superadmin/companies/{id}
GET    /superadmin/companies/{id}/modules
PUT    /superadmin/companies/{id}/modules/{module_id}
POST   /superadmin/companies/{id}/impersonate
DELETE /superadmin/impersonate
GET    /superadmin/audit-logs

---

## Settings — Company

GET    /settings/company
PUT    /settings/company
POST   /settings/company/logo
POST   /settings/company/signature

---

## Settings — Panel Types

GET    /settings/panel-types
POST   /settings/panel-types
GET    /settings/panel-types/{id}
PUT    /settings/panel-types/{id}
DELETE /settings/panel-types/{id}
PUT    /settings/panel-types/reorder

---

## Settings — Pricing

GET    /settings/pricing-rules
POST   /settings/pricing-rules
PUT    /settings/pricing-rules/{id}
DELETE /settings/pricing-rules/{id}
POST   /settings/pricing/calculate        ← Real-time BOQ price calculation

POST /settings/pricing/calculate Request:
{
  "panel_type_id": 1,
  "thickness_mm": 50,
  "density_type": "puf",
  "density_kg_m3": 40,
  "top_skin_material": "PPGI",
  "top_skin_thickness_mm": 0.40,
  "bottom_skin_material": "PPGI",
  "bottom_skin_thickness_mm": 0.40,
  "top_surface": "plain",
  "customer_type": "direct",
  "delivery_state": "GJ",
  "quality_grade": "high",
  "total_sqm": 231.00
}

Response:
{
  "calculated_rate": 1210.00,
  "final_rate": 1149.50,
  "quantity_discount_pct": 5.0,
  "breakdown": { ... }
}

GET    /settings/quantity-slabs
POST   /settings/quantity-slabs
PUT    /settings/quantity-slabs/{id}
DELETE /settings/quantity-slabs/{id}

---

## Settings — Accessories

GET    /settings/accessories
POST   /settings/accessories
PUT    /settings/accessories/{id}
DELETE /settings/accessories/{id}
GET    /settings/colors
POST   /settings/colors
PUT    /settings/colors/{id}

---

## Settings — Production Stages

GET    /settings/production-stages
POST   /settings/production-stages
PUT    /settings/production-stages/{id}
DELETE /settings/production-stages/{id}
PUT    /settings/production-stages/reorder   Body: { "order": [1,2,3...] }

---

## Settings — Roles & Users

GET    /settings/roles
POST   /settings/roles
PUT    /settings/roles/{id}
DELETE /settings/roles/{id}
GET    /settings/users
POST   /settings/users
PUT    /settings/users/{id}
DELETE /settings/users/{id}
POST   /settings/users/{id}/reset-password

---

## Settings — Form Builder

GET    /settings/form-configs
GET    /settings/form-configs/{form_type}/fields
PUT    /settings/form-configs/{form_type}/fields/{field_id}
POST   /settings/form-configs/{form_type}/fields      ← Add custom field
DELETE /settings/form-configs/{form_type}/fields/{field_id}
PUT    /settings/form-configs/{form_type}/fields/reorder

form_type values: boq_header, panel_row, customer, order, dispatch, invoice

---

## Settings — Notifications

GET    /settings/notification-configs
PUT    /settings/notification-configs/{id}
GET    /settings/notification-configs/events

---

## Dashboard

GET    /dashboard

---

## Customers

GET    /customers               ?type=dealer&search=kwality
POST   /customers
GET    /customers/{id}
PUT    /customers/{id}
DELETE /customers/{id}
GET    /customers/{id}/orders
GET    /customers/{id}/invoices
GET    /customers/{id}/payments
GET    /customers/{id}/outstanding

---

## Suppliers

GET    /suppliers
POST   /suppliers
GET    /suppliers/{id}
PUT    /suppliers/{id}
DELETE /suppliers/{id}

---

## Enquiries

GET    /enquiries
POST   /enquiries
GET    /enquiries/{id}
PUT    /enquiries/{id}
DELETE /enquiries/{id}
POST   /enquiries/{id}/convert-to-quotation
POST   /enquiries/{id}/follow-up

---

## Quotations / BOQ

GET    /quotations              ?status=draft&customer_id=1
POST   /quotations              ← Create with all items
GET    /quotations/{id}
PUT    /quotations/{id}
DELETE /quotations/{id}
POST   /quotations/{id}/send
POST   /quotations/{id}/accept  ← Creates order automatically
POST   /quotations/{id}/reject
POST   /quotations/{id}/revise  ← New version
POST   /quotations/{id}/duplicate
GET    /quotations/{id}/pdf
GET    /quotations/{id}/boq-sheet

POST /quotations Accept Request:
{
  "accepted_date": "2025-06-15",
  "create_order": true,
  "advance_amount": 150000,
  "advance_mode": "neft",
  "advance_reference": "UTR123456",
  "committed_delivery": "2025-06-29"
}

Response:
{
  "success": true,
  "data": { "order_id": 22, "order_no": "ORD-2025-022" }
}

---

## Orders

GET    /orders
POST   /orders
GET    /orders/{id}
PUT    /orders/{id}
POST   /orders/{id}/cancel
GET    /orders/{id}/cutting-schedule
GET    /orders/{id}/production-status

---

## Production — Batches

GET    /production/batches      ?status=in_progress&date=2025-06-10
POST   /production/batches
GET    /production/batches/{id}
PUT    /production/batches/{id}
POST   /production/batches/{id}/start
POST   /production/batches/{id}/complete
GET    /production/batches/{id}/stages
POST   /production/batches/{id}/stages/{stage_id}/start
POST   /production/batches/{id}/stages/{stage_id}/complete
POST   /production/batches/{id}/stages/{stage_id}/skip
POST   /production/batches/{id}/stages/{stage_id}/upload-photo

Stage Complete Request (Chemical Injection example):
{
  "completed_at": "2025-06-10T10:30:00",
  "checklist_data": {
    "Polyol drum connected": true,
    "Isocyanate drum connected": true,
    "Mixing head cleaned": true
  },
  "parameters_logged": {
    "polyol_ratio": 55,
    "isocyanate_ratio": 45,
    "mix_temp_c": 22
  }
}

---

## Production — Cutting Schedule

GET    /production/cutting-schedules
POST   /production/cutting-schedules
GET    /production/cutting-schedules/{id}
POST   /production/cutting-schedules/{id}/items/{item_id}/mark-cut
GET    /production/cutting-schedules/{id}/print-sheet

---

## Production — Quality

GET    /production/quality-logs
POST   /production/quality-logs
GET    /production/quality-logs/{id}

---

## Inventory — Coils

GET    /inventory/coils         ?material=ppgi&thickness=0.40&color=off_white
POST   /inventory/coils
GET    /inventory/coils/{id}
PUT    /inventory/coils/{id}
GET    /inventory/coils/available   ?color_code=9002&thickness_mm=0.40

---

## Inventory — Chemicals

GET    /inventory/chemicals
POST   /inventory/chemicals
GET    /inventory/chemicals/{id}
GET    /inventory/chemicals/expiring

---

## Inventory — Stock

GET    /inventory/accessories-stock
PUT    /inventory/accessories-stock/{id}
GET    /inventory/low-stock
GET    /inventory/transactions
POST   /inventory/transactions/adjustment

---

## Dispatch

GET    /dispatches
POST   /dispatches
GET    /dispatches/{id}
PUT    /dispatches/{id}
POST   /dispatches/{id}/mark-delivered
POST   /dispatches/{id}/upload-delivery-photos
GET    /dispatches/{id}/challan-pdf

---

## Invoices

GET    /invoices
POST   /invoices
GET    /invoices/{id}
PUT    /invoices/{id}
DELETE /invoices/{id}
POST   /invoices/{id}/send
GET    /invoices/{id}/pdf
POST   /invoices/{id}/e-invoice
GET    /invoices/gst-summary    ?month=6&year=2025

---

## Payments

GET    /payments
POST   /payments
GET    /payments/{id}
PUT    /payments/{id}
GET    /payments/outstanding-report
PUT    /payments/{id}/cheque-status

---

## Reports

GET    /reports/production-summary    ?from=2025-06-01&to=2025-06-30
GET    /reports/order-status
GET    /reports/inventory-valuation
GET    /reports/customer-outstanding
GET    /reports/dispatch-report
GET    /reports/sales-analysis
GET    /reports/wastage-report
GET    /reports/profitability
GET    /reports/gst-report            ?month=6&year=2025

---

## Notifications

GET    /notifications               ?is_read=false
PUT    /notifications/{id}/read
PUT    /notifications/read-all
DELETE /notifications/{id}

---

## HTTP Status Codes

200 Success | 201 Created | 204 No Content
400 Bad Request | 401 Unauthorized | 403 Forbidden
404 Not Found | 422 Validation Error | 500 Server Error

