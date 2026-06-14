# PanelOS Architecture & Design

## System Architecture

```
┌─────────────────────────────────────────────────────────┐
│                     Client Applications                  │
│              (Web, Mobile, Desktop)                      │
└────────────────────────┬────────────────────────────────┘
                         │ HTTPS
┌────────────────────────▼────────────────────────────────┐
│                    Load Balancer                         │
│              (SSL/TLS Termination)                       │
└────────────────────────┬────────────────────────────────┘
                         │
        ┌────────────────┼────────────────┐
        │                │                │
┌───────▼──────┐ ┌──────▼──────┐ ┌──────▼──────┐
│  Nginx (80)  │ │  Nginx (80) │ │ Nginx (80)  │
│ (Reverse     │ │ (Reverse    │ │ (Reverse    │
│  Proxy)      │ │  Proxy)     │ │  Proxy)     │
└───────┬──────┘ └──────┬──────┘ └──────┬──────┘
        │                │                │
        └────────────────┼────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────┐
│              PHP-FPM Application Pool                     │
│  • Request handling                                       │
│  • Business logic                                         │
│  • Validation                                             │
│  • Authentication/Authorization                          │
└────────────────────────┬──────────────┬──────────────────┘
                         │              │
        ┌────────────────┘              └────────────────┐
        │                                                │
┌───────▼──────────────┐                    ┌────────────▼──┐
│     MySQL 8.0        │                    │    Redis 7     │
│   • Persistent       │                    │  • Cache       │
│     Storage          │                    │  • Queues      │
│   • Multi-tenant     │                    │  • Sessions    │
│     (company_id)     │                    │  • Locks       │
└──────────────────────┘                    └────────────────┘
```

## Layered Architecture

```
┌──────────────────────────────────────┐
│  Presentation Layer (HTTP)           │
│  • Routes                            │
│  • Controllers                       │
│  • Request Validation                │
├──────────────────────────────────────┤
│  Application Layer                   │
│  • Services                          │
│  • DTOs (Data Transfer Objects)      │
│  • Query Builders                    │
├──────────────────────────────────────┤
│  Domain Layer                        │
│  • Models                            │
│  • Business Rules                    │
│  • Validation Rules                  │
├──────────────────────────────────────┤
│  Infrastructure Layer                │
│  • Database (MySQL)                  │
│  • Cache (Redis)                     │
│  • Queue (Redis)                     │
│  • File Storage                      │
└──────────────────────────────────────┘
```

## Directory Structure

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       ├── AuthController.php
│   │   │       └── [Phase 2+]
│   │   ├── Requests/
│   │   ├── Resources/
│   │   └── Middleware/
│   ├── Models/
│   │   ├── BaseModel.php
│   │   ├── Company.php
│   │   ├── User.php
│   │   ├── Role.php
│   │   └── [Domain Models]
│   ├── Services/
│   │   └── [Business Logic]
│   ├── Traits/
│   │   └── ApiResponse.php
│   └── Exceptions/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── routes/
│   ├── api.php
│   └── web.php
├── tests/
│   ├── Feature/
│   ├── Unit/
│   └── Traits/
├── docker/
│   ├── php/
│   ├── nginx/
│   └── mysql/
├── storage/
│   ├── logs/
│   ├── app/
│   └── framework/
├── bootstrap/
├── config/
├── .env
├── docker-compose.yml
├── Dockerfile
└── composer.json
```

## Key Design Patterns

### 1. Multi-Tenancy (company_id)

Every data entity has a `company_id` field for tenant isolation:

```php
// BaseModel automatically scopes queries
class BaseModel extends Model {
    protected static function boot() {
        parent::boot();
        static::addGlobalScope(new CompanyScope);
    }
}

// Automatic tenant isolation
User::all(); // Only returns users for current company
```

**Benefits**:
- Single database for all companies
- Automatic query filtering
- Data isolation enforced at model level
- Cost-effective scaling

### 2. Service Layer Pattern

Business logic separated from controllers:

```php
// Controller (thin)
public function createQuotation(Request $request) {
    $quotation = $this->quotationService->create($request->validated());
    return $this->successResponse($quotation);
}

