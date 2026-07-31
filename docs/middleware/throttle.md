# Throttle Middleware

Rate limiting middleware that enforces request limits per client/user with configurable windows and costs.

Pass any application-provided PSR-6 pool to use throttling without CacheLayer.
Install CacheLayer 2 only when using the default local pool or its atomic counter
backend:

```bash
composer require infocyph/cachelayer
```

---

## Configuration
```php
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Psr\Cache\CacheItemPoolInterface;

$preGlobal[] = new ThrottleMiddleware(
    max: 60,                            // Maximum requests per window
    window: 60,                         // Window duration in seconds
    pool: $cache,                       // PSR-6 cache pool for counters
    retryAsDate: false,                 // Retry-After as HTTP-date (true) or seconds (false)
    identifierResolver: null,           // Custom key resolver (default: client IP)
    emitStandardRateLimit: true,        // Emit RateLimit-* headers (RFC draft)
    scope: 'global',                    // Logical bucket name/namespace
    costAttribute: 'rate_cost.thm',     // Request attribute for per-request cost
    bypass: null,                       // Closure to bypass throttle
    counterStore: null                  // Optional atomic CacheLayer counter backend
);
```

### Constructor Parameters

| Parameter               | Type                      | Default           | Description                                        |
| ----------------------- | ------------------------- | ----------------- | -------------------------------------------------- |
| `max`                   | `int`                     | `1`               | Maximum requests allowed per window                |
| `window`                | `int`                     | `1`               | Time window in seconds                             |
| `pool`                  | `?CacheItemPoolInterface` | `null`            | PSR-6 fallback; defaults to a local CacheLayer pool |
| `retryAsDate`           | `bool`                    | `false`           | `Retry-After` as HTTP-date (true) or seconds (false) |
| `identifierResolver`    | `?callable`               | `null`            | Custom function to resolve identifier from request |
| `emitStandardRateLimit` | `bool`                    | `true`            | Emit `RateLimit-*` headers (IETF draft standard)   |
| `scope`                 | `string`                  | `'global'`        | Namespace for cache keys (allows multiple buckets) |
| `costAttribute`         | `string`                  | `'rate_cost.thm'` | Request attribute name for custom cost             |
| `bypass`                | `?callable`               | `null`            | Function to bypass throttle for certain requests   |
| `counterStore`          | `?AtomicCounterStoreInterface` | `null`       | Atomic Redis/Valkey counter fast path for concurrent workers |

---

## Usage Patterns

### Basic Rate Limiting
```php
// 60 requests per minute, globally
$preGlobal[] = new ThrottleMiddleware(
    max: 60,
    window: 60,
    pool: Cache::pool('throttle')
);
```

### Per-Route Throttling (Via Alias)

First, register the alias:
```php
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

MiddlewareAliases::register(
    'throttle',
    static fn(...$params) => new ThrottleMiddleware(
        max: (int)($params[0] ?? 60),
        window: (int)($params[1] ?? 60),
        pool: Cache::pool('throttle')
    )
);
```

Then use in routes:
```php
// Strict limit on login
Route::post('/login', [AuthController::class, 'login'], [
    'middleware' => ['throttle:5,300']  // 5 attempts per 5 minutes
]);

// Relaxed limit on API
Route::get('/api/users', [UserController::class, 'index'], [
    'middleware' => ['throttle:120,60']  // 120 requests per minute
]);
```

### Per-User vs Per-IP

**Default (Per-IP)**:
```php
new ThrottleMiddleware(
    max: 60,
    window: 60,
    pool: $cache
    // Uses client IP by default
);
```

**Per-User (Custom Resolver)**:
```php
new ThrottleMiddleware(
    max: 1000,  // Authenticated users get higher limit
    window: 60,
    pool: $cache,
    identifierResolver: static function (Request $r): string {
        // Use authenticated user ID if available
        $userId = $r->getAttribute('auth.user_id');
        return $userId ? "user:{$userId}" : "ip:{$r->getAttribute('client_ip')}";
    }
);
```

