# Router API Reference

Complete reference for routing APIs in Webrick.

---

## Table of Contents

- [Route Registration](#route-registration)
- [Kernel Boot Options](#kernel-boot-options)
- [Route Facade](#route-facade)
- [HTTP Verb Methods](#http-verb-methods)
- [Route Groups](#route-groups)
- [Route Parameters](#route-parameters)
- [Route Names](#route-names)
- [Middleware](#middleware)
- [Domain Routing](#domain-routing)
- [URL Generation](#url-generation)
- [Route Caching](#route-caching)
- [Kernel DI Integration](#kernel-di-integration)
- [Error Boundary](#error-boundary)

---

## Route Registration

### Using Registrar

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Log\NullLogger;

$register = function (Registrar $r): void {
    $r->get('/users', [UserController::class, 'index'], 'users.index');
    $r->post('/users', [UserController::class, 'store'], 'users.store');
};

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/.route-cache',
);
```

---

## Kernel Boot Options

`RouterKernel::bootWithRegistrar()` accepts:

| Argument | Type / values | Default | Purpose |
| --- | --- | --- | --- |
| `log` | `Psr\Log\LoggerInterface` | required | Routing, cache, and boundary diagnostics |
| `matcher` | `MatcherInterface` | required | Sharded, fused, generated, or a custom matcher |
| `register` | `Closure(Registrar): void` | required | Cold-path route definitions |
| `routeCache` | directory, file, or `null` | `null` | Enables cache reading at the matcher-specific location |
| `registrarOptions` | associative array | `[]` | Registrar, signed URL, and base URI configuration |
| `preGlobal` | middleware descriptor list | `[]` | Middleware before route middleware |
| `postGlobal` | middleware descriptor list | `[]` | Middleware after route middleware |
| `invokerOnMiddleware` | `true` or `false` | `false` | Use InterMix invocation for middleware calls |
| `errorHandler` | `ErrorHandler` or `null` | `null` | Custom top-level exception renderer |
| `bindUrlServices` | `Closure(Collection): void` or `null` | `null` | Replace default URL-service binding |
| `fallbackAliasesFromRegistrar` | `true`, `false`, or `null` | `null` | Override alias-only registrar fallback |
| `serviceProviders` | service provider class/instance list | `[]` | Import InterMix service providers at boot |
| `preGlobalTags` | list of container tag names | `['webrick.middleware.pre']` | Append tagged pre-route middleware |
| `postGlobalTags` | list of container tag names | `['webrick.middleware.post']` | Append tagged post-route middleware |
| `requestScopeEnabled` | `true` or `false` | `true` | Create and leave an InterMix scope per `handle()` |
| `container` | InterMix `Container` or `null` | `null` | Container used when no explicit invoker is passed |
| `invoker` | InterMix `Invoker` or `null` | `null` | Explicit dispatcher/container integration |

Common `registrarOptions`:

| Key | Values / example | Default |
| --- | --- | --- |
| `autoSlashRedirect` | `true` or `false` | `false` |
| `exposeUrlServices` | `true` or `false` | `false` |
| `signKey` | non-empty secret string or `null` | `null` |
| `signedDefaultTtl` | integer seconds, for example `900`, or `null` | `null` |
| `signedUrlConfig` | `SignedUrlConfig`, configuration array, or `null` | `null` |
| `urlBaseUri` | `https://example.com` or empty string | `''` |

On cached boot, the default URL-service binding is lazy. A custom
`bindUrlServices` callback replaces that behavior, while the signing values in
`registrarOptions` remain the source for the default binding.

`routeCache` enables cache reads but does not authorize request-time writes.
Build artifacts explicitly with `RouteCache::build()` or `route:cache`.

---

## Kernel DI Integration

`RouterKernel::bootWithRegistrar()` can be wired directly with InterMix features:

```php
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use App\Providers\AuthProvider;
use App\Providers\CacheProvider;

$container = Container::instance('intermix');
$invoker = Invoker::with($container);

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/.route-cache',
    invoker: $invoker,                         // or container: $container
    serviceProviders: [
        AuthProvider::class,
        CacheProvider::class,
    ],
    preGlobalTags: ['webrick.middleware.pre'],
    postGlobalTags: ['webrick.middleware.post'],
    requestScopeEnabled: true,                 // enterScope/leaveScope per handle()
);
```

Notes:
- Tagged middleware are appended after explicit `preGlobal` / `postGlobal`.
- `requestScopeEnabled` binds `Request::class` as scoped for each request lifecycle.
- `Response::view()` uses the same `intermix` container path as kernel DI by default.

---

## Error Boundary

`RouterKernel` provides the final exception-to-response boundary for framework-owned failures.

What it catches:
- routing misses such as not found and method not allowed
- typed framework HTTP exceptions thrown by middleware or kernel validation paths
- uncaught throwables that reach the top-level request boundary

What it renders from exceptions:
- status code
- public error message
- response headers carried by the exception

What it does not change:
- a controller or user middleware returning `Response` directly
- normal success responses such as `200`, `204`, redirects, or conditional/range responses

Practical rule:
- return `Response` for normal application flow
- throw typed HTTP exceptions for framework-style rejection flow

Example:

```php
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Router\Kernel\RouterKernel;

$register = function (Registrar $r): void {
    $r->get('/private', static function (): never {
        throw HttpException::forbidden('Token missing');
    });
};
```

You can also replace the final renderer with a custom `ErrorHandler` when you need JSON or another response shape at the boundary.

### Using Facade

```php
use Infocyph\Webrick\Router\Facade\Router as Route;

Route::get('/posts', [PostController::class, 'index']);
Route::post('/posts', [PostController::class, 'store']);
```

---

## Route Facade

### Available Methods

```php
Route::get($path, $handler, $nameOrOpts = null);
Route::post($path, $handler, $nameOrOpts = null);
Route::put($path, $handler, $nameOrOpts = null);
Route::patch($path, $handler, $nameOrOpts = null);
Route::delete($path, $handler, $nameOrOpts = null);
Route::options($path, $handler, $nameOrOpts = null);
Route::head($path, $handler, $nameOrOpts = null);
```

### Multi-Method Endpoints

```php
Route::get('/form', fn() => Response::create('<form>...</form>', 200, [
    'Content-Type' => 'text/html; charset=UTF-8'
]));
Route::post('/form', fn(Request $r) => Response::json(['submitted' => true]));
```

---

## HTTP Verb Methods

### GET

```php
Route::get('/users', function() {
    return Response::json(UserRepository::all());
});
```

### POST

```php
Route::post('/users', function(Request $r) {
    $user = UserRepository::create($r->input());
    return Response::json($user, 201)
        ->withHeader('Location', "/users/{$user['id']}");
});
```

### PUT

```php
Route::put('/users/{id:int}', function(Request $r, int $id) {
    $user = UserRepository::update($id, $r->input());
    return Response::json($user);
});
```

### PATCH

```php
Route::patch('/users/{id:int}', function(Request $r, int $id) {
    $user = UserRepository::patch($id, $r->input());
    return Response::json($user);
});
```

### DELETE

```php
Route::delete('/users/{id:int}', function(int $id) {
    UserRepository::delete($id);
    return Response::noContent();
});
```

### OPTIONS

```php
Route::options('/api/*', function() {
    return Response::create('', 200, [
        'Allow' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS'
    ]);
});
```

---

## Route Groups

### Basic Group

```php
Route::group(callback: function() {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id:int}', [UserController::class, 'show']);
});
```

### Prefix

```php
Route::group(prefix: '/api/v1', callback: function() {
    // GET /api/v1/users
    Route::get('/users', [UserController::class, 'index']);

    // GET /api/v1/posts
    Route::get('/posts', [PostController::class, 'index']);
});
```

### Name Prefix

```php
Route::group(namePrefix: 'admin.', callback: function() {
    // Name: admin.users.index
    Route::get('/users', [AdminController::class, 'users'], 'users.index');

    // Name: admin.settings
    Route::get('/settings', [AdminController::class, 'settings'], 'settings');
});
```

### Middleware

```php
Route::group(middleware: ['auth', 'throttle:60,60'], callback: function() {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
});
```

### Combined Options

```php
Route::group(
    prefix: '/admin',
    namePrefix: 'admin.',
    middleware: ['auth', 'admin'],
    callback: function() {
        // GET /admin/users, name: admin.users, middleware: auth, admin
        Route::get('/users', [AdminController::class, 'users'], 'users');
    }
);
```

### Nested Groups

```php
Route::group(prefix: '/api', callback: function() {
    Route::group(prefix: '/v1', callback: function() {
        // GET /api/v1/users
        Route::get('/users', [UserController::class, 'index']);
    });

    Route::group(prefix: '/v2', callback: function() {
        // GET /api/v2/users
        Route::get('/users', [UserV2Controller::class, 'index']);
    });
});
```

---

## Route Parameters

### Required Parameters

```php
Route::get('/users/{id}', function(int $id) {
    return Response::json(['id' => $id]);
});
```

### Optional Parameters

```php
Route::get('/search/{query?}', function(?string $query = null) {
    return Response::json(['query' => $query ?? 'all']);
});
```

### Constrained Parameters

```php
// Integer constraint
Route::get('/users/{id:int}', function(int $id) { /* ... */ });

// Slug constraint
Route::get('/posts/{slug:slug}', function(string $slug) { /* ... */ });

// UUID constraint
Route::get('/resources/{uuid:uuid}', function(string $uuid) { /* ... */ });

// Hex constraint
Route::get('/colors/{hex:hex}', function(string $hex) { /* ... */ });

// Custom regex
Route::get('/codes/{code:[A-Z]{3}}', function(string $code) { /* ... */ });
```

### Multiple Parameters

```php
Route::get('/posts/{year:int}/{month:int}/{slug:slug}',
    function(int $year, int $month, string $slug) {
        return Response::json([
            'year' => $year,
            'month' => $month,
            'slug' => $slug
        ]);
    }
);
```

---

## Route Names

### Setting Names

```php
// Third parameter
Route::get('/users', [UserController::class, 'index'], 'users.index');

// Via options array
Route::get('/users', [UserController::class, 'index'], [
    'name' => 'users.index'
]);
```

### Checking Route Names

```php
Route::get('/current-route', function(Request $r) {
    $name = $r->getAttribute('route.name');
    return Response::json(['route' => $name]);
});
```

---

## Middleware

### Per-Route Middleware

```php
Route::get('/protected', [SecretController::class, 'index'], [
    'middleware' => ['auth', 'verified']
]);

// Or using facade shorthand
Route::get('/protected', [SecretController::class, 'index'])
    ->withMiddleware(['auth', 'verified']);
```

### Middleware with Parameters

```php
Route::post('/api/data', [ApiController::class, 'store'], [
    'middleware' => ['throttle:30,60', 'verifySignedUrl']
]);
```

### Group Middleware

```php
Route::group(middleware: ['auth'], callback: function() {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
});
```

---

## Domain Routing

### Domain Constraint

```php
Route::group(domain: 'api.example.com', callback: function() {
    Route::get('/users', [ApiController::class, 'users']);
});
```

### Subdomain Wildcards

```php
Route::group(domain: '{account}.example.com', callback: function() {
    Route::get('/', function(string $account) {
        return Response::json(['account' => $account]);
    });
});
```

### Multiple Domains

```php
Route::group(domain: 'admin.example.com', callback: function() {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
});

Route::group(domain: 'api.example.com', callback: function() {
    Route::get('/users', [ApiController::class, 'users']);
});
```

---

## URL Generation

### From Named Routes

```php
// In handler
$url = Route::urlFor('users.show', ['id' => 42]);
// '/users/42'

// Absolute URL
$url = Route::urlFor('users.show', ['id' => 42], absolute: true);
// 'https://example.com/users/42'
```

### With Query Parameters

```php
$url = Route::urlFor('users.index', query: ['page' => 2, 'sort' => 'name']);
// '/users?page=2&sort=name'
```

### Signed URLs

```php
$signed = Route::signedUrlFor('download', ['file' => 'report.pdf']);
$temp = Route::temporaryUrlFor('download', ['file' => 'report.pdf'], ttl: 3600);
```

---

## Route Caching

### Build Cache

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
    'cache' => __DIR__ . '/.route-cache',
    'register' => function($r) {
        require __DIR__ . '/routes/web.php';
        require __DIR__ . '/routes/api.php';
    }
]);
```

### Use Cache

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: new \Psr\Log\NullLogger(),
    matcher: \Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
    register: static function (\Infocyph\Webrick\Router\Definition\Registrar $registrar): void {
        unset($registrar);
        require __DIR__ . '/routes.php';
    },
    routeCache: __DIR__ . '/.route-cache',
);
```

### Clear Cache

```php
RouteCache::clear([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/.route-cache',
]);
```

---

## Common Patterns

### RESTful Resource

```php
Route::get('/posts', [PostController::class, 'index'], 'posts.index');
Route::get('/posts/create', [PostController::class, 'create'], 'posts.create');
Route::post('/posts', [PostController::class, 'store'], 'posts.store');
Route::get('/posts/{id:int}', [PostController::class, 'show'], 'posts.show');
Route::get('/posts/{id:int}/edit', [PostController::class, 'edit'], 'posts.edit');
Route::put('/posts/{id:int}', [PostController::class, 'update'], 'posts.update');
Route::delete('/posts/{id:int}', [PostController::class, 'destroy'], 'posts.destroy');
```

### API Versioning

```php
Route::group(prefix: '/api', callback: function() {
    Route::group(prefix: '/v1', namePrefix: 'v1.', callback: function() {
        Route::get('/users', [V1\UserController::class, 'index'], 'users');
    });

    Route::group(prefix: '/v2', namePrefix: 'v2.', callback: function() {
        Route::get('/users', [V2\UserController::class, 'index'], 'users');
    });
});
```

### Fallback Route

```php
// Must be last route registered
Route::get('/{path:.*}', function(string $path) {
    return Response::json([
        'error' => 'Not Found',
        'path' => $path
    ], 404);
});
```

---

## Method Summary

### Registrar
- `get(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `post(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `put(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `patch(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `delete(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `options(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `head(string $path, array|string|callable $handler, string|array|null $nameOrOpts = null): RouteInterface`
- `group(array|string|null $prefix = null, string|array|Closure|null $domain = null, array|Closure $middleware = [], string|Closure|null $namePrefix = null, ?Closure $callback = null): void`

### Route Facade
- All Registrar methods
- Chainable middleware: `->withMiddleware(array $middleware)`
- Chainable name: `->withName(string $name)`

### URL Generation
- `Route::urlFor(string $name, array $params = [], array $query = [], bool $absolute = false): string`

### URL Signing
- `Route::signedUrlFor(string $name, array $params = [], array $query = [], ?int $ttl = null, bool $absolute = false, ?string $payloadMode = null): string`
- `Route::temporaryUrlFor(string $name, array $params = [], array $query = [], ?int $ttl = null, bool $absolute = false, ?string $payloadMode = null): string`
- `Route::temporaryUrlUntil(string $name, DateTimeInterface|int $expiresAt, array $params = [], array $query = [], bool $absolute = false, ?string $payloadMode = null): string`
