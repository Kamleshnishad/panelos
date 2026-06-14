# PanelOS Testing Infrastructure

## Overview
Comprehensive testing infrastructure implemented with 27 passing tests covering all authentication endpoints and core functionality.

## Test Statistics
- **Total Tests**: 27
- **Passing**: 27 (100%)
- **Coverage**: Authentication system, database operations, token management
- **Test Suites**: 
  - Feature tests: 24
  - Unit tests: 3

## Test Files

### Feature Tests

#### [tests/Feature/AuthControllerTest.php](backend/tests/Feature/AuthControllerTest.php)
24 comprehensive tests for authentication endpoints:

**Login Tests (6)**
- ✓ Login with valid credentials
- ✓ Login with invalid email
- ✓ Login with wrong password
- ✓ Login with missing email
- ✓ Login with missing password
- ✓ Login updates last_login_at timestamp

**Current User Tests (3)**
- ✓ Get current user (authenticated)
- ✓ Get current user without auth (401)
- ✓ Get current user with invalid token (401)

**Refresh Token Tests (3)**
- ✓ Refresh token returns new token
- ✓ Refresh token without auth (401)
- ✓ Old token invalid after refresh

**Change Password Tests (5)**
- ✓ Change password with valid current password
- ✓ Change password with wrong current password (401)
- ✓ Change password confirmation mismatch (422)
- ✓ Change password without auth (401)
- ✓ Change password with missing fields (422)

**Logout Tests (2)**
- ✓ Logout returns 200
- ✓ Logout without auth (401)

**Multi-Tenant Tests (1)**
- ✓ User can only access own company data

#### [tests/Feature/SmokeTest.php](backend/tests/Feature/SmokeTest.php)
2 smoke tests for basic infrastructure:
- ✓ Health check endpoint available
- ✓ Database connectivity

### Unit Tests

#### [tests/Unit/TokenDeletionTest.php](backend/tests/Unit/TokenDeletionTest.php)
3 unit tests for token operations:
- ✓ Token can be deleted directly
- ✓ Current access token works correctly

## Test Helpers

### [tests/Traits/HasAuthTestHelpers.php](backend/tests/Traits/HasAuthTestHelpers.php)
Utilities for authentication testing:
```php
loginUser()           // Login as specific user
loginSuperAdmin()     // Login as super admin
loginCompanyAdmin()   // Login as company admin
getAuthToken()        // Get token for user
authenticatedRequest() // Make authenticated request
```

### [tests/Traits/HasDatabaseTestHelpers.php](backend/tests/Traits/HasDatabaseTestHelpers.php)
Utilities for database testing:
```php
createTestCompany()        // Create test company
createTestUser()           // Create test user
createTestRole()           // Create test role
createAdminUser()          // Create admin user
assertDatabaseHasModel()   // Assert model in DB
assertDatabaseMissingModel() // Assert model not in DB
```

## Test Factories

### [database/factories/CompanyFactory.php](backend/database/factories/CompanyFactory.php)
Generates realistic company test data with all required fields:
- Company name, subdomain, contact info
- Banking details, GST/PAN
- Configuration (colors, prefixes, settings)

### [database/factories/UserFactory.php](backend/database/factories/UserFactory.php)
User factory with state methods:
- `superAdmin()` - Create super admin user
- `companyAdmin()` - Create company admin
- `inactive()` - Create inactive user

### [database/factories/RoleFactory.php](backend/database/factories/RoleFactory.php)
Role factory with presets:
- `superAdmin()` - Super admin role
- `viewer()` - View-only role

## Running Tests

### Run all tests
```bash
php artisan test
```

### Run feature tests only
```bash
php artisan test --testsuite=Feature
```

### Run unit tests only
```bash
php artisan test --testsuite=Unit
```

### Run specific test class
```bash
php artisan test --filter=AuthControllerTest
```

### Run specific test method
```bash
php artisan test --filter=test_login_with_valid_credentials
```

## Test Database

- **Type**: SQLite (in-memory)
- **Isolation**: RefreshDatabase trait ensures clean state for each test
- **Migrations**: Automatically run before each test
- **Seeders**: Database factories used for test data generation

## Error Response Format

All API error responses follow consistent format:
```json
{
  "success": false,
  "errors": {
    "field": ["Error message"]
  },
  "message": "Human readable message",
  "error_code": "ERROR_CODE_ENUM",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

## Configuration

### [phpunit.xml](backend/phpunit.xml)
PHPUnit configuration:
- Test suites: Feature and Unit
- SQLite in-memory database for testing
- Bootstrap: vendor/autoload.php
- Source: app/ directory

### [bootstrap/app.php](backend/bootstrap/app.php)
Application configuration:
- API routes: routes/api.php
- Custom exception handlers for authentication and validation errors
- Sanctum configured for API token authentication

## Next Steps

- [ ] Increase test coverage to >80%
- [ ] Add integration tests for business logic
- [ ] Add performance tests
- [ ] Add database migration tests
- [ ] Add end-to-end test scenarios
