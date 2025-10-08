# Content Negotiation

Serve the best format (JSON, text, XML) from one handler. Webrick’s negotiation middleware inspects client headers and exposes decisions so `Response::auto()` can do the right thing without branching all over your code.

---

## Overview

* **Client expresses** preferences via `Accept`, `Accept-Language`, etc.
* **Negotiation middleware** parses headers and sets request attributes (e.g., `locale`).
* **Your handler** returns `Response::auto($request, $data)` and lets the library pick `Content-Type`.

---

## Enable negotiation

Add `NegotiationMiddleware` in your **pre-global** stack (front controller):

```php
$preGlobal = [
  // ...hardening, limits, throttle, cookies...
  \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
  // ...response cache, validators...
];
```

Optional: pass constructor/config to customize supported media types/locales if your middleware supports it.

---

## Use `Response::auto()`

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

Route::get('/auto', function (Request $r) {
  $payload = ['msg'=>'hello', 'time'=>time()];
  return Response::auto($r, $payload);   // JSON by default, or text/XML when appropriate
});
```

What it generally does:

* Prioritizes `application/json` for API-like Accept headers.
* Falls back to `text/plain; charset=UTF-8` for generic clients (`*/*`).
* Can emit XML/HTML when requested and allowed by your settings.

---

## Forcing a specific type (when needed)

You can always bypass negotiation and respond explicitly:

```php
// Always JSON
return Response::json(['ok'=>true]);

// Always text
return Response::plaintext("hello\n");

// XML explicitly
return Response::create('<note>hello</note>', 200, ['Content-Type'=>'application/xml']);
```

---

## Language & locale

If your negotiation middleware resolves a locale, it is available as an attribute:

```php
$locale = $r->getAttribute('locale') ?? 'en';
```

Use this to select translations, date formats, or numeric formatting. Keep translation concerns outside the router for separation of concerns.

---

## Error formats

For APIs, stick to a consistent error envelope regardless of `Accept`; negotiation can still adjust `Content-Type`, but structure should remain predictable:

```php
return Response::json(['error'=>['code'=>'E_INPUT','message'=>'Invalid field']], 422);
```

If you need multiple representations (e.g., HTML form errors vs JSON API errors), either:

* Split endpoints by UI/API, or
* Branch on `Accept` **once** per handler and keep logic minimal.

---

## Caveats & best practices

* **Don’t trust `Accept` blindly**: some clients send `*/*`. `Response::auto()` handles this gracefully.
* **Compression interplay**: content type affects compression choices; the compression middleware decides what’s safe/beneficial.
* **Caching**: if you vary response by `Accept` or language, ensure your **Vary** header includes `Accept`/`Accept-Language`. A post-global **VaryAccumulatorMiddleware** can help manage this consistently.

Example:

```php
return Response::auto($r, ['ok'=>true])
  ->withAddedHeader('Vary', 'Accept, Accept-Language');
```

(If you use Vary accumulator middleware, it can append these for you.)

---

## Example: one handler, many faces

```php
Route::get('/greet/{name}', function (Request $r, string $name) {
  $data = ['greet' => "Hello, {$name}"];
  return Response::auto($r, $data);
});
```

* `curl -H "Accept: application/json" /greet/Hasan` → `{"greet":"Hello, Hasan"}`
* Browser with generic `Accept` → plain text or JSON depending on defaults
* Custom client `Accept: application/xml` → `<greet>Hello, Hasan</greet>` (if XML enabled)

---

## Troubleshooting

| Symptom                                    | Cause                              | Fix                                            |
| ------------------------------------------ | ---------------------------------- | ---------------------------------------------- |
| Always JSON even with `Accept: text/plain` | Negotiation middleware not enabled | Add `NegotiationMiddleware` to pre-globals     |
| Cache seems wrong between JSON/text        | Missing `Vary` header              | Use Vary accumulator or set `Vary: Accept`     |
| Wrong locale used                          | Header/param mismatch or default   | Inspect `Accept-Language`; set fallback locale |

---

## Checklist

* [ ] Enable `NegotiationMiddleware` in pre-globals
* [ ] Prefer `Response::auto($request, $data)` for polymorphic handlers
* [ ] Set/accumulate `Vary` headers when format/language changes output
* [ ] Keep a consistent error shape across formats for APIs
