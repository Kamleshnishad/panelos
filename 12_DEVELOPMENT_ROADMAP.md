# 12 — Enterprise Development Roadmap

> Phase-wise, production-grade implementation with quality gates, testing, and deployment strategy.
> Built for scale, security, and maintainability from day one.

---

## Table of Contents

1. [Enterprise Standards](#1-enterprise-standards)
2. [Phase Overview](#2-phase-overview)
3. [Phase 1 — Foundation & Infrastructure](#3-phase-1--foundation--infrastructure)
4. [Phase 2 — Core Sales Module (BOQ)](#4-phase-2--core-sales-module-boq)
5. [Phase 3 — Order & Production](#5-phase-3--order--production)
6. [Phase 4 — Inventory & Dispatch](#6-phase-4--inventory--dispatch)
7. [Phase 5 — Accounting & GST](#7-phase-5--accounting--gst)
8. [Phase 6 — Frontend & UX](#8-phase-6--frontend--ux)
9. [Phase 7 — Admin & Configuration](#9-phase-7--admin--configuration)
10. [Phase 8 — Integration & Optimization](#10-phase-8--integration--optimization)
11. [Quality Assurance Framework](#11-quality-assurance-framework)
12. [Deployment Strategy](#12-deployment-strategy)
13. [Post-Launch Support](#13-post-launch-support)

---

## 1. Enterprise Standards

### Code Quality
```
✅ SOLID principles
✅ Design patterns (Repository, Service, Factory)
✅ Type hints everywhere (PHP 8.2)
✅ PSR-12 code style (Laravel)
✅ DRY (Don't Repeat Yourself)
✅ KISS (Keep It Simple, Stupid)
✅ YAGNI (You Aren't Gonna Need It)
```

### Testing Requirements
```
✅ Unit tests: 80%+ code coverage
✅ Integration tests: All API endpoints
✅ Feature tests: User workflows
✅ Database tests: Migration + seeding
✅ Performance tests: Load testing critical paths
✅ Security tests: OWASP top 10
```

### Documentation
```
✅ API documentation (OpenAPI/Swagger)
✅ Database schema diagrams
✅ Architecture decision records (ADR)
✅ Deployment runbooks
✅ Troubleshooting guides
✅ Code comments (WHY, not WHAT)
```

### Git Workflow
```
✅ Feature branches (feature/boq-pricing-engine)
✅ Pull request reviews (min 2 approvals)
✅ Automated tests on PR (CI/CD)
✅ Semantic versioning (v1.0.0)
✅ Release notes per phase
✅ Git hooks (pre-commit, pre-push)
```

### Security
```
✅ Input validation (all endpoints)
✅ SQL injection prevention (Eloquent ORM)
✅ XSS protection (Vue 3 auto-escaping)
✅ CSRF tokens (Sanctum)
✅ Rate limiting (API throttling)
✅ Encryption at rest (sensitive fields)
✅ Encryption in transit (HTTPS only)
✅ Audit logging (all data changes)
✅ Secrets management (.env, not in git)
```

### Performance
```
✅ Database indexing (query optimization)
✅ Redis caching (session, cache, queue)
✅ Lazy loading prevention (N+1 queries)
✅ Pagination (all list endpoints)
✅ Query optimization (select only needed columns)
✅ Asset minification (CSS, JS)
✅ Gzip compression (responses)
✅ CDN ready (static assets)
```

---

## 2. Phase Overview

| Phase | Duration | Focus | Deliverables |
|---|---|---|---|
| **1** | 3-4 weeks | Infrastructure | Project setup, migrations, auth, base API |
| **2** | 5-6 weeks | BOQ Module | Quotation, pricing engine, PDF |
| **3** | 4-5 weeks | Production | Batches, stages, QC, cutting schedule |
| **4** | 3-4 weeks | Inventory & Dispatch | Stock management, dispatch, challan |
| **5** | 3-4 weeks | Accounting | Invoicing, GST, payment tracking |
| **6** | 4-5 weeks | Frontend | Vue components, layouts, UX |
| **7** | 3-4 weeks | Admin & Config | Super admin, form builder, settings |
| **8** | 2-3 weeks | Integration | Notifications, caching, optimization |
| **Total** | **30-36 weeks** (7-9 months) | **Full MVP** | **Production-ready system** |

---

## 3. Phase 1 — Foundation & Infrastructure

**Duration**: 3-4 weeks | **Team**: 2 (Backend Lead + DevOps)

### 1.1 Project Setup

```bash
# Laravel project
laravel new panelos-backend --git

# Key packages
composer require \
  stancl/tenancy \
  laravel/sanctum \
  spatie/laravel-permission \
  spatie/laravel-activitylog \
  barryvdh/laravel-dompdf \
  guzzlehttp/guzzle

# Frontend project
npm create vite@latest panelos-frontend -- --template vue
npm install pinia vue-router primevue tailwindcss
```

### 1.2 Database & Migrations

**Deliverable**: 51 tables with relationships, indices, constraints

```sql
-- Priority order (respecting foreign keys):
001_create_companies_table
002_create_modules_table
003_create_company_modules_table
004_create_users_table
005_create_roles_table
006_create_role_permissions_table
007_create_audit_logs_table
008_create_panel_types_table
009_create_color_master_table
... (see 02_DATABASE_SCHEMA.md for full list)
```

**Quality Gate**: 
- All migrations run cleanly
- Zero foreign key violations
- Seeders complete without errors
- Database can be reset with `migrate:fresh --seed`

### 1.3 Authentication & Authorization

**Deliverable**: 
- User login (email + password)
- JWT tokens (Sanctum)
- Multi-tenant isolation (every query scoped to company_id)
- Role-based access control (6 default roles)

```php
// app/Models/User.php
class User extends Model {
    use HasTenantScoped;  // Auto WHERE company_id = current company
    
    public function roles() { ... }
    public function hasPermission($permission) { ... }
}
```

**API Endpoints**:
```
POST   /auth/login          → Returns token
POST   /auth/logout         → Invalidates token
GET    /auth/me             → Current user + roles
POST   /auth/refresh-token  → Renew expiring token
POST   /auth/change-password
```

**Quality Gate**:
- Login works with valid/invalid credentials
- Token included in all subsequent requests
- Logout clears token
- Expired tokens rejected
- Role-based endpoints return 403 if unauthorized

### 1.4 API Response Wrapper

**Deliverable**: Consistent response format across all endpoints

```php
// Success
{
  "success": true,
  "message": "Quotation created",
  "data": { "id": 1, "quotation_no": "SCP-2025-001" },
  "meta": { "timestamp": "2025-06-10T10:30:00Z" }
}

// Error
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "customer_id": ["Customer is required"],
    "length_mm": ["Length must be between 500 and 14000"]
  },
  "error_code": "VALIDATION_ERROR"
}
```

**Quality Gate**: 
- All endpoints return consistent format
- Error messages are helpful (not technical)
- HTTP status codes match semantics (201 created, 422 validation, 403 forbidden)

### 1.5 Base Models & Traits

**Deliverable**:
- `TenantScoped` trait (auto-applies company_id filter)
- `HasTimestamps` (created_at, updated_at)
- `SoftDeletes` (deleted_at for critical tables)
- `HasAuditLog` (tracks created_by, updated_by)

```php
// Usage
class Quotation extends Model {
    use HasTenantScoped, SoftDeletes, HasAuditLog;
    
    protected $fillable = ['customer_id', 'project_name', ...];
    protected $casts = ['quotation_date' => 'date', ...];
    
    public function customer() { return $this->belongsTo(Customer::class); }
    public function panelItems() { return $this->hasMany(QuotationPanelItem::class); }
}
```

### 1.6 Testing Infrastructure

**Deliverable**:
- PHPUnit configured
- Database factories (for test data)
- Test traits (authentication, database transactions)

```php
// tests/Feature/AuthTest.php
class AuthTest extends TestCase {
    use RefreshDatabase;  // Reset DB after each test
    
    public function test_user_can_login() {
        $user = User::factory()->create(['email' => 'test@test.com']);
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password'
        ]);
        $response->assertStatus(200)->assertJsonStructure(['data' => ['token']]);
    }
}
```

**Quality Gate**: 
- All Phase 1 code has tests
- Test coverage > 80%
- Tests run in < 2 minutes
- CI/CD runs tests on every commit

### 1.7 Environment & DevOps

**Deliverable**:
- Docker setup (local development)
- GitHub Actions (CI/CD pipeline)
- Environment variables documented

**docker-compose.yml**:
```yaml
services:
  app:
    build: .
    ports: ["8000:8000"]
    environment:
      - APP_ENV=local
      - DB_HOST=mysql
    depends_on: [mysql, redis]
  
  mysql:
    image: mysql:8.0
    ports: ["3306:3306"]
    environment:
      MYSQL_DATABASE: panelos
      MYSQL_ROOT_PASSWORD: root
  
  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]
  
  node:
    image: node:20-alpine
    working_dir: /app
    volumes: ["./frontend:/app"]
    command: npm run dev
    ports: ["5173:5173"]
```

**GitHub Actions (.github/workflows/tests.yml)**:
```yaml
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
      redis:
        image: redis:7
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2', extensions: 'redis,mysql' }
      - run: composer install --no-dev
      - run: cp .env.testing .env
      - run: php artisan migrate
      - run: php artisan test
```

**Quality Gate**:
- Docker compose up -d → all services healthy
- `php artisan migrate:fresh --seed` → succeeds
- `php artisan test` → all tests pass
- GitHub Actions runs on every PR

### 1.8 Phase 1 Deliverables Checklist

```
Backend:
☐ Laravel 11 project initialized
☐ All 51 migrations created + tested
☐ Base models with traits
☐ Authentication system (Sanctum)
☐ API response wrapper
☐ Multi-tenancy configured
☐ Unit tests for auth/models
☐ Docker environment

Frontend:
☐ Vue 3 project initialized
☐ Pinia store setup
☐ PrimeVue configured with custom theme
☐ Tailwind CSS setup
☐ API axios client configured
☐ Login page component
☐ Base layout (sidebar + topbar)

DevOps:
☐ docker-compose.yml working
☐ GitHub Actions CI/CD configured
☐ Environment variables documented
☐ .gitignore proper
☐ README with setup instructions

Documentation:
☐ Architecture diagram
☐ API endpoint list (not full documentation yet)
☐ Database schema diagram
☐ Setup guide updated
```

**Completion Criteria**:
- ✅ All tests pass (80%+ coverage)
- ✅ Docker environment works locally
- ✅ Can login and receive JWT token
- ✅ All migrations + seeders work
- ✅ Zero PHP warnings/errors
- ✅ Code passes PSR-12 check (php-cs-fixer)

---

## 4. Phase 2 — Core Sales Module (BOQ)

**Duration**: 5-6 weeks | **Team**: 2 (Backend) + 1 (Frontend)

### 2.1 Database Tables

```
✅ customers
✅ quotations
✅ quotation_panel_items
✅ quotation_accessory_items
✅ panel_types
✅ color_master
✅ accessories_master
✅ pricing_rules
✅ quantity_slabs
```

### 2.2 Pricing Engine (Most Complex)

**Deliverable**: Real-time price calculation API

```
POST /api/v1/settings/pricing/calculate
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
  "breakdown": {
    "base_rate": 1050.00,
    "thickness_premium": 75.00,
    "skin_premium": 50.00,
    "quality_multiplier": 1.10,
    "quantity_discount": -35.50
  }
}
```

**Implementation**:
```php
// app/Services/PricingEngine.php
class PricingEngine {
    public function calculate(array $params): array {
        $baseRate = $this->getBaseRate($params['panel_type_id']);
        $thicknessPremium = $this->calculateThicknessPremium(...);
        $qualityMultiplier = $this->getQualityMultiplier(...);
        $quantityDiscount = $this->getQuantityDiscount(...);
        
        $calculated = $baseRate + $thicknessPremium + ...;
        $final = $calculated * $qualityMultiplier - $quantityDiscount;
        
        return ['calculated_rate' => $calculated, 'final_rate' => $final, ...];
    }
}
```

**Quality Gate**:
- Pricing matches actual invoices (test with real data)
- Edge cases: Min/max lengths, invalid combinations
- Null safety: No PHP notices/warnings
- Performance: < 100ms response time

### 2.3 Quotation CRUD API

```
GET    /api/v1/quotations                    List all (with filters, pagination)
POST   /api/v1/quotations                    Create new
GET    /api/v1/quotations/{id}               Get single
PUT    /api/v1/quotations/{id}               Update
DELETE /api/v1/quotations/{id}               Soft delete
POST   /api/v1/quotations/{id}/send          Send to customer
POST   /api/v1/quotations/{id}/accept        Accept → creates order
POST   /api/v1/quotations/{id}/reject        Reject
POST   /api/v1/quotations/{id}/revise        Create new version
POST   /api/v1/quotations/{id}/duplicate     Copy
GET    /api/v1/quotations/{id}/pdf           Download PDF
```

**Business Logic**:
```
DRAFT   → [Edit, Send, Delete, Duplicate]
SENT    → [Edit, Revise, Accept, Reject]
ACCEPTED→ [Read-only, Revise]
REJECTED→ [Revise]
EXPIRED → [Revise] (after validity_days)
REVISED → [Read-only] (locked, new version is active)
```

**Quality Gate**:
- All endpoints tested (success + error cases)
- Status transitions validated
- Quotation number unique per company per year
- Soft deletes work (deleted still queryable for audit)

### 2.4 Panel Items & Auto-Calculations

**Deliverable**: Add/edit panel rows with real-time calculations

```javascript
{
  panel_type_id: 1,           // Roof
  thickness_mm: 30,
  density_type: "puf",
  density_kg_m3: 40,
  top_skin_material: "PPGL",
  top_skin_thickness_mm: 0.40,
  top_skin_color: "Off White",
  top_surface: "RIBBED",      // AUTO-SET when panel type changes
  bottom_skin_material: "PPGL",
  bottom_skin_thickness_mm: 0.40,
  bottom_skin_color: "Off White",
  bottom_surface: "PLAIN",    // Always PLAIN
  guard_film: true,           // Per row
  cello_tap: true,            // Per row
  length_mm: 3660,
  width_mm: 1000,             // READ-ONLY
  nos: 60,
  sqm: 219.60,                // AUTO: (3660/1000) × (1000/1000) × 60
  final_rate: 1050,           // From pricing engine
  amount: 230580              // AUTO: 219.60 × 1050
}
```

**Critical Logic**:
```php
// Surface auto-set (fires ONLY on panel_type change)
function onPanelTypeChange($panelTypeId) {
    $panel = PanelType::find($panelTypeId);
    $this->top_surface = $panel->category === 'roof' ? 'RIBBED' : 'PLAIN';
    $this->bottom_surface = 'PLAIN';  // Always
}

// SQM auto-calculation (fires on any length/nos change)
function calculateSQM() {
    return ($this->length_mm / 1000) * ($this->width_mm / 1000) * $this->nos;
}

// Amount auto-calculation (fires on sqm/rate change)
function calculateAmount() {
    return $this->sqm * $this->final_rate;
}
```

**Quality Gate**:
- Width always 1000mm (cannot be edited)
- SQM calculates correctly (test with actual invoices)
- Auto-set surface only on panel type change (not on other field changes)
- Manual surface override tracked (don't re-trigger auto-set on re-edit)

### 2.5 Accessories Management

**Deliverable**: Dynamic accessories from master

```
✅ Dropdown: Select from master (Ridge Cap, L-Angle, Door, etc.)
✅ Sub-type: Free text (e.g., "Outer" for ridge cap)
✅ Unit: Auto from master (MTR, NOS, KG)
✅ Qty: Number input
✅ Rate: Auto from master, editable
✅ Amount: Auto = Qty × Rate
✅ HSN Code: Auto, read-only
✅ Add/remove rows
```

**Special: Door/Window Items**
```
{
  accessory_type: "door",
  width_mm: 900,
  height_mm: 2100,
  skin_material: "PPGI",
  skin_thickness_mm: 0.80,
  view_panel: true,
  view_panel_size: "400×600",
  accessories: ["hinges", "lock", "closer"],
  qty: 2,
  unit: "NOS",
  rate: 5000,
  amount: 10000
}
```

**Quality Gate**:
- Accessory master CRUD works
- Door/window special fields render correctly
- Unit auto-set from master
- Rate editable but default from master

### 2.6 Summary & Totals Calculation

**Deliverable**: Real-time summary section

```
Panel Subtotal:      ₹ 3,28,577
Accessory Subtotal:  ₹    29,250
Installation:        ₹         0
──────────────────────────────
Subtotal:            ₹ 3,57,827

Discount [0]%:       ₹         0
Taxable Amount:      ₹ 3,57,827

GST Detection:
├─ Company GSTIN: 24AAFFU9050M1ZS → State: 24 (Gujarat)
├─ Customer State: 24 (Gujarat) → Intra-state
├─ CGST 9%: ₹ 32,204
├─ SGST 9%: ₹ 32,205
└─ Total GST: ₹ 64,409

OR if interstate:
├─ Customer State: 08 (Rajasthan)
├─ IGST 18%: ₹ 64,409
└─ Total GST: ₹ 64,409

Transportation:
├─ Type: ○ Extra as Actual ● Fixed
├─ Amount: ₹ 18,000
└─ Total: ₹ 18,000

─────────────────────────────
GRAND TOTAL:         ₹ 4,40,236

Advance (50%):       ₹ 2,20,118
Balance Due:         ₹ 2,20,118
```

**Logic**:
```php
$taxable = $panelSubtotal + $accessorySubtotal - $discount;

if ($this->isIntrastate()) {  // Same state
    $cgst = $taxable * 0.09;
    $sgst = $taxable * 0.09;
    $totalGST = $cgst + $sgst;
} else {  // Different state
    $igst = $taxable * 0.18;
    $totalGST = $igst;
}

$grandTotal = $taxable + $totalGST + $transportation;
$advance = $grandTotal * 0.50;
$balance = $grandTotal - $advance;
```

**Quality Gate**:
- GST state detection correct (first 2 chars of GSTIN = state code)
- Math accurate (test against real invoices)
- Rounding to nearest rupee
- Real-time updates as user types

### 2.7 PDF Generation

**Deliverable**: Professional Proforma Invoice PDF matching Signature format

**Page 1**: Main invoice
```
[Logo]           PROFORMA INVOICE        Date: 27/11/23

UMA SIGNATURE PUF PANEL LLP             info@signaturepufpanel.com
Survey No 158/1, Dhanora...             GSTIN: 24AAFFU9050M1ZS
                                        AN ISO 9001:2015 COMPANY

BUYER'S: KWALITY BUSINESS...            DATE: 27/11/23
[Address]                               REF NO: ...
GSTIN: [if available]                   PFI NO: SCP-027

[ITEMS TABLE with embedded SIZE SUB-TABLE]
Sr.No | Description               | Qty | UoM | Rate | Amount
  1   | SUPPLY OF SIGNATURE ROOF  |312.93|SQM | 1050 | 328577
      | 30MM Thick, 0.40 PPGL     |      |    |      |
      | SIZE SUB-TABLE:           |      |    |      |
      | LENGTH|WIDTH|NOS|SQM      |      |    |      |
      | 3660  | 1000| 60| 219.6   |      |    |      |
      | 3355  | 1000| 24| 80.52   |      |    |      |
  2   | ROOF SIDE CAP             | 55.0 | MTR | 150  | 8,250

                            TOTAL:           ₹ 3,57,827
                           GST @18%:         ₹    64,409
    ADVANCE:             ₹ 1,50,000
    BALANCE:             ₹ 2,72,236
```

**Page 2**: Terms & Conditions (from settings)

**Page 3**: BOQ Sheet (Cutting list)

**Implementation**:
```php
// app/Services/PDFService.php
public function generateQuotationPDF(Quotation $quotation) {
    $data = [
        'company' => $quotation->company,
        'quotation' => $quotation,
        'items' => $quotation->panelItems,
        'accessories' => $quotation->accessoryItems,
        'custom_fields' => $this->getCustomFieldValues($quotation),
    ];
    
    return Pdf::loadView('pdfs.quotation', $data)
        ->setPaper('a4', 'portrait')
        ->setOptions(['dpi' => 150, 'isHtml5ParserEnabled' => true])
        ->download("quotation_{$quotation->quotation_no}.pdf");
}
```

**Quality Gate**:
- PDF matches Signature PUF format (pixel-perfect)
- All pages render correctly
- Images (logo) embed properly
- Special characters (₹, °) display correctly
- File size < 2MB
- Generation time < 5 seconds

### 2.8 Frontend Components (Vue 3)

**Deliverables**:

```
views/BOQ/
├── Index.vue           (List with filters, pagination, actions)
├── Create.vue          (Form with multi-step: header → items → accessories → summary)
├── Edit.vue            (Same as create)
├── Detail.vue          (Read-only preview)
├── components/
│   ├── BOQHeader.vue   (Customer, project, quality grade)
│   ├── PanelRowTable.vue (Add/edit/delete panel rows)
│   ├── PanelRowForm.vue (Modal for editing single row)
│   ├── AccessoryTable.vue (Add/edit/delete accessories)
│   ├── AccessoryForm.vue (Modal for editing single accessory)
│   ├── SummaryCard.vue (Real-time totals + GST)
│   └── QuotationPreview.vue (PDF modal preview)
```

**Quality Gate**:
- All forms validated on client-side (UX feedback)
- Validations repeated on server-side (security)
- Real-time SQM/Amount calculations
- Responsive on desktop + tablet
- Accessible (ARIA labels, keyboard navigation)

### 2.9 Phase 2 Deliverables Checklist

```
API:
☐ All quotation endpoints (CRUD)
☐ Pricing engine API
☐ PDF generation endpoint
☐ Quotation validation
☐ Status workflow logic
☐ Revision system

Database:
☐ quotations table
☐ quotation_panel_items table
☐ quotation_accessory_items table
☐ panel_types seeded
☐ accessories_master seeded
☐ pricing_rules seeded
☐ quantity_slabs seeded

Frontend:
☐ BOQ list page
☐ BOQ create/edit forms
☐ Panel items table with inline editing
☐ Accessories management
☐ Summary calculations (real-time)
☐ PDF preview + download
☐ Status badges + actions
☐ Form validations

Documentation:
☐ Pricing engine algorithm documented
☐ API endpoints documented (OpenAPI)
☐ Database schema updated
☐ Test data provided
```

**Completion Criteria**:
- ✅ Pricing matches actual invoices (±0.01%)
- ✅ All validations pass
- ✅ PDF generation working
- ✅ Tests > 85% coverage
- ✅ No N+1 queries
- ✅ Performance: list < 500ms, create < 1s
- ✅ Deploy to staging, test manually with real workflows

---

## 5. Phase 3 — Order & Production

**Duration**: 4-5 weeks | **Team**: 2 (Backend) + 1 (Frontend)

### 3.1 Order Generation from Quotation

**API**:
```
POST /api/v1/quotations/{id}/accept
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
  "data": {
    "order_id": 22,
    "order_no": "ORD-2025-022"
  }
}
```

**Business Logic**:
```
1. Validate quotation status = SENT
2. Copy quotation to order (new order_id, order_no)
3. Copy all panel items
4. Copy all accessories
5. Record advance payment
6. Change quotation status → ACCEPTED
7. Create order with status = CONFIRMED
8. Trigger notification: "Order created from SCP-028"
```

### 3.2 Production Batch CRUD

**API**:
```
POST /api/v1/production/batches
{
  "order_id": 22,
  "order_item_id": 45,
  "machine_id": 1,
  "shift": "day",
  "planned_date": "2025-06-10",
  "planned_qty": 87,
  "operator_id": 5
}

Response:
{
  "success": true,
  "data": {
    "batch_id": 45,
    "batch_no": "BATCH-2025-045"
  }
}
```

### 3.3 Production Stages (Configurable)

**API Endpoints**:
```
GET    /api/v1/settings/production-stages         List all
POST   /api/v1/settings/production-stages         Create
PUT    /api/v1/settings/production-stages/{id}    Update
DELETE /api/v1/settings/production-stages/{id}    Delete
PUT    /api/v1/settings/production-stages/reorder  Drag-drop reorder

GET    /api/v1/production/batches/{id}/stages           List stages for batch
POST   /api/v1/production/batches/{id}/stages/{stage_id}/start
POST   /api/v1/production/batches/{id}/stages/{stage_id}/complete
POST   /api/v1/production/batches/{id}/stages/{stage_id}/skip
POST   /api/v1/production/batches/{id}/stages/{stage_id}/upload-photo
```

**Stage Completion Logic**:
```
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
    "mix_temp_c": 22,
    "ambient_temp_c": 28
  },
  "remarks": "Good quality batch",
  "photos": ["photo1.jpg", "photo2.jpg"]
}
```

**Validation**:
- Mandatory checklist items must be checked
- Required parameters must be provided
- Parameter values within min/max range
- Parameters match field type (number, text, dropdown, etc.)
- Cannot skip mandatory stages
- Stages must complete in order

### 3.4 Quality Control

**API**:
```
POST /api/v1/production/quality-logs
{
  "batch_id": 45,
  "thickness_actual_mm": 30.5,
  "thickness_tolerance": 2,
  "density_actual_kg_m3": 39.8,
  "density_tolerance": 2,
  "foam_quality": "pass",         // pass | fail | marginal
  "surface_quality": "pass",
  "skin_adhesion": "pass",
  "edge_quality": "pass",
  "overall_result": "pass",        // pass | fail | rework
  "total_checked": 87,
  "rejected_count": 2,
  "rejection_reason": "Surface scratch on 2 panels",
  "defects": ["scratch"],
  "corrective_action": "Check guard film tension"
}

Response:
{
  "success": true,
  "data": {
    "qc_log_id": 12,
    "batch_status": "completed",
    "rejection_rate": 2.3
  }
}
```

**Result Handling**:
```
PASS:
├─ Batch → COMPLETED
├─ Order Item → PRODUCED
├─ Panels available for dispatch

FAIL:
├─ Alert owner + supervisor
├─ Batch → FAILED
├─ Options: SCRAP / REWORK / DOWNGRADE

MARGINAL:
├─ Supervisor review required
├─ Awaiting decision
```

### 3.5 Cutting Schedule (Critical: < 2000mm Logic)

**Cutting Schedule Generation**:
```
GET /api/v1/production/cutting-schedules/{id}/items

For each order item:
  IF order_length < 2000mm:
    production_length = order_length × 2
    needs_doubling = true
    note = "Produce at 3000mm, cut to 1500mm × 2 pcs"
  ELSE:
    production_length = order_length
    needs_doubling = false
```

**Example**:
```
Order Item: Roof 30MM, 1500mm length, 3 nos
Calculation:
  1500mm < 2000mm → DOUBLE
  Production: 3000mm × 2 panels → CUT → 1500mm × 2 pcs
  Total panels: 4 (customer gets 3, 1 is wastage)

Display:
  ⚠️ *Order: 1500mm → Produce at 3000mm → Cut to 1500mm × 2 pcs
```

**API**:
```
GET    /api/v1/production/cutting-schedules
POST   /api/v1/production/cutting-schedules
GET    /api/v1/production/cutting-schedules/{id}
POST   /api/v1/production/cutting-schedules/{id}/items/{item_id}/mark-cut
GET    /api/v1/production/cutting-schedules/{id}/print-sheet
```

### 3.6 Phase 3 Deliverables Checklist

```
API:
☐ Order CRUD endpoints
☐ Order status tracking
☐ Batch CRUD
☐ Stage completion with logging
☐ QC log creation
☐ Cutting schedule generation
☐ Mark-cut workflow

Database:
☐ orders table
☐ order_items table
☐ production_batches table
☐ production_stage_logs table
☐ production_stages_config table
☐ cutting_schedules table
☐ cutting_schedule_items table
☐ quality_logs table

Frontend:
☐ Order list + detail page
☐ Batch creation form
☐ Stage progress tracker (UI)
☐ Stage completion form (with checkl lists + parameters)
☐ QC entry form
☐ Cutting schedule view
☐ Print cutting sheet

Validations:
☐ Doubling logic for < 2000mm
☐ Stage order enforcement
☐ Mandatory stage blocking
☐ Parameter range validation
☐ QC tolerance checks

Documentation:
☐ Doubling rule documented with examples
☐ Stage configuration guide
☐ QC workflow documented
```

**Completion Criteria**:
- ✅ Doubling logic correct (test with real dimensions)
- ✅ Stages complete in order (cannot skip mandatory)
- ✅ QC pass/fail/marginal handled correctly
- ✅ Cutting schedule generates correctly
- ✅ Tests > 85% coverage
- ✅ Performance: Batch list < 500ms

---

## 6. Phase 4 — Inventory & Dispatch

**Duration**: 3-4 weeks | **Team**: 1-2 (Backend) + 1 (Frontend)

### 4.1 Coil Stock Management

**API**:
```
GET    /api/v1/inventory/coils
POST   /api/v1/inventory/coils
GET    /api/v1/inventory/coils/{id}
PUT    /api/v1/inventory/coils/{id}
GET    /api/v1/inventory/coils/available  (filter by color/thickness)
```

**Coil Entry**:
```json
{
  "coil_tag_no": "COIL-2025-045",
  "supplier_id": 1,
  "material_type": "ppgi",
  "thickness_mm": 0.40,
  "width_mm": 1000,
  "color_code": "9002",
  "color_name": "Off White",
  "total_weight_kg": 1200,
  "purchase_rate_kg": 95.50,
  "warehouse_location": "Bay A, Rack 2",
  "received_date": "2025-06-01",
  "purchase_invoice_no": "SI/2025/1234",
  "minimum_alert_kg": 100
}
```

### 4.2 Chemical Stock (with Expiry)

**API**:
```
GET    /api/v1/inventory/chemicals
POST   /api/v1/inventory/chemicals
GET    /api/v1/inventory/chemicals/{id}
GET    /api/v1/inventory/chemicals/expiring  (within 30 days)
```

**Alerts**:
```
30 days before expiry → Alert Store Manager
7 days before → URGENT alert
Expired → Status: EXPIRED, blocked from production
```

### 4.3 Stock Transactions (Immutable Log)

**API**:
```
GET    /api/v1/inventory/transactions                List all
POST   /api/v1/inventory/transactions/adjustment     Create adjustment
GET    /api/v1/inventory/low-stock                   Alert summary
```

**Transaction Types**:
```
purchase_in       → Increases stock
production_consume → Decreases (linked to batch)
wastage           → Decreases (linked to batch)
return_to_supplier → Decreases
adjustment_in     → Manual increase (requires reason)
adjustment_out    → Manual decrease (requires reason)
```

**Rules**:
```
✅ Transactions IMMUTABLE (no edit, no delete)
✅ Corrections via new adjustment entry only
✅ Cannot go below zero (warns, allows with reason)
✅ Each transaction has: user, timestamp, IP, reason
```

### 4.4 Dispatch Management

**API**:
```
GET    /api/v1/dispatches
POST   /api/v1/dispatches
GET    /api/v1/dispatches/{id}
PUT    /api/v1/dispatches/{id}
POST   /api/v1/dispatches/{id}/mark-delivered
POST   /api/v1/dispatches/{id}/upload-delivery-photos
GET    /api/v1/dispatches/{id}/challan-pdf
```

**Dispatch Entry**:
```json
{
  "order_id": 22,
  "customer_id": 5,
  "dispatch_date": "2025-06-11",
  "delivery_address": "Customer's warehouse",
  "vehicle_no": "MH-01-AB-1234",
  "driver_name": "Rajesh",
  "driver_phone": "9876543210",
  "transporter_name": "XYZ Logistics",
  "lr_no": "LR123456",
  "eway_bill_no": "12345678901234",
  "total_panels": 85,
  "total_sqm": 312.93,
  "freight_amount": 5000,
  "freight_paid_by": "customer",
  "is_partial": false
}
```

### 4.5 Challan PDF

**Deliverable**: Print-friendly dispatch document

```
┌────────────────────────────────────┐
│ [Logo]    DISPATCH CHALLAN  Date   │
├────────────────────────────────────┤
│ Dispatch No: DISP-2025-015         │
│ Order No: ORD-2025-022             │
│ Customer: Kwality Business         │
├────────────────────────────────────┤
│ Sr | Length | Width | Nos | SQM   │
│  1 |  3660  | 1000  | 60  | 219.6 │
│  2 |  3355  | 1000  | 24  | 80.52 │
│    | Total  |       | 87  | 312.93│
├────────────────────────────────────┤
│ Vehicle: MH-01-AB-1234             │
│ Driver: Rajesh | 9876543210        │
│ E-way Bill: 12345678901234         │
└────────────────────────────────────┘
```

### 4.6 Phase 4 Deliverables Checklist

```
API:
☐ Coil CRUD endpoints
☐ Chemical CRUD + expiry alerts
☐ Stock transactions (immutable)
☐ Dispatch CRUD
☐ Challan PDF generation
☐ Low stock alert

Database:
☐ coil_stock table
☐ chemical_stock table
☐ inventory_transactions table
☐ dispatches table
☐ dispatch_items table

Frontend:
☐ Coil inventory list + add form
☐ Chemical inventory with expiry indicators
☐ Transaction log (view-only)
☐ Dispatch list + create form
☐ Challan PDF preview + download
☐ Low stock alerts dashboard

Validation:
☐ Stock cannot go negative (unless reason provided)
☐ Dispatch only if order ready
☐ E-way bill validation (if interstate + > ₹50k)
☐ Transaction immutability enforced

Documentation:
☐ Inventory transaction types documented
☐ Stock alert rules documented
☐ Dispatch workflow documented
```

**Completion Criteria**:
- ✅ All transactions logged correctly
- ✅ Stock calculations accurate
- ✅ E-way bill integration ready
- ✅ Tests > 85% coverage

---

## 7. Phase 5 — Accounting & GST

**Duration**: 3-4 weeks | **Team**: 1 (Backend) + 1 (Frontend)

### 7.1 Invoice Generation

**API**:
```
POST   /api/v1/invoices
{
  "invoice_type": "tax_invoice",
  "order_id": 22,
  "dispatch_id": 15,
  "customer_id": 5,
  "invoice_date": "2025-06-11",
  "due_date": "2025-06-13",
  "is_intrastate": false
}

Response:
{
  "success": true,
  "data": {
    "invoice_id": 28,
    "invoice_no": "INV-2025-028"
  }
}
```

### 7.2 GST Calculation (Complex State Logic)

**Logic**:
```php
function determineGSTType($companyGSTIN, $customerStateCode) {
    $companyStateCode = substr($companyGSTIN, 0, 2);  // 24AAFFU... → 24 (GJ)
    
    if ($companyStateCode === $customerStateCode) {
        return 'intrastate';  // CGST 9% + SGST 9%
    }
    return 'interstate';      // IGST 18%
}

// State codes
24 → Gujarat | 27 → Maharashtra | 08 → Rajasthan | 09 → UP | 07 → Delhi | 29 → Karnataka | 33 → Tamil Nadu
```

### 7.3 Invoice PDF (Tax Invoice vs Proforma)

**Proforma Invoice**:
- NOT a legal GST document
- Used for advance collection
- Same format as BOQ
- Not sequential numbered

**Tax Invoice**:
- Legal GST document
- Sequential numbering mandatory (INV-2025-001)
- Resets April 1 (financial year)
- Must include: HSN codes, GST rates, place of supply
- If e-invoice applicable: IRN + QR code

### 7.4 Payment Recording

**API**:
```
POST /api/v1/payments
{
  "customer_id": 5,
  "invoice_id": 28,
  "payment_date": "2025-06-12",
  "amount": 220118,
  "mode": "neft",
  "reference_no": "UTR202506121234567",
  "is_advance": false
}
```

**Cheque Handling**:
```
{
  "mode": "cheque",
  "cheque_no": "123456",
  "cheque_date": "2025-06-15",
  "bank_name": "SBI",
  "cheque_status": "pending"  // pending → deposited → cleared / bounced
}

Alerts:
- 3 days before cheque date: "Deposit cheque from Kwality | ₹220,118"
- If bounced: Payment reversal, outstanding restored
```

### 7.5 Outstanding Tracking

**API**:
```
GET /api/v1/payments/outstanding-report

Response:
{
  "total_outstanding": 850000,
  "aging": {
    "0_30_days": 420000,
    "31_60_days": 280000,
    "61_90_days": 100000,
    "90_plus_days": 50000
  },
  "customers": [
    {
      "customer_id": 5,
      "customer_name": "Kwality Business",
      "billed": 1200000,
      "paid": 927765,
      "outstanding": 272235,
      "oldest_invoice": "INV-2025-028",
      "days_overdue": 195
    }
  ]
}
```

**Auto Reminders**:
```
Day 30: First WhatsApp reminder
Day 45: Second reminder
Day 60: Third reminder + Owner alert
Day 90+: Daily alert to Owner
```

### 7.6 GST Compliance & GSTR-1

**API**:
```
GET /api/v1/invoices/gst-summary?month=6&year=2025

Response:
{
  "period": "June 2025",
  "section_b2b": [
    {
      "customer_name": "Kwality Business",
      "customer_gstin": "27AAFFU...",
      "invoice_no": "INV-028",
      "invoice_date": "2025-06-11",
      "taxable_value": 357827,
      "igst": 64409,
      "cgst": 0,
      "sgst": 0
    }
  ],
  "hsn_summary": [
    {
      "hsn_code": "39259010",
      "description": "PUF Panels",
      "uom": "SQM",
      "qty": 1250.5,
      "taxable_value": 1500000,
      "tax": 270000
    }
  ],
  "exports": {
    "json_for_portal": "...",
    "excel": "gst_summary_jun2025.xlsx"
  }
}
```

### 7.7 Credit & Debit Notes

**API**:
```
POST /api/v1/invoices/{id}/credit-note
{
  "reason": "Rate correction",
  "amount": 5000,
  "gst_amount": 900
}

POST /api/v1/invoices/{id}/debit-note
{
  "reason": "Additional charge",
  "amount": 2000,
  "gst_amount": 360
}
```

### 7.8 E-Invoice (IRN) Integration

**When Applicable**:
```
Company turnover > ₹5 crore
For: Tax Invoices only
NOT for: Proforma invoices
```

**Flow**:
```
1. Create tax invoice (DRAFT)
2. Validate mandatory fields (buyer GSTIN, HSN codes, etc.)
3. POST to IRP (Invoice Registration Portal) API
4. IRP returns: IRN (64-char hash), ACK number, QR code
5. Store IRN + QR in database
6. Print on PDF
```

**API**:
```
POST /api/v1/invoices/{id}/e-invoice

Response:
{
  "success": true,
  "data": {
    "irn": "abc123...xyz789",
    "ack_no": "11234567890",
    "ack_date": "2025-06-11T10:30:00Z",
    "qr_code": "image_base64_data"
  }
}
```

### 7.9 Phase 5 Deliverables Checklist

```
API:
☐ Invoice CRUD endpoints
☐ GST calculation (intra/inter state)
☐ Payment recording
☐ Cheque tracking
☐ Outstanding report
☐ Credit/debit notes
☐ E-invoice (IRN) generation
☐ GSTR-1 export

Database:
☐ invoices table
☐ invoice_items table
☐ payments table
☐ credit_debit_notes table
☐ payment_reminders table (for scheduling)

Frontend:
☐ Invoice creation form
☐ Invoice detail + PDF preview
☐ Payment entry form
☐ Cheque tracking
☐ Outstanding aging report
☐ GST summary dashboard
☐ GSTR-1 export button

Validations:
☐ Invoice number unique + sequential
☐ GST state detection correct
☐ Payment allocation logic
☐ Cheque date validation (future dates allowed for post-dated)

Documentation:
☐ GST calculation documented (with examples)
☐ Invoice workflow documented
☐ E-invoice API integration guide
☐ Outstanding tracking rules documented
```

**Completion Criteria**:
- ✅ GST matches actual invoices (test with real data)
- ✅ Invoice numbering sequential per year
- ✅ E-invoice optional (can be enabled/disabled)
- ✅ Tests > 85% coverage

---

## 8. Phase 6 — Frontend & UX

**Duration**: 4-5 weeks | **Team**: 2 (Frontend)

### 8.1 Vue 3 SPA Architecture

```
src/
├── components/
│   ├── Common/
│   │   ├── Sidebar.vue
│   │   ├── Topbar.vue
│   │   ├── StatusBadge.vue
│   │   └── AppLayout.vue
│   ├── Forms/
│   │   ├── DynamicField.vue
│   │   ├── FormBuilder.vue
│   │   └── DatePicker.vue
│   └── Tables/
│       ├── DataTableQuotations.vue
│       ├── DataTableOrders.vue
│       └── DataTableInvoices.vue
├── pages/
│   ├── BOQ/
│   │   ├── List.vue
│   │   ├── Create.vue
│   │   ├── Edit.vue
│   │   └── Detail.vue
│   ├── Orders/
│   ├── Production/
│   ├── Inventory/
│   ├── Dispatch/
│   ├── Accounts/
│   └── Reports/
├── stores/  (Pinia)
│   ├── authStore.js
│   ├── boqStore.js
│   ├── orderStore.js
│   └── uiStore.js
├── composables/
│   ├── useAPI.js
│   ├── useFormConfig.js
│   ├── useToast.js
│   └── useConfirm.js
├── router/
│   └── index.js
├── styles/
│   ├── main.css
│   ├── colors.css
│   └── components.css
└── main.js
```

### 8.2 Design System Implementation

**Color Tokens**:
```css
/* src/styles/colors.css */
:root {
  --color-primary: #1a237e;
  --color-primary-light: #3949ab;
  --color-accent: #f57f17;
  --color-success: #2e7d32;
  --color-warning: #e65100;
  --color-danger: #c62828;
  --color-bg: #f5f6fa;
  --color-surface: #ffffff;
  --color-text-primary: #212121;
  --color-text-secondary: #616161;
  --color-border: #e0e0e0;
}
```

**Tailwind Config**:
```js
// tailwind.config.js
export default {
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#1a237e',
          light: '#3949ab',
          dark: '#0d1257',
        },
        accent: {
          DEFAULT: '#f57f17',
          light: '#ffb300',
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['Roboto Mono', 'monospace'],
      }
    }
  }
}
```

### 8.3 Key Pages & Components

**Dashboard**:
```vue
<template>
  <div class="grid grid-cols-4 gap-4 mb-6">
    <KPICard
      label="Today's Production"
      value="1,120"
      unit="SQM"
      icon="pi-chart-line"
      trend="+12%"
    />
    <KPICard label="In Production" value="2" unit="batches" />
    <KPICard label="Ready Dispatch" value="3" unit="orders" />
    <KPICard
      label="Overdue"
      value="2"
      unit="invoices"
      severity="danger"
    />
  </div>
  <ActiveBatches />
  <Alerts />
</template>
```

**BOQ List**:
```vue
<template>
  <DataTable
    :value="quotations"
    :paginator="true"
    :rows="20"
    sort-mode="multiple"
    filter-display="row"
  >
    <Column field="quotation_no" header="BOQ No" sortable />
    <Column field="customer_name" header="Customer" />
    <Column field="grand_total" header="Amount">
      <template #body="{ data }">
        ₹{{ formatCurrency(data.grand_total) }}
      </template>
    </Column>
    <Column field="status" header="Status">
      <template #body="{ data }">
        <StatusBadge :status="data.status" type="quotation" />
      </template>
    </Column>
    <Column header="Actions">
      <template #body="{ data }">
        <Button icon="pi-eye" size="small" text @click="view(data.id)" />
        <Button icon="pi-pencil" size="small" text @click="edit(data.id)" />
        <Button icon="pi-file-pdf" size="small" text @click="downloadPDF(data.id)" />
      </template>
    </Column>
  </DataTable>
</template>
```

**BOQ Create**:
```vue
<template>
  <Stepper>
    <!-- Step 1: Header -->
    <StepperPanel header="Header">
      <div class="grid grid-cols-2 gap-4">
        <Dropdown v-model="form.customer_id" :options="customers" />
        <InputText v-model="form.project_name" />
      </div>
    </StepperPanel>

    <!-- Step 2: Panel Items -->
    <StepperPanel header="Panels">
      <PanelItemTable v-model="form.panel_items" />
    </StepperPanel>

    <!-- Step 3: Accessories -->
    <StepperPanel header="Accessories">
      <AccessoryTable v-model="form.accessory_items" />
    </StepperPanel>

    <!-- Step 4: Summary -->
    <StepperPanel header="Summary">
      <SummaryCard :form="form" />
      <Button label="Save & Preview" @click="saveAndPreview" />
    </StepperPanel>
  </Stepper>
</template>
```

### 8.4 Form Validations

**Client-side** (UX feedback):
```js
const errors = reactive({})

function validateForm() {
  errors.value = {}
  if (!form.customer_id) errors.customer_id = ['Customer is required']
  if (form.panel_items.length === 0) errors.panel_items = ['Add at least 1 panel']
  return Object.keys(errors).length === 0
}
```

**Server-side** (Security):
```php
public function store(StoreQuotationRequest $request) {
    // Laravel automatically validates against rules in StoreQuotationRequest
    $validated = $request->validated();
    return Quotation::create($validated);
}
```

### 8.5 Real-time Calculations

```js
// composables/useBOQCalculations.js
export function useBOQCalculations(form) {
  const summary = reactive({
    panelSubtotal: 0,
    accessorySubtotal: 0,
    subtotal: 0,
    discount: 0,
    taxable: 0,
    cgst: 0,
    sgst: 0,
    igst: 0,
    totalGST: 0,
    transportation: 0,
    grandTotal: 0,
    advance: 0,
    balance: 0
  })

  watch(() => [form.panel_items, form.accessory_items, form.discount_pct], () => {
    summary.panelSubtotal = form.panel_items.reduce((sum, item) => sum + item.amount, 0)
    summary.accessorySubtotal = form.accessory_items.reduce((sum, item) => sum + item.amount, 0)
    summary.subtotal = summary.panelSubtotal + summary.accessorySubtotal
    summary.discount = summary.subtotal * (form.discount_pct / 100)
    summary.taxable = summary.subtotal - summary.discount

    if (form.is_intrastate) {
      summary.cgst = summary.taxable * 0.09
      summary.sgst = summary.taxable * 0.09
      summary.totalGST = summary.cgst + summary.sgst
    } else {
      summary.igst = summary.taxable * 0.18
      summary.totalGST = summary.igst
    }

    summary.grandTotal = summary.taxable + summary.totalGST + form.transportation_amount
    summary.advance = summary.grandTotal * 0.50
    summary.balance = summary.grandTotal - summary.advance
  }, { deep: true })

  return { summary }
}
```

### 8.6 Responsive Design

**Tailwind Breakpoints**:
```
mobile:   < 640px   (not primary, Phase 2)
tablet:   640-1024px (sidebar collapses, forms stack)
desktop:  > 1024px   (primary target)
```

**Responsive Grid**:
```vue
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
  <!-- 1 col on mobile, 2 on tablet, 4 on desktop -->
</div>
```

### 8.7 Phase 6 Deliverables Checklist

```
Components:
☐ Sidebar navigation
☐ Topbar with search + notifications + user menu
☐ StatusBadge (all status types)
☐ DataTable (quotations, orders, invoices)
☐ Forms with inline validation
☐ Modal dialogs (edit, confirm, preview)
☐ Toast notifications
☐ Loading spinners + skeletons
☐ Empty states

Pages:
☐ Dashboard with KPI cards
☐ BOQ list, create, edit, detail
☐ Order list, detail
☐ Production batches list, detail
☐ Inventory dashboards
☐ Dispatch list, create
☐ Invoice list, detail
☐ Payment entry
☐ Reports (various)

Styling:
☐ PrimeVue theme customized
☐ Tailwind utilities applied
☐ Responsive (desktop, tablet, mobile)
☐ Dark mode ready (optional Phase 2)
☐ Accessible (ARIA labels, keyboard nav)

Validations:
☐ Client-side form validation
☐ Error messages helpful
☐ Disabled buttons while loading
☐ Confirm dialogs before delete
```

**Completion Criteria**:
- ✅ All pages responsive (test on mobile, tablet, desktop)
- ✅ No console errors/warnings
- ✅ Accessible (tested with keyboard navigation)
- ✅ Lighthouse score > 90
- ✅ Performance: Page load < 2s, interactions < 100ms

---

## 9. Phase 7 — Admin & Configuration

**Duration**: 3-4 weeks | **Team**: 1 (Backend) + 1 (Frontend)

### 9.1 Super Admin Portal

**Separate subdomain**: admin.panelosapp.com

**Features**:
```
✅ 2FA login (TOTP via Google Authenticator)
✅ IP whitelist (office IP + VPN only)
✅ Companies list + create
✅ Module enable/disable per company
✅ Settings per module
✅ Audit logs view
✅ Impersonation (login AS client user)
✅ System health monitor
```

### 9.2 Form Builder (Dynamic Fields)

**Available Forms**:
```
✅ boq_header        (quotation header)
✅ panel_row         (each panel line item)
✅ customer          (customer create/edit)
✅ order             (order creation)
✅ dispatch          (dispatch creation)
✅ invoice           (invoice creation)
✅ production_batch  (batch creation)
✅ quality_log       (QC entry)
```

**Field Configuration**:
```
✅ Show/hide on screen
✅ Mark required/optional
✅ Show/hide on PDF
✅ Change label
✅ Set placeholder, help text
✅ Set default value
✅ Reorder (drag-drop)
✅ Add conditional logic
✅ Validation rules (min/max)
```

**Custom Field Types**:
```
✅ text, textarea, number, decimal, date, datetime
✅ checkbox, dropdown, multi_select, file
✅ phone, email, url
```

**Conditional Logic**:
```js
{
  "show_if": {
    "field": "panel_category",
    "operator": "equals",
    "value": "cold_room"
  }
}

// OR logic:
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

### 9.3 Module Configuration per Company

**Example: BOQ Module Settings**:
```
Quotation Prefix:           SCP
Default Validity Days:      10
Allow Price Override:       YES
Max Override % below:       15%
Override Requires Approval: NO
Show Bank Details on PDF:   YES
Show BOQ Sheet (Page 3):    YES
Authorized Signatory:       Pratik Patel
Payment Terms:              50% advance, 50% before dispatch
Terms & Conditions:         [multiline text]
Exclusions:                 [list]
Wastage Note:               [text]
```

**Example: Production Module Settings**:
```
Min Production Length:      2000 mm
Priority Method:            Delivery Date (FIFO / Manual)
Day Shift:                  07:00 - 19:00
Night Shift:                19:00 - 07:00
Max Rejection Rate Alert:   5.0%
Thickness Tolerance:        ±2.0 mm
Density Tolerance:          ±2.0 kg/m³
Default Polyol Ratio:       55%
Default Isocyanate Ratio:   45%
```

### 9.4 Notification Configuration

**All 14+ events configurable**:
```
order_confirmed         → Channel: WhatsApp    → Recipients: Owner, Production
quotation_accepted      → Channel: WhatsApp+Email → Recipients: Owner, Sales
production_started      → Channel: WhatsApp    → Recipients: Owner
stage_completed         → Channel: In-App      → Recipients: Supervisor
qc_failed               → Channel: WhatsApp    → Recipients: Owner, Supervisor
batch_completed         → Channel: WhatsApp    → Recipients: Owner, Sales
dispatch_done           → Channel: WhatsApp+Email → Recipients: Customer, Owner
payment_received        → Channel: WhatsApp    → Recipients: Owner, Accountant
payment_overdue_30      → Channel: WhatsApp    → Recipients: Owner
stock_low               → Channel: WhatsApp    → Recipients: Owner, Store
stockout                → Channel: WhatsApp URGENT → Recipients: Owner
chemical_expiring       → Channel: WhatsApp    → Recipients: Store Manager
cheque_due              → Channel: WhatsApp    → Recipients: Accountant
machine_breakdown       → Channel: WhatsApp URGENT → Recipients: Owner, Supervisor
```

**Template Variables**:
```
{company_name}   {order_no}      {customer_name}
{amount}         {due_date}      {balance_due}
{panel_type}     {sqm}           {stage_name}
{dispatch_date}  {vehicle_no}    {quotation_no}
{validity_date}  {batch_no}      {material_name}
{remaining_qty}  {minimum_qty}
```

### 9.5 Impersonation (Login AS Client User)

**How It Works**:
```
1. Super admin visits client company detail page
2. Clicks [View As Client]
3. Selects user (Owner, Sales Manager, Production Supervisor, etc.)
4. Enters reason ("Support call", "Debug issue", etc.)
5. Redirected to signature.panelosapp.com logged in as selected user
6. Banner shows: "🔴 IMPERSONATING: Pratik Patel @ Signature PUF"
7. Can view/edit/create records
8. CANNOT delete, change subscription, access super admin

All actions logged:
├─ User who impersonated
├─ User impersonated
├─ Reason
├─ Duration
├─ All actions taken
├─ IP address
```

### 9.6 Audit Logs

**What Gets Logged**:
```
Auth:       Login, logout, failed login, password change
BOQ:        Create, edit, delete, send, accept, reject, revise
Orders:     Create, edit, cancel, status change
Production: Batch create, stage complete, QC log
Inventory:  Stock in/out, adjustment
Dispatch:   Create, mark delivered
Invoice:    Create, finalize, send
Payment:    Record, update, reverse
Settings:   ANY settings change
Super Admin:All impersonation + all actions
```

**UI**:
```
Filters: Company | Action | User | Date Range

Table:
Timestamp    | Company    | User   | Action  | Details
10-Jun 14:32 | Signature  | Pratik | Created | BOQ SCP-028
10-Jun 14:15 | Signature  | SuperA | Imperson| As Pratik P
10-Jun 12:45 | Signature  | Rahul  | Updated | Order ORD-022
```

**Retention**: 7 years (GST compliance)

### 9.7 System Health Dashboard

```
┌──────────────────────────────────────┐
│ Companies: 1    Users: 8    Orders: 45│
├──────────────────────────────────────┤
│ API Server:   Online | 45ms response  │
│ Database:     Online | 234 queries/min│
│ Redis:        Online | 89% hit rate   │
│ Queue Worker: Online | 2 pending jobs │
│ WhatsApp API: Online | Sent 5 min ago │
├──────────────────────────────────────┤
│ RECENT ERRORS:                        │
│ ⚠️ 10-Jun 12:45 | PDF generation timeout │
├──────────────────────────────────────┤
│ QUEUE:                                │
│ PDF: SCP-2025-028 (30s old)           │
│ WhatsApp: Order confirmation (1m old) │
└──────────────────────────────────────┘
```

### 9.8 Phase 7 Deliverables Checklist

```
Super Admin API:
☐ Companies CRUD
☐ Module enable/disable
☐ Settings per module
☐ Impersonation endpoint
☐ Audit logs API
☐ Health check endpoint

Form Builder API:
☐ Form configurations list
☐ Field CRUD
☐ Conditional logic save/validate
☐ Field reorder
☐ Custom field creation

Super Admin Frontend:
☐ Companies list + create
☐ Module toggle UI
☐ Settings forms (per module)
☐ View as client button + user selection
☐ Audit logs table
☐ Health dashboard
☐ 2FA setup (QR code display)

Form Builder Frontend:
☐ Fields list with drag-drop reorder
☐ Add custom field form
☐ Edit field properties (visibility, required, etc.)
☐ Conditional logic builder UI
☐ Toggle buttons (quick-enable/disable)
☐ Form preview

Documentation:
☐ Admin setup guide
☐ Impersonation workflow
☐ Audit log retention policy
☐ 2FA setup instructions
```

**Completion Criteria**:
- ✅ Super admin features secure (2FA, IP whitelist)
- ✅ Impersonation fully logged and audited
- ✅ Form builder works (custom fields appear on forms + PDFs)
- ✅ All 14+ notifications configurable
- ✅ Tests > 80% coverage

---

## 10. Phase 8 — Integration & Optimization

**Duration**: 2-3 weeks | **Team**: 1 (Backend) + 1 (DevOps)

### 10.1 WhatsApp & Email Notifications

**WhatsApp API Integration**:
```
Provider: 2Factor / Twilio / WATI
Queue: Laravel Queue (Redis)
Template system: Dynamic messages with variables

Examples:
"Order confirmed: ORD-2025-022 | Expected delivery: 20-Jun | Customer: Kwality"
"Stock low: PPGI 0.40mm OW | Remaining: 45kg (min: 100kg)"
"Invoice overdue 30+ days: INV-028 | Outstanding: ₹2,72,236"
```

**Email** (Gmail SMTP):
```
To: customer@kwality.com
Subject: Your order is dispatched | ORD-2025-022
Body: HTML template with order details + delivery info
Attachment: Challan PDF
```

**Queue Processing**:
```
php artisan queue:work --queue=default,notifications --sleep=3
```

### 10.2 Caching Strategy

**What to Cache**:
```
✅ Panel types + options (30 min)
✅ Pricing rules (60 min)
✅ Color master (60 min)
✅ Company settings (30 min)
✅ User permissions (15 min)
✅ Quotation list (10 min, invalidate on create/update)
```

**Implementation**:
```php
$panelTypes = Cache::remember(
    'panel_types.company_' . auth()->user()->company_id,
    now()->addMinutes(30),
    fn() => PanelType::where('company_id', auth()->user()->company_id)->get()
);
```

### 10.3 Performance Optimization

**Database**:
```sql
✅ Indexes on frequently filtered columns (company_id, status, customer_id, etc.)
✅ Composite indexes (company_id, created_at)
✅ Avoid N+1 queries (use ->with() for eager loading)
✅ Pagination for all list endpoints
```

**API Response**:
```php
// Only select needed columns
$quotations = Quotation::select('id', 'quotation_no', 'customer_id', 'grand_total', 'status')
    ->with(['customer:id,name'])
    ->paginate(20);
```

**Frontend**:
```
✅ Code splitting (lazy-load routes)
✅ Image optimization (resize, compress)
✅ Asset minification (webpack/vite)
✅ Gzip compression (server config)
```

### 10.4 Load Testing

**Tools**: Apache JMeter or k6

**Scenarios**:
```
✅ 100 concurrent users creating BOQs
✅ 500 concurrent users viewing quotation list
✅ PDF generation under load
✅ Database under 1000 queries/second
✅ Queue processing 100 notifications/min
```

**Acceptance Criteria**:
```
✅ No timeout errors
✅ P95 response time < 2 seconds
✅ Database CPU < 70%
✅ Memory stable (no leaks)
```

### 10.5 Security Hardening

**Input Validation**:
```
✅ All user input validated
✅ SQL injection prevention (Eloquent ORM)
✅ XSS prevention (Vue 3 escaping)
✅ CSRF tokens (Sanctum)
```

**Rate Limiting**:
```php
Route::middleware('throttle:60,1')->post('/api/v1/auth/login', ...);
Route::middleware('throttle:1000,1')->group(function () {
    Route::get('/api/v1/quotations', ...);
});
```

**Encryption**:
```
✅ HTTPS only (SSL certificate)
✅ Sensitive fields encrypted at rest (passwords, tokens)
✅ Secrets in .env (not git)
✅ API tokens rotated regularly
```

**Headers**:
```
X-Frame-Options: DENY (prevent clickjacking)
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000
```

### 10.6 Monitoring & Logging

**Application Logs**:
```
storage/logs/laravel.log

Levels: debug | info | notice | warning | error | critical | alert | emergency

Examples:
[2025-06-10 10:30:45] production.INFO: Quotation created SCP-2025-028 by user 1
[2025-06-10 10:32:12] production.ERROR: PDF generation failed for quotation 45
```

**Error Tracking**:
```
Service: Sentry (optional Phase 2)
Captures: Exceptions, errors, stack traces
Alerts: Slack notification on critical errors
```

### 10.7 Database Backup & Recovery

**Automated Backups**:
```bash
# Daily at 2 AM
mysqldump panelos | gzip > /backups/db_$(date +%Y-%m-%d).sql.gz

# Upload to S3
aws s3 cp /backups/db_$(date +%Y-%m-%d).sql.gz s3://panelos-backups/db/
```

**Recovery Procedure**:
```bash
# Restore from backup
zcat /backups/db_2025-06-10.sql.gz | mysql panelos
```

### 10.8 Phase 8 Deliverables Checklist

```
Notifications:
☐ WhatsApp API integration
☐ Email integration
☐ Queue worker configured
☐ All 14+ notification events sending

Caching:
☐ Redis configured
☐ Cache keys documented
☐ Cache invalidation on updates
☐ Cache hit rate > 80%

Performance:
☐ No N+1 queries
☐ Database indexes created
☐ Pagination on all lists
☐ Load test passed (100+ concurrent users)
☐ P95 response time < 2s

Security:
☐ Input validation everywhere
☐ Rate limiting configured
☐ SSL certificate installed
☐ Security headers set
☐ No sensitive data in logs
☐ API tokens rotated

Monitoring:
☐ Application logs configured
☐ Error logging working
☐ Health check endpoint
☐ Database monitoring
☐ Queue monitoring

Backup:
☐ Automated daily backups
☐ S3 upload working
☐ Recovery tested
☐ Retention policy (30 days minimum)
```

**Completion Criteria**:
- ✅ Load test: 100+ concurrent users, no errors
- ✅ Response time: P95 < 2 seconds
- ✅ Security: OWASP top 10 checked
- ✅ Backups: Verified restorable
- ✅ All errors logged + monitored

---

## 11. Quality Assurance Framework

### Test Coverage Strategy

```
Unit Tests:        80%+ coverage
├─ Models
├─ Services
├─ Helpers

Integration Tests: Critical paths 100%
├─ BOQ creation → PDF → Acceptance → Order
├─ Order → Production → Dispatch → Invoice
├─ Payment recording → Outstanding

Feature Tests:     All user workflows
├─ BOQ workflow
├─ Production workflow
├─ Dispatch workflow
└─ Accounting workflow

Performance Tests: Critical operations
├─ PDF generation (< 5s)
├─ Large list retrieval (< 500ms)
├─ Pricing calculation (< 100ms)

Security Tests:    OWASP top 10
├─ SQL injection
├─ XSS
├─ CSRF
├─ Authentication bypass
└─ Authorization bypass
```

### Test Execution

```bash
# All tests
php artisan test

# With coverage
php artisan test --coverage

# Specific test file
php artisan test tests/Feature/BOQ/CreateQuotationTest.php

# Watch mode (dev)
php artisan test --watch
```

### Code Quality Tools

```bash
# PSR-12 style check
./vendor/bin/php-cs-fixer fix

# Static analysis
./vendor/bin/phpstan analyse

# Security audit
composer audit

# Frontend linting
npm run lint

# Frontend tests
npm run test:unit
```

### Manual Testing Checklist

Before each phase completion:
```
☐ All workflows tested with real data
☐ Edge cases tested (min/max values, special characters)
☐ Error scenarios tested (network timeout, validation error)
☐ Responsive design tested (mobile, tablet, desktop)
☐ Accessibility tested (keyboard navigation, screen reader)
☐ Performance verified (no timeout, no slowness)
☐ Browser compatibility (Chrome, Firefox, Safari, Edge)
```

---

## 12. Deployment Strategy

### Environment Hierarchy

```
Development (Local)
  ↓ (merge to feature branch)
Staging (Pre-production replica)
  ↓ (merge to main)
Production (Live)
```

### CI/CD Pipeline (GitHub Actions)

```yaml
# .github/workflows/tests.yml
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - Checkout
      - Setup PHP 8.2
      - Install dependencies
      - Create .env.testing
      - Run migrations
      - Run tests (PHPUnit)
      - Run linting (php-cs-fixer)
      - Run static analysis (phpstan)
      - Report coverage

  deploy-staging:
    needs: test
    if: github.ref == 'refs/heads/develop'
    steps:
      - Checkout
      - SSH to staging server
      - Pull latest code
      - composer install --no-dev
      - php artisan migrate
      - php artisan cache:clear
      - Restart queue workers

  deploy-production:
    needs: test
    if: github.ref == 'refs/heads/main'
    steps:
      - Checkout
      - SSH to production server
      - Pull latest code
      - composer install --optimize-autoloader --no-dev
      - php artisan migrate --force
      - php artisan optimize
      - Restart queue workers
      - Smoke test
```

### Deployment Checklist

```
BEFORE DEPLOYMENT:
☐ All tests passing
☐ Code reviewed (2+ approvals)
☐ No breaking changes documented
☐ Database backups taken
☐ Rollback plan ready

DEPLOYMENT STEPS:
☐ Pull latest code
☐ Install dependencies
☐ Run migrations
☐ Clear caches
☐ Restart queue workers
☐ Restart PHP-FPM
☐ Run smoke tests
☐ Monitor error logs

POST-DEPLOYMENT:
☐ Verify all pages load
☐ Test critical workflows (BOQ → Order → Dispatch)
☐ Check API response times
☐ Monitor server resources
☐ Check error logs (should be zero errors)
☐ Notify stakeholders
```

### Rollback Plan

```bash
# If deployment fails:
1. git revert <commit_hash>
2. git push origin main
3. CI/CD automatically redeploys
4. Verify production working
5. Investigate error
6. Fix + test in develop branch
7. Re-deploy
```

---

## 13. Post-Launch Support

### Phase 8.1 — Go-Live Preparation (1-2 weeks)

```
☐ Data migration from old system (if applicable)
☐ User training sessions (2-3 hours each)
☐ Documentation finalized
☐ Support team trained
☐ Help desk setup
☐ Monitoring alerts configured
☐ 24/7 on-call schedule
```

### Phase 8.2 — Soft Launch (1 week)

```
Limited users (Owner + Sales team):
☐ Test with real workflows
☐ Collect feedback
☐ Fix critical bugs
☐ Document workarounds for edge cases
```

### Phase 8.3 — Full Launch (1 day)

```
All users go live:
☐ Morning launch
☐ Support team on-call
☐ Monitor for errors
☐ Quick fixes for issues
☐ Evening retrospective
```

### Phase 8.4 — 30-Day Support

```
Daily:
☐ Monitor error logs
☐ Address user issues within 2 hours
☐ Document feature requests

Weekly:
☐ Retrospective meeting
☐ Prioritize improvements
☐ Plan hot-fixes

Monthly:
☐ Performance review
☐ User feedback analysis
☐ Plan Phase 2 features
```

### Phase 2 Roadmap (Next)

```
After Phase 1-8 complete, next priorities:
1. Mobile app (React Native) — Production supervisor on floor
2. WhatsApp integration — Better than email
3. Dealer portal — Customers check order status
4. Advanced reporting — Predictive analytics
5. Multi-language support
6. Dark mode
7. Offline mode (for floor workers)
```

---

## Summary: 30-36 Week Timeline

| Phase | Duration | Focus | Status |
|---|---|---|---|
| 1 | 3-4 weeks | Infrastructure | Foundation ready |
| 2 | 5-6 weeks | BOQ Module | Sales process working |
| 3 | 4-5 weeks | Production | Manufacturing tracked |
| 4 | 3-4 weeks | Inventory & Dispatch | Logistics ready |
| 5 | 3-4 weeks | Accounting | GST compliant |
| 6 | 4-5 weeks | Frontend | UX complete |
| 7 | 3-4 weeks | Admin & Config | Flexible system |
| 8 | 2-3 weeks | Integration | Production ready |
| **Total** | **30-36 weeks** | **Full MVP** | **Ready to deploy** |

---

**Enterprise-Grade Development Standards Met:**
✅ SOLID principles, Design patterns, Type safety
✅ 80%+ test coverage, Integration testing, Security testing
✅ API documentation, Database schema, Architecture diagrams
✅ Git workflow, PR reviews, Automated CI/CD
✅ Input validation, Encryption, Rate limiting, Audit logging
✅ Database indexing, Redis caching, Query optimization
✅ Responsive design, Accessibility, Performance optimized
✅ Deployment runbooks, Backup/recovery, Monitoring
✅ Post-launch support, Training, Documentation

**You're building enterprise software.** This is not a startup MVP; this is production infrastructure that will power manufacturing operations.

---

*Built with precision. Deployed with confidence. Supported 24/7.*

