# Rate Limiting Recipe

Protect your API from abuse with flexible rate limiting.

---

## Problem

You need to limit how many requests users can make to prevent abuse, ensure fair usage, and protect your infrastructure.

---

## Solution

### 1. Simple Rate Limiter

```php
<?php
// src/Service/RateLimiter.php

namespace App\Service;

use Psr\SimpleCache\CacheInterface;

final class RateLimiter
{
    public function __construct(
        private CacheInterface $cache
    ) {}

    public function attempt(
        string $key,
        int $maxAttempts,
        int $decaySeconds
    ): bool {
        $attempts = $this->attempts($key);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $this->hit($key, $decaySeconds);

        return true;
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $cacheKey = $this->getCacheKey($key);

        $attempts = (int) $this->cache->get($cacheKey, 0);
        $attempts++;

        $this->cache->set($cacheKey, $attempts, $decaySeconds);

        return $attempts;
    }

    public function attempts(string $key): int
    {
        return (int) $this->cache->get($this->getCacheKey($key), 0);
    }

    public function resetAttempts(string $key): void
    {
        $this->cache->delete($this->getCacheKey($key));
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    public function availableIn(string $key): int
    {
        $cacheKey = $this->getCacheKey($key);
        // Get TTL from cache (implementation-specific)
        return $this->cache->getTtl($cacheKey) ?? 0;
    }

    private function getCacheKey(string $key): string
    {
        return 'rate_limit:' . $key;
    }
}
```

### 2. Rate Limit Middleware

```php
<?php
// src/Middleware/RateLimitMiddleware.php

namespace App\Middleware;

use App\Service\RateLimiter;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Closure;

final class RateLimitMiddleware
{
    public function __construct(
        private RateLimiter $limiter,
        private int $maxAttempts = 60,
        private int $decaySeconds = 60
    ) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        $key = $this->resolveRequestSignature($r);

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            return $this->buildRateLimitedResponse($key);
        }

        $this->limiter->hit($key, $this->decaySeconds);

        $response = $next($r);

        return $this->addHeaders(
            $response,
            $this->maxAttempts,
            $this->limiter->remaining($key, $this->maxAttempts),
            $this->limiter->availableIn($key)
        );
    }

    protected function resolveRequestSignature(Request $r): string
    {
        // Use IP + route
        $ip = $r->getAttribute('client_ip', $r->server('REMOTE_ADDR'));
        $route = $r->getPath();

        return sha1($ip . '|' . $route);
    }

    protected function buildRateLimitedResponse(string $key): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return Response::json([
            'message' => 'Too many requests',
            'retry_after' => $retryAfter
        ], 429)->withHeader('Retry-After', (string) $retryAfter);
    }

    protected function addHeaders(
        Response $response,
        int $limit,
        int $remaining,
        int $retryAfter
    ): Response {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) (time() + $retryAfter));
    }
}
```

### 3. Per-User Rate Limiting

```php
final class PerUserRateLimitMiddleware extends RateLimitMiddleware
{
    protected function resolveRequestSignature(Request $r): string
    {
        $userId = $r->getAttribute('auth.user_id');

        if ($userId) {
            return "user:{$userId}";
        }

        // Fallback to IP for unauthenticated
        return 'ip:' . $r->getAttribute('client_ip', $r->server('REMOTE_ADDR'));
    }
}

// Usage
Route::get('/api/data', $handler)->middleware([
    'auth',
    new PerUserRateLimitMiddleware($limiter, maxAttempts: 1000, decaySeconds: 60)
]);
```

### 4. Dynamic Rate Limits (Tiered)

```php
final class TieredRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct(
        private RateLimiter $limiter,
        private array $tiers = []
    ) {
        $this->tiers = $tiers ?: [
            'free' => ['limit' => 60, 'decay' => 60],
            'pro' => ['limit' => 300, 'decay' => 60],
            'enterprise' => ['limit' => PHP_INT_MAX, 'decay' => 60]
        ];
    }

    public function __invoke(Request $r, Closure $next): Response
    {
        $tier = $this->getUserTier($r);
        $config = $this->tiers[$tier];

        $this->maxAttempts = $config['limit'];
        $this->decaySeconds = $config['decay'];

        return parent::__invoke($r, $next);
    }

    private function getUserTier(Request $r): string
    {
        $userId = $r->getAttribute('auth.user_id');

        if (!$userId) {
            return 'free';
        }

        $user = UserRepository::find($userId);
        return $user['subscription_tier'] ?? 'free';
    }
}
```

