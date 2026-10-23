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

# public/storage -> storage/app/public. storage/ is a named volume (see
# docker-compose.prod.yml) so this must run at container start, not build
# time: the volume isn't mounted yet during the image build. Idempotent —
# the command just warns and no-ops if the link already exists.
php artisan storage:link 2>&1 || true

# Hand the logs back to the user the web workers run as (see docker/php/www.conf).
# This entrypoint and every `docker exec ... php artisan` in the deploy workflows run
# as root, so any log line they write creates laravel.log root:root 0644 — which the
# unprivileged php-fpm workers cannot append to. Every error after that then failed to
# log AND made the error handler itself throw, so users got an empty 500 (PB-042).
# storage/ is a persistent volume, so a bad file survives container recreation; this
# repairs it on every start. The logging config also sets permission 0666 so a file
# created later by root stays writable.
chown -R panelos:panelos storage/logs 2>/dev/null || true
chmod -R u+rwX,g+rwX storage/logs 2>/dev/null || true

exec /usr/bin/supervisord -c /etc/supervisord.conf
