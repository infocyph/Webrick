# Middleware Overview

Webrick’s middleware pipeline runs **before** and **after** your route handler. Use **pre-global** middleware for request hardening and shaping; use **post-global** middleware for response transformation and delivery. You can also attach **per-route** middleware via route options or attributes.

---

## The pipeline

```
Client → [ Pre-Global Middleware ] → Route Handler → [ Post-Global Middleware ] → Emitter → Client
```

### Pre-global (typical)

* **Gateway Hardening** – basic security headers & request sanity checks
* **Telemetry** – request IDs, timing, tracing hooks
* **Maintenance Mode** – short-circuit with 503 during maintenance windows
* **Request Limits** – size/time guards before reaching handlers
* **Throttle** – per-key rate limiting, emits 429 + retry headers
* **Cookie Encryption** – decrypts incoming cookies into `Request`
* **Normalize Method** – honors `_method` override for HTML forms
* **Input Sanitizer** – trims/normalizes common inputs
* **Negotiation** – parses `Accept`/language, sets attributes
* **Response Cache** – fast path for cacheable GETs (if configured)
* **Cache Validators** – handles `ETag` / `Last-Modified`, returns 304/412 when applicable

### Post-global (typical)

* **Compression** – negotiates `zstd/br/gzip` safely; coordinates ETags
* **CORS & Policies** – CORS preflight/allow headers + security policies
* **Vary Accumulator** – canonicalizes and appends `Vary` tokens
* **Response Linter (dev)** – catches header/body anti-patterns early

> Keep **order** intentional: validators before handlers; compression after handlers.

---

## Wiring middleware

You attach global middleware when booting the kernel:

```php
$preGlobal = [
  \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
  \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
  \Infocyph\Webrick\Middleware\MaintenanceModeMiddleware::class,
  \Infocyph\Webrick\Middleware\RequestLimitsMiddleware::class,
  \Infocyph\Webrick\Middleware\ThrottleMiddleware::class,
  new \Infocyph\Webrick\Middleware\CookieEncryptionMiddleware($_ENV['WEBRICK_COOKIE_KEY'] ?? 'dev'),
  \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class,
  \Infocyph\Webrick\Middleware\InputSanitizerMiddleware::class,
  \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
  \Infocyph\Webrick\Middleware\ResponseCacheMiddleware::class,
  \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
];

$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  \Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
  \Infocyph\Webrick\Middleware\ResponseLinterMiddleware::class, // dev only
];
```

Per-route middleware:

```php
Route::get('/secure/{id:int}', fn()=>['ok'=>true], [
  'middleware' => ['verifySignedUrl','throttle:5,60'],
]);
```

Attributes also accept `middleware:` arrays.

---

## Choosing the right order

1. **Hardening & limits** first → drop bad or oversized requests early
2. **Throttling** before any heavy work
3. **Normalization & sanitization** before reading input
4. **Negotiation** before handlers (so `Response::auto()` has context)
5. **Response cache / validators** before handler to short-circuit
6. **Compression / CORS / Vary** after handler, final shaping

---

## Performance notes

* Middleware classes should be **stateless** or light; reuse shared services via DI.
* Prefer **header appends** over string manipulation of big bodies in post-globals.
* Use **route cache warmups** so matcher/registrar work is done ahead of time.
* Avoid expensive I/O in pre-globals unless it short-circuits a lot of work.

---

## Testing strategy

* Unit test individual middleware (given a request → expect headers/status).
* Integration test the **ordered stack** to catch interactions (e.g., ETag + compression).
* For throttling and response cache, add **time-based** tests with controlled clocks.

---

## What’s next

Deep-dives for each middleware:

* [`compression.md`](compression.md) – encodings, ETag strategies, caveats
* [`cache-validators.md`](cache-validators.md) – 304/412 logic, ETag/Last-Modified providers
* [`vary-accumulator.md`](vary-accumulator.md) – consistent `Vary` management
* [`cors-and-policies.md`](cors-and-policies.md) – cross-origin and security headers
* [`throttle.md`](throttle.md) – limits, keys, headers
* [`request-limits.md`](request-limits.md) – body size/time caps
* [`cookie-encryption.md`](cookie-encryption.md) – encrypt/decrypt lifecycle
* [`maintenance-mode.md`](maintenance-mode.md) – toggles & messages
* [`telemetry.md`](telemetry.md) – IDs, timings, tracing hooks
* [`normalize-method.md`](normalize-method.md) – `_method` override rules
* [`input-sanitizer.md`](input-sanitizer.md) – best-effort normalization
* [`negotiation.md`](negotiation.md) – content & locale selection
* [`response-cache.md`](response-cache.md) – when to serve from cache
* [`response-linter.md`](response-linter.md) – dev-only quality guard