### 5. Cost-Based Rate Limiting

```php
final class CostBasedRateLimitMiddleware
{
    public function __construct(
        private RateLimiter $limiter,
        private int $maxCost = 100,
        private int $decaySeconds = 60
    ) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        $key = $this->resolveKey($r);
        $cost = $r->getAttribute('rate_limit.cost', 1);

        $currentCost = $this->limiter->attempts($key);

        if ($currentCost + $cost > $this->maxCost) {
            return Response::json([
                'message' => 'Rate limit exceeded',
                'current_cost' => $currentCost,
                'max_cost' => $this->maxCost
            ], 429);
        }

        // Add cost
        for ($i = 0; $i < $cost; $i++) {
            $this->limiter->hit($key, $this->decaySeconds);
        }

        return $next($r);
    }

    private function resolveKey(Request $r): string
    {
        $userId = $r->getAttribute('auth.user_id');
        return "cost:user:{$userId}";
    }
}

// Usage - mark expensive operations
Route::post('/api/expensive', function(Request $r) {
    // This operation costs 10 tokens
    $r = $r->withAttribute('rate_limit.cost', 10);

    // ... expensive operation ...

    return Response::json(['result' => $result]);
})->middleware([new CostBasedRateLimitMiddleware($limiter, maxCost: 100)]);
```

---

## Advanced Patterns

### Sliding Window

```php
final class SlidingWindowRateLimiter
{
    public function __construct(
        private CacheInterface $cache
    ) {}

    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Get timestamps of requests in current window
        $timestamps = $this->cache->get($key, []);

        // Filter out old timestamps
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $windowStart);

        if (count($timestamps) >= $maxAttempts) {
            return false;
        }

        // Add current timestamp
        $timestamps[] = $now;
        $this->cache->set($key, $timestamps, $windowSeconds);

        return true;
    }
}
```

### Token Bucket Algorithm

```php
final class TokenBucketRateLimiter
{
    private int $capacity;
    private float $refillRate;  // tokens per second

    public function __construct(
        private CacheInterface $cache,
        int $capacity = 100,
        float $refillRate = 1.0
    ) {
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
    }

    public function allow(string $key, int $tokens = 1): bool
    {
        $bucket = $this->cache->get($key, [
            'tokens' => $this->capacity,
            'last_refill' => time()
        ]);

        $now = time();
        $timePassed = $now - $bucket['last_refill'];

        // Refill tokens based on time passed
        $bucket['tokens'] = min(
            $this->capacity,
            $bucket['tokens'] + ($timePassed * $this->refillRate)
        );
        $bucket['last_refill'] = $now;

        // Check if enough tokens
        if ($bucket['tokens'] < $tokens) {
            $this->cache->set($key, $bucket, 3600);
            return false;
        }

        // Consume tokens
        $bucket['tokens'] -= $tokens;
        $this->cache->set($key, $bucket, 3600);

        return true;
    }

    public function remaining(string $key): float
    {
        $bucket = $this->cache->get($key, [
            'tokens' => $this->capacity,
            'last_refill' => time()
        ]);

        $now = time();
        $timePassed = $now - $bucket['last_refill'];

        return min(
            $this->capacity,
            $bucket['tokens'] + ($timePassed * $this->refillRate)
        );
    }
}
```

### Distributed Rate Limiting (Redis Lua)

```php
final class RedisRateLimiter
{
    private \Redis $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $script = <<<'LUA'
local key = KEYS[1]
local max = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])

local current = redis.call('GET', key)
if current and tonumber(current) >= max then
    return 0
end

redis.call('INCR', key)
if not current then
    redis.call('EXPIRE', key, ttl)
end

return 1
LUA;

        $result = $this->redis->eval($script, [$key, $maxAttempts, $decaySeconds], 1);

        return $result === 1;
    }

    public function attempts(string $key): int
    {
        return (int) $this->redis->get($key);
    }

    public function reset(string $key): void
    {
        $this->redis->del($key);
    }
}
```

---

## Testing

### Unit Test

