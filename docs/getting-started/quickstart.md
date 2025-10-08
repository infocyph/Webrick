# Quick Start

This page wires a minimal app end-to-end: front controller → routes → controllers → middleware → emitter. You’ll be serving JSON in minutes, then you’ll add signed URLs, streaming, and route caching.

:::{admonition} Prerequisites
:class: tip

* Installed via Composer: `composer require infocyph/webrick`
* PHP 8.4+, Composer autoloading
* A writable cache directory (e.g., `var/cache/routes`)
  :::

---

## 1) Create your front controller

`public/index.php`:

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

$routesFile  = __DIR__ . '/../routes/web.php';
$routeCache  = __DIR__ . '/../var/cache/routes';
$signKey     = $_ENV['WEBRICK_SIGN_KEY']  ?? 'dev-sign-key';
$defaultTtl  = (int)($_ENV['WEBRICK_SIGN_TTL'] ?? 900);

$preGlobal = [
  // e.g. hardening, limits, throttle, cookie encryption, normalize-method, input-sanitizer,
  // negotiation, response-cache, cache-validators...
];

$postGlobal = [
  // e.g. compression, CORS & policies, vary-accumulator (and response-linter in dev)...
];

$kernel = RouterKernel::bootWithRegistrar(
  log: new NullLogger(),
  matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
  register: static function ($registrar) use ($routesFile) {
    require $routesFile;                    // 2) your route definitions
  },
  routeCache: $routeCache,                  // sharded cache directory
  registrarOptions: [
    'exposeUrlServices' => true,
    'autoSlashRedirect' => false,
    'signKey'           => $signKey,
    'signedDefaultTtl'  => $defaultTtl,
  ],
  preGlobal: $preGlobal,
  postGlobal: $postGlobal,
  bindUrlServices: static function ($routes) use ($signKey, $defaultTtl) {
    Response::bindUrlServices($routes, $signKey, $defaultTtl);
  }
);

(new AutoEmitter())->emit($kernel->handle());
```

Run locally:

```bash
php -S 127.0.0.1:8000 -t public
```

---

## 2) Define your first routes

`routes/web.php`:

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

// Home
Route::get('/', fn () => Response::json(['ok' => true, 'router' => 'webrick']), 'home');

// Parameters
Route::get('/hello/{name}', fn (Request $r, string $name) => Response::json(['hello' => $name]));

// Method variants (POST/PUT)
Route::post('/echo', fn (Request $r) => Response::json(['payload' => $r->all()]));
Route::put('/user/{id:int}', fn (Request $r, int $id) => Response::json(['updated' => $id]));

// Redirects & attachments
Route::get('/to-home', fn () => Response::redirect('/', 302));
Route::get('/download', fn () => Response::attachment(__FILE__, 'web.php'));
```

Test quickly:

```bash
curl http://127.0.0.1:8000/hello/Hasan
```

---

## 3) Add a controller

`src/Http/DemoController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class DemoController
{
    public function hello(Request $request, string $name): Response
    {
        return Response::json(['controller' => 'DemoController', 'hello' => $name]);
    }
}
```

Route using the controller:

```php
use App\Http\DemoController;
use Infocyph\Webrick\Router\Facade\Router as Route;

Route::get('/class/test/{name}', [DemoController::class, 'hello'], 'demo.hello');
```

---

## 4) Groups, prefixes & domains

```php
// Prefixed group
Route::group(prefix:'/api', namePrefix:'api.', callback:function ($api) {
  $api->get('/ping', fn() => 'pong', 'ping');
});

// Domain-scoped (example dev host)
Route::group(domain:'api.localhost', namePrefix:'v1.', prefix:'/v1', callback:function () {
  Route::get('/status', fn()=>['ok'=>true], 'status');
});
```

---

## 5) Attribute routes (optional)

Autoload routes from annotated classes. First, register directories in your **front controller** (or via your warmup script):

```php
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;

$register = static function ($registrar): void {
  require __DIR__ . '/../routes/web.php';
  AttributeRouteLoader::registerFromDirs(
    $registrar,
    ['App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes']
  );
};
```

Then in `src/Http/Routes/Hello.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Routes;

use Attribute;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Get;

final class Hello
{
    #[Get('/attr/hello/{name}', 'attr.hello')]
    public function __invoke(string $name)
    {
        return Response::json(['hello' => $name, 'via' => 'attribute']);
    }
}
```

---

## 6) Signed & temporary URLs

Enable URL services (already wired above with `bindUrlServices`). Use in routes:

```php
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Request\Request;

Route::get('/secure/{id:int}', function (Request $r, int $id) {
  return Response::json(['id'=>$id, 'ok'=>true, 'time'=>time()]);
}, ['as'=>'secure.show', 'middleware'=>['verifySignedUrl','throttle:5,1']]);

Route::get('/make-signed/{id:int}', function (int $id) {
  $url = Response::temporaryUrlFor('secure.show', ['id'=>$id], ['dl'=>1], absolute:false, ttl:900);
  return Response::redirect($url, 302);
}, 'make.signed');
```

Try:

```bash
curl -i http://127.0.0.1:8000/make-signed/42
```

Follow the redirect to see the validated endpoint.

---

## 7) Streaming responses

For long-running tasks or chunked output:

```php
use Infocyph\Webrick\Response\Response;

Route::get('/stream', function () {
  return Response::stream(function () {
    for ($i=1; $i<=5; $i++) {
      yield "chunk: {$i}\n";
      usleep(150_000);
    }
    return ''; // optional trailer
  });
});
```

---

## 8) Middleware (pre & post) at a glance

* **Pre-global** (runs before handler): gateway hardening, telemetry, maintenance-mode, request limits, throttle, cookie encryption, normalize-method (supporting `_method`), input sanitizer, negotiation, response cache, cache validators.
* **Post-global** (after handler): compression, CORS & policies, vary accumulator, response linter (dev).

Wire them by class name (or with constructor args) in `$preGlobal` / `$postGlobal` in `index.php`.

Per-route middleware can be added via route options:

```php
Route::get('/limited', fn()=> 'ok', ['middleware'=>['throttle:10,1']]);
```

---

## 9) Route cache warmup (AOT)

Add a small script to prebuild caches in CI/CD:

`scripts/build-route-cache.php`:

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

RouteCache::build([
  'cache'   => __DIR__ . '/../var/cache/routes', // sharded (dir)
  'routes'  => __DIR__ . '/../routes/web.php',
  'logger'  => new NullLogger(),
  'registrarOptions' => [
    'exposeUrlServices' => true,
  ],
]);
```

Run:

```bash
php scripts/build-route-cache.php
```

On boot, the matcher will use the warmed cache automatically.

---

## 10) Debugging tips

* **404s:** check `try_files` and that `public/index.php` is hit; verify route names and parameter patterns.
* **Signed URL errors:** confirm `WEBRICK_SIGN_KEY`, TTL, and that you used the same route name/params for generation and verification.
* **Compression not applied:** ensure client sends `Accept-Encoding` and PHP has the necessary codecs enabled.
* **Streaming looks buffered:** test with `curl` and avoid buffering proxies; ensure your web server doesn’t buffer upstream responses.

---

## Checklist

* [ ] Front controller created and autoloading verified
* [ ] Routes file added (basic, controller, groups)
* [ ] (Optional) Attribute routes registered
* [ ] (Optional) Signed URLs key & TTL configured
* [ ] (Optional) Streaming route validated with `curl`
* [ ] Route cache warmup script added to CI/CD

