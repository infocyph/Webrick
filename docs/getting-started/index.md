# Getting Started

Start here if you want a working Webrick app with current APIs and production-friendly defaults.

## What you'll set up

1. Install Webrick with Composer
2. Boot `RouterKernel::bootWithRegistrar(...)`
3. Register routes through `Infocyph\Webrick\Router\Facade\Router as Route`
4. Enable URL generation and signed URLs
5. Add middleware aliases where string middleware is needed
6. Build route cache for deployment

## Install

```bash
composer require infocyph/webrick
```

## Boot the kernel

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
    registrarOptions: [
        'exposeUrlServices' => true,
        'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev-key-change-me',
        'signedDefaultTtl' => 300,
        'urlBaseUri' => $_ENV['WEBRICK_URL_BASE_URI'] ?? 'http://localhost',
    ],
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

## First route helpers

```php
use Infocyph\Webrick\Router\Facade\Router as Route;

$url = Route::urlFor('home');
$signed = Route::signedUrlFor('home');
$temp = Route::temporaryUrlFor('home', ttl: 300);
```

## Route cache build

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
```

## Next steps

- Read `quickstart` for a fuller bootstrap with aliases and middleware.
- Read `framework-integration` when mounting Webrick inside another framework.
- Read `guides/urls` for the richer signed URL surface.
- Read `reference/route-cache` for matcher and artifact details.

```{toctree}
:maxdepth: 2
:hidden:
:caption: Getting Started

installation
quickstart
framework-integration
```
