# Webrick Router

A modern PHP router with route caching, signed URLs, production middleware, and response helpers.

[![Security & Standards](https://github.com/infocyph/Webrick/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/Webrick/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/webrick?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fwebrick)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/webrick)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/webrick/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/Webrick)
[![Documentation](https://img.shields.io/badge/Documentation-Webrick-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/webrick/en/latest/)

## Highlights

- Fast routing: named routes, groups, domains, resources, attribute discovery
- Signed URLs: permanent, TTL-based, or explicit-expiry links
- Rich signing controls: relative or absolute payloads, ignored query params, key rotation, custom signature params and algorithms
- Central error boundary: framework middleware throws typed HTTP exceptions and the kernel renders them just before emission
- Response helpers: JSON, plaintext, redirects, streaming, ranged file/download responses, views
- Route caching: sharded, fused, or generated matchers
- Middleware pipeline: negotiation, compression, throttling, validators, telemetry, cookie encryption, and more

## Requirements

- PHP 8.4+
- Composer 2.x

## Installation

```bash
composer require infocyph/webrick
```

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

        Route::get('/', fn() => Response::plaintext('Hello Webrick', 200), 'home');
        Route::get('/api/users/{id:int}', fn(Request $request, string $id) => Response::json([
            'id' => (int) $id,
            'method' => $request->getMethod(),
        ]), 'users.show');
    },
    routeCache: __DIR__ . '/.route-cache',
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

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
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\NullLogger;

require __DIR__ . '/vendor/autoload.php';

$signKey = $_ENV['WEBRICK_SIGN_KEY'] ?? 'change-me';
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
    bindUrlServices: static function (Collection $routes) use ($signKey, $signedUrls, $baseUri): void {
        Route::bindUrlServices($routes, $signKey, 900, $signedUrls, $baseUri);
    },
    fallbackAliasesFromRegistrar: true,
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

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

Framework-owned failures such as invalid signed URLs, negotiation failures, throttling, request limits, bad Host headers, and maintenance mode now throw typed HTTP exceptions internally. `RouterKernel` catches them at the top-level error boundary and renders the final HTTP response there. Your controllers and user middleware can still return `Response` objects with explicit status codes directly.

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

Build cache artifacts during CI or deploy:

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
```

Clear them when needed:

```bash
php ./webrick route:clear --matcher=sharded --cache=.route-cache
```

## Security

Protected by [PHPForge](https://github.com/infocyph/PHPForge) — an automated quality and security gate for PHP projects.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/webrick/en/latest/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a> •
  <a href="https://github.com/infocyph/Webrick/issues">Report | Request | Suggest</a>
</div>
