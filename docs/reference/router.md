# Router API Reference

Low-level, definitive reference for registering routes and groups in Webrick.

---

## Namespace & facade

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
```

You can also use the underlying registrar passed into group callbacks (shown below as `$r`), which exposes the same methods.

---

## Terminology

* **Path template**: string with optional tokens, e.g. `/users/{id:int}`.
* **Handler**: `callable` – a closure or `[$class, 'method']`.
* **Route name**: unique identifier used for URL generation (`users.show`).
* **Options**: array with keys like `as`, `middleware`, `domain` (see below).

---

## Tokens & constraints

Inside `{name:constraint}`:

* Built-in aliases (common set):

    * `:int` → `\d+`
    * `:uuid` → canonical UUID
    * `:slug` → `[A-Za-z0-9-._]+` (typical)
    * `:hex` → `[A-Fa-f0-9]+`
    * `:any` → `[^/]+`
* Optional segment: `{name:int?}` (handler default should cover nulls).
* Raw regex: `{name:([A-Z]{2}\d{4})}`.
* Splat (rest path) pattern: `{path:.*}` (place **last** to avoid shadowing).

---

## Registration methods

### HTTP verbs

```php
Route::get   (string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
Route::post  (string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
Route::put   (string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
Route::patch (string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
Route::delete(string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
Route::any   (string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
```

* `$handler` can be a closure or `[$class, 'method']` (controller).
* Third param:

    * **string** → route name.
    * **array** → options (below).

### Multiple methods (match)

If supported:

```php
Route::match(array $methods, string $path, callable|array $handler, string|array|null $nameOrOptions = null): void;
```

Use sparingly; prefer explicit methods where possible.

### Resource routes (REST set)

```php
Route::resource(string $name, string $basePath, string $controllerClass): void;
```

Registers conventional routes:

| Action  | Method | Path               | Name            | Controller method |
| ------- | ------ | ------------------ | --------------- | ----------------- |
| index   | GET    | `/users`           | `users.index`   | `index()`         |
| create  | GET    | `/users/create`    | `users.create`  | `create()`        |
| store   | POST   | `/users`           | `users.store`   | `store()`         |
| show    | GET    | `/users/{id}`      | `users.show`    | `show($id)`       |
| edit    | GET    | `/users/{id}/edit` | `users.edit`    | `edit($id)`       |
| update  | PUT    | `/users/{id}`      | `users.update`  | `update($id)`     |
| destroy | DELETE | `/users/{id}`      | `users.destroy` | `destroy($id)`    |

(Implement only what you need; others can 404.)

### Groups

```php
Route::group(
  ?string $prefix = null,
  ?string $namePrefix = null,
  array|string|null $middleware = null,
  ?string $domain = null,
  callable $callback
): void;
```

Inside `$callback`, use either the injected registrar (e.g., `function ($r) { $r->get(...); }`) or the `Route::...` facade.

* **Nesting** is supported. Inner groups inherit and append `prefix` and `namePrefix`; middleware arrays are merged.

---

## Options array (per route)

Pass instead of a plain name:

```php
[
  'as'         => 'users.show',          // route name
  'middleware' => ['verifySignedUrl','throttle:5,60'],
  'domain'     => 'api.example.com',
]
```

* `as` (string): route name (will be prefixed by group `namePrefix` if present).
* `middleware` (string|array): appended to group middleware.
* `domain` (string): host scoping; prefer group-level for many routes.

---

## Ordering & matching rules

* Match order is the **registration order** within the same group scope.
* Put **static** routes before **dynamic** ones (`/users/new` before `/users/{id:int}`).
* Place **catch-alls** (`.*`) last inside their scope.
* Domain-scoped routes match only their host; no conflict with others.

---

## Handlers & dependency injection

Handlers may accept:

```php
function (\Infocyph\Webrick\Request\Request $r, int $id) { /* ... */ }
```

* Route params inject by **name**; scalars can be type-hinted.
* You can also inject the `Request` (first or mixed in any position).

---

## URL generation (via Response helpers)

Use route **names** to generate URLs:

```php
use Infocyph\Webrick\Response\Response;

$url = Response::urlFor('users.show', ['id'=>42]);               // /users/42
$abs = Response::urlFor('users.show', ['id'=>42], absolute:true);// https://host/users/42
```

Signed / temporary URLs (see `guides/urls.md`) require binding URL services at boot.

---

## Attribute routes (alternate registration)

Instead of central files, annotate classes:

```php
use Infocyph\Webrick\Router\Definition\Attribute\Get;

final class HelloRoutes {
  #[Get('/hello/{name}', name:'hello')]
  public function hello(string $name) { /* ... */ }
}
```

Discover & register:

```php
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;

AttributeRouteLoader::registerFromDirs($registrar, [
  'App\\Http\\Routes\\' => __DIR__.'/../src/Http/Routes',
]);
```

You can wrap the loader in a `Route::group(...)` to apply `prefix`, `namePrefix`, and middleware to all discovered routes.

---

## Route cache (performance)

Prebuild a **sharded** directory or **fused** file in CI and point the kernel to it at boot:

```php
$kernel = RouterKernel::bootWithRegistrar(
  /* ... */,
  routeCache: __DIR__.'/../var/cache/routes' // or routes.php
);
```

See `deployments/route-cache-warmup.md`.

---

## Examples

### Basic

```php
Route::get('/ping', fn () => 'pong', 'ping');
```

### Controller method with constraints & middleware

```php
Route::get('/users/{id:int}', [App\Http\Users::class, 'show'], [
  'as' => 'users.show',
  'middleware' => ['auth','throttle:60,60'],
]);
```

### Grouped API v1

```php
Route::group(prefix:'/api', namePrefix:'api.', middleware:['throttle:120,60'], callback:function ($api) {
  $api->get('/v1/status', fn()=>['ok'=>true], 'v1.status');
});
```

### Domain-scoped admin

```php
Route::group(domain:'admin.example.com', prefix:'/dashboard', namePrefix:'admin.', callback:function () {
  Route::get('/', fn()=>'home', 'home');
});
```

---

## Troubleshooting

| Symptom                  | Likely cause                         | Fix                                                                 |
| ------------------------ | ------------------------------------ | ------------------------------------------------------------------- |
| 404 on dynamic route     | Constraint too strict / order        | Relax token or place before catch-alls                              |
| 405 Method Not Allowed   | Path matches different verb          | Register the intended method or allow override via NormalizeMethod  |
| Wrong URL generated      | Missing/incorrect `name`             | Set `'as' => '...'`; confirm `namePrefix` from groups               |
| Domain route not hit     | Host mismatch                        | Use group `domain:` or generate **absolute** URLs with correct host |
| Attribute routes missing | Loader not called or wrong namespace | Register directories and namespaces as in the loader mapping        |

---

## Checklist

* [ ] Name important routes (for URL generation & redirects)
* [ ] Constrain params (`:int`, `:uuid`, custom regex)
* [ ] Use groups for shared `prefix`, `namePrefix`, `middleware`, `domain`
* [ ] Order static before dynamic; catch-alls last
* [ ] Consider attribute routes for modular codebases
* [ ] Prebuild **route cache** for faster boots in prod

