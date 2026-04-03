# Quick Start

Wire a minimal app end‑to‑end: front controller → routes → middleware → emit. Then add signed URLs and route caching.

:::{admonition} Prerequisites
:class: tip

* Installed via Composer: `composer require infocyph/webrick`
* PHP 8.4+, Composer autoloading
* A writable cache directory (e.g., `.route-cache`)
  :::

---

## 1) Create your front controller (full-featured)

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Middleware\{CacheValidatorsMiddleware,CompressionMiddleware,CookieEncryptionMiddleware,CorsAndPoliciesMiddleware,GatewayHardeningMiddleware,InputSanitizerMiddleware,MaintenanceModeMiddleware,NegotiationMiddleware,NormalizeMethodMiddleware,RequestLimitsMiddleware,ResponseCacheMiddleware,ResponseLinterMiddleware,TelemetryMiddleware,ThrottleMiddleware,VaryAccumulatorMiddleware,VerifySignedUrlMiddleware};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route;
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
    $route::get('/ping', fn() => Response::plaintext('pong', 200));
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
    routeCache: __DIR__ . '/../.route-cache',
    registrarOptions: [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
        'signKey'           => $signKey,
        'signedDefaultTtl'  => $defaultTtl,
    ],
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
    bindUrlServices: static function (Collection $routes) use ($signKey, $defaultTtl): void {
        Route::bindUrlServices($routes, $signKey, $defaultTtl);
    },
    // Keep true initially to fall back to live aliases if cache is incomplete
    fallbackAliasesFromRegistrar: true,
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

Optional InterMix-first wiring in the same bootstrap call:

```php
$kernel = RouterKernel::bootWithRegistrar(
    // ... existing options
    serviceProviders: [
        App\Providers\AuthProvider::class,
        App\Providers\CacheProvider::class,
    ],
    preGlobalTags: ['webrick.middleware.pre'],
    postGlobalTags: ['webrick.middleware.post'],
    requestScopeEnabled: true, // per-request enterScope/leaveScope
);
```

`preGlobalTags` / `postGlobalTags` append tagged middleware from InterMix definitions.

> To switch to a **single‑file fused cache**, use `Matching\FusedMatcher::make()` and set `routeCache` to a file (e.g., `.../.route-cache/__routes.php`).

---

## 2) Generate signed & temporary URLs

Once `exposeUrlServices` is enabled (or you bound them manually via `bindUrlServices`), you can generate URLs from handlers:

```php
// Inside a route handler:
$url        = Route::urlFor('download');
$signedUrl  = Route::signedUrlFor('protected');           // permanent signature
$tempUrl    = Route::temporaryUrlFor('protected', ttl: 900);   // TTL 900s
```

Attach the verifier to your protected route:

```php
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;

$route::get('/protected', fn() => Response::plaintext('secret', 200))
    ->middleware(VerifySignedUrlMiddleware::class);
```

---

## 3) Minimal "Hello Webrick" (quick sanity test)

A tiny project you can run in seconds. Great for verifying your PHP setup and seeing the router respond.

### Directory layout
```
examples/hello-webrick/
├─ composer.json
├─ public/
│  └─ index.php
└─ README.md
```

### composer.json (example)
```json
{
  "name": "infocyph/hello-webrick",
  "type": "project",
  "require": {
    "php": ">=8.4",
    "infocyph/webrick": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

### public/index.php (example)
```php
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $r): void {
        $route = $r->facade();

        // GET / → plaintext hello
        $route::get('/', fn (Request $req) => Response::plaintext('Hello Webrick!', 200));

        // GET /api/ping → JSON
        $route::get('/api/ping', fn (Request $req) => Response::json([
            'ok' => true,
            'message' => 'pong',
            'time' => gmdate('c'),
        ]));
    },
);

(new AutoEmitter())->emit($kernel->handle(Request::fromGlobals()));
```

### Run locally
```
cd examples/hello-webrick
composer install
php -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080/ and http://127.0.0.1:8080/api/ping

**Notes**
- Uses `Response::plaintext()` and `Response::json()` helpers.
- Swap `run()` to your preferred emitter if your stack requires it.


---

## 4) Register Middleware Aliases (Required for String Syntax)

Before using string-based middleware like `'throttle:60,60'` or `'verifySignedUrl'`, register them:
```php
<?php
// In your front controller (public/index.php), before booting the kernel

use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;

// Register throttle alias with parameter parsing
MiddlewareAliases::register(
    'throttle',
    static fn(...$params) => new ThrottleMiddleware(
        max: (int)($params[0] ?? 60),      // max requests
        window: (int)($params[1] ?? 60),   // window in seconds
        pool: Cache::local('throttle')     // Your cache instance
    )
);

// Register signed URL verifier
MiddlewareAliases::register(
    'verifySignedUrl',
    static function () use ($signKey) {
        return new VerifySignedUrlMiddleware(
            signKey: $signKey,
            leeway: 5  // 5-second clock skew tolerance
        );
    }
);

// Now you can use string syntax in routes
Route::get('/protected', fn() => Response::json(['ok' => true]), [
    'middleware' => ['verifySignedUrl', 'throttle:30,60']
]);
```

**Common Aliases**:
```php
// Authentication
MiddlewareAliases::register('auth', fn() => new AuthMiddleware());

// CORS with specific config
MiddlewareAliases::register('cors', fn() => new CorsAndPoliciesMiddleware([
    'allow_origins' => ['https://app.example.com'],
    'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
]));

// Custom throttle presets
MiddlewareAliases::register('throttle.strict', fn() => new ThrottleMiddleware(
    max: 10, window: 60, pool: Cache::local('throttle')
));

MiddlewareAliases::register('throttle.relaxed', fn() => new ThrottleMiddleware(
    max: 1000, window: 60, pool: Cache::local('throttle')
));
```

**Without Aliases** (Alternative):
```php
// You can also use class instances directly (no alias needed)
Route::get('/protected', fn() => Response::json(['ok' => true]), [
    'middleware' => [
        new VerifySignedUrlMiddleware($signKey, leeway: 5),
        new ThrottleMiddleware(max: 30, window: 60, pool: $cache)
    ]
]);
```


---

## 5) Next steps

- Explore **Guides → Routing, Groups & Domains** for more patterns.
- See **Middleware** pages for what each pre/post step does.
- Read **Reference → Route Cache** to choose between **Sharded** and **Fused** caches.
