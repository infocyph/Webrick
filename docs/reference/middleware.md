# Middleware API Reference

How to **write**, **configure**, and **compose** middleware in Webrick. Covers lifecycle, interfaces, short-circuiting, and best practices.

---

## Concepts at a glance

* **Pre-global** middleware runs before the route handler.
* **Post-global** middleware runs after the handler and can transform the response.
* **Per-route** middleware attaches to specific routes (by name or attribute).
* Middleware can **short-circuit** (return a `Response` early) or **decorate** (modify request/response and continue).

---

## Interfaces & signatures

Two styles are typically supported. Pick the one your project uses (both shown for clarity).

### 1) Class with `handle(Request $r, callable $next): Response`

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class ExampleMiddleware
{
    public function handle(Request $r, callable $next): Response
    {
        // pre logic (read/augment request)
        if ($r->getHeaderLine('x-block') === '1') {
            return Response::json(['error'=>'blocked'], 403);
        }

        $resp = $next($r); // call next middleware / handler

        // post logic (tweak response)
        return $resp->withAddedHeader('X-Example', '1');
    }
}
```

### 2) PSR-15–style `process(Request $r, RequestHandlerInterface $handler): Response`

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ExampleMiddleware implements MiddlewareInterface
{
    public function process(Request $r, RequestHandlerInterface $handler): Response
    {
        // pre...
        $resp = $handler->handle($r);
        // post...
        return $resp->withAddedHeader('X-Example', '1');
    }
}
```

> Internally, Webrick adapts to one shape. Stick to the project’s preferred style.

---

## Registering middleware

### Global stacks (at boot)

```php
$preGlobal = [
  \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
  \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
  // ...
];

$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
];
```

You may pass **instances** when you need constructor options:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\RequestLimitsMiddleware(maxBodyBytes: 1_048_576);
```

### Per-route

```php
Route::get('/secure', fn()=>['ok'=>true], [
  'middleware' => ['verifySignedUrl','throttle:5,60'],
]);
```

In **attributes**, provide `middleware:` on the attribute:

```php
#[Get('/attr', name: 'attr.example', middleware: ['throttle:10,60'])]
```

---

## Pipeline order (rules of thumb)

1. **Hardening & limits** (drop bad/oversized requests)
2. **Telemetry** (adds IDs for all logs)
3. **Maintenance** (fast 503 switch)
4. **Throttle** (cheap denial before heavy work)
5. **Cookie decrypt** → **Method normalize** → **Input sanitize**
6. **Negotiation** (decide media/locale)
7. **Response cache** → **Cache validators** (304/412 short-circuit)
8. **Handler**
9. **Compression** → **CORS/Policies** → **Vary accumulator** → **Linter (dev)**

---

## Short-circuiting

Any pre-global middleware may return a `Response` **without** calling `$next($r)`:

```php
if (!$authorized) {
  return Response::json(['error'=>'unauthorized'], 401);
}
```

* Use for **auth gates**, **maintenance**, **preflight OPTIONS**, **cache hits**, **conditional requests** (304), **rate limits** (429).
* Post-globals should **not** short-circuit (they run after handler) except for special cases (e.g., CORS preflight handled earlier).

---

## Reading & writing context

### Request attributes

Store small, cross-cutting context:

```php
$r = $r->withAttribute('auth.user_id', $userId);
return $next($r);
```

Handlers and later middleware can read via `$request->getAttribute('auth.user_id')`.

### Vary management

If your middleware changes **representation**, add `Vary` tokens (directly or through the accumulator):

```php
\Infocyph\Webrick\Middleware\VaryAccumulator::add('Accept');
```

---

## Error handling & exceptions

* Throwing an exception bubbles to the framework’s **global error handler**.
* Include **request/correlation IDs** from Telemetry in logs and (optionally) in response headers.
* If your middleware can fail due to **user input** (e.g., bad signature), prefer returning a structured 4xx with a machine-readable error code.

---

## Performance tips

* Keep middleware **stateless**; inject heavy services via constructor DI.
* Avoid reading full bodies in pre-globals; rely on **limits** middleware first.
* For **Compression**, prefer buffering to recompute **strong ETag**; skip for **streams/SSE**.
* Use **atomic stores** (Redis) for throttles/caches; don’t keep per-worker state that breaks under scaling.

---

## Testing middleware

### Unit test with a fake handler

```php
$mw = new ExampleMiddleware();

