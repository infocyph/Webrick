# Attribute Routes

Keep routes close to their handlers by annotating classes/methods with attributes. Webrick can scan one or more directories, discover route attributes, and register them alongside your classic `routes/*.php` definitions.

---

## When to use attributes

* Feature modules that keep handler + route in one file
* Libraries that ship their own routes
* Large apps where route declarations would otherwise be huge

> For small apps or quick prototypes, a plain `routes.php` is perfectly fine. You can mix both styles.

---

## 1) Register attribute directories

Tell the router **where** to scan, and **which namespace** the files live under. Do this in your front controller (or in your route-cache warmup script):

```php
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;

$register = static function ($registrar): void {
    // keep classic file routes
    require __DIR__ . '/../routes/web.php';

    // add attribute-discovered routes
    AttributeRouteLoader::registerFromDirs(
        $registrar,
        [
            'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
            // you can add more: 'Vendor\\Pkg\\Routes\\' => __DIR__.'/../vendor/vendor/pkg/src/Routes'
        ]
    );
};
```

* Keys = **root namespace**
* Values = **absolute directory path** (non-absolute paths will be normalized relative to the current file—prefer absolute for clarity)

---

## 2) Annotate handlers

Create a class and annotate methods (or `__invoke`) with HTTP verb attributes.

```php
<?php
declare(strict_types=1);

namespace App\Http\Routes;

use Infocyph\Webrick\Router\Definition\Attribute\Get;
use Infocyph\Webrick\Router\Definition\Attribute\Post;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

final class HelloRoutes
{
    #[Get('/attr/hello/{name}', name: 'attr.hello')]
    public function hello(Request $r, string $name)
    {
        return Response::json(['hello' => $name, 'via' => 'attribute']);
    }

    #[Post('/attr/echo', name: 'attr.echo', middleware: ['throttle:5,1'])]
    public function echo(Request $r)
    {
        return Response::json(['payload' => $r->all()]);
    }
}
```

### Supported verb attributes (typical)

* `#[Get(path, name?: string, middleware?: array|string, domain?: string)]`
* `#[Post(...)]`, `#[Put(...)]`, `#[Patch(...)]`, `#[Delete(...)]`
* `#[Any(...)]` (use carefully)

> Exact attribute classes live under `Infocyph\Webrick\Router\Definition\Attribute\*`.

---

## 3) Route options via attributes

Attributes accept the same knobs you use in array route options:

* `name`: route name (will participate in `namePrefix` if grouped—see below)
* `middleware`: per-route middleware list
* `domain`: host scoping (prefer grouping for broad scopes)

Constraints are expressed inline in the path, e.g. `'/users/{id:int}'`, `'/color/{hex:hex}'`.

---

## 4) Attribute classes per file

You can spread attribute routes across multiple files in your module:

```
src/Http/Routes/
├─ HelloRoutes.php
├─ UsersRoutes.php
└─ ReportsRoutes.php
```

As long as the classes are under the registered namespace and directory, they’ll be discovered.

---

## 5) Mixing with classic routes & groups

You can still declare plain routes; attributes are **additional** registrations. If you need group-level prefix/name/middleware around attribute routes, wrap the loader call inside a group:

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;

Route::group(prefix:'/api', namePrefix:'api.', middleware:['throttle:60,1'], callback:function ($r) {
    AttributeRouteLoader::registerFromDirs(
        $r, ['App\\Api\\Routes\\' => __DIR__ . '/../src/Api/Routes']
    );
});
```

* All attribute-discovered routes inside will inherit `/api`, `api.*`, and the group middleware.

---

## 6) Route cache warmup with attributes

If you build **route cache** ahead-of-time (recommended in CI), include attribute directories there too:

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
  'cache'   => __DIR__ . '/../var/cache/routes',
  'routes'  => __DIR__ . '/../routes/web.php',   // classic file still included
  'registrarOptions' => [
    'exposeUrlServices' => true,
  ],
  // Option A: register attributes inside your routes.php/closure
  // Option B: or pass a registrar that calls AttributeRouteLoader::registerFromDirs(...)
]);
```

> The safest approach is to keep the `AttributeRouteLoader::registerFromDirs(...)` call in the same `$register` closure you use in production boot, so warmup and runtime see the exact same registration flow.

---

## 7) Parameter binding & signatures

Handlers work the same as classic routes:

```php
#[Get('/users/{id:int}', name: 'users.show')]
public function show(int $id) { /* ... */ }
```

* Type-hint scalars (`int`, `string`) or inject `Request` as needed.
* Add middleware like `'verifySignedUrl'` to secure signed endpoints.

---

## 8) Testing attribute discovery

Quick smoke test:

```bash
# assuming /attr/hello/{name}
curl -i http://127.0.0.1:8000/attr/hello/Hasan
```

If you get 404:

* Confirm the class namespace matches the configured root (`App\\Http\\Routes\\`).
* Ensure the directory path is correct and readable.
* Verify the loader is actually called (e.g., placed in your `$register`).

---

## 9) Common pitfalls

| Issue                      | Why it happens                                   | Fix                                                                                                |
| -------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| 404 on attribute route     | Loader not executed or path wrong                | Call `AttributeRouteLoader::registerFromDirs()` in your `$register`; check absolute directory path |
| Wrong route name           | Missing `name:` or unexpected group `namePrefix` | Set `name:` explicitly; verify group prefixing                                                     |
| Middleware not applied     | Placed only at group or only on attribute        | Apply where intended; group adds to all, attribute adds per-route                                  |
| Namespace mismatch         | Namespace root or PSR-4 path incorrect           | Align PHP namespace with folder path & loader mapping                                              |
| Domain routes not matching | Host mismatch                                    | Use `domain:` or group-domain and generate absolute URLs accordingly                               |

---

## 10) Style tips

* Keep **one concern per attribute class** (e.g., `UsersRoutes`, `ReportsRoutes`) for discoverability.
* Prefer **explicit names** (`name:`) to avoid brittle auto-naming.
* Use **group wrappers** when attribute routes form a module (prefix + namePrefix + shared middleware).
* Keep attributes declarative; place logic in services/controllers.

---

## Cheatsheet

```php
// Register
AttributeRouteLoader::registerFromDirs($registrar, [
  'App\\Http\\Routes\\' => __DIR__.'/../src/Http/Routes',
]);

// Define
#[Get('/v1/hello/{name}', name:'api.v1.hello', middleware:['throttle:30,1'])]
public function hello(string $name) { /* ... */ }
```
