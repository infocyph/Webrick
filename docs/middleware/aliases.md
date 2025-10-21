# Middleware Aliases

Register middleware aliases to use concise string syntax in route definitions instead of class instances.

---

## Why Use Aliases?

**Without Aliases**:
```php
Route::get('/protected', fn() => ['ok' => true], [
    'middleware' => [
        new VerifySignedUrlMiddleware($signKey, leeway: 5),
        new ThrottleMiddleware(max: 30, window: 60, pool: $cache)
    ]
]);
```

**With Aliases**:
```php
Route::get('/protected', fn() => ['ok' => true], [
    'middleware' => ['verifySignedUrl', 'throttle:30,60']
]);
```

**Benefits**:
- ✅ Cleaner route definitions
- ✅ Centralized configuration
- ✅ Parameter parsing support
- ✅ Easier testing (mock aliases)

---

## Registration

Register aliases before defining routes:

```php
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

// Simple alias (no parameters)
MiddlewareAliases::register(
    'auth',
    static fn() => new AuthMiddleware()
);

// Alias with parameters
MiddlewareAliases::register(
    'throttle',
    static fn(...$params) => new ThrottleMiddleware(
        max: (int)($params[0] ?? 60),
        window: (int)($params[1] ?? 60),
        pool: Cache::pool('throttle')
    )
);

// Alias with dependencies
MiddlewareAliases::register(
    'verifySignedUrl',
    static function () use ($signKey) {
        return new VerifySignedUrlMiddleware($signKey, leeway: 5);
    }
);
```

---

## Common Aliases

### Authentication & Authorization

```php
MiddlewareAliases::register('auth', fn() => new AuthMiddleware());
MiddlewareAliases::register('guest', fn() => new GuestMiddleware());
MiddlewareAliases::register('admin', fn() => new AdminMiddleware());
MiddlewareAliases::register('verified', fn() => new VerifiedMiddleware());
```

### Rate Limiting

```php
// Flexible throttle with parameters
MiddlewareAliases::register(
    'throttle',
    fn(...$p) => new ThrottleMiddleware((int)($p[0]??60), (int)($p[1]??60), $cache)
);

// Preset throttle levels
MiddlewareAliases::register(
    'throttle.strict',
    fn() => new ThrottleMiddleware(10, 60, $cache)
);

MiddlewareAliases::register(
    'throttle.relaxed',
    fn() => new ThrottleMiddleware(1000, 60, $cache)
);
```

### Security

```php
MiddlewareAliases::register(
    'verifySignedUrl',
    fn() => new VerifySignedUrlMiddleware($signKey, leeway: 5)
);

MiddlewareAliases::register(
    'csrf',
    fn() => new CsrfMiddleware($secret)
);
```

### CORS

```php
MiddlewareAliases::register(
    'cors',
    fn() => new CorsAndPoliciesMiddleware([
        'allow_origins' => ['https://app.example.com'],
        'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    ])
);

MiddlewareAliases::register(
    'cors.public',
    fn() => new CorsAndPoliciesMiddleware([
        'allow_origins' => ['*'],
        'allow_methods' => ['GET', 'OPTIONS'],
    ])
);
```

---

## Usage in Routes

### Single Middleware

```php
Route::get('/admin', /* ... */, ['middleware' => ['admin']]);
```

### Multiple Middleware

```php
Route::post('/api/data', /* ... */, [
    'middleware' => ['auth', 'throttle:120,60', 'verifySignedUrl']
]);
```

### In Groups

```php
Route::group(middleware: ['auth', 'throttle:60,60'], callback: function() {
    Route::get('/profile', /* ... */);
    Route::post('/profile', /* ... */);
});
```

---

## Parameter Parsing

Aliases can accept colon-separated parameters:

```
'throttle:30,60'  → max=30, window=60
'cache:3600'      → ttl=3600
'role:admin'      → role='admin'
```

