# Webrick Router – Documentation Index

These docs track the current Webrick API and runtime shape.

## What you get

- Routing: named routes, groups, domains, resources, and attribute discovery
- Signed URLs: relative or absolute payload signing, TTL or explicit expiry, ignored query params, key rotation
- Error boundary: framework failures throw typed HTTP exceptions and the kernel renders the final response
- Middleware pipeline: pre-global and post-global stacks plus string aliases or direct middleware instances
- Responses: JSON, plaintext, redirects, streaming, ranged file/download helpers, and views
- Route cache: sharded, fused, or generated matcher modes
- PSR interop: request/response factories and stream abstractions

## Install

```bash
composer require infocyph/webrick
```

## Current boot pattern

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        unset($registrar);

        Route::get('/', fn() => Response::plaintext('Hello Webrick', 200), 'home');
    },
    routeCache: __DIR__ . '/.route-cache',
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

## Signed URL example

```php
use Infocyph\Webrick\Router\Facade\Router as Route;

$href = Route::temporaryUrlFor('file.download', ['file' => 'report.pdf'], ttl: 900);
```

## Production notes

- Prebuild route cache in CI and ship `.route-cache` with your release artifact.
- Register middleware aliases explicitly before you use string middleware like `throttle:60,60` or `verifySignedUrl`.
- Set a stable signing key and, when generating absolute URLs, configure `urlBaseUri`.
- Preserve query strings at the proxy layer; signed URLs depend on them.

## New guide

If you want JSON API errors, HTML browser errors, or any other boundary-specific rendering strategy, see:

- [Error Rendering](./guides/error-rendering.md)

```{toctree}
:maxdepth: 2
:hidden:
:caption: Contents

getting-started/index
guides/index
middleware/index
deployments/index
reference/index
recipes/index
```
