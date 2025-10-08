# Deployments Overview

Production recipes and guardrails for running Webrick reliably—whether you ship to bare metal, VMs, containers, or serverless edges.

---

## Goals

* **Fast cold start:** warm route cache, warm OPcache.
* **Predictable latency:** pre-global hardening, throttles, compression, and validators.
* **Safe rollouts:** atomic releases, quick rollback, health probes.

---

## Environments

* **Dev/stage:** verbose logs, Response Linter enabled (`warn`/`throw`), low TTL caches.
* **Prod:** stable keys, strict CORS/policies, long-lived OPcache, telemetry enabled, linter off.

---

## Build artifacts (12-factor)

* Commit code; **do not** commit secrets.
* Build a **release artifact** (or container) with:

    * `vendor/` installed
    * `docs/` optional
    * `public/` webroot
    * `routes/`, `src/`, `scripts/`
    * **Prebuilt route cache** (see “Route Cache & Warmup”)
* Runtime writes only to `var/` (e.g., `var/cache/routes/`, logs).

---

## App bootstrap (recap)

Your front controller (`public/index.php`) boots the kernel with:

* **Matcher:** sharded or fused (pick sharded for larger apps)
* **Route cache path:** writable, ideally **warmed in CI**
* **Pre-global** middleware: hardening → telemetry → limits → throttle → cookies → normalize → sanitizer → negotiation → response cache → validators
* **Post-global** middleware: compression → CORS/policies → Vary accumulator → (linter in dev)

---

## Web server layouts

### Nginx → PHP-FPM (recommended)

* Serve static assets directly.
* Forward everything else to `public/index.php`.
* Align **timeouts** with streaming/long requests (avoid buffering SSE).

> A full example lives in `deployments/nginx-apache-fpm.md`.

### Apache → PHP-FPM

* Use `FallbackResource /index.php` or RewriteRules.
* Disable `mod_deflate` re-compression if app already compresses.

---

## PHP-FPM & OPcache

* Enable **OPcache**; set `opcache.validate_timestamps=0` in prod (immutable releases).
* Size for your codebase (`opcache.memory_consumption`, `opcache.interned_strings_buffer`).
* Pool tuning:

    * **pm**: `ondemand` or `dynamic` depending on traffic shape.
    * **pm.max_children**: based on CPU * work factor (DB calls, external IO).
    * **pm.max_requests**: rotate workers to mitigate leaks (e.g., `1000`).

See `deployments/php-fpm-tuning.md` for a checklist.

---

## Route cache & warmup (critical)

* Build the route cache **ahead of time** in CI:

    * Sharded: directory of optimized match files.
    * Fused: single optimized PHP file for tiny apps.
* Bake the cache into the artifact; ensure prod has **read** access and can clear on new releases.

Details in `deployments/route-cache-warmup.md`.

---

## Compression & validators in prod

* Turn on **Compression** (prefer `zstd`/`br`, fall back to `gzip`).
* Use **ETag strategy: recompute-strong** for clarity with proxies.
* Enable **CacheValidators** pre-handler; combine with `Cache-Control` from handlers for wins via **304**.

---

## CORS & security headers

* Lock `allow_origins` to your domains; echo specific origins when using credentials.
* Ship CSP, Referrer-Policy, Permissions-Policy, nosniff, and frame options centrally.

See `deployments/nginx-apache-fpm.md` for server header synergy.

---

## Zero-downtime rollouts

1. **Build** artifact/container; run tests & linters.
2. **Warm** OPcache (optional) by hitting a small set of URLs post-deploy.
3. **Warm** route cache (already baked).
4. **Enable maintenance** (optional for DB migrations).
5. **Migrate** databases with backward-compatible steps.
6. **Flip symlink** or **roll new container** (blue/green or canary).
7. **Smoke test** via health/bypass.
8. **Disable maintenance**; watch metrics and error rates.

Rollback: swap symlink or roll back container image; clear caches if necessary.

---

## Health checks

* `/health` → 200, tiny body, no DB if possible.
* Keep **outside** maintenance mode allowlist.
* Add liveness/readiness probes in container orchestrators.

---

## Observability & budgets

* Telemetry headers: `X-Request-Id`, optional `Server-Timing`.
* Metrics to export:

    * `http_request_duration_seconds` (histogram by route)
    * `response_cache_hits_total` / `misses_total`
    * `throttle_requests_limited_total`
    * `errors_total{type=...}`
* Log JSON with request/correlation IDs.

---

## CI/CD checklist

* [ ] `composer install --no-dev --classmap-authoritative`
* [ ] Build route cache (`scripts/build-route-cache.php`)
* [ ] Static analysis/tests pass
* [ ] Artifact packaged (or container image built)
* [ ] Secrets injected at deploy time (env/secret store)
* [ ] Migrations run, with rollback plan
* [ ] Post-deploy smoke test & alerts quiet

---

## Containers & serverless

* **Containers:** include PHP-FPM + Nginx (or Caddy) under Supervisor or sidecar. Non-root, read-only FS except `var/`.
* **Serverless:** ensure cold-start bootstrap runs once; pre-bundle route cache; use minimal extensions; consider timeouts for streaming endpoints.

See `deployments/containers-and-ci.md` and `deployments/vercel-and-serverless.md`.

---

## Common pitfalls

| Issue                     | Cause                              | Fix                                                            |
| ------------------------- | ---------------------------------- | -------------------------------------------------------------- |
| 404 on everything         | Wrong `try_files` / docroot        | Point to `public/`; `try_files $uri /index.php?$query_string;` |
| Double compression        | CDN auto-compress + app            | Disable at CDN or at app; ensure single `Content-Encoding`     |
| Stale assets after deploy | Cache not busted                   | Version asset URLs; set long `max-age` + `immutable`           |
| 5xx under load            | FPM pool too small / DB saturation | Tune `pm.max_children`; add DB pools; implement throttles      |
| Signed URLs failing       | Key mismatch across instances      | Use consistent `WEBRICK_SIGN_KEY`; plan rotation               |

