# Utilities Reference

Helper utilities and facades that are active in current Webrick runtime.

---

## Route Cache

Use `Infocyph\Webrick\Support\RouteCache` to prebuild/clear matcher artifacts.

### Build Cache

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
    'matcher' => 'sharded',                  // sharded|fused|generated (optional)
    'cache' => __DIR__ . '/.route-cache',    // sharded dir or fused file path
    'routes' => __DIR__ . '/routes.php',
    'registrarOptions' => [
        'exposeUrlServices' => true,
        'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev',
        'signedDefaultTtl' => 900,
    ],
]);
```

### Clear Cache

```php
RouteCache::clear([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/.route-cache',
    'aggressive' => false,
]);
```

### CLI Equivalent

```bash
php webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
php webrick route:clear --matcher=sharded --cache=.route-cache
```

---

## Route URL Helpers

Use the `Route` facade for URL generation/signing.

```php
use Infocyph\Webrick\Router\Route;

$url = Route::urlFor('users.show', ['id' => 42]);
$signed = Route::signedUrlFor('users.show', ['id' => 42]);
$temp = Route::temporaryUrlFor('users.show', ['id' => 42], ttl: 900);
```

If you need manual binding outside registrar options:

```php
Route::bindUrlServices($routes, $signKey, 900);
```

---

## Middleware Aliases

Register middleware aliases via `Infocyph\Webrick\Router\Dispatch\MiddlewareAliases`.

```php
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;

MiddlewareAliases::register(
    'throttle',
    static fn (...$params) => new ThrottleMiddleware(
        max: (int)($params[0] ?? 60),
        window: (int)($params[1] ?? 60),
    ),
);
```

Then use alias strings in route/group middleware lists (for example `throttle:30,60`).

---

## Enums

Webrick ships enum-backed constants in `Infocyph\Webrick\Constants`:

- `HttpMethodEnum`
- `MediaTypeEnum`
- `StatusEnum`
- `MatcherModeEnum`

Use these for type-safe status/method/content-type/matcher handling instead of hardcoded strings.
