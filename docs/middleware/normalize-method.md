# Normalize Method

Support HTTP method overrides from HTML forms and limited clients. This middleware translates a `POST` into `PUT`, `PATCH`, or `DELETE` when an override is present—so your routes can use real verbs without hacks.

---

## What it does

* Detects an override method from **body** or **query** (e.g., `_method=PUT`)
* Validates the override against an **allowlist** (typically `PUT`, `PATCH`, `DELETE`)
* Updates the effective request method **before** routing
* Leaves `GET`/`HEAD` alone (overrides only apply to `POST` by default)

---

## Wiring

Place it in **pre-global** after cookies/limits but **before** anything that depends on the method (routing, throttling by verb, etc.):

```php
$preGlobal = [
  // ... hardening, telemetry, limits, cookies ...
  \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class,
  // ... input sanitizer, negotiation, validators ...
];
```

If you need to customize where it reads from or which verbs are allowed, instantiate with options:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware(
  param: '_method',                 // override key
  sources: ['body','query'],        // where to look
  allow: ['PUT','PATCH','DELETE'],  // allowed overrides
  onlyFor: ['POST']                 // normalize only when original is POST
);
```

*(Adjust option names to your implementation.)*

---

## How to use (client side)

### HTML forms

HTML can’t submit `PUT/PATCH/DELETE` directly. Use a hidden field:

```html
<form method="POST" action="/users/42">
  <input type="hidden" name="_method" value="PUT">
  <!-- fields ... -->
  <button>Save</button>
</form>
```

### Query-string override (optional)

If enabled, you can support `? _method=DELETE` for link-driven actions:

```
POST /posts/7?_method=DELETE
```

> Prefer hidden-field overrides for CSRF-protected forms. Be cautious with query overrides—you don’t want web crawlers to trigger state changes.

---

## Routing examples

Define routes using the **true** verbs:

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Response\Response;

Route::put   ('/users/{id:int}', fn($id)=>Response::json(['updated'=>$id]));
Route::patch ('/users/{id:int}', fn($id)=>Response::json(['patched'=>$id]));
Route::delete('/users/{id:int}', fn($id)=>Response::json(['deleted'=>$id]));
```

With the middleware enabled, a `POST` + `_method=PUT` will match the `PUT` route.

---

## Safety notes

* **CSRF**: method overrides don’t replace CSRF protection. Keep CSRF checks for state-changing routes.
* **Idempotency**: treat overrides as the **real** method for semantics (`PUT` idempotent, `POST` not).
* **Allowlist**: never allow overriding to `GET` or `HEAD`. It defeats caching semantics and can create subtle bugs.

---

## Debugging

* Log both `original_method` and `normalized_method` (Telemetry middleware can help).
* If a route 404s:

    * Confirm the override parameter name matches (`_method`)
    * Ensure the middleware runs **before** routing
    * Verify the allowed list includes your desired verb

---

## Example: simple controller edit flow

```php
Route::get('/users/{id:int}/edit', fn($id) => /* render form */ '...');
Route::put('/users/{id:int}', function (int $id) use ($repo) {
    $repo->update($id, /* ... */);
    return Response::redirect(Response::urlFor('users.show', ['id'=>$id]), 303);
});
```

Form:

```html
<form method="POST" action="/users/42">
  <input type="hidden" name="_method" value="PUT">
  <!-- inputs -->
  <button>Save</button>
</form>
```

---

## Checklist

* [ ] Enable NormalizeMethod in **pre-global** (before routing)
* [ ] Use a **hidden `_method`** field in forms for PUT/PATCH/DELETE
* [ ] Keep a strict **allowlist** (no GET/HEAD overrides)
* [ ] Continue to enforce **CSRF** on state-changing routes
* [ ] Log normalized vs original method for traceability

