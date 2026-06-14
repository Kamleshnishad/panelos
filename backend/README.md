# PanelOS - Manufacturing ERP System

> Enterprise-grade ERP system for PUF/PIR panel manufacturers built with Laravel 11, Vue 3, and MySQL.

![PHP Version](https://img.shields.io/badge/PHP-8.3-blue)
![Laravel Version](https://img.shields.io/badge/Laravel-11-red)
![Tests](https://img.shields.io/badge/Tests-27/27-brightgreen)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED)
![Status](https://img.shields.io/badge/Status-Phase%201%20Ready-orange)

## 🎯 Features

### Phase 1 ✅ (Core Foundation - COMPLETE)
- [x] **Authentication System** - JWT token-based API authentication
- [x] **Multi-Tenancy** - Company-level data isolation
- [x] **Role-Based Access** - 6 default roles with permissions
- [x] **REST API** - Standardized response format and error handling
- [x] **Testing** - 27 tests with 100% pass rate
- [x] **Docker Stack** - Production-ready containerization
- [x] **CI/CD Pipeline** - Automated testing and deployment

### Phase 2-3 (In Progress)
- [ ] **BOQ Module** - Quotation/pricing engine with PDF generation
- [ ] **Production** - Batch tracking and QC workflows

### Phase 4-8 (Planned)
- [ ] **Inventory** - Stock tracking and management
- [ ] **Accounting** - Invoice generation and GST
- [ ] **Frontend** - Vue 3 dashboard
- [ ] **Admin** - Configuration portal
- [ ] **Integration** - WhatsApp, notifications, optimization

## 🚀 Quick Start (5 minutes)

### Step 1: Clone & Setup
```bash
cd backend
cp .env.example .env
```

### Step 2: Start Docker
```bash
docker-compose up -d
```

### Step 3: Initialize
```bash
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate --seed
```

### Step 4: Verify
```bash
curl http://localhost/api/health
```

### Step 5: Login
```bash
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.local","password":"password123"}'
```

✅ **Done!** API running at `http://localhost/api`

## 📚 Documentation

| Document | Purpose |
|----------|---------|
| [API_ENDPOINTS.md](API_ENDPOINTS.md) | All API endpoints with examples |
| [ARCHITECTURE.md](ARCHITECTURE.md) | System design and patterns |
| [DOCKER_SETUP.md](../DOCKER_SETUP.md) | Docker guide and troubleshooting |
| [TESTING_SUMMARY.md](../TESTING_SUMMARY.md) | Test infrastructure details |

## 🏗️ Architecture

**5-Service Stack**:
- PHP 8.3-FPM (Application)
- MySQL 8.0 (Database)
- Redis 7 (Cache & Queue)
- Nginx (Web Server)
- Node 18 (Frontend, optional)

**Design Patterns**:
- Multi-tenancy with company_id isolation
- Service layer for business logic
- Event-driven architecture
- RBAC with 6 roles
- JWT authentication via Sanctum

## 🧪 Tests

**27/27 Passing** ✅

```bash
# Run all tests
docker-compose exec app php artisan test

# Feature tests
docker-compose exec app php artisan test --testsuite=Feature

# Unit tests
docker-compose exec app php artisan test --testsuite=Unit

# With coverage
docker-compose exec app php artisan test --coverage-html coverage
```

## 🔐 Default Credentials

| Field | Value |
|-------|-------|
| Email | admin@demo.local |
| Password | password123 |
| Role | Super Admin |
| Company | Demo Company |

## 📋 Roles

```
Super Admin      → Full system access (*)
Company Admin    → Company-level management
Sales Manager    → Quotation/order creation
Production Mgr   → Batch management
Accounts         → Invoice/payment management
Viewer           → Read-only access
```

## 🛠️ Common Commands

**Database**:
```bash
docker-compose exec app php artisan migrate
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan migrate:fresh --seed
```

**Logs**:
```bash
docker-compose logs -f              # All services
docker-compose logs -f app          # PHP only
docker-compose logs -f mysql        # Database only
```

**Management**:
```bash
docker-compose ps                   # Service status
docker-compose restart app          # Restart service
docker-compose down                 # Stop all services
```

## 📊 Project Status

| Phase | Progress | Status |
|-------|----------|--------|
| 1 | 87/100 | ✅ Nearly Complete |
| 2 | 0/100 | ⏳ Pending |
| 3 | 0/100 | ⏳ Pending |
| 4-8 | 0/400 | ⏳ Pending |

**Phase 1 Tasks**:
- [x] 1.1 Project Setup
- [x] 1.2 Database Migrations
- [x] 1.3 Authentication & Authorization
- [x] 1.4 API Response Wrapper
- [x] 1.5 Base Models & Traits
- [x] 1.6 Testing Infrastructure
- [x] 1.7 Environment & DevOps
- [x] 1.8 Documentation

## 🚀 Deployment

### Docker
```bash
docker build -t panelos:1.0.0 .
docker tag panelos:1.0.0 registry.example.com/panelos:1.0.0
docker push registry.example.com/panelos:1.0.0
```

### CI/CD
Automated via GitHub Actions on push to main:
- Run tests
- Code quality checks
- Security scanning
- Build Docker image
- Deploy to production

## 🔒 Security

- ✅ Password hashing (bcrypt)
- ✅ CSRF protection
- ✅ SQL injection prevention (ORM)
- ✅ XSS prevention (JSON)
- ✅ Rate limiting ready
- ✅ Security headers

## 🐛 Troubleshooting

**Port in use**:
```bash
# Change port in docker-compose.yml
# Find: ports: - "80:80"
# Change to: ports: - "8080:80"
docker-compose up -d
```

**Database error**:
```bash
# Verify DB_HOST=mysql (not localhost) in .env
docker-compose restart mysql
docker-compose exec app php artisan migrate
```

**Tests failing**:
```bash
docker-compose exec app php artisan migrate:fresh --seed
docker-compose exec app php artisan test
```

See [DOCKER_SETUP.md](../DOCKER_SETUP.md) for more troubleshooting.

## 📈 Roadmap

- **Week 5-10**: Phase 2 (BOQ Module)
- **Week 11-15**: Phase 3 (Production)
- **Week 16-20**: Phase 4-5 (Inventory & Accounting)
- **Week 21-25**: Phase 6 (Frontend)
- **Week 26-30**: Phase 7-8 (Admin & Integration)

**Target**: December 2026

## 👥 Team & Contribution

### Getting Help
1. Check [API_ENDPOINTS.md](API_ENDPOINTS.md)
2. Review [ARCHITECTURE.md](ARCHITECTURE.md)
3. See [DOCKER_SETUP.md](../DOCKER_SETUP.md)
4. Check logs: `docker-compose logs -f`

### Code Standards
- PSR-12 coding standard
- Type hints required
- Comprehensive tests
- Meaningful names

## 📄 License

MIT License - see LICENSE file

---

**Version**: 1.0.0-beta  
**Phase**: 1 Complete (87%)  
**Updated**: 2026-05-17  
**Status**: ✅ Ready for Phase 2

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
