# Response Cache

Serve cached responses for safe requests (typically **GET/HEAD**) without running handlers. This middleware provides a fast-path lookup and a coherent invalidation story that plays well with **ETag/Last-Modified** and **Compression**.

`ResponseCacheMiddleware` is an optional module boundary. Install CacheLayer 2
before using this class:

```bash
composer require "infocyph/cachelayer:^2.0"
```

Core routing does not load, initialize, or require CacheLayer.

---

## Configuration

```php
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\CacheLayer\Cache\Cache;

$preGlobal[] = new ResponseCacheMiddleware(
    store: Cache::local('http'),               // PSR-16 simple cache
    ttlSeconds: 10,                            // Base TTL (micro-cache strategy)
    includeQuery: true,                        // Include query params in cache key
    maxBodyBytes: 1_048_576,                   // Max body size to cache (1MB)
    defaultVary: ['Accept', 'Accept-Language', 'Accept-Encoding'],
    skipWhenPersonalized: true,                // Don't cache Set-Cookie responses
    respectResponseCacheControl: true,         // Honor no-store/private directives
    avoidSetCookie: true                       // Skip caching if Set-Cookie present
);
```

### Constructor Parameters

| Parameter                    | Type               | Default                 | Description                                     |
| ---------------------------- | ------------------ | ----------------------- | ----------------------------------------------- |
| `store`                      | `?Infocyph\CacheLayer\Cache\CacheInterface` | `null` | CacheLayer store; defaults to `Cache::local('http')` |
| `ttlSeconds`                 | `int`              | `10`                    | Default TTL for cached responses                |
| `includeQuery`               | `bool`             | `true`                  | Include query string in cache key               |
| `maxBodyBytes`               | `int`              | `1048576`               | Maximum response body size to cache (bytes)     |
| `defaultVary`                | `array<string>`    | `['Accept', ...]`       | Default Vary dimensions to include in key       |
| `skipWhenPersonalized`       | `bool`             | `true`                  | Skip caching if response varies by user         |
| `respectResponseCacheControl`| `bool`             | `true`                  | Honor Cache-Control directives from response    |
| `avoidSetCookie`             | `bool`             | `true`                  | Never cache responses with Set-Cookie header    |

---

## How It Keys

Cache key structure:
```
{method}|{host}|{path}|{query}|{media}|{charset}|{locale}|{encoding}|{vary_surface}
```

**Example**:
```
GET|example.com|/products/42|sort=price|application/json|utf-8|en|br|Accept:application/json|Accept-Language:en
```

**Hashed**: Uses a full SHA-256 digest so distinct request variants cannot silently share a cache entry:
```
9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08
```

### What Affects the Key

1. **HTTP Method**: GET, HEAD (others not cached)
2. **Host + Path**: `/users/42` on `api.example.com`
3. **Query String** (if `includeQuery: true`): `?page=2&sort=name`
4. **Negotiated Content**:
   - Media type: `application/json`
   - Charset: `utf-8`
   - Locale: `en`
   - Encoding: `br` (if cached post-compression)
5. **Vary Surface**: Actual header values for `Vary` dimensions

**Not Included** (by design):
- Request headers like `User-Agent`, `Referer`
- Cookies (unless explicitly added to Vary)
- Request body
- Time of request

---

## What it does

* Checks a cache store for a previously rendered response for the current request (usually by **route name + params + query + negotiated media**).
* If found → returns the cached response immediately (with headers/body/status), skipping the handler.
* If not found → lets the handler run, then **stores** the response if it’s cacheable.
* Coordinates with **Vary** (e.g., `Accept`, `Accept-Encoding`) to avoid mixed variants.
* Obeys **Cache-Control** directives on responses (`no-store`, `max-age`, `private`, etc.) and avoids caching when unsafe.

> Think of it as **application-level** read-through caching. It complements (not replaces) CDN/reverse-proxy caches.

---

## Wiring

Place in **pre-global**, after negotiation and before cache validators (or alongside, depending on your strategy):

```php
$preGlobal = [
  // hardening, telemetry, limits, throttle, cookies, normalize, sanitizer...
  \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
  \Infocyph\Webrick\Middleware\ResponseCacheMiddleware::class,
  \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
  // ... router → post-globals (compression, CORS, vary) ...
];
```

Why before validators? If you hit a warm cache entry, you can bypass validator checks entirely. Alternatively, you may choose validators first and only cache on 200-paths; both are viable—pick one consistently.

---

## Keying strategy (conceptual)

Default cache key often includes:

* **Route name** (or path template)
* **Route params** (e.g., `id=42`)
* **Query params** (normalized order)
* **Negotiation** results (media type, locale)
* Optional **auth/tenant** dimension (for private caches)

> Don’t include ephemeral headers like `User-Agent`. Keep keys minimal but correct.

---

## What gets cached (and what doesn’t)

**Cacheable by default:**

