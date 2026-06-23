#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
#  PanelOS backend entrypoint (OPS-M4)
#  Caches config + events at CONTAINER START — env is injected by docker-compose
#  by now, so values are correct (this is why we don't bake config:cache at build
#  time). Removes per-request config/event parsing on top of OPcache.
#
#  We deliberately do NOT `route:cache`: a few routes are action closures
#  (/health, /) which route:cache cannot serialize — it would error.
#
#  Any caching failure falls back to a cleared (uncached) state so a bad cache
#  can never brick the container; the app still boots, just un-cached.
#  Finally hand off (exec) to supervisord, which runs php-fpm + nginx + scheduler.
# ─────────────────────────────────────────────────────────────────────────────
set -e
cd /var/www/html

php artisan config:cache 2>&1 || { echo "[entrypoint] config:cache failed — booting un-cached"; php artisan config:clear || true; }
php artisan event:cache  2>&1 || true

exec /usr/bin/supervisord -c /etc/supervisord.conf
