# Containers & CI

A pragmatic, copy-pasteable approach to packaging Webrick with Docker and wiring a CI pipeline that builds, tests, warms caches, and ships images safely.

---

## Image layout

Use a **multi-stage** build to keep images small and production-only.

```dockerfile
# ---- 1) Builder: install deps & build route cache
FROM php:8.4-cli-alpine AS builder

# System deps for composer (and optional zstd/br if you compile them here)
RUN apk add --no-cache git unzip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction

# Copy source
COPY . .

# Build route cache (script should exist in repo)
RUN mkdir -p var/cache/routes && php scripts/build-route-cache.php

# ---- 2) Runtime: php-fpm + nginx
FROM php:8.4-fpm-alpine AS runtime

# Packages: nginx + supervisor + libs
RUN apk add --no-cache nginx supervisor bash zlib

# (Optional) Enable OPcache
RUN docker-php-ext-install opcache

# App
WORKDIR /app
COPY --from=builder /app /app

# Permissions: allow FPM user to write var/
RUN mkdir -p /app/var && chown -R www-data:www-data /app/var

# Nginx & Supervisor config
COPY ./.docker/nginx.conf /etc/nginx/http.d/default.conf
COPY ./.docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
```

`.docker/nginx.conf` (minimal):

```nginx
server {
  listen 80;
  server_name _;

  root /app/public;
  index index.php;

  location / { try_files $uri /index.php?$query_string; }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_read_timeout 120s;
  }
}
```

`.docker/supervisord.conf`:

```ini
[supervisord]
nodaemon=true

[program:php-fpm]
command=php-fpm
autorestart=true
priority=10

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
priority=20
```

**Why this layout?**

* Composer + cache build happen in the **builder** stage (faster CI, smaller runtime).
* Runtime is clean: PHP-FPM + Nginx + your prebuilt **route cache**.

---

## Environment & secrets

* Pass secrets as **env vars** at runtime (never bake into image):

    * `WEBRICK_SIGN_KEY`, `WEBRICK_COOKIE_KEY`, DB creds, etc.
* In orchestrators (Kubernetes, ECS), mount from secret stores.
* Keep the filesystem **read-only** except for `/app/var`.

Docker run example:

```bash
docker run -p 8080:80 \
  -e WEBRICK_SIGN_KEY="prod-..." \
  -e WEBRICK_COOKIE_KEY="prod-..." \
  -e PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
  -v app-var:/app/var \
  your/image:tag
```

---

## CI pipeline (generic)

A simple 5-stage pipeline (GitHub Actions/YAML-ish pseudocode):

```yaml
name: build-and-deploy

on:
  push:
    branches: [ main ]
  workflow_dispatch:

jobs:
  build_test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - name: Install deps
        run: composer install --no-interaction
      - name: Lint & tests
        run: |
          composer run phpcs || true
          composer run phpstan || true
          composer run test
      - name: Build route cache
        run: php scripts/build-route-cache.php

  docker_build_push:
    needs: build_test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Login to registry
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - name: Build
        run: docker build -t ghcr.io/OWNER/webrick:$(git rev-parse --short HEAD) .
      - name: Push
        run: |
          TAG=ghcr.io/OWNER/webrick:$(git rev-parse --short HEAD)
          docker push $TAG
          docker tag $TAG ghcr.io/OWNER/webrick:latest
          docker push ghcr.io/OWNER/webrick:latest

  deploy:
    needs: docker_build_push
    runs-on: ubuntu-latest
    steps:
      - name: Trigger deploy (example)
        run: curl -X POST -H "Authorization: Bearer ${{ secrets.DEPLOY_TOKEN }}" https://deploy.example.com/hooks/webrick
```

> Adapt to your registry/orchestrator. If you use BuildKit cache, your build will be much faster on subsequent runs.

---

## Kubernetes snippet

Minimal Deployment + Service:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: webrick
spec:
  replicas: 3
  selector:
    matchLabels: { app: webrick }
  template:
    metadata:
      labels: { app: webrick }
    spec:
      containers:
        - name: web
          image: ghcr.io/OWNER/webrick:latest
          ports: [{ containerPort: 80 }]
          env:
            - { name: WEBRICK_SIGN_KEY, valueFrom: { secretKeyRef: { name: webrick-secrets, key: sign_key } } }
            - { name: WEBRICK_COOKIE_KEY, valueFrom: { secretKeyRef: { name: webrick-secrets, key: cookie_key } } }
          volumeMounts:
            - { name: var, mountPath: /app/var }
          readinessProbe:
            httpGet: { path: /health, port: 80 }
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet: { path: /health, port: 80 }
            initialDelaySeconds: 10
            periodSeconds: 10
      volumes:
        - name: var
          emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: webrick
spec:
  selector: { app: webrick }
  ports:
    - port: 80
      targetPort: 80
```

**Ingress**: attach TLS + host routing via your cluster’s Ingress controller (Nginx, Traefik, etc.).

---

## Caching & warmup in containers

* Route cache is **baked** by the builder stage (no rebuild at runtime).
* **OPcache warmup**: hit a small “warm” endpoint after rolling—use a post-deploy job or readiness script.
* If using **Response Cache** in-memory, prefer a shared backing store in cluster (Redis) to get cross-pod hits.

---

## Logs & metrics

* Write access logs from Nginx to stdout; FPM logs to stdout/stderr with `catch_workers_output = yes`.
* Export metrics via a sidecar or integrate with your APM/OTEL:

    * route durations, 5xx rates, cache hit/miss, throttle 429s.

Example Docker env for Nginx logs to stdout (Alpine default already does).

---

## Security checklist

* Non-root user where feasible (Alpine PHP-FPM runs as `www-data`).
* Read-only root FS (mount `/app/var` writable).
* Disable Xdebug, dev tools in runtime image.
* Tight CORS/policy headers in app.
* Keep image SBOM and enable vulnerability scanning in registry.

---

## Troubleshooting

| Symptom                   | Likely cause             | Fix                                                                |
| ------------------------- | ------------------------ | ------------------------------------------------------------------ |
| Container boots but 404s  | Wrong `root`/`try_files` | Nginx `root /app/public; try_files $uri /index.php?$query_string;` |
| 502 between Nginx and FPM | Socket/host mismatch     | Ensure `fastcgi_pass 127.0.0.1:9000` or correct UNIX socket        |
| Double compression        | Edge + app gzip          | Disable one side; prefer app or edge—not both                      |
| Env vars not applied      | Missing secrets/values   | Check K8s `envFrom`/`secretKeyRef` or docker run `-e` flags        |
| Slow first requests       | Cold OPcache             | Warm after deploy; ensure `opcache.validate_timestamps=0` in prod  |

---

## Makefile (optional QoL)

```makefile
IMAGE ?= ghcr.io/OWNER/webrick
TAG ?= $(shell git rev-parse --short HEAD)

build:
\tdocker build -t $(IMAGE):$(TAG) .

push:
\tdocker push $(IMAGE):$(TAG)

run:
\tdocker run --rm -p 8080:80 -e WEBRICK_SIGN_KEY=dev -e WEBRICK_COOKIE_KEY=dev $(IMAGE):$(TAG)
```
