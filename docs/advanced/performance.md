# Performance Tuning

Production-grade optimization checklist for Webrick applications.

---

## Benchmarking Baseline

Use `wrk` or `ab` to establish baseline:
```bash
# Install wrk
apt-get install wrk  # or brew install wrk

# Benchmark
wrk -t4 -c100 -d30s http://127.0.0.1:8000/ping

# Expected baseline with OPcache + route cache
# 10,000+ req/s for simple routes
# 2,000-5,000 req/s for typical API endpoints
```

---

## Optimization Checklist

### ✅ **1. Enable OPcache** (Critical)

**php.ini** (production):
```ini
opcache.enable=1
opcache.enable_cli=0
opcache.validate_timestamps=0    ; Immutable releases (deploy by symlink flip)
opcache.revalidate_freq=0
opcache.jit=disable               ; Keep predictable unless proven faster

; Size to your codebase
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.max_wasted_percentage=5
opcache.save_comments=1
```

**Impact**: **5-10x faster** vs no OPcache.

---

### ✅ **2. Prebuild Route Cache** (High Impact)
```bash
# In CI/build step
php ./webrick route:cache --cache=.route-cache --routes=routes.php
```

Ship `.route-cache/` with your artifact.
```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: ShardedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/.route-cache'  // Pre-built
);
```

**Impact**: **50% faster boot** vs live registration (~100ms → ~50ms for 1000 routes).

---

### ✅ **3. Use ShardedMatcher** (100+ Routes)
```php
$matcher = ShardedMatcher::make();
```

**Benefits**:
- Lazy loads only needed shards
- Better OPcache locality
- Faster than FusedMatcher for large apps

**When to use Fused**:
- < 100 routes
- Serverless/edge deployments
- Simple services

---

### ✅ **4. Minimize Pre-Global Middleware**

Remove unused middleware in production:
```php
$preGlobal = [
    // ✅ Keep
    GatewayHardeningMiddleware::class,
    TelemetryMiddleware::class,
    CacheValidatorsMiddleware::class,
    NegotiationMiddleware::class,

    // ❌ Remove in prod
    // ResponseLinterMiddleware::class,  // Dev only
    // MaintenanceModeMiddleware::class,  // Only during maintenance
];
```

**Each middleware adds ~0.5-2ms latency**.

---

### ✅ **5. Compression Settings**
```php
new CompressionMiddleware(
    minBytes: 1400,              // MTU-friendly (1 packet)
    prefOrder: ['zstd', 'br'],   // Skip gzip (slower, worse ratio)
    etagMode: CompressionMiddleware::ETAG_STRONG_DERIVE,  // Avoid recomputing hash
    maxBufferBytes: 8_388_608    // 8MB safety ceiling
);
```

**zstd** is **2-3x faster** than gzip with better compression.

---

### ✅ **6. Response Cache (Micro-Cache)**
```php
new ResponseCacheMiddleware(
    ttlSeconds: 5,       // 5-second micro-cache
    includeQuery: true,
    maxBodyBytes: 1_048_576  // 1MB
);
```

**Impact**: **10-100x faster** for hot GET endpoints (bypasses handler entirely).

**Best for**:
- Product listings
- Public APIs
- Read-heavy endpoints

---

### ✅ **7. PHP-FPM Tuning**
```ini
; /etc/php/8.4/fpm/pool.d/www.conf

[www]
pm = static                    ; Or 'dynamic' for variable load
pm.max_children = 24           ; floor(RAM_for_PHP / avg_worker_RSS)
pm.max_requests = 1000         ; Recycle to avoid leaks

request_terminate_timeout = 120s
request_slowlog_timeout = 3s
slowlog = /var/log/php-fpm-slow.log
```

**Sizing `max_children`**:
```bash
# Measure average worker RSS
ps -o rss= -C php-fpm8.4 | awk '{sum+=$1; n++} END {print "avg_mb=" sum/n/1024}'

# Example: 90MB per worker, 2.2GB for PHP
# max_children = floor(2200 / 90) = 24
```

