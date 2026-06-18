# ═══════════════════════════════════════════════════════════════
#  PanelOS Backend — Production image
#  Architecture: one container, three processes (nginx + php-fpm + supervisord)
#  Why: container is self-testable (curl it directly to verify health) and
#  the compose file in Phase 5 stays simple — no shared volumes for fastcgi.
# ═══════════════════════════════════════════════════════════════

# ─── Stage 1 — Composer (build-only; thrown away in the final image) ───
FROM composer:2.8 AS deps
WORKDIR /app
# Manifests-only first → layer cached as long as composer.lock is unchanged.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-progress \
        --prefer-dist \
        --no-interaction

# ─── Stage 2 — Final runtime image ─────────────────────────────────────
FROM php:8.3-fpm-alpine

ARG APP_USER=panelos
ARG APP_UID=1000

# Two apk groups: runtime libs (kept) + build-only headers (deleted after compile).
# Doing this in ONE RUN keeps the final image small — intermediate apks aren't
# saved as separate layers and the .build-deps virtual package gets removed.
RUN apk add --no-cache \
        nginx \
        supervisor \
        curl \
        bash \
        tini \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        gd \
        exif \
        zip \
        opcache \
        pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

# Composer binary for the dump-autoload step further down. No need to
# install PHP a second time — we copy from the official composer image.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Non-root app user. Even if a Laravel request handler is compromised, the
# attacker is not root inside the container. Defence-in-depth.
RUN adduser -D -u ${APP_UID} -s /bin/sh ${APP_USER}

WORKDIR /var/www/html

# Stage-1 vendor/ first (cached unless composer.lock changed).
COPY --from=deps /app/vendor ./vendor

# Now the rest of the source — .dockerignore controls what's excluded.
COPY . .

# Re-dump autoload now that all PHP files are present, with all optimisations.
# We deliberately do NOT bake `config:cache` — env values are injected at run
# time by docker-compose, and a baked cache would freeze them at build time.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan view:clear \
    && php artisan config:clear

# storage/ and bootstrap/cache/ must be writable by the runtime user.
# Everything else can stay root-owned + world-readable.
RUN chown -R ${APP_USER}:${APP_USER} \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && chmod -R 775 \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

# Inject our prod-grade configs (we author these alongside this Dockerfile).
COPY docker/php/php.ini             /usr/local/etc/php/conf.d/zz-panelos.ini
COPY docker/php/www.conf            /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx/conf.d/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf        /etc/supervisord.conf

# Runtime dirs + socket dir + log files with correct ownership/permissions.
RUN mkdir -p /run/php /run/nginx /var/log/supervisor /var/log/nginx \
    && chown -R ${APP_USER}:${APP_USER} /run/php /run/nginx /var/log/supervisor /var/log/nginx \
    && touch /var/log/nginx/access.log /var/log/nginx/error.log \
    && chown ${APP_USER}:${APP_USER} /var/log/nginx/access.log /var/log/nginx/error.log

# Healthcheck: Docker marks the container unhealthy after 3 consecutive
# failures, and compose's restart policy will then kick the container.
# 30s start_period gives php-fpm + nginx time to come up before checks count.
HEALTHCHECK --interval=15s --timeout=3s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/api/health || exit 1

# The container's HTTP port (nginx listens here). Compose decides whether
# to expose this to the host or keep it internal to the docker network.
EXPOSE 8080

# tini is a 200 KB init that reaps zombie php-fpm children. Without it,
# defunct workers accumulate over weeks and eventually exhaust the PID table.
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