// Service (business logic)
class QuotationService {
    public function create(array $data) {
        // Validation
        // Calculations
        // Database operations
        // Event dispatching
    }
}
```

**Benefits**:
- Reusable business logic
- Testable independently
- Clear separation of concerns
- Easy to maintain

### 3. Repository Pattern (Future)

Will abstract database access:

```php
interface QuotationRepository {
    public function findById(int $id);
    public function findByStatus(string $status);
    public function save(Quotation $quotation);
}
```

### 4. Event-Driven Architecture

Events trigger actions asynchronously:

```php
// Event
event(new QuotationCreated($quotation));

// Listener
class SendQuotationNotification {
    public function handle(QuotationCreated $event) {
        // Send email/WhatsApp
    }
}
```

### 5. Query Builder Pattern

Type-safe query building:

```php
// Instead of:
$query = "SELECT * FROM quotations WHERE company_id = ?";

// Use:
Quotation::query()
    ->where('status', 'draft')
    ->whereBetween('created_at', [now()->startOfMonth(), now()])
    ->get();
```

## Authentication & Authorization

### Sanctum Token Authentication

```
User Login
    ↓
Generate Personal Access Token
    ↓
Token stored in personal_access_tokens table
    ↓
Client includes: Authorization: Bearer {token}
    ↓
Middleware validates token
    ↓
Request->user() returns authenticated User
    ↓
Route authorization checks role/permission
```

**Token Structure**:
```php
// Token format: ID|HashableToken
// Example: 1|abcdef1234567890

// Token lifecycle:
- Created: login
- Refreshed: refresh-token endpoint
- Deleted: logout
- Expires: never (server-side session)
```

### Role-Based Access Control (RBAC)

```php
// 6 Default Roles
- Super Admin      // Full system access
- Company Admin    // Company-level management
- Sales Manager    // Quotation/order creation
- Production Mgr   // Batch management
- Accounts         // Invoice/payment management
- Viewer           // Read-only access

// Permission structure
$user->hasRole('Super Admin');
$user->hasPermission('quotations.create');
```

## Database Design

### Multi-Tenant Schema

```
companies ──┬── users
            ├── roles
            ├── quotations
            ├── orders
            ├── production_batches
            ├── invoices
            ├── payments
            └── ...
```

**Key Principles**:
- Every table has `company_id` (except companies itself)
- Soft deletes on critical entities
- Foreign key cascades for data integrity
- Indexes on company_id + status/date fields

### Relationships

```
Company
  ├─ hasMany(User)
  ├─ hasMany(Role)
  └─ hasMany(Quotation)

User
  ├─ belongsTo(Company)
  ├─ belongsTo(Role)
  └─ hasMany(PersonalAccessToken)

Role
  ├─ belongsTo(Company)
  └─ hasMany(User)

Quotation
  ├─ belongsTo(Company)
  ├─ belongsTo(Customer)
  └─ hasMany(QuotationItem)
```

## Caching Strategy

### Cache Layers

1. **Query Cache** (Redis)
   - Frequently accessed data
   - TTL: 1 hour
   - Key: `quotations:company:{company_id}`

2. **Configuration Cache**
   - Company settings
   - Tax rates
   - TTL: 24 hours

3. **Computed Cache**
   - Price calculations
   - Summaries
   - TTL: 30 minutes

### Cache Invalidation

```php
// Automatic invalidation on update
class UpdateQuotation {
    public function handle(QuotationUpdated $event) {
        Cache::forget("quotation:{$event->quotation->id}");
    }
}
```

## Queue System

### Asynchronous Tasks (Redis)

```php
// Email sending
event(new QuotationSent($quotation)); // Queued

// PDF generation
Queue::dispatch(new GeneratePDFJob($quotation));

// Notifications
Queue::dispatch(new SendWhatsAppNotification($user, $message));
```

### Queue Configuration

```env
QUEUE_CONNECTION=redis
```

## Error Handling

### Exception Mapping

```php
InvalidCredentialsException → 401 Unauthorized
ValidationException         → 422 Unprocessable Entity
ResourceNotFoundException    → 404 Not Found
InsufficientPermissionEx.   → 403 Forbidden
```

### Error Response Format

```json
{
  "success": false,
  "errors": { "field": ["message"] },
  "message": "Human readable message",
  "error_code": "VALIDATION_ERROR",
  "meta": { "timestamp": "...", "version": "1.0" }
}
```

## Testing Architecture

### Test Types

```
Unit Tests (isolated components)
    ↓
    ├─ Models
    ├─ Services
    └─ Traits

