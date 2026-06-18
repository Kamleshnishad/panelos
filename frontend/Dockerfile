# ═══════════════════════════════════════════════════════════════
#  PanelOS Frontend — Production image
#  Architecture: Vue source → npm build → static dist → tiny nginx
#  Final image is ~25 MB (vs ~400 MB if we shipped node_modules).
# ═══════════════════════════════════════════════════════════════

# ─── Stage 1 — Build the Vue bundle (Node only, throwaway) ───
FROM node:20-alpine AS builder
WORKDIR /app

# Manifests first → npm ci layer is cached unless lockfile changes.
COPY package.json package-lock.json ./

# `npm ci` (not `npm install`) is strict — requires a lockfile, deletes any
# existing node_modules, installs EXACTLY what's locked. Reproducible builds.
RUN npm ci --no-audit --no-fund --prefer-offline

# Now the rest of the frontend source. .dockerignore controls exclusions.
COPY . .

# Vite reads /etc/hosts of build container; no special build args needed
# because all our API calls are relative ('/api/...') resolved by the
# browser at runtime against the page origin.
RUN npm run build

# Sanity check: fail the build early if dist/ wasn't produced.
RUN test -f dist/index.html || (echo "ERROR: dist/index.html missing — build failed" && exit 1)

# ─── Stage 2 — Lean nginx runtime ────────────────────────────
FROM nginx:1.27-alpine

# Drop the default placeholder vhost; ship our own.
RUN rm -f /etc/nginx/conf.d/default.conf

# Our vhost (proxies /api → backend, serves the SPA, handles SPA routing).
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# The built Vue bundle. /usr/share/nginx/html is nginx's default root.
COPY --from=builder /app/dist /usr/share/nginx/html

# Tiny hardening: drop unnecessary capabilities, run worker as non-root user.
# (nginx:alpine already runs workers as `nginx` user; master needs root to
# bind :80. That's fine — only inside the container.)

# Healthcheck — Docker marks unhealthy if index.html stops being servable.
HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1/ >/dev/null 2>&1 || exit 1

EXPOSE 80

# nginx:alpine's default CMD already runs nginx in foreground (`-g 'daemon off;'`),
# so we don't need to override CMD here.
