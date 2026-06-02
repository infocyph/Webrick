# Quick Start

Build a small app with the current Webrick runtime flow: boot the kernel, register routes through the `Route` facade, add middleware aliases, and generate signed URLs.

## 1. Front controller

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

require __DIR__ . '/../vendor/autoload.php';

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
    matcher: ShardedMatcher::make(__DIR__ . '/../.route-cache'),
    register: static function (Registrar $registrar): void {
        unset($registrar);

        Route::get('/ping', fn() => Response::plaintext('pong', 200), 'ping');
        Route::get('/hello/{name}', fn(string $name) => Response::json(['hello' => $name]), 'hello');
        Route::get('/protected', fn() => Response::json(['ok' => true]), [
            'as' => 'protected',
            'middleware' => ['verifySignedUrl'],
        ]);
        Route::get('/download/{file}', fn(string $file) => Response::attachment(__DIR__ . '/../files/' . $file, $file), [
            'as' => 'download',
            'middleware' => ['verifySignedUrl', 'throttle:30,60'],
        ]);

        Route::group(prefix: '/api', callback: static function (): void {
            Route::get('/status', fn() => Response::json(['status' => 'ok']), 'api.status');
        });
    },
    routeCache: __DIR__ . '/../.route-cache',
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

## 2. Generate URLs

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

$url = Route::urlFor('download', ['file' => 'report.csv']);
$signed = Route::signedUrlFor('protected');
$temp = Route::temporaryUrlFor('download', ['file' => 'report.csv'], ttl: 900);
$until = Route::temporaryUrlUntil('download', new DateTimeImmutable('+15 minutes'), ['file' => 'report.csv']);
$absolutePayload = Route::signedUrlFor(
    'download',
    ['file' => 'report.csv'],
    absolute: true,
    payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
);
```

## 3. Build route cache

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
```

Switch to fused cache by using a single file path such as `.route-cache/__routes.php` with `FusedMatcher::make(...)` and the same `routeCache` path.

## 4. Run locally

```bash
php -S 127.0.0.1:8080 -t public
```

Open:

- `http://127.0.0.1:8080/ping`
- `http://127.0.0.1:8080/hello/Alice`
- `http://127.0.0.1:8080/api/status`

## 5. Notes

- String middleware like `verifySignedUrl` and `throttle:30,60` requires alias registration first.
- If you do not want aliases, pass middleware instances directly in the route options array.
- When you generate absolute URLs, set `urlBaseUri` to your real application origin.
- Signed URLs reserve the signature and expiry query keys from the active `SignedUrlConfig`.
