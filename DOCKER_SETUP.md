# PanelOS Docker Setup Guide

## Overview

PanelOS includes complete Docker containerization for local development and production deployments. The Docker setup includes:

- **PHP 8.3-FPM** - Laravel application runtime
- **MySQL 8.0** - Database server
- **Redis** - Cache and queue system
- **Nginx** - Web server / reverse proxy
- **Node.js 18** - Frontend asset compilation (optional)

## Prerequisites

### System Requirements
- **Docker Desktop** (v20.10+)
- **Docker Compose** (v2.0+)
- **4GB RAM** minimum (8GB recommended)
- **10GB disk space** for images and volumes

### Installation

#### Windows
1. Download [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop)
2. Run the installer
3. Enable "WSL 2" during installation
4. Restart your computer
5. Verify installation:
```bash
docker --version
docker-compose --version
```

#### macOS
1. Download [Docker Desktop for Mac](https://www.docker.com/products/docker-desktop)
2. Run the installer
3. Verify installation:
```bash
docker --version
docker-compose --version
```

#### Linux
1. Install Docker:
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
```

2. Install Docker Compose:
```bash
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

## Quick Start

### 1. Clone or Extract Project
```bash
cd backend
```

### 2. Create Environment File
```bash
cp .env.example .env
```

### 3. Update Environment Variables
For Docker, update these in `.env`:
```env
DB_HOST=mysql
DB_USERNAME=panelos_user
DB_PASSWORD=panelos_secret

REDIS_HOST=redis
REDIS_PASSWORD=redis_secret

CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

### 4. Build and Start Containers
```bash
docker-compose up -d
```

This will:
- Build the PHP-FPM image
- Start all services (app, mysql, redis, nginx)
- Initialize the database
- Create persistent volumes

### 5. Install Dependencies
```bash
docker-compose exec app composer install
```

### 6. Generate Application Key
```bash
docker-compose exec app php artisan key:generate
```

### 7. Run Migrations
```bash
docker-compose exec app php artisan migrate --seed
```

### 8. Access the Application
- **API Base URL**: `http://localhost/api`
- **Health Check**: `http://localhost/api/health`
- **Login Test**: 
  ```bash
  curl -X POST http://localhost/api/auth/login \
    -H "Content-Type: application/json" \
    -d '{"email":"admin@demo.local","password":"password123"}'
  ```

## Common Commands

### View Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f app
docker-compose logs -f mysql
docker-compose logs -f redis
```

### Access Application Shell
```bash
docker-compose exec app bash
```

### Run Artisan Commands
```bash
docker-compose exec app php artisan tinker
docker-compose exec app php artisan migrate:refresh
docker-compose exec app php artisan db:seed
```

### Run Tests
```bash
docker-compose exec app php artisan test
docker-compose exec app php artisan test --testsuite=Feature
```

### Database Access
```bash
docker-compose exec mysql mysql -u panelos_user -ppanelos_secret panelos_dev
```

### Redis Access
```bash
docker-compose exec redis redis-cli -a redis_secret
```

### View Container Status
```bash
docker-compose ps
```

### Restart Services
```bash
# Restart specific service
docker-compose restart app

# Restart all services
docker-compose restart
```

## Service Details

### Application (PHP-FPM)
- **Image**: Custom Dockerfile (PHP 8.3)
- **Port**: 9000 (internal)
- **Volumes**: Current directory mounted at `/app`
- **Dependencies**: mysql, redis
- **Health Check**: API endpoint check every 30s

### MySQL
- **Image**: mysql:8.0
- **Port**: 3306
- **Database**: panelos_dev
- **User**: panelos_user
- **Root Password**: root_secret
- **Volume**: mysql_data (persistent)
- **Configuration**: docker/mysql/my.cnf

### Redis
- **Image**: redis:7-alpine
- **Port**: 6379
- **Password**: redis_secret
- **Volume**: redis_data (persistent)
- **Features**: Append-only file (AOF) for persistence

### Nginx
- **Image**: nginx:alpine
- **Ports**: 80 (HTTP), 443 (HTTPS)
- **Configuration**: docker/nginx/conf.d/default.conf
- **Features**: Security headers, gzip compression, health check

### Node.js (Optional Frontend)
- **Image**: node:18-alpine
- **Profile**: frontend (not started by default)
- **Port**: 5173 (Vite dev server)
- **Command**: `npm run dev`
- **To enable**: `docker-compose --profile frontend up`

## Advanced Configuration

### Environment Variables in Detail

```env
# App
APP_ENV=local|production
APP_DEBUG=true|false
APP_URL=http://localhost

# Database
DB_HOST=mysql (or host IP when Docker-less)
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# Redis
REDIS_PASSWORD=your_password
REDIS_CLIENT=phpredis|predis

# Cache
CACHE_STORE=redis|database
CACHE_PREFIX=panelos_

# Queue
QUEUE_CONNECTION=redis|database|sync

# Mail
MAIL_MAILER=log|smtp|mailgun|sendgrid
MAIL_FROM_ADDRESS=no-reply@panelos.local
```

### Persistent Volumes

Docker creates named volumes for data persistence:

- **mysql_data**: Database files
- **redis_data**: Cache and queue data

View volumes:
```bash
docker volume ls | grep panelos
```

Remove volumes (WARNING: Deletes data):
```bash
docker-compose down -v
```

### Scaling Services

For production, scale services:
```bash
docker-compose up -d --scale app=3
```

### Resource Limits

Edit `docker-compose.yml` to add limits:
```yaml
services:
  app:
    deploy:
      resources:
        limits:
          cpus: '1'
          memory: 512M
        reservations:
          cpus: '0.5'
          memory: 256M
```

## Troubleshooting

### Port Already in Use
```bash
# Find process using port
lsof -i :80  # macOS/Linux
netstat -ano | findstr :80  # Windows

# Change ports in docker-compose.yml
# Then restart
docker-compose up -d
```

### Database Connection Error
```bash
# Check MySQL is running
docker-compose ps mysql

# Check database credentials in .env
# Verify DB_HOST=mysql (not localhost when in Docker)

# Restart MySQL
docker-compose restart mysql
```

### Out of Disk Space
```bash
# Remove unused images/volumes
docker system prune -a

# Check disk usage
docker system df
```

### Slow Performance on Windows/Mac
- Ensure Docker Desktop has sufficient resources allocated
- Check WSL 2 backend (Windows)
- Consider using named volumes instead of bind mounts

### Database Migration Errors
```bash
# Fresh database
docker-compose exec app php artisan migrate:fresh --seed

# Check migrations
docker-compose exec app php artisan migrate:status

# Rollback
docker-compose exec app php artisan migrate:rollback
```

## Production Deployment

### Building for Production

1. **Optimize Dockerfile**:
   - Use multi-stage builds
   - Remove dev dependencies
   - Minimize layer size

2. **Environment Configuration**:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   LOG_LEVEL=warning
   ```

3. **Build Image**:
   ```bash
   docker build -f Dockerfile -t panelos:1.0.0 .
   ```

4. **Push to Registry**:
   ```bash
   docker tag panelos:1.0.0 registry.example.com/panelos:1.0.0
   docker push registry.example.com/panelos:1.0.0
   ```

### Using Docker Swarm or Kubernetes

For orchestration, use:
- **Docker Swarm**: `docker stack deploy`
- **Kubernetes**: Convert docker-compose to Helm charts

### SSL/TLS Certificates

For HTTPS:
1. Place certificates in `docker/nginx/ssl/`
2. Update `docker/nginx/conf.d/default.conf`
3. Restart nginx: `docker-compose restart nginx`

## Performance Tuning

### MySQL
- Increase `innodb_buffer_pool_size`
- Configure slow query log
- Enable query caching

### Redis
- Monitor memory usage
- Configure eviction policy
- Enable AOF for persistence

### PHP-FPM
- Adjust `pm.max_children` based on memory
- Configure `pm.max_requests`
- Enable opcache (default enabled)

### Nginx
- Enable gzip compression ✓
- Configure caching headers ✓
- Use http/2 (optional)

## Security Best Practices

### Do's
- ✓ Use strong passwords in .env
- ✓ Keep images updated: `docker-compose pull`
- ✓ Use non-root user in production
- ✓ Enable health checks ✓
- ✓ Use environment variables for secrets

### Don'ts
- ✗ Don't commit .env file to git
- ✗ Don't expose database ports publicly
- ✗ Don't use default passwords
- ✗ Don't disable authentication
- ✗ Don't run containers as root

## Additional Resources

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Reference](https://docs.docker.com/compose/compose-file/)
- [Laravel Docker Guide](https://laravel.com/docs/deployment#docker)
- [Nginx Configuration](https://nginx.org/en/docs/)

## Support

For issues or questions:
1. Check Docker logs: `docker-compose logs -f`
2. Review .env configuration
3. Ensure all prerequisites are installed
4. Check Docker Desktop settings (memory, disk space)

---

**Last Updated**: 2026-05-17
**Docker Compose Version**: 3.8
**Tested On**: Docker Desktop 4.20+
