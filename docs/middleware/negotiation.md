# Negotiation

Pick the most appropriate representation for each request: media type (JSON/text/XML) and optional locale. This middleware parses client headers, sets **request attributes**, and helps `Response::auto()` choose the right `Content-Type`—so your handlers stay minimal.

---

## What it does

* Parses **`Accept`** and resolves a best-match media type from a configured list
* Optionally parses **`Accept-Language`** and resolves a **`locale`** (you decide how to use it)
* Stores decisions on the **Request** (attributes) for downstream access
* Can register `Vary` tokens (`Accept`, `Accept-Language`) so caches don’t mix variants

---

## Wiring

Add to **pre-global** before your handlers (and before cache validators):

```php
$preGlobal[] = \Infocyph\Webrick\Middleware\NegotiationMiddleware::class;
```

If you need custom preferences:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\NegotiationMiddleware(
  mediaTypes: ['application/json', 'text/plain', 'application/xml'],
  defaultMediaType: 'application/json',
  locales: ['en', 'bn', 'fr'],
  defaultLocale: 'en',
  addVaryHeaders: true // cooperate with VaryAccumulator if present
);
```

*(Adjust option names to your implementation.)*

---

## Request attributes set

After the middleware runs, handlers (and other middleware) can read:

```php
$media  = $request->getAttribute('media')   ?? 'application/json';
$locale = $request->getAttribute('locale')  ?? 'en';
```

Other useful attributes (implementation-dependent):

* `charset` (e.g., `utf-8`)
* `quality`/q-values used for tie-breaking

---

## Using with `Response::auto()`

Let one handler serve many formats:

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

Route::get('/status', function (Request $r) {
  $payload = ['ok'=>true, 'time'=>time()];
  return Response::auto($r, $payload); // picks JSON/text/XML appropriately
});
```

If you need to **force** a type, bypass negotiation and return `Response::json(...)` or `Response::create(...)` explicitly.

---

## Vary headers

If you produce different bodies depending on `Accept` or language, caches must key by those headers.

* With a **Vary accumulator** present, this middleware can call:
  `VaryAccumulator::add('Accept');` and `VaryAccumulator::add('Accept-Language');`
* Otherwise, it can append `Vary` directly.

> Avoid over-varying; include only headers that truly change the representation.

---

## Locales

Negotiation may set a **locale** attribute based on `Accept-Language`. You can:

* Select translations / message bundles
* Format dates and numbers
* Choose right-to-left vs left-to-right rendering flags

Fallback to `defaultLocale` when no match. If you also support a `?lang=xx` query or user profile setting, decide precedence (URL → user setting → header → default).

---

## Error & edge cases

* **`Accept: */*`** → falls back to `defaultMediaType` (usually JSON).
* **Unrecognized locales** → use `defaultLocale`.
* **Explicit but unsupported media type** → either fall back or respond **406 Not Acceptable** (configurable).
* **Compression** → handled later; just ensure `Vary: Accept` and `Vary: Accept-Encoding` are set by the respective middleware.

---

## Examples

### Strict 406 for unsupported types

```php
new NegotiationMiddleware(
  mediaTypes: ['application/json', 'text/plain'],
  defaultMediaType: 'application/json',
  notAcceptableOnMismatch: true
);
```

Request:

```
Accept: application/xml
```

Response:

```
406 Not Acceptable
```

### Locale-aware greeting

```php
Route::get('/hello/{name}', function (Request $r, string $name) {
  $locale = $r->getAttribute('locale') ?? 'en';
  $msg = match ($locale) {
    'bn' => "হ্যালো, {$name}",
    'fr' => "Bonjour, {$name}",
    default => "Hello, {$name}",
  };
  return Response::auto($r, ['message'=>$msg]);
});
```

---

## Troubleshooting

| Symptom              | Likely cause              | Fix                                                     |
| -------------------- | ------------------------- | ------------------------------------------------------- |
| Always returns JSON  | Middleware not registered | Add `NegotiationMiddleware` to **pre-global**           |
| Cached wrong variant | Missing `Vary` header     | Enable `addVaryHeaders` or use Vary accumulator         |
| Locale ignored       | Not reading attribute     | Use `$request->getAttribute('locale')`; define fallback |
| 406s for browsers    | Too strict                | Disable `notAcceptableOnMismatch` or add `*/*` fallback |

---

## Checklist

* [ ] Add Negotiation to **pre-global**
* [ ] Configure allowed **media types** (and default)
* [ ] (Optional) Configure **locales** and fallback
* [ ] Cooperate with **Vary** management (`Accept`, `Accept-Language`)
* [ ] Prefer `Response::auto()` for polymorphic handlers; use explicit helpers when needed
