# Webrick Router

A fast, modern PHP router with production-grade middleware, signed & temporary URLs, smart responses (JSON/streaming), and first-class route caching.

<p align="center">
  <a href="https://packagist.org/packages/infocyph/webrick"><img alt="Packagist" src="https://img.shields.io/packagist/v/infocyph/webrick.svg"></a>
  <a href="https://packagist.org/packages/infocyph/webrick"><img alt="PHP Version" src="https://img.shields.io/packagist/php-v/infocyph/webrick.svg"></a>
  <a href="https://github.com/infocyph/webrick/actions"><img alt="CI" src="https://github.com/infocyph/webrick/actions/workflows/ci.yml/badge.svg"></a>
  <a href="./LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
</p>

---

## Highlights

- **Powerful routing:** Named routes, groups, domain scoping, resources, attribute discovery.
- **Signed & temporary URLs:** Helpers + verifier middleware; cache-friendly and proxy-safe.
- **Middleware pipeline:** Gateway hardening, method normalization, negotiation, validators, CORS/policies, compression, telemetry, throttling, etc.
- **Smart responses:** `json()`, `plaintext()`, `attachment()`, `download()`, `stream()`; auto content negotiation.
- **Route cache:** Sharded (directory) or Fused (single file) builders for instant cold starts.
- **PSR interop friendly:** Optional PSR-7 factory if you want to wire into other ecosystems.

> Target: PHP **8.4+** for production. Works great with OPcache and FPM tuning.

---

## Installation

```bash
composer require infocyph/webrick
````

---

## Quick Start

Wire a minimal app end-to-end: front controller → routes → middleware → emit. Then add signed URLs and route caching.

### Front controller (full-featured)

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Middleware\{
    CacheValidatorsMiddleware, CompressionMiddleware, CookieEncryptionMiddleware,
    CorsAndPoliciesMiddleware, GatewayHardeningMiddleware, InputSanitizerMiddleware,
    MaintenanceModeMiddleware, NegotiationMiddleware, NormalizeMethodMiddleware,
    RequestLimitsMiddleware, ResponseCacheMiddleware, ResponseLinterMiddleware,
    TelemetryMiddleware, ThrottleMiddleware, VaryAccumulatorMiddleware, VerifySignedUrlMiddleware
};
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

$postGlobal = [
    CompressionMiddleware::class,
    CorsAndPoliciesMiddleware::class,
    VaryAccumulatorMiddleware::class,
    // ResponseLinterMiddleware::class // enable in dev
];

$register = static function (Registrar $registrar) use ($signKey): void {
    $router = $registrar->router();
    $route  = $registrar->facade();

    $route::get('/ping', fn() => Response::plaintext('pong'));
    $route::get('/hello/{name}', fn(Request $r, string $name)
        => Response::json(['hello' => $name, 'prefers' => $r->prefers(['application/json','+json','text/plain'])])
    )->name('hello');

    $route::get('/protected', fn() => Response::json(['ok' => true]))
        ->middleware(VerifySignedUrlMiddleware::class);

    $route::get('/download', fn() => Response::attachment('readme.txt', 'Hello'))->name('download');

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
    fallbackAliasesFromRegistrar: true // helpful while warming cache
);

(new AutoEmitter())->emit($kernel->handle(Request::capture()));
```

**Switch to Fused cache:** use `Matching\FusedMatcher::make()` and set `routeCache` to a file (e.g. `.../var/cache/routes/__routes.php`).

### Minimal “Hello Webrick” (sanity test)

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

require __DIR__ . '/../vendor/autoload.php';

$router = new RouterKernel();

$router->get('/', fn(Request $r) => Response::plaintext('Hello Webrick!'));
$router->get('/api/ping', fn(Request $r) => Response::json(['ok' => true, 'message' => 'pong', 'time' => gmdate('c')]));

$router->run();
```

Run locally:

```bash
php -S 127.0.0.1:8080 -t public
# open http://127.0.0.1:8080/ and /api/ping
```

---

## Recipes

Common tasks with copy-paste-able snippets:

* Trailing-slash normalization
* HEAD handling
* ETag & conditional requests
* Streaming downloads
* CORS per-route override
* Throttling patterns

---

## Middleware (built-ins)

* **GatewayHardeningMiddleware** – security headers, sane defaults.
* **NormalizeMethodMiddleware** – handles method overrides safely.
* **NegotiationMiddleware** – JSON/text/content negotiation helpers.
* **CacheValidatorsMiddleware** – ETag / If-Modified-Since handling.
* **ResponseCacheMiddleware** – opt-in response caching.
* **CorsAndPoliciesMiddleware** – CORS + policy headers.
* **CompressionMiddleware** – output compression (if proxy isn’t doing it).
* **VaryAccumulatorMiddleware** – builds `Vary` correctly across layers.
* **ThrottleMiddleware** – basic rate limiting patterns.
* **TelemetryMiddleware** – minimal request metrics hooks.

> Tip: Prefer **one source of compression** (app *or* proxy). Disable proxy buffering for streaming endpoints.

---

## Signed & Temporary URLs

```php
$url       = Response::urlFor('download');
$signed    = Response::signedUrlFor('protected');      // permanent signature
$temp      = Response::temporaryUrlFor('protected', 900); // expires in 900s
```

Protect the route:

```php
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;

Route::get('/protected', fn() => Response::plaintext('secret'))
    ->middleware(VerifySignedUrlMiddleware::class);
```

---

## Performance & Deployment Notes

* Enable **OPcache** in production; pre-warm the **route cache** in CI and ship artifacts.
* Keep **route names** stable—for durable links and signed URLs.
* Preserve **query strings** at the proxy (signed URLs require them).
* Tune **PHP-FPM** pools (pm, max_children) for your workload.

See: `docs/deployments/` (Nginx, Apache, Containers/K8s, Serverless, Troubleshooting, FPM tuning).

---

## Versioning & Support Matrix

* PHP: **8.4+** primary target.
* SemVer for public APIs. Breaking changes are noted in release notes.

---

## Roadmap (short)

* Route-level PSR-15 adapters & PSR-7 factories examples
* More recipes (auth patterns, sub-requests, websockets via adapter)
* Extended telemetry (OpenTelemetry exporter hooks)

---

## Contributing

PRs welcome! Please:

1. Run tests: `composer test`
2. Format: `composer format`
3. Keep examples **copy-paste-able** and docs in sync

See [`CONTRIBUTING`](./CONTRIBUTING.md) for details.

---

## Security

If you discover a security issue, please email the maintainer privately. Do **not** open a public issue until we coordinate a disclosure.

---

## License

[MIT](./LICENSE) © Infocyph