**Parser function**:
```php
MiddlewareAliases::register(
    'throttle',
    static function (...$params) use ($cache) {
        // $params = ['30', '60'] from 'throttle:30,60'
        return new ThrottleMiddleware(
            max: (int)($params[0] ?? 60),
            window: (int)($params[1] ?? 60),
            pool: $cache
        );
    }
);
```

---

## Advanced Patterns

### Conditional Aliases

```php
MiddlewareAliases::register(
    'env',
    static function(...$params) {
        $env = $params[0] ?? 'production';
        return new EnvironmentMiddleware($env);
    }
);

// Usage
Route::get('/debug', /* ... */, ['middleware' => ['env:development']]);
```

### Composite Aliases

```php
// "verified" = auth + verified email
MiddlewareAliases::register(
    'verified',
    static fn() => new CompositeMiddleware([
        new AuthMiddleware(),
        new VerifiedEmailMiddleware()
    ])
);
```

### Factory with DI

```php
// Using a DI container
MiddlewareAliases::register(
    'auth',
    static fn() => $container->make(AuthMiddleware::class)
);
```

---

## Testing with Aliases

### Mock Aliases in Tests

```php
class RouteTest extends TestCase
{
    protected function setUp(): void
    {
        // Replace real middleware with test double
        MiddlewareAliases::register(
            'auth',
            fn() => new class {
                public function __invoke($req, $next) {
                    // Always authenticated in tests
                    $req = $req->withAttribute('auth.user_id', 999);
                    return $next($req);
                }
            }
        );
    }
}
```

---

## Best Practices

### ✅ **Do**

1. **Register aliases early** (before route registration)
   ```php
   // In bootstrap/front controller
   require __DIR__ . '/middleware-aliases.php';
   require __DIR__ . '/routes/web.php';
   ```

2. **Use descriptive names**
   ```php
   'auth' not 'a'
   'throttle' not 't'
   ```

3. **Provide defaults**
   ```php
   (int)($params[0] ?? 60)  // Default to 60 if not specified
4. **Document parameters**
```php
   // middleware-aliases.php

   // throttle:<max>,<window>
   // Example: 'throttle:30,60' = 30 requests per 60 seconds
   MiddlewareAliases::register('throttle', /* ... */);
```

5. **Group related aliases**
```php
   // Auth aliases
   MiddlewareAliases::register('auth', /* ... */);
   MiddlewareAliases::register('guest', /* ... */);
   MiddlewareAliases::register('admin', /* ... */);

   // Throttle aliases
   MiddlewareAliases::register('throttle', /* ... */);
   MiddlewareAliases::register('throttle.strict', /* ... */);
```

### ❌ **Don't**

1. **Don't hardcode secrets in aliases**
```php
   // ❌ Bad
   MiddlewareAliases::register('auth', fn() => new AuthMiddleware('secret123'));

   // ✅ Good
   MiddlewareAliases::register('auth', fn() => new AuthMiddleware($_ENV['AUTH_SECRET']));
```

2. **Don't register aliases after routes**
```php
   // ❌ Won't work
   require __DIR__ . '/routes.php';
   MiddlewareAliases::register('auth', /* ... */);
```

3. **Don't use overly generic names**
```php
   // ❌ Ambiguous
   MiddlewareAliases::register('check', /* ... */);

   // ✅ Clear
   MiddlewareAliases::register('checkPermission', /* ... */);
```

4. **Don't create complex parsers**
```php
   // ❌ Too complex
   'throttle:max=30,window=60,scope=api'

   // ✅ Simpler
   'throttle:30,60'
   'throttle.api'  // Separate alias for scoped version
```

---

## Centralized Configuration

Create a dedicated file for aliases:
```php
<?php
// config/middleware-aliases.php

use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

