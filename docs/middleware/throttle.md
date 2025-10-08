# Throttle

Apply per-route or global **rate limits** to protect your app and guide well-behaved clients. This middleware enforces token/bucket rules, emits standards-friendly headers, and returns **429 Too Many Requests** when limits are exceeded.

---

## What it does

* Enforces a **max requests** per **time window** (e.g., `60 per 60s`) using a token-bucket or leaky-bucket strategy
* Keys by **client identity** (IP, user ID, API token, tenant, etc.)
* Adds response headers to communicate budgets:

    * `Retry-After: <seconds>`
    * `X-RateLimit-Limit: <max>`
    * `X-RateLimit-Remaining: <remaining>`
    * `X-RateLimit-Reset: <unix-epoch-seconds>`
    * *(optional)* IETF `RateLimit-Limit` / `RateLimit-Remaining` / `RateLimit-Reset`
* Short-circuits with **429** when budget is exhausted

> Use conservative defaults on public endpoints, looser limits for authenticated or internal routes.

---

## Wiring

Add to **pre-global** so it runs before expensive work:

```php
$preGlobal[] = \Infocyph\Webrick\Middleware\ThrottleMiddleware::class;
```

Or pass explicit options:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\ThrottleMiddleware(
  defaultLimit: '120,60',               // "<max>,<windowSeconds>"
  policy: 'token_bucket',               // or 'leaky_bucket'
  emitIetfHeaders: true,                // add RateLimit-* trio
  clockSkewSeconds: 1,                  // tolerate small drift
  store: myRateLimiterStore(),          // PSR-like cache/atomic store
  keyResolver: function (\Infocyph\Webrick\Request\Request $r): string {
    // Prefer user/token/tenant over IP when available
    if ($uid = $r->getAttribute('auth.user_id')) return "u:$uid";
    return "ip:" . ($r->server('REMOTE_ADDR') ?? '0.0.0.0');
  }
);
```

*(Adjust names to your actual constructor.)*

---

## Per-route limits

Attach a concise directive via route options:

```php
Route::post('/login', fn()=> 'ok', [
  'middleware' => ['throttle:10,300'],  // 10 requests per 5 minutes
]);

Route::get('/otp', fn()=> 'ok', [
  'middleware' => ['throttle:5,60'],    // 5/min
]);
```

### Group-level limits

```php
Route::group(prefix:'/api', middleware:['throttle:120,60'], callback:function ($api) {
  $api->get('/status', fn()=> ['ok'=>true], 'status');
});
```

A per-route `throttle:` that’s **stricter** than the group takes precedence for that route.

---

## Keying strategy (important)

Choose a key that reflects **fairness**:

* **Anonymous traffic**: IP address (trust only known proxy headers).
* **Authenticated APIs**: user ID or API token.
* **Multi-tenant**: `tenantId:userId` or `tenantId:ip`.
* **Endpoint fairness** (optional): include route name → `u:7|r:api.search`.

> Mis-keying (e.g., all users share one key) leads to noisy-neighbor issues.

---

## Headers & semantics

On successful requests:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 47
X-RateLimit-Reset: 1738961123
RateLimit-Limit: 60, w=60
RateLimit-Remaining: 47
RateLimit-Reset: 23
```

On throttled requests:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 23
X-RateLimit-Remaining: 0
```

* `X-RateLimit-Reset` is a UNIX timestamp; IETF `RateLimit-Reset` is seconds until reset.

---

## Algorithms

* **Token bucket** (recommended): allows short bursts up to `max`, refills continuously over `windowSeconds`. Smooth UX for brief spikes.
* **Leaky bucket**: stable outflow; stricter pacing. Good for expensive operations.

Pick one globally; mixing per-route is possible if your implementation supports it.

---

## Storage backends

Use an **atomic** store for correctness:

* Redis (INCR + EXPIRE or Lua) – best choice
* APCu – single-node only
* Database – acceptable with row-level atomic ops; watch contention
* In-memory arrays – dev/testing only

---

## Safe lists & bypasses

Allow health checks/administration to skip limits:

```php
$preGlobal[] = new ThrottleMiddleware(
  /* ... */,
  bypass: function ($r) {
    return in_array($r->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
        || $r->getHeaderLine('x-internal-probe') === '1';
  }
);
```

---

## Error payload (example)

When returning **429**, include a machine-readable body:

```json
{
  "error": {
    "code": "E_RATE_LIMIT",
    "message": "Too many requests. Try again in 23 seconds.",
    "retry_after": 23
  }
}
```

---

## Patterns

### Stricter unauthenticated, looser authenticated

```php
Route::group(middleware:['throttle:60,60'], callback:function () {
  Route::post('/login', fn()=> 'ok', ['middleware'=>['throttle:10,300']]);
});

Route::group(middleware:['auth','throttle:600,60'], callback:function () {
  Route::get('/me', fn()=> ['ok'=>true]);
});
```

### Expensive endpoints

```php
Route::post('/export', fn()=> 'queued', [
  'middleware'=>['auth','throttle:3,3600']
]);
```

### Tenant fairness

```php
$keyResolver = fn($r) => 't:' . $r->getAttribute('tenant_id') . '|u:' . ($r->getAttribute('auth.user_id') ?? 'anon');
```

---

## Testing

```bash
# Hit 6 times quickly against throttle:5,60
for i in {1..6}; do curl -i http://127.0.0.1:8000/otp; done
# Expect the 6th to return 429 with Retry-After
```

---

## Observability

Emit counters and histograms:

* `throttle_requests_limited_total{route="...",key="..."}`
* `throttle_tokens_remaining{bucket="..."}`
* `throttle_eval_seconds` (middleware duration)

Correlate with **Telemetry** `X-Request-Id` for investigations.

---

## Troubleshooting

| Symptom                         | Likely cause                       | Fix                                                                         |
| ------------------------------- | ---------------------------------- | --------------------------------------------------------------------------- |
| Legit users throttled by others | Keying by IP behind NAT            | Use user/token key when authenticated; trust proxy chain only if configured |
| Limits “reset” too late/early   | Clock skew                         | Set `clockSkewSeconds` or prefer token bucket (continuous refill)           |
| CDN blocks before app           | Edge rate limiting on              | Harmonize or disable CDN limits for app-enforced paths                      |
| Headers missing                 | Custom error handler replaced them | Ensure middleware writes headers on both success and 429                    |
| Inconsistent counts in cluster  | Non-atomic store                   | Use Redis/Lua or a store with atomic increments                             |

---

## Checklist

* [ ] Add Throttle to **pre-global**
* [ ] Choose **token bucket** and a **stable, atomic store** (Redis)
* [ ] Define a **key resolver** (IP vs user/token vs tenant)
* [ ] Use per-route `throttle:<max>,<window>` for sensitive endpoints
* [ ] Emit `Retry-After` and `X-RateLimit-*`/`RateLimit-*` headers
* [ ] Monitor 429 rates and adjust limits; safe-list health checks
