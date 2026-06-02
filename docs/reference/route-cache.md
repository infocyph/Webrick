# Route Cache Reference

Webrick route cache stores compiled routing artifacts so boot and match work can be reused across requests.

## Modes

- `sharded`: directory of PHP artifacts
- `fused`: single PHP artifact file
- `generated`: no route-cache artifact files

## Build with PHP API

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/../.route-cache',
    'routes' => __DIR__ . '/../routes.php',
    'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev-key',
    'signedDefaultTtl' => 900,
    'registrarOptions' => [
        'exposeUrlServices' => true,
        'urlBaseUri' => $_ENV['WEBRICK_URL_BASE_URI'] ?? 'http://localhost',
    ],
]);
```

## Build with CLI

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
php ./webrick route:clear --matcher=sharded --cache=.route-cache
```

## Runtime wiring

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(__DIR__ . '/../.route-cache'),
    register: static function (Registrar $registrar): void {
        unset($registrar);
        require __DIR__ . '/../routes.php';
    },
    routeCache: __DIR__ . '/../.route-cache',
);
```

Use `.route-cache/__routes.php` instead when you choose fused mode.

## What the cache includes

- compiled route tables
- name-to-path and name-to-domain lookup metadata
- middleware and domain metadata
- URL-generation templates used by `Route::urlFor(...)`

## Operational notes

- Build cache during CI or deploy, not during live request handling in production.
- Keep route registration flow identical between cache builds and runtime boot.
- Preserve the `.route-cache/.gitignore` sentinel when clearing sharded caches.
- When signed URL generation is enabled, keep `signKey`, TTL defaults, and `urlBaseUri` aligned between build and runtime.