Feature Tests (full request/response)
    ↓
    ├─ Authentication
    ├─ Authorization
    ├─ Business Logic
    └─ Error Cases

Integration Tests (external services)
    ↓
    ├─ Database
    ├─ Cache
    ├─ Queue
    └─ Email
```

### Test Database

- SQLite in-memory (fast)
- RefreshDatabase trait (isolation)
- Factories for test data
- Database state reset per test

## CI/CD Pipeline

### Automated Testing

```
Push Code
    ↓
Run Tests (PHPUnit)
    ↓
Code Quality (PHPStan, PHP-CS-Fixer)
    ↓
Security Scanning
    ↓
Build Docker Image
    ↓
Push to Registry
    ↓
Deploy to Production
```

### Deployment Strategy

```
Main Branch
    ↓
    ├─ Staging Deployment
    │   ├─ Run migrations
    │   ├─ Run tests
    │   └─ Health check
    │
    └─ Production Deployment
        ├─ Zero-downtime deploy
        ├─ Run migrations
        ├─ Warm caches
        └─ Health verification
```

## Security Architecture

### Defense in Depth

```
1. Network Level
   - HTTPS/TLS
   - Firewall rules
   
2. Application Level
   - Input validation
   - SQL injection prevention (Eloquent ORM)
   - XSS prevention (JSON responses)
   
3. Authentication
   - Sanctum tokens
   - Password hashing (bcrypt)
   
4. Authorization
   - Role-based access
   - Policy classes
   
5. Data Level
   - Encryption at rest (future)
   - PII masking in logs
```

### Security Headers (Nginx)

```
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000
```

## Scalability Considerations

### Horizontal Scaling

```
Load Balancer
    ↓
    ├─ App Server 1 ─┐
    ├─ App Server 2  ├─ Shared MySQL + Redis
    └─ App Server 3 ─┘
```

### Database Scaling

- **Read Replicas**: For reporting queries
- **Sharding**: By company_id (future)
- **Connection Pooling**: Via Redis

### Caching Optimization

- Cache frequently accessed queries
- Implement cache warming on deploy
- Use cache tags for bulk invalidation

## Monitoring & Observability

### Logging

```php
// Structured logging
Log::info('Quotation created', [
    'quotation_id' => $quotation->id,
    'company_id' => $quotation->company_id,
    'amount' => $quotation->total,
]);
```

### Metrics (Future)

- Request latency
- Error rates
- Cache hit ratio
- Queue depth

### Health Checks

```
/api/health → Database, Cache, Queue status
```

## Technology Stack Summary

| Layer | Technology | Version |
|-------|-----------|---------|
| Runtime | PHP | 8.3 |
| Framework | Laravel | 11 |
| Database | MySQL | 8.0 |
| Cache | Redis | 7 |
| Web Server | Nginx | 1.25 |
| Testing | PHPUnit | 12 |
| Auth | Laravel Sanctum | 4 |
| Deployment | Docker | 20.10 |

## Design Principles

1. **SOLID Principles**
   - Single Responsibility
   - Open/Closed
   - Liskov Substitution
   - Interface Segregation
   - Dependency Inversion

2. **DRY (Don't Repeat Yourself)**
   - Base classes for common functionality
   - Traits for shared behavior
   - Service classes for reusable logic

3. **KISS (Keep It Simple, Stupid)**
   - Clear, readable code
   - Minimal abstractions
   - Obvious naming

4. **Enterprise Patterns**
   - Service layer for business logic
   - Repository pattern (future)
   - Event-driven architecture
   - CQRS (future)

## Future Architectural Improvements

1. **Phase 4+**: Domain-Driven Design (DDD)
2. **Phase 5+**: Event Sourcing for audit trail
3. **Phase 6+**: GraphQL API alongside REST
4. **Phase 7+**: Microservices (async operations)
5. **Phase 8+**: Event-driven notifications

---

**Document Version**: 1.0
**Last Updated**: 2026-05-17
**Reviewed By**: Architecture Team