### Per-Request Cost

Charge different rates for different endpoints:
```php
// In middleware or route handler
Route::post('/expensive-operation', function (Request $r) {
    // This request costs 10 tokens
    $r = $r->withAttribute('rate_cost.thm', 10);
    return $next($r);
})->withMiddleware([
    new ThrottleMiddleware(max: 100, window: 60, pool: $cache)
]);

// Regular request costs 1 token (default)
Route::get('/cheap-read', fn() => Response::json(['ok' => true]))->withMiddleware([
    new ThrottleMiddleware(max: 100, window: 60, pool: $cache)
]);
```

**Result**: Expensive operation consumes 10 tokens, cheap read consumes 1.

### Bypass for Internal/Health Checks
```php
new ThrottleMiddleware(
    max: 60,
    window: 60,
    pool: $cache,
    bypass: static function (Request $r): bool {
        // Skip throttle for health checks
        if ($r->getPath() === '/health') {
            return true;
        }

        // Skip for internal IPs
        $ip = $r->getAttribute('client_ip');
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
            return true;
        }

        // Skip for API keys with bypass privilege
        $apiKey = $r->getHeaderLine('X-API-Key');
        if ($apiKey && ApiKeyService::hasBypass($apiKey)) {
            return true;
        }

        return false;  // Apply throttle
    }
);
```

---

## Headers Emitted

### Standard Headers (Always)
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1700000000
```

### On Throttle (429 Response)
```
HTTP/1.1 429 Too Many Requests
Retry-After: 23
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1700000023
```

### IETF Draft Standard (Optional)

When `emitStandardRateLimit: true`:
```
RateLimit-Limit: 60
RateLimit-Remaining: 42
RateLimit-Reset: 23
```

Format: `RateLimit-Reset` is seconds until reset (not timestamp).

---

## Multiple Throttle Buckets

Use different scopes for different limits:
```php
// Global throttle (all requests)
$preGlobal[] = new ThrottleMiddleware(
    max: 1000,
    window: 60,
    pool: $cache,
    scope: 'global'
);

// Login throttle (stricter)
MiddlewareAliases::register('throttle.login', fn() => new ThrottleMiddleware(
    max: 5,
    window: 300,
    pool: $cache,
    scope: 'login'  // Separate bucket
));

Route::post('/login', /* ... */, ['middleware' => ['throttle.login']]);
```

**Result**: User can make 1000 requests/minute globally, but only 5 login attempts per 5 minutes.

---

## Redis Backend (Recommended)

For distributed/multi-server setups, use CacheLayer's atomic counter API:
```php
use Infocyph\CacheLayer\Counter\AtomicCounters;

new ThrottleMiddleware(
    max: 60,
    window: 60,
    counterStore: AtomicCounters::redis(
        namespace: 'throttle',
        dsn: 'redis://127.0.0.1:6379',
    ),
);
```

**Why Redis**:
- ✅ Atomic INCR operations (no race conditions)
- ✅ TTL built-in (automatic cleanup)
- ✅ Shared across multiple app servers

---

The generic PSR-6 fallback remains available for local/single-worker use, but its read-modify-write sequence is not atomic across workers.

## Algorithm (Fixed Window)
```
1. Generate key: {scope}:{identifier}:{window_start}
2. Increment counter in cache (atomic)
3. If counter <= max: allow (200)
4. If counter > max: deny (429)
5. Set TTL = window duration
```

The atomic path counts rejected attempts until the fixed window expires, preventing repeated over-limit requests from racing back under the limit.

**Window sliding**: Uses fixed windows aligned to Unix timestamps.
```php
$windowStart = floor(time() / $window) * $window;
$key = "{$scope}:{$identifier}:{$windowStart}";
```

**Example** (60s window, max=10):
- 12:00:00 - 12:00:59 → Window 1 (max 10 requests)
- 12:01:00 - 12:01:59 → Window 2 (max 10 requests, reset)

---

## Testing

### Unit Test
```php
use PHPUnit\Framework\TestCase;

