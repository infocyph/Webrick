# Webrick

A framework-neutral HTTP routing kernel for PHP with deploy-time route compilation,
lazy middleware resolution, signed URLs, response helpers and emitters for
traditional and persistent runtimes.

[![Security & Standards](https://github.com/infocyph/Webrick/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/Webrick/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/webrick?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fwebrick)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/webrick)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/webrick/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/Webrick)
[![Documentation](https://img.shields.io/badge/Documentation-Webrick-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/webrick/en/latest/)

## Highlights

- Fast routing: named routes, groups, domains, resources, attribute discovery
- Framework-neutral: run Webrick standalone or mount it behind another framework's request/response adapter
- Deploy-time compilation: sharded, fused and generated PHP route-cache artifacts
- Validated cache publication: exact format versions, staged PHP validation and atomic activation
- Lean cached dispatch: class handlers and string middleware stay scalar until the matched route is materialized
- Safe callable caching: public static first-class callables become scalar descriptors; stateful closures keep serializer semantics
- Lazy optional services: middleware alias families and URL generation are resolved only when used
- DI-aware dispatch: constructor and method injection through InterMix, with request scopes and service providers
- Signed URLs: permanent, TTL-based, or explicit-expiry links
- Rich signing controls: relative or absolute payloads, ignored query params, key rotation, custom signature params and algorithms
- Central error boundary: framework middleware throws typed HTTP exceptions and the kernel renders them just before emission
- User controllers and user middleware can still return `Response` directly; only framework-owned rejection paths are exception-driven
- Response helpers: JSON, plaintext, redirects, streaming, ranged file/download responses, views
- Middleware pipeline: negotiation, compression, throttling, validators, telemetry, cookie encryption and more
- Runtime emitters: PHP-FPM, FrankenPHP, LiteSpeed, Nginx Unit, CLI, Swoole, RoadRunner and Workerman

## Requirements

- PHP 8.4+
- Composer 2.x

## Installation

```bash
composer require infocyph/webrick
```

Core routing does not install or initialize CacheLayer. Add CacheLayer only when using
`ResponseCacheMiddleware`, the default throttle cache backend, or CacheLayer's
atomic counter backend:

```bash
composer require infocyph/cachelayer
```

`ThrottleMiddleware` can also run without CacheLayer when the application
supplies its own PSR-6 cache pool.

Webrick is a library, not an application skeleton. Its directory layout,
configuration source, container composition and deployment entry point remain
under the host application's control.

## Minimal Boot Example

```php
<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

require __DIR__ . '/vendor/autoload.php';

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        unset($registrar);

        Route::get('/', static fn() => Response::plaintext('Hello Webrick', 200), 'home');
        Route::get('/api/users/{id:int}', static fn(Request $request, string $id) => Response::json([
            'id' => (int) $id,
            'method' => $request->getMethod(),
        ]), 'users.show');
    },
    routeCache: __DIR__ . '/.route-cache',
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

For deployable route caches, prefer a controller class-string or
`[Controller::class, 'method']` handler. Static controller methods avoid a
controller allocation; non-static methods are constructed through InterMix.
Closures and object-backed handlers remain supported through the serializer
fallback, but they are not the cheapest cache representation.

Route-cache builds validate staged executable PHP before activation. Sharded
builds publish an immutable generation through a small manifest, so an
incomplete build cannot replace the generation used by live workers.
On systems that support symbolic links, a tiny atomic `__current` pointer keeps
generation selection out of PHP cache hydration; the manifest remains the
portable fallback.

## Use Inside Another Framework

Keep one `RouterKernel` for the application or worker lifecycle and adapt only at
the HTTP boundary:

```php
$webrickRequest = $requestAdapter->toWebrick($frameworkRequest);
$webrickResponse = $kernel->handle($webrickRequest);

return $responseAdapter->fromWebrick($webrickResponse);
```

Webrick's `Request` and `Response` expose familiar PSR-7-style message methods,
but they do not implement the PSR-7 interfaces. A host framework must therefore
provide the explicit adapters shown above. Do not emit the response from
Webrick when the host framework owns emission.

See documentation for container, middleware, request-scope and persistent-worker guidance.

## Production-Oriented Boot Example

```php
<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\NullLogger;

require __DIR__ . '/vendor/autoload.php';

$signKey = $_ENV['WEBRICK_SIGN_KEY']
    ?? throw new RuntimeException('WEBRICK_SIGN_KEY is required');
$baseUri = $_ENV['WEBRICK_URL_BASE_URI'] ?? 'http://localhost';
$signedUrls = new SignedUrlConfig(
    generationKey: $signKey,
    verificationKeys: [$signKey],
    defaultTtl: 900,
);

MiddlewareAliases::register(
    'throttle',
    static fn(...$params) => new ThrottleMiddleware(
        max: (int) ($params[0] ?? 60),
        window: (int) ($params[1] ?? 60),
    ),
);
MiddlewareAliases::register(
    'verifySignedUrl',
    static fn() => new VerifySignedUrlMiddleware($signKey, 5),
);

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        unset($registrar);

        Route::get('/users/{id:int}', fn(string $id) => Response::json(['id' => (int) $id]), 'users.show');
        Route::get('/files/{file}', fn(string $file) => Response::attachment(__DIR__ . '/files/' . $file, $file), [
            'as' => 'files.show',
            'middleware' => ['verifySignedUrl'],
        ]);
    },
    routeCache: __DIR__ . '/.route-cache',
    registrarOptions: [
        'exposeUrlServices' => true,
        'signKey' => $signKey,
        'signedDefaultTtl' => 900,
        'signedUrlConfig' => $signedUrls,
        'urlBaseUri' => $baseUri,
    ],
    preGlobal: [
        GatewayHardeningMiddleware::class,
        NegotiationMiddleware::class,
    ],
    postGlobal: [
        CompressionMiddleware::class,
    ],
    fallbackAliasesFromRegistrar: true,
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

The signing configuration is preserved in `registrarOptions`. On cached boot,
the default URL binding defers alias loading and `UrlGenerator` construction
until the first URL helper call. Supply `bindUrlServices` only when an
integration needs to replace that default binding behavior.

## URL Generation

```php
use DateTimeImmutable;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

$url = Route::urlFor('users.show', ['id' => 42]);
$absolute = Route::urlFor('users.show', ['id' => 42], absolute: true);

$signed = Route::signedUrlFor('files.show', ['file' => 'report.pdf']);
$temp = Route::temporaryUrlFor('files.show', ['file' => 'report.pdf'], ttl: 900);
$until = Route::temporaryUrlUntil('files.show', new DateTimeImmutable('+15 minutes'), ['file' => 'report.pdf']);

$absolutePayload = Route::signedUrlFor(
    'files.show',
    ['file' => 'report.pdf'],
    absolute: true,
    payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
);
```

Framework-owned failures such as invalid signed URLs, negotiation failures, throttling, request limits, bad Host headers and maintenance mode now throw typed HTTP exceptions internally. `RouterKernel` catches them at the top-level error boundary and renders the final HTTP response there. Your controllers and user middleware can still return `Response` objects with explicit status codes directly.

The demo app also includes `/api/error-demo`, which throws a framework HTTP exception and is rendered as JSON through a custom error boundary override.

You can also customize the final exception-to-response conversion by supplying your own `ErrorHandler`:

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Throwable;

$errorHandler = new ErrorHandler(
    responseRenderer: static function (Request $request, Throwable $e, int $status, array $headers): ?Response {
        if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
            return null;
        }

        return Response::json([
            'error' => $e->getMessage(),
            'status' => $status,
            'path' => $request->getUri()->getPath(),
        ], $status, $headers);
    },
);
```

## Route Cache

Build cache artifacts during CI or deploy. Cache creation may spend more work so
the request path does less:

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php
php ./webrick route:cache --matcher=generated --cache=.route-cache/generated.php --routes=routes.php
```

Clear them when needed:

```bash
php ./webrick route:clear --matcher=sharded --cache=.route-cache
```

All matcher factories are zero-argument. Pass the cache directory or file only
as `routeCache:` when booting the kernel.

For the major release, rebuild every route-cache artifact after updating;
cache formats are internal deployment artifacts and are not portable across
major versions.

## Benchmarks

The two benchmark directories serve different workflows:

- `benchmark/` contains the standalone executable matcher runner for quick
  local comparisons and smoke checks.
- `benchmarks/` contains the structured PhpBench suite for repeatable matcher,
  cache-lifecycle, kernel-dispatch, signed-URL, and ETag measurements.

Keep build and boot results separate from steady-state request measurements.
Record PHP, extension, hardware, route-set, warmup, and iteration details when
publishing results.

## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/Webrick/security/advisories/new).

Webrick is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.


---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/Webrick/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/Webrick/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/Webrick/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/Webrick/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/Webrick/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/Webrick/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/Webrick/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>
