# Webrick Router – Documentation Index

A fast, modern PHP router with production-grade middleware, signed URLs, streaming responses, and route caching.
These docs are aligned exactly to the codebase.

## What you get

- **Powerful routing**: Named routes, groups, domain scoping, resource routes, attribute discovery.
- **Signed & temporary URLs**: First-class helpers + verifier middleware.
- **Middleware pipeline**: Pre/post globals for gateway hardening, negotiation, validators, compression, CORS/policies, telemetry, throttling, etc.
- **Smart responses**: JSON/text auto negotiation, streaming, attachments, downloads.
- **Route cache**: Sharded (directory) or Fused (single-file) builders.
- **PSR interop**: Optional PSR-7 factory to build Request/Response/Stream.

## Requirements & Install

- PHP 8.4+ with OPcache enabled for production.
- Composer:

```bash
composer require infocyph/webrick
```

## Quick Start

1) **Boot the kernel** with a matcher and registrar:

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

$kernel = RouterKernel::bootWithRegistrar(
    ShardedMatcher::make(__DIR__.'/var/route-cache'),
    require __DIR__.'/routes.php',
    registrarOptions: [
        'autoSlashRedirect' => true,
        'exposeUrlServices' => true,
        'signKey'           => getenv('WEBRICK_SIGN_KEY') ?: 'dev-key-change-me',
        'signedDefaultTtl'  => 300,
        'fallbackAliasesFromRegistrar' => true,
    ],
    preGlobal: [
        \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
        \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
        \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
        // ...sanitize, throttle, cookies, etc.
    ],
    postGlobal: [
        \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
        \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
        // \Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware::class,
    ]
);
```

2) **Define routes** (routes.php):

```php
use Infocyph\Webrick\Router\Route;
use Infocyph\Webrick\Response\Response as R;

Route::get('/', fn() => R::plaintext('Hello Webrick'))->name('home');

// Signed download
Route::get('/download/{file}', function (string $file) {
    return R::attachment(__DIR__.'/files/'.$file);
})->name('file.download')->middleware(['verifySignedUrl']);

// Group + throttle
Route::group(['prefix' => '/api', 'middleware' => ['throttle:60,60']], function () {
    Route::get('/users', [App\Http\Controller\UserController::class, 'index'])->name('users.index');
});
```

3) **Generate a temporary signed link**:

```php
$href = R::temporaryUrlFor('file.download', ['file' => 'report.pdf'], ttl: 900);
```

## Documentation Map

- **Getting Started**
  - Installation, front controller, first routes
- **Guides**
  - Signed & Temporary URLs
  - Attribute Routes
  - Responses & Content Negotiation
- **Middleware**
  - Validators, Compression, Cookies, Negotiation, CORS/Policies, Gateway Hardening, Normalize Method, Throttle, Telemetry, Vary Accumulator
- **Deployments**
  - Nginx, Apache, Containers, Kubernetes, Serverless, Troubleshooting
- **Reference**
  - Matcher (Sharded vs Fused), Route Cache, Enums, Request/Response (with PSR-7 factory notes)

## Tips for Production

- Prefer **one source of compression** (app or proxy).
- Pre-warm **route cache** during CI and ship with artifacts.
- Keep **route names stable** for durable links.
- Preserve **query strings** at the proxy (required for signed URLs).
- Tune **FPM** (pm/max_children) and enable **OPcache**.

```{toctree}
:maxdepth: 2
:hidden:
:caption: Contents

getting-started/index
guides/index
middleware/index
deployments/index
reference/index
quickstart/hello-webrick
recipes/index