class ThrottleTest extends TestCase
{
    public function testAllowsWithinLimit(): void
    {
        $cache = new \Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter();
        $throttle = new ThrottleMiddleware(max: 3, window: 60, pool: $cache);

        $req = Request::fake(method: 'GET', uri: '/test');
        $next = fn($r) => Response::json(['ok' => true]);

        // First 3 should pass
        for ($i = 0; $i < 3; $i++) {
            $resp = $throttle($req, $next);
            $this->assertEquals(200, $resp->getStatusCode());
        }

        // 4th should be throttled
        $resp = $throttle($req, $next);
        $this->assertEquals(429, $resp->getStatusCode());
        $this->assertNotEmpty($resp->getHeaderLine('Retry-After'));
    }
}
```

### Integration Test
```bash
#!/bin/bash
# test-throttle.sh

set -e

BASE_URL="http://localhost:8000"
ENDPOINT="/api/test"

echo "Testing throttle (limit: 5/min)..."

SUCCESS=0
for i in {1..10}; do
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL$ENDPOINT")

    if [ "$HTTP_CODE" = "200" ]; then
        SUCCESS=$((SUCCESS + 1))
    elif [ "$HTTP_CODE" = "429" ]; then
        echo "✅ Throttled after $SUCCESS successful requests"
        exit 0
    fi
done

echo "❌ Expected throttle after 5 requests, but got $SUCCESS/10"
exit 1
```

---

## Monitoring & Alerts

### Metrics to Track
```php
// Prometheus-style
throttle_requests_total{result="allowed"}
throttle_requests_total{result="denied"}
throttle_denials_by_endpoint{endpoint="/api/users"}
throttle_remaining_capacity{scope="global"}
```

### Alert Examples

```yaml
# Alert on high throttle rate
- alert: HighThrottleRate
  expr: rate(throttle_requests_total{result="denied"}[5m]) > 10
  for: 5m
  annotations:
    summary: "High rate of throttled requests"
    description: "{{ $value }} requests/sec being throttled"

# Alert on potential abuse
- alert: ThrottleAbuse
  expr: sum(rate(throttle_requests_total{result="denied"}[1m])) by (client_ip) > 50
  annotations:
    summary: "Possible abuse from {{ $labels.client_ip }}"
```

### Logging

```php
// In custom throttle wrapper
if ($throttled) {
    $logger->warning('Request throttled', [
        'ip' => $request->getAttribute('client_ip'),
        'user_id' => $request->getAttribute('auth.user_id'),
        'path' => $request->getPath(),
        'remaining' => 0,
        'reset' => $resetTime
    ]);
}
```

---

## Common Patterns

### API with Tiered Limits

```php
// Free tier: 60/hour
// Pro tier: 1000/hour
// Enterprise: unlimited

new ThrottleMiddleware(
    max: 60,  // Default for free tier
    window: 3600,
    pool: $cache,
    identifierResolver: function (Request $r): string {
        $apiKey = $r->getHeaderLine('X-API-Key');
        $tier = ApiKeyService::getTier($apiKey);

        return match($tier) {
            'pro' => 'pro:' . $apiKey,
            'enterprise' => 'enterprise:' . $apiKey,
            default => 'free:' . ($apiKey ?: $r->getAttribute('client_ip'))
        };
    },
    max: function (Request $r): int {
        $apiKey = $r->getHeaderLine('X-API-Key');
        $tier = ApiKeyService::getTier($apiKey);

        return match($tier) {
            'pro' => 1000,
            'enterprise' => PHP_INT_MAX,  // Unlimited
            default => 60
        };
    }
);
```

### Gradual Backoff

Increase penalty for repeated violations:

```php
new ThrottleMiddleware(
    max: 60,
    window: 60,
    pool: $cache,
    onDeny: function (Request $r, int $remaining, int $reset): Response {
        $key = 'violations:' . $r->getAttribute('client_ip');
        $violations = Cache::increment($key, 1, ttl: 3600);

        // Exponential backoff
        $retryAfter = min(60 * (2 ** ($violations - 1)), 3600);  // Cap at 1 hour

        return Response::json([
            'error' => 'Rate limit exceeded',
            'retry_after' => $retryAfter,
            'violations' => $violations
        ], 429)->withHeader('Retry-After', (string)$retryAfter);
    }
);
```

### Burst + Sustained

Allow short bursts but enforce sustained rate:

```php
// Burst bucket: 20 requests/second
$burstThrottle = new ThrottleMiddleware(
    max: 20,
    window: 1,
    pool: $cache,
    scope: 'burst'
);

