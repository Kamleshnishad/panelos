# PanelOS API Endpoints Documentation

## Overview

Complete REST API documentation for PanelOS. All endpoints are prefixed with `/api/v1` and return JSON responses.

**Base URL**: `http://localhost/api`

## Response Format

### Success Response
```json
{
  "success": true,
  "data": { /* endpoint-specific data */ },
  "message": "Human readable message",
  "meta": {
    "timestamp": "2026-05-17T12:00:00Z",
    "version": "1.0"
  }
}
```

### Error Response
```json
{
  "success": false,
  "errors": {
    "field_name": ["Error message"]
  },
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "meta": {
    "timestamp": "2026-05-17T12:00:00Z",
    "version": "1.0"
  }
}
```

### Pagination Response
```json
{
  "success": true,
  "data": [ /* array of items */ ],
  "meta": {
    "pagination": {
      "total": 100,
      "count": 20,
      "per_page": 20,
      "current_page": 1,
      "total_pages": 5
    },
    "timestamp": "2026-05-17T12:00:00Z",
    "version": "1.0"
  }
}
```

## HTTP Status Codes

| Code | Meaning | Usage |
|------|---------|-------|
| 200 | OK | Successful GET, PUT, PATCH request |
| 201 | Created | Successful POST request |
| 204 | No Content | Successful DELETE request |
| 400 | Bad Request | Invalid request parameters |
| 401 | Unauthorized | Missing or invalid authentication |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | Resource not found |
| 422 | Unprocessable Entity | Validation error |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Server Error | Internal server error |

## Authentication

All protected endpoints require a Bearer token in the Authorization header:

```bash
Authorization: Bearer {access_token}
```

Tokens are obtained through the login endpoint and remain valid for the user session.

---

## Authentication Endpoints

### POST /auth/login
Login and receive authentication token.

**Access**: Public

**Request**:
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response** (200):
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "user@example.com",
      "company_id": 1,
      "is_super_admin": false,
      "is_company_admin": false
    },
    "token": "1|abcdef...",
    "token_type": "Bearer"
  },
  "message": "Login successful"
}
```

**Error Responses**:
- 422: Validation error (missing email/password)
- 401: Invalid credentials

---

### POST /auth/logout
Logout and invalidate current token.

**Access**: Protected (Sanctum)

**Response** (200):
```json
{
  "success": true,
  "data": null,
  "message": "Logout successful"
}
```

---

### GET /auth/me
Get current authenticated user profile.

**Access**: Protected (Sanctum)

**Response** (200):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com",
    "phone": null,
    "company_id": 1,
    "is_super_admin": false,
    "is_company_admin": false,
    "is_active": true,
    "last_login_at": "2026-05-17T10:30:00Z"
  },
  "message": "Current user retrieved"
}
```

---

### POST /auth/refresh-token
Refresh authentication token (extends session).

**Access**: Protected (Sanctum)

**Response** (200):
```json
{
  "success": true,
  "data": {
    "token": "1|newtoken...",
    "token_type": "Bearer"
  },
  "message": "Token refreshed successfully"
}
```

---

### POST /auth/change-password
Change user password.

**Access**: Protected (Sanctum)

**Request**:
```json
{
  "current_password": "password123",
  "new_password": "newpassword456",
  "new_password_confirmation": "newpassword456"
}
```

**Response** (200):
```json
{
  "success": true,
  "data": null,
  "message": "Password changed successfully"
}
```