```php
<?php

use PHPUnit\Framework\TestCase;
use App\Service\RateLimiter;

class RateLimiterTest extends TestCase
{
    public function testAllowsWithinLimit(): void
    {
        $cache = new InMemoryCache();
        $limiter = new RateLimiter($cache);

        $key = 'test:user:1';

        // First 5 should succeed
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->attempt($key, 5, 60));
        }

        // 6th should fail
        $this->assertFalse($limiter->attempt($key, 5, 60));
    }

    public function testResetClearsAttempts(): void
    {
        $cache = new InMemoryCache();
        $limiter = new RateLimiter($cache);

        $key = 'test:user:2';

        $limiter->hit($key, 60);
        $limiter->hit($key, 60);
        $this->assertEquals(2, $limiter->attempts($key));

        $limiter->resetAttempts($key);
        $this->assertEquals(0, $limiter->attempts($key));
    }
}
```

### Integration Test

```bash
#!/bin/bash
# test-rate-limit.sh

set -e

BASE_URL="http://localhost:8000"
ENDPOINT="/api/test"

echo "Testing rate limit (max: 5/minute)..."

SUCCESS=0
for i in {1..10}; do
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL$ENDPOINT")

    if [ "$HTTP_CODE" = "200" ]; then
        SUCCESS=$((SUCCESS + 1))
    elif [ "$HTTP_CODE" = "429" ]; then
        echo "✅ Rate limited after $SUCCESS requests"
        exit 0
    fi
done

echo "❌ Expected rate limit after 5 requests, got $SUCCESS/10"
exit 1
```

---

## Monitoring & Alerts

### Metrics Collection

```php
final class RateLimitMetrics
{
    public static function recordHit(string $key, bool $allowed): void
    {
        $metrics = [
            'timestamp' => time(),
            'key' => $key,
            'allowed' => $allowed,
            'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];

        // Send to metrics system (Prometheus, StatsD, etc.)
        Metrics::increment('rate_limit_hits_total', [
            'result' => $allowed ? 'allowed' : 'denied',
            'endpoint' => $metrics['endpoint']
        ]);
    }
}

// In middleware
if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
    RateLimitMetrics::recordHit($key, false);
    return $this->buildRateLimitedResponse($key);
}

RateLimitMetrics::recordHit($key, true);
```

### Alert Rules

```yaml
# Prometheus alert rules

groups:
  - name: rate_limiting
    rules:
      - alert: HighRateLimitDenials
        expr: rate(rate_limit_hits_total{result="denied"}[5m]) > 10
        for: 5m
        annotations:
          summary: "High rate of rate limit denials"
          description: "{{ $value }} requests/sec being rate limited"

      - alert: PossibleAttack
        expr: sum(rate(rate_limit_hits_total{result="denied"}[1m])) by (client_ip) > 50
        annotations:
          summary: "Possible attack from {{ $labels.client_ip }}"
```

---

## Best Practices

### 1. Set Appropriate Limits

```php
// Public endpoints (strict)
Route::post('/api/public/search', $handler)
    ->middleware(['throttle:10,60']);  // 10/minute

// Authenticated endpoints (moderate)
Route::get('/api/user/profile', $handler)
    ->middleware(['auth', 'throttle:120,60']);  // 120/minute

// Premium users (relaxed)
Route::get('/api/premium/data', $handler)
    ->middleware(['auth', new TieredRateLimitMiddleware($limiter)]);
```

### 2. Return Proper Headers

```php
HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1234567890
Retry-After: 60

{
  "message": "Too many requests",
  "retry_after": 60
}
```

### 3. Use Multiple Buckets

```php
// Per-route limiting
$routeLimiter = new RateLimitMiddleware($limiter, 60, 60);

// Global limiting (all routes)
$globalLimiter = new RateLimitMiddleware($limiter, 1000, 60);

// Apply both
$preGlobal = [$globalLimiter];
$perRoute = [$routeLimiter];
```

### 4. Whitelist Internal Services

```php
final class SmartRateLimitMiddleware extends RateLimitMiddleware
{
    private array $whitelist = ['10.0.0.0/8', '127.0.0.1'];

    public function __invoke(Request $r, Closure $next): Response
    {
        $ip = $r->getAttribute('client_ip');

        // Skip rate limiting for whitelisted IPs
        if ($this->isWhitelisted($ip)) {
            return $next($r);
        }

        return parent::__invoke($r, $next);
    }

    private function isWhitelisted(string $ip): bool
    {
        foreach ($this->whitelist as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }
}
```

### 5. Implement Gradual Backoff