// Sustained bucket: 1000 requests/hour
$sustainedThrottle = new ThrottleMiddleware(
    max: 1000,
    window: 3600,
    pool: $cache,
    scope: 'sustained'
);

// Apply both
$preGlobal[] = $burstThrottle;
$preGlobal[] = $sustainedThrottle;
```

---

## Troubleshooting

### Issue: Throttle not working

**Symptoms**: Unlimited requests allowed

**Causes**:
1. Cache not configured correctly
2. Identifier resolver returns different keys per request
3. Bypass function always returns true

**Debug**:
```php
new ThrottleMiddleware(
    max: 5,
    window: 60,
    pool: $cache,
    debug: true,  // If supported
    identifierResolver: function (Request $r): string {
        $id = $r->getAttribute('client_ip');
        error_log("Throttle ID: {$id}");  // Debug
        return $id;
    }
);
```

### Issue: Users throttled incorrectly

**Symptoms**: User gets 429 even with low usage

**Causes**:
1. Shared IP (NAT, VPN, corporate proxy)
2. Multiple users on same identifier
3. Window alignment issue

**Solutions**:
- Use authenticated user ID instead of IP
- Increase limits for known shared IPs
- Use per-user + per-IP hybrid

```php
identifierResolver: function (Request $r): string {
    $userId = $r->getAttribute('auth.user_id');
    $ip = $r->getAttribute('client_ip');

    // If authenticated, use user ID
    if ($userId) {
        return "user:{$userId}";
    }

    // If known shared IP, use session
    if (in_array($ip, ['corporate-nat', '10.0.0.1'])) {
        return "session:{$r->cookie('sess')}";
    }

    // Default to IP
    return "ip:{$ip}";
}
```

### Issue: Cache growing unbounded

**Symptoms**: Redis/cache memory grows over time

**Causes**:
- TTL not set correctly
- Old keys not expiring

**Fix**: Ensure cache adapter supports TTL:
```php
// Verify TTL is working
$item = $cache->getItem('test-ttl');
$item->set('value')->expiresAfter(60);
$cache->save($item);

// Check after 70 seconds
$exists = $cache->hasItem('test-ttl');  // Should be false
```

### Issue: Distributed setup inconsistency

**Symptoms**: Limit enforced differently across servers

**Causes**:
- Using local cache (APCu, file) instead of shared (Redis, Memcached)
- Clock skew between servers

**Fix**:
```php
// Use shared cache
use Symfony\Component\Cache\Adapter\RedisAdapter;

$redis = new \Redis();
$redis->connect('shared-redis-host', 6379);
$cache = new RedisAdapter($redis);