$fakeNext = function ($r) { return Response::json(['ok'=>true]); };

$resp = $mw->handle(fakeRequest(headers: ['x-block' => '0']), $fakeNext);
assert($resp->getHeaderLine('X-Example') === '1');
```

### Integration test ordering

Spin up the full stack and assert behaviors (e.g., a cached GET bypasses the handler, negotiates content, and compresses once).

---

## Common patterns (snippets)

### Caching pre-handler

```php
final class ResponseCacheMiddleware {
  public function handle(Request $r, callable $next): Response {
      if ($hit = $this->cache->get($this->key($r))) {
          return $hit; // short-circuit
      }
      $resp = $next($r);
      if ($this->isCacheable($resp, $r)) $this->cache->set($this->key($r), $resp, $this->ttl($resp));
      return $resp;
  }
}
```

### CORS preflight

```php
if ($r->isOptions() && $r->getHeaderLine('Origin')) {
    return Response::plaintext('')
      ->withHeader('Access-Control-Allow-Origin', $origin)
      ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
      ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
      ->withHeader('Access-Control-Max-Age', '600');
}
```

### Rate limit (token bucket idea)

```php
$key = $this->key($r);
if (!$this->bucket->consume($key, 1)) {
  return Response::json(['error'=>['code'=>'E_RATE_LIMIT']], 429)
    ->withHeader('Retry-After', $this->bucket->retryAfter($key));
}
```

---

## Middleware registry (introspection)

Many apps expose a debug view of the active stacks:

```
Pre-global:
  - GatewayHardeningMiddleware
  - TelemetryMiddleware
  - RequestLimitsMiddleware
  - ThrottleMiddleware
  - CookieEncryptionMiddleware
  - NormalizeMethodMiddleware
  - InputSanitizerMiddleware
  - NegotiationMiddleware
  - ResponseCacheMiddleware
  - CacheValidatorsMiddleware

Post-global:
  - CompressionMiddleware
  - CorsAndPoliciesMiddleware
  - VaryAccumulatorMiddleware
  - ResponseLinterMiddleware (dev)
```

Keep this **documented** for your team; it prevents order-related regressions.

---

## Troubleshooting

| Symptom                     | Likely cause                    | Fix                                                                        |
| --------------------------- | ------------------------------- | -------------------------------------------------------------------------- |
| Double compression          | Edge + app both compress        | Pick one layer; ensure only Compression middleware sets `Content-Encoding` |
| JSON without `Content-Type` | Handler bypassed helpers        | Use `Response::json()` or set header explicitly; linter can catch          |
| 304 never returned          | Validators not configured       | Add CacheValidators with a provider or set `ETag`/`Last-Modified`          |
| Rate limit headers missing  | Error handler replaced response | Ensure middleware writes headers in both pass/deny paths                   |
| Wrong variant cached        | Missing `Vary` token            | Add `Accept`/`Accept-Encoding` via Vary accumulator                        |

---

## Checklist

* [ ] Implement middleware using the project’s standard interface
* [ ] Place it correctly in **pre/post** stacks (order matters)
* [ ] Short-circuit only when you mean to; return proper status & headers
* [ ] Use **request attributes** for shared context; avoid globals
* [ ] Coordinate with **Vary**, **Cache**, **Compression**, **Validators**
* [ ] Add thorough unit tests and an integration sanity test for ordering


