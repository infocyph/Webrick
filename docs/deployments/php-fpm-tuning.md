# PHP-FPM Tuning & OPcache

Make PHP workers predictable under load. This page gives practical defaults and a repeatable sizing method for **OPcache** and **PHP-FPM** pools.

---

## OPcache (must-have in prod)

Enable and lock code at deploy time (immutable releases).

`php.ini` (production):

```ini
opcache.enable=1
opcache.enable_cli=0
opcache.validate_timestamps=0   ; immutable release (symlink flip)
opcache.revalidate_freq=0
opcache.jit=disable             ; keep predictable unless proven faster

; Size these to your codebase; start here and adjust via metrics:
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.max_wasted_percentage=5
opcache.save_comments=1
```

For **dev/stage** (hot reload):

```ini
opcache.validate_timestamps=1
opcache.revalidate_freq=1
```

**Warm it** post-deploy by hitting a small URL set (or run a CLI warmup script that requires common entry points).

---

## PHP-FPM process model

FPM serves PHP from a pool. Right-size it so you don’t oversubscribe CPU or starve memory.

### Recommended: `pm = dynamic` (or `ondemand` for spiky/low traffic)

`/etc/php/8.4/fpm/pool.d/www.conf` (example):

```ini
[www]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm.sock

pm = dynamic               ; or ondemand
pm.max_children = 24       ; see sizing below
pm.start_servers = 6
pm.min_spare_servers = 6
pm.max_spare_servers = 12
pm.max_requests = 1000     ; recycle to avoid leaks

; Timeouts
request_terminate_timeout = 120s   ; kill long-running scripts
request_slowlog_timeout = 3s
slowlog = /var/log/php8.4-fpm.slow.log

; Realpath & security
security.limit_extensions = .php
catch_workers_output = yes
```

**Ondemand** variant (useful in server-scarce environments):

```ini
pm = ondemand
pm.max_children = 24
pm.process_idle_timeout = 10s
pm.max_requests = 1000
```

---

## How to size `pm.max_children`

Rule of thumb:

```
max_children ≈ floor( (RAM_for_PHP) / (avg_RSS_per_worker) )
```

1. Measure average RSS (resident set size) per worker under typical traffic:

```bash
ps -o rss= -C php-fpm8.4 | awk '{sum+=$1; n++} END {printf "avg_kb=%.0f\n", sum/n}'
```

Convert to MiB (`avg_kb/1024`). Suppose ~90 MiB.

2. Decide how much RAM you want to give PHP workers (exclude OS, web server, DB drivers, caches). On a 4 GiB box with Nginx + Redis external, maybe 2.2 GiB for PHP.

3. `max_children = floor( 2.2 GiB / 90 MiB ) = floor( 2252 / 90 ) = 25` → set **24** for headroom.

> Validate with load tests; watch swap (should be **0** in prod).

---

## Concurrency & upstreams

* If your handlers do **blocking I/O** (DB, HTTP calls), you can run more workers than CPU cores because they spend time waiting.
* If mostly **CPU-bound**, align workers close to **cores**.
* Use **Throttle** middleware for heavy endpoints to protect pools.

---

## Timeouts (be explicit)

* `request_terminate_timeout` caps a single request runtime. Set ≥ your longest legitimate task (e.g., 120–180s).
* Nginx/Apache **read** timeouts must be **≥** FPM’s terminate timeout to avoid upstream cutting early.
* For **streaming/SSE**, extend **read timeouts** but keep terminate timeouts for non-stream endpoints.

---

## Logs & diagnostics

Enable slowlog and inspect:

```ini
request_slowlog_timeout = 3s
slowlog = /var/log/php8.4-fpm.slow.log
```

Then:

```bash
tail -f /var/log/php8.4-fpm.slow.log
```

Correlate with Telemetry’s `X-Request-Id`.

---

## Health & readiness

Expose a fast **/health** route with no DB. Configure orchestrator probes:

* **Liveness**: `/health` returns 200
* **Readiness**: optionally checks Redis/DB briefly (or just returns 200 if you rely on retry logic)

---

## Common knobs (pitfalls included)

* `pm.max_requests=1000` recycles workers—good to limit memory creep.
* `rlimit_files` (if opening many files/sockets) – raise as needed.
* `catch_workers_output=yes` forwards `stdout/stderr` to logs (useful in containers).
* **Disable Xdebug** in prod.
* Ensure system limits allow enough processes & open files:

    * `/etc/security/limits.conf` (`nofile`, `nproc`)
    * `sysctl fs.file-max` for OS-wide file handles.

---

## Container specifics

* One container can run **php-fpm** and another for **nginx**, or both via Supervisor (simple setups).
* Mount only **/app/var** writable; keep code read-only.
* Pass `PHP_INI_SCAN_DIR` for environment-specific INI fragments.
* Set `OPCACHE_VALIDATE_TIMESTAMPS=0` in prod images; deploy by image replace, not in-place edits.

---

## Observability (watch these)

* `php_fpm_processes{state=...}` (active/idle)
* `php_fpm_accepted_connections_total`
* `php_fpm_max_children_reached_total` (**must be 0**)
* `opcache_memory_used_bytes` vs `opcache_memory_free_bytes`
* `opcache_cached_scripts` vs `max_accelerated_files`
* App-level metrics: request duration, 5xx rates, throttle 429s, response-cache hits

---

## Troubleshooting

| Symptom                                   | Likely cause                            | Fix                                                                     |
| ----------------------------------------- | --------------------------------------- | ----------------------------------------------------------------------- |
| `server reached pm.max_children` messages | Pool too small / memory mis-estimated   | Raise `max_children` or reduce per-request memory; add cache/DB pooling |
| Random 502/504 under load                 | Upstream timeouts too short             | Increase Nginx `fastcgi_read_timeout`/Apache `ProxyTimeout`             |
| Memory ballooning over time               | Leaks or unbounded buffering            | Lower `pm.max_requests` (e.g., 500), audit large responses/streams      |
| First hit slow after deploy               | Cold OPcache                            | Warm entry points post-deploy; prebuild route cache                     |
| High CPU with low RPS                     | Misconfigured compression or busy loops | Ensure single compression layer; profile hot code paths                 |
| Cache misses explode                      | Vary keys wrong or tiny TTL             | Use Vary Accumulator; adjust TTL; check Response Cache config           |

---

## Quick checklist

* [ ] OPcache enabled; timestamps off in prod; sized memory & files
* [ ] FPM `pm` tuned (`dynamic`/`ondemand`), `max_children` sized by memory
* [ ] `pm.max_requests` set (~1000) to recycle workers
* [ ] Slowlog on; request/terminate timeouts aligned with web server
* [ ] No Xdebug in prod; system limits (nofile/nproc) sane
* [ ] Metrics wired; alerts on **max_children reached** and 5xx spikes