---

### ✅ **8. Nginx Tuning**
```nginx
worker_processes auto;
worker_connections 2048;

# Disable buffering for API
location /api/ {
    fastcgi_buffering off;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

# Cache static assets
location ~* \.(css|js|png|jpg|jpeg|gif|ico|woff2)$ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}
```

---

### ✅ **9. Database Connection Pooling**

Use persistent connections:
```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
```

**Impact**: Saves ~10-50ms per request (connection overhead).

---

### ✅ **10. Avoid Attribute Scanning in Runtime**

Prebuild attribute routes into route cache:
```php
// In build script
RouteCache::build([
    'register' => static function (Registrar $r): void {
        require __DIR__ . '/../routes.php';

        // Scan attributes during build, not runtime
        AttributeRouteLoader::registerFromDirs($r, [
            'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes'
        ]);
    }
]);
```

**Scanning 1000 classes at runtime = ~100ms penalty**.

---

## Profiling

### Xdebug Profiler (Dev)
```bash
php -d xdebug.mode=profile \
    -d xdebug.output_dir=/tmp \
    public/index.php
```

Analyze with **QCacheGrind** or **KCacheGrind**.

### Blackfire.io (Prod-Safe)
```bash
blackfire curl http://example.com/api/users
```

### Simple Timing
```php
final class TimingMiddleware
{
    public function __invoke(Request $req, Closure $next): Response
    {
        $start = hrtime(true);
        $resp = $next($req);
        $duration = (hrtime(true) - $start) / 1e6;

        error_log(sprintf("[%s] %s: %.2fms",
            $req->getAttribute('request_id'),
            $req->getPath(),
            $duration
        ));

        return $resp;
    }
}
```

---

## Common Bottlenecks

| Issue                   | Symptom             | Fix                                      |
| ----------------------- | ------------------- | ---------------------------------------- |
| Cold OPcache            | First hit slow      | Warm cache post-deploy                   |
| Attribute scanning      | Boot ~100ms         | Prebuild route cache                     |
| Large JSON responses    | High memory         | Use pagination; enable compression       |
| N+1 queries             | DB load spikes      | Eager load; use query logging            |
| No response cache       | Redundant work      | Add ResponseCacheMiddleware for hot GETs |
| Double compression      | CPU waste           | Pick edge OR app, not both               |
| Unindexed DB columns    | Slow queries        | Add indexes; analyze EXPLAIN             |
| Too many pre-globals    | High latency        | Remove unused middleware                 |
| Small FPM pool          | 502 errors          | Size `pm.max_children` by memory         |
| No connection pooling   | Slow DB connections | Use persistent PDO connections           |

---

## Production Checklist

- [ ] OPcache enabled (`validate_timestamps=0`)
- [ ] Route cache prebuilt in CI
- [ ] ShardedMatcher for 100+ routes
- [ ] Compression enabled (app OR edge, not both)
- [ ] Response cache for hot GETs
- [ ] PHP-FPM sized by memory
- [ ] Database connection pooling
- [ ] Static assets cached at edge
- [ ] Unnecessary middleware removed
- [ ] Profiling set up (Blackfire/Xdebug)

---

## Benchmark Results (Reference)

**Setup**: 4-core, 8GB RAM, PHP 8.4, OPcache, ShardedMatcher, micro-cache

| Endpoint              | Requests/sec | P50 Latency | P99 Latency |
| --------------------- | -----------: | ----------: | ----------: |
| `/ping` (cached)      |      25,000+ |        2ms  |        5ms  |
| `/api/users` (cached) |      15,000+ |        3ms  |        8ms  |
| `/api/users` (DB)     |       2,500+ |       15ms  |       45ms  |
| `/api/heavy` (no cache) |        500+ |      80ms  |      200ms  |

**Key Insight**: Micro-cache provides **5-10x improvement** for read-heavy endpoints.
