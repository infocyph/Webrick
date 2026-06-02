# Middleware API Reference

Complete reference for creating and using middleware in Webrick.

---

## Table of Contents

- [Middleware Interface](#middleware-interface)
- [Creating Middleware](#creating-middleware)
- [Registering Middleware](#registering-middleware)
- [Middleware Order](#middleware-order)
- [Request Transformation](#request-transformation)
- [Response Transformation](#response-transformation)
- [Short-Circuit Responses](#short-circuit-responses)
- [Middleware Aliases](#middleware-aliases)

---

## Middleware Interface

Middleware must be callable with signature:

```php
function (Request $request, Closure $next): Response
```

---

## Creating Middleware

### Closure Middleware

```php
$middleware = function (Request $r, Closure $next): Response {
    // Before handler
    $r = $r->withAttribute('start_time', microtime(true));

    // Call next middleware/handler
    $response = $next($r);

    // After handler
    $duration = microtime(true) - $r->getAttribute('start_time');
    return $response->withHeader('X-Response-Time', $duration . 'ms');
};
```

### Class Middleware

```php
final class LoggingMiddleware
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        $this->logger->info('Request', [
            'method' => $r->getMethod(),
            'path' => $r->getPath()
        ]);

        $response = $next($r);

        $this->logger->info('Response', [
            'status' => $response->getStatusCode()
        ]);

        return $response;
    }
}
```

### Invokable Class

```php
final class AuthMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        $token = $r->getHeaderLine('Authorization');

        if (!$this->isValidToken($token)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $userId = $this->extractUserId($token);
        $r = $r->withAttribute('auth.user_id', $userId);

        return $next($r);
    }

    private function isValidToken(string $token): bool { /* ... */ }
    private function extractUserId(string $token): int { /* ... */ }
}
```

---

## Registering Middleware

### Pre-Global (Before Routing)

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: $matcher,
    register: $register,
    preGlobal: [
        new GatewayHardeningMiddleware(/* ... */),
        new TelemetryMiddleware(/* ... */),
        new ThrottleMiddleware(/* ... */),
    ],
);
```

**Use for**:
- Security checks (IP filtering, host validation)
- Request logging
- Rate limiting
- Early request rejection

### Post-Global (After Routing, Before Handler)

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: $matcher,
    register: $register,
    postGlobal: [
        new CorsAndPoliciesMiddleware(/* ... */),
        new NegotiationMiddleware(/* ... */),
        new ResponseCacheMiddleware(/* ... */),
    ],
);
```

**Use for**:
- CORS headers
- Content negotiation
- Response caching
- Compression

### Per-Route

```php
Route::get('/admin', [AdminController::class, 'index'], [
    'middleware' => ['auth', 'admin']
]);
```

---

## Middleware Order

**Execution flow**:

```
Request
  ↓
Pre-Global Middleware (in order)
  ↓
Routing
  ↓
Post-Global Middleware (in order)
  ↓
Per-Route Middleware (in order)
  ↓
Handler
  ↓
Per-Route Middleware (reverse order)
  ↓
Post-Global Middleware (reverse order)
  ↓
Pre-Global Middleware (reverse order)
  ↓
Response
```

**Example**:

```php
$preGlobal = [A, B, C];
$postGlobal = [D, E];
$perRoute = [F, G];

// Execution order:
// A → B → C → [routing] → D → E → F → G → [handler] → G → F → E → D → C → B → A
```

---

## Request Transformation

### Add Attributes

```php
$middleware = function (Request $r, Closure $next): Response {
    $r = $r->withAttribute('user_id', 42);
    $r = $r->withAttribute('roles', ['admin', 'editor']);

    return $next($r);
};
```

### Modify Headers

```php
$middleware = function (Request $r, Closure $next): Response {
    // Normalize header
    $auth = $r->getHeaderLine('Authorization');
    if (str_starts_with($auth, 'bearer ')) {
        $auth = 'Bearer ' . substr($auth, 7);
        $r = $r->withHeader('Authorization', $auth);
    }

    return $next($r);
};
```

### Parse Body

```php
$middleware = function (Request $r, Closure $next): Response {
    if ($r->getHeaderLine('Content-Type') === 'application/x-msgpack') {
        $body = $r->getContent();
        $data = msgpack_unpack($body);
        $r = $r->withParsedBody($data);
    }

    return $next($r);
};
```

---

## Response Transformation

### Add Headers

```php
$middleware = function (Request $r, Closure $next): Response {
    $response = $next($r);

    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY');
};
```

### Modify Body

```php
$middleware = function (Request $r, Closure $next): Response {
    $response = $next($r);

    // Wrap JSON responses
    if (str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
        $body = json_decode((string) $response->getBody(), true);
        $wrapped = [
            'success' => true,
            'data' => $body,
            'meta' => ['timestamp' => time()]
        ];

        return Response::json($wrapped, $response->getStatusCode());
    }

    return $response;
};
```

### Compress Response

```php
$middleware = function (Request $r, Closure $next): Response {
    $response = $next($r);

    $acceptEncoding = $r->getHeaderLine('Accept-Encoding');

    if (str_contains($acceptEncoding, 'gzip')) {
        $body = (string) $response->getBody();
        $compressed = gzencode($body, 6);

        return $response
            ->withBody(stream_for($compressed))
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Content-Length', (string) strlen($compressed));
    }

    return $response;
};
```

---

## Short-Circuit Responses

### Early Return

```php
final class MaintenanceModeMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        if ($this->isInMaintenanceMode()) {
            return Response::json([
                'error' => 'Service Unavailable',
                'message' => 'We are currently performing maintenance'
            ], 503);
        }

        return $next($r);
    }
}
```

### Conditional Execution

```php
final class CacheMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        // Only cache GET/HEAD
        if (!in_array($r->getMethod(), ['GET', 'HEAD'])) {
            return $next($r);
        }

        $cacheKey = $this->getCacheKey($r);
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;  // Short-circuit
        }

        $response = $next($r);
        $this->cache->set($cacheKey, $response, 3600);

        return $response;
    }
}
```

---

## Middleware Aliases

### Register Alias

```php
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

MiddlewareAliases::register('auth', fn() => new AuthMiddleware());

MiddlewareAliases::register('throttle', fn(...$params) => new ThrottleMiddleware(
    max: (int)($params[0] ?? 60),
    window: (int)($params[1] ?? 60),
    pool: Cache::pool('throttle')
));
```

### Use Alias

```php
Route::get('/protected', [SecretController::class, 'index'], [
    'middleware' => ['auth', 'throttle:30,60']
]);
```

---

## Common Patterns

### Timing Middleware

```php
final class TimingMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($r);

        $duration = (microtime(true) - $start) * 1000;  // ms

        return $response->withHeader('X-Response-Time', number_format($duration, 2) . 'ms');
    }
}
```

### Request ID Middleware

```php
final class RequestIdMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        $requestId = $r->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(16));

        $r = $r->withAttribute('request_id', $requestId);

        $response = $next($r);

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
```

### Locale Middleware

```php
final class LocaleMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        $locale = $r->query('lang')
               ?? $r->cookie('locale')
               ?? $r->getAttribute('locale', 'en');

        $r = $r->withAttribute('locale', $locale);

        $response = $next($r);

        // Set cookie for next request
        if (!$r->cookie('locale')) {
            $cookie = "locale={$locale}; Path=/; Max-Age=31536000; SameSite=Lax";
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }
}
```

### CORS Middleware

```php
final class SimpleCorsMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        // Preflight request
        if ($r->isOptions()) {
            return Response::create('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
                'Access-Control-Max-Age' => '3600'
            ]);
        }

        $response = $next($r);

        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

---

## Best Practices

### ✅ **Do**

1. **Keep middleware focused** (single responsibility)
   ```php
   // ✅ Good: One concern
   final class AuthMiddleware { /* Only auth */ }

   // ❌ Bad: Multiple concerns
   final class AuthAndLoggingMiddleware { /* Auth + logging */ }
   ```

2. **Use immutable modifications**
   ```php
   $r = $r->withAttribute('key', 'value');  // ✅ Correct
   $r->setAttribute('key', 'value');        // ❌ Wrong (doesn't exist)
   ```

3. **Always call $next unless short-circuiting**
   ```php
   // ✅ Good
    return $next($r);

   // ❌ Bad (breaks chain)
   $next($r);  // Returns nothing
   ```

4. **Place order-sensitive middleware correctly**
   ```php
   // ✅ Correct order
   $preGlobal = [
       GatewayHardeningMiddleware::class,  // First: security
       TelemetryMiddleware::class,         // Then: logging
       ThrottleMiddleware::class,          // Then: rate limiting
   ];
   ```

5. **Use type hints**
   ```php
   // ✅ Good
   public function __invoke(Request $r, Closure $next): Response

   // ❌ Bad
   public function __invoke($r, $next)
   ```

### ❌ **Don't**

1. **Don't mutate request/response directly**
   ```php
   // ❌ Wrong
   $r->attributes['key'] = 'value';

   // ✅ Correct
   $r = $r->withAttribute('key', 'value');
   ```

2. **Don't catch exceptions without re-throwing**
   ```php
   // ❌ Bad (swallows errors)
   try {
       return $next($r);
   } catch (\Throwable $e) {
       return Response::json(['error' => 'Something went wrong'], 500);
   }

   // ✅ Good (logs but re-throws)
   try {
       return $next($r);
   } catch (\Throwable $e) {
       $this->logger->error('Middleware error', ['exception' => $e]);
       throw $e;
   }
   ```

3. **Don't forget to return response**
   ```php
   // ❌ Bad
   public function __invoke(Request $r, Closure $next): Response {
       $next($r);  // Missing return
   }

   // ✅ Good
   public function __invoke(Request $r, Closure $next): Response {
       return $next($r);
   }
   ```

4. **Don't perform heavy operations in every request**
   ```php
   // ❌ Bad (slow)
   public function __invoke(Request $r, Closure $next): Response {
       $this->rebuildCache();  // Expensive!
       return $next($r);
   }

   // ✅ Good (conditional)
   public function __invoke(Request $r, Closure $next): Response {
       if ($r->getPath() === '/admin/rebuild-cache') {
           $this->rebuildCache();
       }
       return $next($r);
   }
   ```

---

## Testing Middleware

### Unit Test

```php
use PHPUnit\Framework\TestCase;

class AuthMiddlewareTest extends TestCase
{
    public function testRejectsUnauthenticated(): void
    {
        $middleware = new AuthMiddleware();
        $request = Request::fake(method: 'GET', uri: '/protected');
        $next = fn() => Response::json(['secret' => 'data']);

        $response = $middleware($request, $next);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testAllowsAuthenticated(): void
    {
        $middleware = new AuthMiddleware();
        $request = Request::fake(
            headers: ['Authorization' => 'Bearer valid-token'],
            method: 'GET',
            uri: '/protected',
        );
        $next = fn($r) => Response::json(['user_id' => $r->getAttribute('auth.user_id')]);

        $response = $middleware($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertNotNull($data['user_id']);
    }
}
```

### Integration Test

```php
class MiddlewareStackTest extends TestCase
{
    public function testMiddlewareStack(): void
    {
        $kernel = RouterKernel::bootWithRegistrar(
            log: $logger,
            matcher: $matcher,
            register: $register,
            preGlobal: [
                new TimingMiddleware(),
                new RequestIdMiddleware(),
            ],
            postGlobal: [
                new CorsMiddleware(),
            ],
        );

        $request = Request::fake(method: 'GET', uri: '/test');
        $response = $kernel->handle($request);

        $this->assertTrue($response->hasHeader('X-Response-Time'));
        $this->assertTrue($response->hasHeader('X-Request-Id'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
    }
}
```

---

## Summary

**Middleware provides**:
- ✅ Request/response transformation
- ✅ Cross-cutting concerns (logging, caching, security)
- ✅ Composable, reusable logic
- ✅ Clean separation of concerns

**Key concepts**:
1. **Callable with (Request, Closure) → Response**
2. **Three registration points** (pre-global, post-global, per-route)
3. **Immutable transformations** (PSR-7)
4. **Can short-circuit** (early return)
5. **Order matters** (especially security middleware)

**Golden rule**: Each middleware should do one thing well.
