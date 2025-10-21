# Throttling & Rate Limits

Protect endpoints from abuse and smooth load by applying per-route or grouped throttles. Webrick’s throttle middleware returns **429 Too Many Requests** with helpful headers so clients know when to retry.

---

## What throttling does

* Enforces a **burst** + **window** limit (e.g., “10 requests per minute”).
* Emits informative headers:

    * `Retry-After` (seconds until safe to retry)
    * `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
    * Optionally IETF `RateLimit-*` headers if enabled
* Adds server timing hints for observability.

> Apply conservative limits on public routes; loosen for authenticated/internal traffic.

---

## Enable the middleware

Add it to **pre-global** or use per-route options.

```php
$preGlobal = [
  // ...
  \Infocyph\Webrick\Middleware\ThrottleMiddleware::class,
  // ...
];
```php

---

## Per-route throttles

Use a concise `throttle:<max>,<perSeconds>` syntax in route options.

```php
// 5 requests per minute
Route::get('/otp', fn()=> 'ok', [
  'middleware' => ['throttle:5,60'],
]);

// Tighter on login
Route::post('/login', fn()=> 'ok', [
  'middleware' => ['throttle:10,300'], // 10 per 5 minutes
]);
```php

---

## Group-level throttles

Apply one limit to an entire module:

```php
Route::group(
  prefix:'/api',
  namePrefix:'api.',
  middleware:['throttle:120,60'], // 120/minute across all API routes
  callback:function ($api) {
    $api->get('/status', fn()=> ['ok'=>true], 'status');
  }
);
```php

Routes can **add** their own per-route throttle on top (becomes stricter).

---

## Keying & fairness

Typical keying strategies (implementation-dependent):

* **Anonymous**: client IP (be careful behind proxies; trust only known forwarders)
* **Authenticated**: user ID or API token
* **Composite**: `userID + routeName` for per-endpoint fairness

> For multi-tenant apps, include tenant/org ID in the key to avoid noisy neighbors.

---

## Client-visible headers

A successful request includes remaining budget:

```php
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1738961123
```php

A throttled request:

```php
HTTP/1.1 429 Too Many Requests
Retry-After: 23
X-RateLimit-Remaining: 0
```php

Optionally emit the standard `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` as well.

---

## Handling bursts & backoff

* Favor **token bucket** or **leaky bucket** semantics to allow short bursts.
* Clients should:

    * Respect `Retry-After`
    * Implement exponential backoff (e.g., 1s, 2s, 4s, … up to a ceiling)
    * Avoid synchronizing retries across many clients

---

## Testing

```bash
# Hit 6 times quickly against throttle:5,60
for i in {1..6}; do curl -i http://127.0.0.1:8000/otp; done
```php

Expect first 5 = 200, 6th = 429 with `Retry-After`.

---

## Common patterns

### Stricter unauthenticated, looser authenticated

```php
Route::group(middleware:['throttle:60,60'], callback:function () {
  Route::post('/login', fn()=> 'ok', ['middleware'=>['throttle:10,300']]);
});

Route::group(middleware:['auth','throttle:600,60'], callback:function () {
  Route::get('/me', fn()=> ['ok'=>true]);
});
```php

### Endpoint-specific “expensive” operations

```php
Route::post('/export', fn()=> 'queued', ['middleware'=>['auth','throttle:3,3600']]);
```php

---

## Operational tips

* **Observability**: surface throttle counters in metrics; enable `Server-Timing` hints for tracing.
* **Edge/CDN**: if terminating at an edge, perform coarse throttles there and fine-grained app throttles in Webrick.
* **Abuse spikes**: pair with IP reputation, CAPTCHA, or proof-of-work for ultra-hot endpoints (login, OTP, password reset).
* **Safe-lists**: allow internal monitors/healthchecks via bypass key or CIDR allowlist (carefully).

---

## Checklist

* [ ] Add global throttle middleware
* [ ] Set sensible defaults per module (`/api`, `/auth`)
* [ ] Use per-route throttles for sensitive/expensive endpoints
* [ ] Pick correct **key** (IP vs user vs token vs tenant)
* [ ] Verify headers appear; clients should obey `Retry-After`
* [ ] Monitor 429 rates and tune limits accordingly