**Error Responses**:
- 422: Validation error (passwords don't match, too short)
- 401: Current password incorrect

---

## Companies Endpoints
*Coming in Phase 2*

### GET /companies
List all companies (Super Admin only)

### GET /companies/{id}
Get company details

### POST /companies
Create new company

### PUT /companies/{id}
Update company

### DELETE /companies/{id}
Delete company

---

## Quotations (BOQ) Endpoints
*Coming in Phase 2*

### GET /quotations
List quotations with pagination

### POST /quotations
Create new quotation

### GET /quotations/{id}
Get quotation details with items

### PUT /quotations/{id}
Update quotation

### POST /quotations/{id}/send
Send quotation to customer

### POST /quotations/{id}/accept
Accept quotation (convert to order)

### DELETE /quotations/{id}
Delete quotation (DRAFT only)

### POST /quotations/{id}/export-pdf
Export quotation as PDF

---

## Orders Endpoints
*Coming in Phase 3*

### GET /orders
List orders with pagination

### POST /orders
Create order from quotation

### GET /orders/{id}
Get order details

### PUT /orders/{id}
Update order

### POST /orders/{id}/dispatch
Mark order as dispatched

### DELETE /orders/{id}
Cancel order

---

## Production Endpoints
*Coming in Phase 3*

### GET /production/batches
List production batches

### POST /production/batches
Create production batch

### PUT /production/batches/{id}
Update batch details

### POST /production/batches/{id}/stages/{stage}/complete
Mark production stage complete

### GET /production/batches/{id}/quality-control
Get QC checklist

### POST /production/batches/{id}/quality-control
Submit QC entry

---

## Inventory Endpoints
*Coming in Phase 4*

### GET /inventory/coil-stock
List coil inventory

### POST /inventory/coil-stock
Add coil stock

### GET /inventory/chemicals
List chemical inventory

### POST /inventory/chemicals
Add chemical stock

### GET /inventory/low-stock-alerts
Get low stock alerts

### POST /inventory/stock-transactions
Record stock transaction (immutable log)

---

## Dispatch Endpoints
*Coming in Phase 4*

### GET /dispatch
List dispatches

### POST /dispatch
Create dispatch challan

### GET /dispatch/{id}
Get dispatch details

### POST /dispatch/{id}/export-challan
Export dispatch challan as PDF

---

## Invoicing Endpoints
*Coming in Phase 5*

### GET /invoices
List invoices

### POST /invoices
Generate invoice from order

### GET /invoices/{id}
Get invoice details

### POST /invoices/{id}/export
Export invoice as PDF

### POST /invoices/gst-report
Generate GSTR-1 report

### POST /invoices/e-invoice
Generate E-invoice (IRN)

---

## Payment Endpoints
*Coming in Phase 5*

### GET /payments
List payment records

### POST /payments
Record payment

### GET /payments/{id}
Get payment details

### POST /payments/{id}/cheque-status
Update cheque status

### GET /outstanding-aging
Get outstanding aging report

---

## Health Check Endpoint

### GET /health
System health check (public)

**Response** (200):
```json
{
  "status": "OK",
  "timestamp": "2026-05-17T12:00:00Z"
}
```

---

## Error Code Reference

### Authentication Errors
- `UNAUTHENTICATED` - Missing or invalid token (401)
- `INVALID_CREDENTIALS` - Wrong email/password (401)
- `PASSWORD_MISMATCH` - Wrong current password (401)
- `TOKEN_EXPIRED` - Token has expired (401)

### Validation Errors
- `VALIDATION_ERROR` - Request validation failed (422)
- `INVALID_INPUT` - Invalid input format (400)

### Authorization Errors
- `FORBIDDEN` - Insufficient permissions (403)
- `COMPANY_MISMATCH` - Access denied for this company (403)

### Resource Errors
- `NOT_FOUND` - Resource does not exist (404)
- `CONFLICT` - Duplicate resource (409)
- `INVALID_STATE` - Invalid state transition (422)

### System Errors
- `INTERNAL_ERROR` - Server error (500)
- `SERVICE_UNAVAILABLE` - Service temporarily unavailable (503)

---

## Rate Limiting

API implements rate limiting to prevent abuse:
- **Limit**: 1000 requests per hour per IP
- **Window**: Sliding window (1 hour)
- **Headers**:
  - `X-RateLimit-Limit`: Total requests allowed
  - `X-RateLimit-Remaining`: Requests remaining
  - `X-RateLimit-Reset`: Timestamp when limit resets

Response when rate limit exceeded:
```json
{
  "success": false,
  "message": "Rate limit exceeded",
  "error_code": "RATE_LIMIT_EXCEEDED",
  "meta": {
    "retry_after": 3600
  }
}
```

---

## Pagination

List endpoints support pagination with query parameters:

```
GET /quotations?page=1&per_page=20&sort=created_at&order=desc
```

**Parameters**:
- `page` (int): Page number (default: 1)
- `per_page` (int): Items per page (default: 20, max: 100)
- `sort` (string): Sort field (default: created_at)
- `order` (string): Sort order - asc|desc (default: desc)

**Response includes**:
```json
"meta": {
  "pagination": {
    "total": 100,
    "count": 20,
    "per_page": 20,
    "current_page": 1,
    "total_pages": 5
  }
}
```

---

## Filtering

Most list endpoints support filtering:

```
GET /quotations?status=draft&customer_id=5&created_after=2026-05-01
```

Common filters:
- `status`: Filter by status field
- `company_id`: Filter by company (multi-tenant)
- `created_after`: Filter by creation date (ISO 8601)
- `created_before`: Filter by creation date
- `search`: Full-text search on name/email

---

## Testing Endpoints

### Test Account
- **Email**: admin@demo.local
- **Password**: password123
- **Company**: Demo Company
- **Role**: Super Admin

### Quick Test
```bash
# Login
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.local","password":"password123"}'

# Copy token from response, then get current user
curl -X GET http://localhost/api/auth/me \
  -H "Authorization: Bearer {token}"
```

---

## Versioning

API uses URL versioning: `/api/v1/`

Current version: **1.0**

Backward compatibility is maintained within major versions. Breaking changes increment version number.

---

## Webhook Support

*Coming in Phase 8*

Webhooks allow real-time event notifications:
- Order created/updated
- Payment received
- Dispatch completed
- Invoice generated

---

## Changelog

### Version 1.0 (2026-05-17)
- Initial authentication endpoints
- Health check endpoint
- Response wrapper format
- Error handling

### Future Versions
- v1.1: Companies endpoints
- v1.2: Quotations/BOQ endpoints
- v2.0: GraphQL support (planned)

---

## Support

For API issues or questions:
1. Check this documentation
2. Review error codes and messages
3. Check API_TESTING_GUIDE.md for examples
4. File issue on GitHub

Last updated: 2026-05-17