```php
final class BackoffRateLimitMiddleware extends RateLimitMiddleware
{
    protected function buildRateLimitedResponse(string $key): Response
    {
        $violations = $this->limiter->attempts("violations:{$key}");
        $this->limiter->hit("violations:{$key}", 3600);  // Track violations

        // Exponential backoff
        $retryAfter = min(60 * (2 ** $violations), 3600);  // Cap at 1 hour

        return Response::json([
            'message' => 'Too many requests',
            'retry_after' => $retryAfter,
            'violations' => $violations + 1
        ], 429)->withHeader('Retry-After', (string) $retryAfter);
    }
}
```

### 6. Log Violations

```php
if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
    $this->logger->warning('Rate limit exceeded', [
        'key' => $key,
        'ip' => $r->getAttribute('client_ip'),
        'user_id' => $r->getAttribute('auth.user_id'),
        'endpoint' => $r->getPath(),
        'attempts' => $this->limiter->attempts($key)
    ]);

    return $this->buildRateLimitedResponse($key);
}
```

---

## Common Patterns

### Login Rate Limiting

```php
Route::post('/login', function(Request $r) use ($limiter) {
    $email = $r->input('email');
    $ip = $r->getAttribute('client_ip');

    // Rate limit by email
    $emailKey = "login:email:{$email}";
    if ($limiter->tooManyAttempts($emailKey, 5, 300)) {
        return Response::json([
            'error' => 'Too many login attempts for this email'
        ], 429);
    }

    // Rate limit by IP
    $ipKey = "login:ip:{$ip}";
    if ($limiter->tooManyAttempts($ipKey, 10, 300)) {
        return Response::json([
            'error' => 'Too many login attempts from this IP'
        ], 429);
    }

    // Attempt authentication
    $user = AuthService::attempt($email, $r->input('password'));

    if (!$user) {
        $limiter->hit($emailKey, 300);
        $limiter->hit($ipKey, 300);

        return Response::json(['error' => 'Invalid credentials'], 401);
    }

    // Success - reset counters
    $limiter->resetAttempts($emailKey);
    $limiter->resetAttempts($ipKey);

    return Response::json(['token' => $jwt->generate($user['id'])]);
});
```

### API Key Rate Limiting

```php
final class ApiKeyRateLimitMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        $apiKey = $r->getHeaderLine('X-API-Key');

        if (!$apiKey) {
            return Response::json(['error' => 'API key required'], 401);
        }

        $key = "api_key:{$apiKey}";
        $limit = $this->getLimitForApiKey($apiKey);

        if ($this->limiter->tooManyAttempts($key, $limit, 60)) {
            return Response::json([
                'error' => 'Rate limit exceeded',
                'limit' => $limit,
                'window' => '60 seconds'
            ], 429);
        }

        $this->limiter->hit($key, 60);

        return $next($r);
    }

    private function getLimitForApiKey(string $apiKey): int
    {
        $apiKeyData = DB::queryOne('SELECT * FROM api_keys WHERE key = ?', [$apiKey]);
        return $apiKeyData['rate_limit'] ?? 60;
    }
}
```

### Webhook Verification Rate Limiting

```php
Route::post('/webhooks/stripe', function(Request $r) use ($limiter) {
    $signature = $r->getHeaderLine('Stripe-Signature');

    // Rate limit webhook verifications
    $key = "webhook:stripe:verify";
    if ($limiter->tooManyAttempts($key, 100, 60)) {
        return Response::json(['error' => 'Too many requests'], 429);
    }

    if (!$this->verifyStripeSignature($signature, $r->getContent())) {
        $limiter->hit($key, 60);
        return Response::json(['error' => 'Invalid signature'], 401);
    }

    // Process webhook
    $this->processStripeWebhook($r->json());

    return Response::json(['status' => 'success']);
});
```

---

## Summary

**This recipe provides**:
- ✅ Flexible rate limiter service
- ✅ Multiple rate limiting strategies
- ✅ Per-user and per-IP limiting
- ✅ Tiered/dynamic rate limits
- ✅ Cost-based rate limiting
- ✅ Distributed rate limiting with Redis
- ✅ Monitoring and alerting

**Production checklist**:
- [ ] Set appropriate limits per endpoint type
- [ ] Return proper rate limit headers
- [ ] Implement per-user and global limits
- [ ] Use Redis for distributed setups
- [ ] Whitelist internal services
- [ ] Log rate limit violations
- [ ] Monitor denial rates
- [ ] Implement gradual backoff for repeat offenders
- [ ] Test rate limiting under load
