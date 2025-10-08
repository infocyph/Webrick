# Routing

Webrick’s router is ergonomic and explicit. Define routes with closures or controller methods, add constraints, name them, bundle them into groups, and even scope by domain. This guide covers the essentials and useful patterns.

---

## Basic routes

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Response\Response;

// Plain text
Route::get('/ping', fn () => 'pong', 'ping');

// JSON
Route::get('/json', fn () => Response::json(['ok'=>true]), 'json');
```

* Signature: `Route::get($path, $handler, ?$nameOrOptions = null)`
* `$handler` can be a closure or `[$class, 'method']` (see Controllers below).
* Third argument may be a **name** (string) or **options array** (see “Route options”).

---

## HTTP methods

```php
Route::post('/submit', fn() => 'posted');
Route::put('/users/{id:int}', fn($id) => "updated $id");
Route::patch('/users/{id:int}', fn($id) => "patched $id");
Route::delete('/users/{id:int}', fn($id) => "deleted $id");

// Any method (use judiciously)
Route::any('/debug', fn()=>'ok');
```

Tip: If you need method override on forms, enable `NormalizeMethodMiddleware` in pre-globals to honor `_method=PUT|PATCH|DELETE`.

---

## Parameters & constraints

Segments inside `{}` are captured and injected into your handler by name.

```php
// Type constraints
Route::get('/users/{id:int}', fn(int $id) => "user $id");

// Hex color (custom token)
Route::get('/color/{hex:hex}', fn(string $hex) => Response::json(['hex'=>$hex]));
```

### Supported patterns

* `:int` → `\d+`
* `:uuid` → canonical UUID
* `:slug` → `[A-Za-z0-9-._]+` (example)
* `:hex` → `[A-Fa-f0-9]+`
* `:any` → `[^/]+` (single segment)

> You can also use raw regex: `{name:([A-Z]{2}\d{4})}`

### Optional segments & defaults

```php
// Optional with default in handler
Route::get('/page/{n:int?}', fn(?int $n = 1) => "page " . ($n ?? 1));
```

---

## Controllers

```php
use App\Http\DemoController;

Route::get('/class/test/{name}', [DemoController::class, 'hello'], 'demo.hello');
// DemoController::hello(Request $r, string $name): Response
```

Keep controller methods **thin**; delegate work to services for testability.

---

## Naming routes

Names are convenient for URL generation and redirection.

```php
Route::get('/profile/{id:int}', fn($id)=>"profile $id", 'profile.show');

// Later
use Infocyph\Webrick\Response\Response;
$url = Response::urlFor('profile.show', ['id'=>42]);     // /profile/42
return Response::redirect($url, 302);
```

---

## Route options

Instead of passing a plain name, pass an options array:

```php
Route::get('/secure/{id:int}', fn($id)=>"ok $id", [
  'as'         => 'secure.show',      // name
  'middleware' => ['verifySignedUrl','throttle:5,1'],
  'domain'     => 'api.example.com',  // domain scoping (rarely used directly; prefer groups)
]);
```

* `as` (string): route name
* `middleware` (array|string): per-route middleware
* `domain` (string): host to match (e.g., `api.example.com`)

---

## Groups, prefixes & domains

Bundle routes with common options (prefix, name prefix, middleware, domain).

```php
Route::group(
  prefix: '/api',
  namePrefix: 'api.',
  middleware: ['throttle:60,1'],
  callback: function ($api) {
    $api->get('/ping', fn()=> 'pong', 'ping');
    $api->get('/users/{id:int}', fn($id)=>"user $id", 'users.show');
  }
);

// Domain-scoped API v1 on dev
Route::group(
  domain: 'api.localhost',
  prefix: '/v1',
  namePrefix: 'v1.',
  callback: function () {
    Route::get('/status', fn()=>['ok'=>true], 'status');
  }
);
```

Nesting groups is supported; inner groups inherit and append prefixes and name prefixes.

---

## Resource routes

Generate a REST-style set in one line:

```php
use App\Http\UsersController;

Route::resource('users', '/users', UsersController::class);
// yields users.index/create/store/show/edit/update/destroy named routes
```

Implement only the methods you need; others can 404 or be omitted.

---

## Redirects & downloads

```php
Route::get('/to-json', fn() => Response::redirect(Response::urlFor('json'), 302));
Route::get('/download', fn() => Response::attachment(__FILE__, 'routes.php'));
```

---

## URL generation (absolute & signed)

```php
// Absolute URL
Response::urlFor('profile.show', ['id'=>7], absolute: true);

// Signed URLs (requires Response::bindUrlServices at boot)
Response::signedUrlFor('profile.show', ['id'=>7], absolute: false);
Response::temporaryUrlFor('secure.show', ['id'=>7], query:['dl'=>1], absolute:false, ttl:900);
```

Use `verifySignedUrl` middleware on endpoints that require a valid signature.

---

## Ordering & matching rules

* Routes are matched in **registration order** within the same group scope.
* Prefer **static** routes before **dynamic** ones under the same prefix (e.g., `/users/new` before `/users/{id:int}`).
* Specific constraints beat generic ones—use `:int`/`:uuid` when appropriate.

---

## 405 & 404 behavior

* If a path matches but the method doesn’t, the router can return **405 Method Not Allowed** (and include `Allow` header) depending on configuration.
* Otherwise, unmatched paths return **404**. You can register a catch-all or a fallback controller for custom UX.

---

## Advanced patterns

### Regex capture with multiple params

```php
// e.g., /report/2025-10
Route::get('/report/{ym:(\d{4})-(\d{2})}', function (string $ym) {
  return "report for $ym";
});
```

### Splat (rest-of-path) style

For a simple “rest” capture, define a relaxed pattern:

```php
Route::get('/assets/{path:.*}', fn($path) => "you asked: $path");
```

> Use sparingly; wide patterns can shadow other routes. Place them last.

---

## Testing your routes

Use `curl` for quick smoke tests:

```bash
curl -i http://127.0.0.1:8000/ping
curl -i http://127.0.0.1:8000/users/42
curl -i -X POST http://127.0.0.1:8000/echo -d 'x=1&y=2'
```

Consider adding a small PHPUnit test that boots your router and asserts status codes, payloads, and headers for critical endpoints.

---

## Checklist

* [ ] Name important routes
* [ ] Constrain parameters (`:int`, `:uuid`, `:hex`)
* [ ] Group related routes with `prefix`/`namePrefix` and shared middleware
* [ ] Generate URLs via `Response::urlFor()` (and `signedUrlFor()` / `temporaryUrlFor()` if needed)
* [ ] Order static before dynamic; keep catch-alls last

