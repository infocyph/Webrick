# Utilities Reference

Utilities that are part of the active Webrick runtime shape.

## RouteCache

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/.route-cache',
    'routes' => __DIR__ . '/routes.php',
    'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev-key',
    'signedDefaultTtl' => 900,
    'registrarOptions' => [
        'exposeUrlServices' => true,
        'urlBaseUri' => $_ENV['WEBRICK_URL_BASE_URI'] ?? 'http://localhost',
    ],
]);

RouteCache::clear([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/.route-cache',
]);
```

CLI equivalents:

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
php ./webrick route:clear --matcher=sharded --cache=.route-cache
```

## Route facade URL helpers

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

$url = Route::urlFor('users.show', ['id' => 42]);
$signed = Route::signedUrlFor('users.show', ['id' => 42]);
$temp = Route::temporaryUrlFor('users.show', ['id' => 42], ttl: 900);
$until = Route::temporaryUrlUntil('users.show', new DateTimeImmutable('+15 minutes'), ['id' => 42]);
$absolutePayload = Route::signedUrlFor('users.show', ['id' => 42], absolute: true, payloadMode: SignedUrlConfig::MODE_ABSOLUTE);
```

If you need manual binding outside `registrarOptions`:

```php
Route::bindUrlServices($routes, $signKey, 900, $signedUrlConfig, $baseUri);
```

## MiddlewareAliases

```php
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

MiddlewareAliases::register(
    'throttle',
    static fn(...$params) => new ThrottleMiddleware(
        max: (int) ($params[0] ?? 60),
        window: (int) ($params[1] ?? 60),
    ),
);
```

## Enums

- `HttpMethodEnum`
- `MatcherModeEnum`
- `MediaTypeEnum`
- `StatusEnum`