* 200/203/204 responses to **GET/HEAD** with **public** or **implicit** cacheability
* Responses with explicit `Cache-Control: public, max-age=...` or `s-maxage=...`

**Not cached:**

* `Set-Cookie` responses (unless you explicitly allow private caching)
* `Cache-Control: no-store` or `private` (by default)
* Non-idempotent methods (POST/PUT/PATCH/DELETE)
* Streaming/SSE responses (size indeterminate)
* Bodies below a threshold can still be cached; the decision is policy-based rather than size-based

---

## TTLs & invalidation

* **TTL**: derive from `Cache-Control: max-age` or a default fallback (e.g., 60s).
* **Stale-while-revalidate** (optional): serve slightly stale and refresh in the background (if your implementation supports it).
* **Manual invalidation**: expose helpers to purge by key, by route + params, or by tags.
* **Event-driven**: invalidate when the underlying resource changes (e.g., after updating a user, purge `users.show:id`).

> Start with short TTLs (30–120s) on hot reads; widen once confident.

---

## Cooperation with ETags & Compression

* If a cached **entity** includes an **ETag**, the cached response should store it.
* If you cache **already-compressed** bytes per encoding, key must vary by `Accept-Encoding` (or store uncompressed and let **Compression** run afterward—trade CPU vs memory).
* Using **Compression** with **recompute-strong** ETag strategy: you may cache **post-compression** bytes; validators and caches stay precise.

---

## Private vs public cache

* **Public (shared)** cache: safe for content identical across users (e.g., `/status`, blog pages).
* **Private (per-user)** cache: key includes user/tenant ID; respects `private` caching rules if you opt-in.

> When in doubt, default to **public** for anonymous pages and **no-cache/private** for authenticated content until you design per-user caching.

---

## Controlling cacheability in handlers

Use headers to guide the middleware:

```php
return Response::json($data)
  ->withHeader('Cache-Control', 'public, max-age=60'); // cache for 60s
```

Opt-out:

```php
return Response::json($data)
  ->withHeader('Cache-Control', 'no-store');
```

Short-lived private cache (if enabled in middleware):

```php
return Response::json($data)
  ->withHeader('Cache-Control', 'private, max-age=10');
```

---

## Example

### Cached product page

```php
Route::get('/products/{id:int}', function (int $id) {
  $product = repo()->getProduct($id); // DB call
  return Response::json($product)
    ->withHeader('Cache-Control', 'public, max-age=120');
}, 'products.show');
```

Subsequent GETs within 120s hit the cache (no DB). After that, first request recomputes and refreshes the entry.

### Manual purge (pseudo)

```php
// after admin updates a product
cache()->purgeByTag("product:{$id}");     // if you tag keys
// or
cache()->purgeKey(routeKey('products.show', ['id'=>$id], $query, $media));
```

---

## Configuration knobs (typical)

* `store` – PSR-16/PSR-6 cache adapter or custom store
* `defaultTtl` – seconds when response lacks explicit `max-age`
* `respectCacheControl` – honor `no-store/private` (default true)
* `varyBy` – list of attributes to include in keys (`media`, `locale`, `encoding`)
* `includeAcceptEncodingInKey` – true if storing pre-compressed bodies
* `privateKeyResolver` – callable to include `userId`/`tenantId` in keys for private caches
* `maxObjectSize` – guardrail for memory stores

*(Match names to your implementation.)*

---

## Observability

Expose counters:

* `response_cache_hits_total{route="..."}`
* `response_cache_misses_total{route="..."}`
* `response_cache_store_errors_total{...}`
* `response_cache_bytes{...}` (if you track object size)

Log **keys** at debug level for tricky bugs.

---

## Troubleshooting

| Symptom                   | Likely cause                           | Fix                                                                                                       |
| ------------------------- | -------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Fresh changes not visible | Stale cache                            | Lower TTLs, add purge on write, or enable SWR                                                             |
| Wrong variant served      | Missing `Vary` dimensions in key       | Include `media` / `locale` / `Accept-Encoding` as appropriate                                             |
| Auth data leaked          | Using public cache for private content | Mark responses `private` and include user/tenant in key or disable caching                                |
| No caching happening      | `no-store` or store misconfigured      | Remove `no-store`, set `defaultTtl`, verify cache adapter                                                 |
| Double compression        | Storing compressed & compressing again | Store uncompressed, or if storing compressed, set `includeAcceptEncodingInKey=true` and don’t re-compress |

---

## Checklist

* [ ] Add ResponseCache early in **pre-global** (after negotiation)
* [ ] Choose a key that reflects route + params + query (+ media/locale)
* [ ] Respect `Cache-Control`; set TTLs in handlers
* [ ] Coordinate with **ETag**/**Compression** strategies
* [ ] Plan invalidation on writes (purge by key or tags)
* [ ] Monitor hit/miss ratios and adjust TTLs