return function (array $config, CacheInterface $cache) {
    // Authentication
    MiddlewareAliases::register('auth', fn() => new AuthMiddleware());
    MiddlewareAliases::register('guest', fn() => new GuestMiddleware());
    MiddlewareAliases::register('admin', fn() => new AdminMiddleware());

    // Rate Limiting
    MiddlewareAliases::register('throttle', fn(...$p) => new ThrottleMiddleware(
        max: (int)($p[0] ?? 60),
        window: (int)($p[1] ?? 60),
        pool: $cache
    ));

    // Security
    MiddlewareAliases::register('verifySignedUrl', fn() => new VerifySignedUrlMiddleware(
        signKey: $config['sign_key'],
        leeway: 5
    ));

    MiddlewareAliases::register('csrf', fn() => new CsrfMiddleware(
        secret: $config['csrf_secret']
    ));

    // CORS
    MiddlewareAliases::register('cors', fn() => new CorsAndPoliciesMiddleware([
        'allow_origins' => $config['cors_origins'],
        'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    ]));
};
```

**Load in bootstrap**:
```php
// public/index.php
$config = require __DIR__ . '/../config/app.php';
$cache = Cache::pool('middleware');

$registerAliases = require __DIR__ . '/../config/middleware-aliases.php';
$registerAliases($config, $cache);

// Now define routes
require __DIR__ . '/../routes/web.php';
```

---

## Debugging Aliases

### Check Registered Aliases
```php
// Debug helper
Route::get('/__debug/middleware-aliases', function() {
    $aliases = MiddlewareAliases::all();  // If supported
    return Response::json(array_keys($aliases));
});
```

### Log Middleware Execution
```php
MiddlewareAliases::register('auth', function() use ($logger) {
    return new class($logger) {
        public function __construct(private LoggerInterface $logger) {}

        public function __invoke($req, $next) {
            $this->logger->debug('AuthMiddleware: executing');

            // Actual auth logic...

            return $next($req);
        }
    };
});
```

---

## Migration Guide

### Before (Class Instances)
```php
use Infocyph\Webrick\Middleware\{AuthMiddleware, ThrottleMiddleware};

Route::get('/admin/users', [AdminController::class, 'users'], [
    'middleware' => [
        new AuthMiddleware(),
        new ThrottleMiddleware(max: 60, window: 60, pool: $cache)
    ]
]);

Route::get('/admin/settings', [AdminController::class, 'settings'], [
    'middleware' => [
        new AuthMiddleware(),
        new ThrottleMiddleware(max: 60, window: 60, pool: $cache)
    ]
]);
```

### After (Aliases)

**1. Register aliases once**:
```php
// config/middleware-aliases.php
MiddlewareAliases::register('auth', fn() => new AuthMiddleware());
MiddlewareAliases::register('throttle', fn(...$p) => new ThrottleMiddleware(
    (int)($p[0]??60), (int)($p[1]??60), Cache::pool('throttle')
));
```

**2. Use in routes**:
```php
Route::get('/admin/users', [AdminController::class, 'users'], [
    'middleware' => ['auth', 'throttle:60,60']
]);

Route::get('/admin/settings', [AdminController::class, 'settings'], [
    'middleware' => ['auth', 'throttle:60,60']
]);
```

**3. Or use groups**:
```php
Route::group(middleware: ['auth', 'throttle:60,60'], callback: function() {
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::get('/admin/settings', [AdminController::class, 'settings']);
});
```

---

## Summary

**Middleware aliases provide**:
- ✅ Cleaner route definitions
- ✅ Centralized middleware configuration
- ✅ Parameterization support
- ✅ Easier refactoring (change implementation in one place)
- ✅ Better testability

**When to use**:
- ✅ Middleware used across many routes
- ✅ Middleware with common configurations
- ✅ Team prefers declarative route definitions

**When not to use**:
- ❌ One-off middleware with unique config
- ❌ Complex conditional logic per route
- ❌ Very small apps (<10 routes)

**Golden rule**: Register aliases for **reusable patterns**, use instances for **one-off cases**.
