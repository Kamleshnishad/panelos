# PanelOS API Testing Guide

## Base URL
```
http://localhost/backend/public/api
```

## Authentication Endpoints

### 1. Login - POST /auth/login
**Public endpoint** - No token required

**Request:**
```json
{
  "email": "admin@demo.local",
  "password": "password123"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "name": "Demo Admin",
      "email": "admin@demo.local",
      "company_id": 1,
      "is_super_admin": true,
      "is_company_admin": true
    },
    "token": "Bearer_TOKEN_HERE",
    "token_type": "Bearer"
  },
  "message": "Login successful",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

---

### 2. Get Current User - GET /auth/me
**Protected endpoint** - Requires Bearer token

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Demo Admin",
    "email": "admin@demo.local",
    "phone": null,
    "company_id": 1,
    "is_super_admin": true,
    "is_company_admin": true,
    "is_active": true,
    "last_login_at": "2026-05-17T..."
  },
  "message": "Current user retrieved",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

---

### 3. Refresh Token - POST /auth/refresh-token
**Protected endpoint** - Requires Bearer token

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "token": "NEW_BEARER_TOKEN_HERE",
    "token_type": "Bearer"
  },
  "message": "Token refreshed successfully",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

---

### 4. Change Password - POST /auth/change-password
**Protected endpoint** - Requires Bearer token

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request:**
```json
{
  "current_password": "password123",
  "new_password": "newpassword456",
  "new_password_confirmation": "newpassword456"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": null,
  "message": "Password changed successfully",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

---

### 5. Logout - POST /auth/logout
**Protected endpoint** - Requires Bearer token

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": null,
  "message": "Logout successful",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

---

## Testing with cURL

### Login
```bash
curl -X POST http://localhost/backend/public/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.local","password":"password123"}'
```

### Get Current User
```bash
curl -X GET http://localhost/backend/public/api/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Refresh Token
```bash
curl -X POST http://localhost/backend/public/api/auth/refresh-token \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

## Testing with Postman

1. Create a collection named "PanelOS API"
2. Set base URL: `http://localhost/backend/public/api`
3. Create requests for each endpoint above
4. Use "Bearer Token" authorization type
5. Copy token from login response to test protected endpoints

---

## Error Responses

### Validation Error (422)
```json
{
  "success": false,
  "errors": {
    "email": ["The email field is required."]
  },
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

### Unauthorized (401)
```json
{
  "success": false,
  "errors": {
    "email": ["Invalid credentials"]
  },
  "message": "Invalid email or password",
  "error_code": "INVALID_CREDENTIALS",
  "meta": {
    "timestamp": "2026-05-17T...",
    "version": "1.0"
  }
}
```

---

## Default Test Account

- **Email:** admin@demo.local
- **Password:** password123
- **Company:** Demo Company
- **Role:** Super Admin
- **Subdomain:** demo

---

## Next Steps

1. ✅ Test all authentication endpoints
2. ⏳ Create business domain endpoints (Quotations, Orders, etc.)
3. ⏳ Implement additional models and controllers
4. ⏳ Add comprehensive error handling
5. ⏳ Set up comprehensive testing suite