// Ensure NTP sync on all servers
// systemctl enable ntp && systemctl start ntp
```

---

## Performance Considerations

### Cache Operations Per Request

- **Allowed request**: 1 read + 1 write (INCR)
- **Denied request**: 1 read

**Total latency**: ~1-2ms for Redis, <0.5ms for in-memory

### Scaling

**Single Redis**:
- Can handle ~50,000 requests/sec
- Good for most applications

**Redis Cluster**:
- Shard by identifier prefix
- Can handle >100,000 requests/sec

**Optimization**:
```php
// Use pipelining for multiple buckets
$pipe = $redis->multi(\Redis::PIPELINE);
$pipe->incr("global:{$ip}:{$window}");
$pipe->incr("login:{$ip}:{$window}");
$results = $pipe->exec();
```

---

## Security Considerations

### Distributed Denial of Service (DDoS)

Throttle middleware **helps** but isn't a complete solution:

**What it does**:
- ✅ Limits individual IPs/users
- ✅ Prevents application overload
- ✅ Protects expensive endpoints

**What it doesn't do**:
- ❌ Block massive botnet attacks
- ❌ Prevent SYN floods
- ❌ Stop layer 3/4 attacks

**Recommended Stack**:
1. **Network level**: Cloudflare, AWS Shield (layer 3/4)
2. **Edge level**: WAF rules, bot detection
3. **App level**: ThrottleMiddleware (layer 7)

### Rate Limit Bypass Attempts

**Attack**: Attacker rotates IPs to bypass limits

**Mitigations**:
- Require authentication for sensitive endpoints
- Use CAPTCHA after N failures
- Implement device fingerprinting
- Block known proxy/VPN ranges for high-risk operations

```php
new ThrottleMiddleware(
    max: 5,
    window: 300,
    pool: $cache,
    identifierResolver: function (Request $r): string {
        // Combine IP + User-Agent + Accept-Language for basic fingerprint
        $ip = $r->getAttribute('client_ip');
        $ua = substr($r->getHeaderLine('User-Agent'), 0, 50);
        $lang = $r->getHeaderLine('Accept-Language');

        $fingerprint = hash('sha256', "{$ip}:{$ua}:{$lang}");
        return "fp:{$fingerprint}";
    }
);
```

---

## Best Practices

### ✅ **Do**

1. **Start conservative, loosen gradually**
   ```php
   // Start with strict limits
   max: 30, window: 60

   // Monitor, then increase
   max: 120, window: 60
   ```

2. **Use different limits for different endpoints**
   ```php
   Route::post('/login', middleware: ['throttle:5,300']);      // Strict
   Route::get('/api/read', middleware: ['throttle:1000,60']);  // Relaxed
   ```

3. **Provide clear error messages**
   ```php
   return Response::json([
       'error' => 'Rate limit exceeded',
       'limit' => 60,
       'window' => '1 minute',
       'retry_after' => $retryAfter,
       'documentation' => 'https://docs.example.com/rate-limits'
   ], 429);
   ```

4. **Monitor throttle rates**
   ```php
   // Alert if >10% of requests are throttled
   if ($throttledRate > 0.1) {
       alert('High throttle rate - consider increasing limits');
   }
   ```

5. **Document limits in API docs**
   ```
   ## Rate Limits

   - Free tier: 60 requests/hour
   - Pro tier: 1,000 requests/hour
   - Enterprise: Custom limits

   Headers:
   - X-RateLimit-Limit
   - X-RateLimit-Remaining
   - X-RateLimit-Reset
   ```

### ❌ **Don't**

1. **Don't use overly strict limits**
   ```php
   max: 1, window: 60  // ❌ Too strict, poor UX
   ```

2. **Don't throttle health checks**
   ```php
   // Always bypass
   bypass: fn($r) => $r->getPath() === '/health'
   ```

3. **Don't use local cache in multi-server setup**
   ```php
   // ❌ Each server has separate counter
   Cache::local('apcu')

   // ✅ Shared counter
   Cache::redis()
   ```

4. **Don't ignore Retry-After**
   ```php
   // Clients should respect this
   Retry-After: 60
   ```

5. **Don't apply same limits to all endpoints**
   ```php
   // ❌ Read and write have same limit
   // ✅ Different limits based on cost
   ```

---

## Summary

**Throttle middleware is essential for**:
- ✅ Preventing abuse and brute force
- ✅ Ensuring fair resource allocation
- ✅ Protecting against application-level DoS
- ✅ API billing/metering

**Key configuration decisions**:
1. **Max + Window**: Balance UX vs protection
2. **Identifier**: IP vs User vs Hybrid
3. **Scope**: Global vs per-endpoint buckets
4. **Cache**: Redis (distributed) vs local (single server)
5. **Bypass**: Health checks, internal IPs, privileged API keys

**Golden rule**: Start strict, monitor real usage, adjust limits based on data.
