# Quick Start

Wire a minimal app end‑to‑end: front controller → routes → middleware → emit. Then add signed URLs and route caching.

:::{admonition} Prerequisites
:class: tip

* Installed via Composer: `composer require infocyph/webrick`
* PHP 8.4+, Composer autoloading
* A writable cache directory (e.g., `var/cache/routes`)
:::

---

## 1) Create your front controller

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Middleware\{CacheValidatorsMiddleware,CompressionMiddleware,CookieEncryptionMiddleware,CorsAndPoliciesMiddleware,GatewayHardeningMiddleware,InputSanitizerMiddleware,MaintenanceModeMiddleware,NegotiationMiddleware,NormalizeMethodMiddleware,RequestLimitsMiddleware,ResponseCacheMiddleware,ResponseLinterMiddleware,TelemetryMiddleware,ThrottleMiddleware,VaryAccumulatorMiddleware,VerifySignedUrlMiddleware};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

$signKey    = $_ENV['WEBRICK_SIGN_KEY'] ?? 'change-me';
$defaultTtl = (int)($_ENV['WEBRICK_SIGN_TTL'] ?? 900);

/** Global middleware (pre) */
$preGlobal = [
    GatewayHardeningMiddleware::class,
    RequestLimitsMiddleware::class,
    ThrottleMiddleware::class,
    CookieEncryptionMiddleware::class,
    NormalizeMethodMiddleware::class,
    InputSanitizerMiddleware::class,
    NegotiationMiddleware::class,
    ResponseCacheMiddleware::class,
    CacheValidatorsMiddleware::class,
];

/** Global middleware (post) */
$postGlobal = [
    CompressionMiddleware::class,
    CorsAndPoliciesMiddleware::class,
    VaryAccumulatorMiddleware::class,
    // add ResponseLinterMiddleware::class in development
];

/** Register routes */
$register = static function (Registrar $registrar) use ($signKey): void {
    $router = $registrar->router();
    $route  = $registrar->facade();

    // Simple routes
    $route::get('/ping', fn() => Response::plaintext('pong'));
    $route::get('/hello/{name}', function (Request $r, string $name) {
        return Response::json(['hello' => $name, 'prefers' => $r->prefers(['application/json','+json','text/plain'])]);
    })->name('hello');

    // Signed route (protected)
    $route::get('/protected', fn() => Response::json(['ok' => true]))
        ->middleware(VerifySignedUrlMiddleware::class);

    // Named route + URL helpers
    $route::get('/download', fn() => Response::attachment('readme.txt', 'Hello'))
        ->name('download');

    // Group with prefix
    $router->group(prefix: '/api', callback: function () use ($route) {
        $route::get('/status', fn() => Response::json(['status' => 'ok']));
    });
};

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/../var/cache/routes',
    registrarOptions: [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
        'signKey'           => $signKey,
        'signedDefaultTtl'  => $defaultTtl,
    ],
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
    bindUrlServices: static function (Collection $routes) use ($signKey, $defaultTtl): void {
        Response::bindUrlServices($routes, $signKey, $defaultTtl);
    },
    // Keep true initially to fall back to live aliases if cache is incomplete
    fallbackAliasesFromRegistrar: true,
);

(new AutoEmitter())->emit($kernel->handle(Request::capture()));
```

> To switch to a **single‑file fused cache**, use `Matching\FusedMatcher::make()` and set `routeCache` to a file (e.g., `.../var/cache/routes/__routes.php`).

---

## 2) Generate signed & temporary URLs

Once `exposeUrlServices` is enabled (or you bound them manually via `bindUrlServices`), you can generate URLs from handlers:

```php
// Inside a route handler:
$url        = Response::urlFor('download');
$signedUrl  = Response::signedUrlFor('protected');           // permanent signature
$tempUrl    = Response::temporaryUrlFor('protected', 900);   // TTL 900s
```

Attach the verifier to your protected route:

```php
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;

$route::get('/protected', fn() => Response::plaintext('secret'))
    ->middleware(VerifySignedUrlMiddleware::class);
```

---

## 3) Next steps

- Explore **Guides → Routing, Groups & Domains** for more patterns.
- See **Middleware** pages for what each pre/post step does.
- Read **Reference → Route Cache** to choose between **Sharded** and **Fused** caches.
