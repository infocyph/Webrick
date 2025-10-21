# Utilities Reference

Helper classes and utility functions in Webrick.

---

## Table of Contents

- [Route Cache](#route-cache)
- [URL Signer](#url-signer)
- [Middleware Aliases](#middleware-aliases)
- [Stream Helpers](#stream-helpers)
- [HTTP Status](#http-status)

---

## Route Cache

Pre-build routes for production performance.

### Build Cache

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
    'cache' => __DIR__ . '/var/cache/routes',
    'register' => static function ($registrar): void {
        require __DIR__ . '/routes/web.php';
        require __DIR__ . '/routes/api.php';

        AttributeRouteLoader::registerFromDirs($registrar, [
            'App\\Http\\Routes\\' => __DIR__ . '/src/Http/Routes',
        ]);
    },
    'registrarOptions' => [
        'exposeUrlServices' => true,
        'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev',
    ],
]);
```

### Clear Cache

```php
RouteCache::clear(__DIR__ . '/var/cache/routes');
```

### Use Cache

```php
$kernel = RouterKernel::boot([
    'cache' => __DIR__ . '/var/cache/routes',
]);
```

---

## URL Signer

Create tamper-proof signed URLs.

### Basic Signing

```php
use Infocyph\Webrick\Router\UrlSigner;

$signer = new UrlSigner($signKey);

// Sign URL (expires in 1 hour)
$signed = $signer->sign('/download/file.pdf', expiration: 3600);
// '/download/file.pdf?expires=1234567890&signature=abc123...'
```

### Verify Signature

```php
if ($signer->verify($request->getUri())) {
    // Valid signature
} else {
    // Invalid or expired
    return Response::json(['error' => 'Invalid signature'], 403);
}
```

### Permanent Signed URLs

```php
// No expiration
$signed = $signer->sign('/permanent/link');
// '/permanent/link?signature=abc123...'
```

### Custom Parameters

```php
$signed = $signer->sign('/resource', expiration: 7200, additionalParams: [
    'user_id' => 42,
    'action' => 'download'
]);
// '/resource?expires=...&signature=...&user_id=42&action=download'
```

---

## Middleware Aliases

Register middleware with short names.

### Register Alias

```php
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

// Simple alias
MiddlewareAliases::register('auth', fn() => new AuthMiddleware());

// With parameters
MiddlewareAliases::register('throttle', fn(...$params) => new ThrottleMiddleware(
    max: (int)($params[0] ?? 60),
    window: (int)($params[1] ?? 60),
    pool: Cache::pool('throttle')
));

// With dependencies
MiddlewareAliases::register('log', fn() => new LoggingMiddleware(
    logger: Container::get(LoggerInterface::class)
));
```

### Use Alias

```php
// In routes
Route::get('/protected', $handler, options: [
    'middleware' => ['auth', 'throttle:30,60']
]);

// In groups
Route::group(middleware: ['auth'], callback: function() {
    // All routes here use 'auth' middleware
});
```

---

## Stream Helpers

PSR-7 stream utilities.

### Create Stream from String

```php
$stream = stream_for('Hello World');
```

### Create Stream from Resource

```php
$handle = fopen('/path/to/file', 'r');
$stream = stream_for($handle);
```

### Create Stream from Callback

```php
$stream = stream_for(function() {
    for ($i = 0; $i < 10; $i++) {
        yield "Line {$i}\n";
    }
});
```

### Stream to String

```php
$content = (string) $stream;
```

---

## HTTP Status

Standard HTTP status codes.

### Status Code Constants

```php
use Infocyph\Webrick\Http\Status;

// Success
Status::OK;                    // 200
Status::CREATED;               // 201
Status::ACCEPTED;              // 202
Status::NO_CONTENT;            // 204

// Redirection
Status::MOVED_PERMANENTLY;     // 301
Status::FOUND;                 // 302
Status::SEE_OTHER;             // 303
Status::NOT_MODIFIED;          // 304
Status::TEMPORARY_REDIRECT;    // 307
Status::PERMANENT_REDIRECT;    // 308

// Client Error
Status::BAD_REQUEST;           // 400
Status::UNAUTHORIZED;          // 401
Status::FORBIDDEN;             // 403
Status::NOT_FOUND;             // 404
Status::METHOD_NOT_ALLOWED;    // 405
Status::NOT_ACCEPTABLE;        // 406
Status::CONFLICT;              // 409
Status::GONE;                  // 410
Status::PAYLOAD_TOO_LARGE;     // 413
Status::UNPROCESSABLE_ENTITY;  // 422
Status::TOO_MANY_REQUESTS;     // 429

// Server Error
Status::INTERNAL_SERVER_ERROR; // 500
Status::NOT_IMPLEMENTED;       // 501
Status::BAD_GATEWAY;           // 502
Status::SERVICE_UNAVAILABLE;   // 503
Status::GATEWAY_TIMEOUT;       // 504
```

### Usage

```php
return Response::json(['error' => 'Not Found'], Status::NOT_FOUND);

return Response::create('', Status::NO_CONTENT);

return Response::redirect('/login', Status::TEMPORARY_REDIRECT);
```

---

## Common Utility Patterns

### Build Routes in CI/CD

```bash
#!/bin/bash
# deploy.sh

echo "Building route cache..."
php bin/build-routes.php

echo "Deploying..."
rsync -av --exclude='*.backup' ./ production:/var/www/app/

echo "Deployment complete"
```

### Signed Download Links

```php
Route::get('/files/{id:int}/download', function(int $id) use ($signer) {
    $file = FileRepository::find($id);

    // Generate signed URL valid for 1 hour
    $url = $signer->sign("/internal/download/{$file->path}", expiration: 3600);

    return Response::json(['download_url' => $url]);
});

Route::get('/internal/download/{path:.*}', function(Request $r, string $path) use ($signer) {
    if (!$signer->verify($r->getUri())) {
        return Response::json(['error' => 'Invalid or expired signature'], 403);
    }

    return Response::download(storage_path($path));
})->middleware(['verifySignedUrl']);
```

### Middleware Preset Groups

```php
// Define preset groups
MiddlewareAliases::register('api.v1', fn() => new CompositeMiddleware([
    new ThrottleMiddleware(max: 120, window: 60, pool: $cache),
    new CorsMiddleware(['allow_origins' => ['*']]),
    new NegotiationMiddleware(produces: ['+json'])
]));

MiddlewareAliases::register('api.v2', fn() => new CompositeMiddleware([
    new ThrottleMiddleware(max: 180, window: 60, pool: $cache),
    new CorsMiddleware(['allow_origins' => ['*']]),
    new NegotiationMiddleware(produces: ['+json', 'application/xml'])
]));

// Use in routes
Route::group(prefix: '/api/v1', middleware: ['api.v1'], callback: function() {
    // v1 routes
});

Route::group(prefix: '/api/v2', middleware: ['api.v2'], callback: function() {
    // v2 routes
});
```

### Health Check Utility

```php
final class HealthCheck
{
    public static function run(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => time(),
            'checks' => [
                'database' => self::checkDatabase(),
                'cache' => self::checkCache(),
                'disk' => self::checkDisk(),
            ]
        ];
    }

    private static function checkDatabase(): array
    {
        try {
            DB::query('SELECT 1');
            return ['status' => 'up', 'latency' => 1.2];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private static function checkCache(): array
    {
        try {
            Cache::set('health_check', time());
            Cache::get('health_check');
            return ['status' => 'up'];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private static function checkDisk(): array
    {
        $free = disk_free_space('/');
        $total = disk_total_space('/');
        $used = $total - $free;
        $percent = ($used / $total) * 100;

        return [
            'status' => $percent < 90 ? 'up' : 'warning',
            'used_percent' => round($percent, 2),
            'free_gb' => round($free / 1024 / 1024 / 1024, 2)
        ];
    }
}

// Use in route
Route::get('/health', fn() => Response::json(HealthCheck::run()));
```

---

## Summary

**Utilities provide**:
- ✅ Route caching for production performance
- ✅ URL signing for secure links
- ✅ Middleware aliases for cleaner routes
- ✅ Stream helpers for PSR-7 compliance
- ✅ HTTP status constants for readability

**Best practices**:
1. Always cache routes in production
2. Use signed URLs for sensitive operations
3. Register middleware aliases early
4. Use status constants instead of magic numbers
