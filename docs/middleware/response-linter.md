# Response Linter (dev)

Catch response anti-patterns **during development** before they leak into production. This middleware inspects outbound responses and logs/warns (or throws in strict mode) when it detects common mistakes.

> Enable only in **dev/staging**. In production, use **warn** mode at most.

---

## What it checks (typical)

* **Content-Type sanity**

    * Missing or wrong `Content-Type` (e.g., JSON body without `application/json; charset=UTF-8`)
    * HTML/XML without charset
* **Content-Length / streaming**

    * `Content-Length` present on a **streaming** response (illegal)
    * Negative/invalid lengths
* **Compression & validators**

    * `Content-Encoding` set but body appears uncompressed (double-compress risk upstream)
    * `ETag` present + Compression active with a mismatched strategy (e.g., strong ETag on pre-encoding bytes when strategy is recompute-strong)
* **Caching headers**

    * Conflicting `Cache-Control` directives (`no-store` with `max-age`)
    * Missing `Vary` when body changes by `Accept` or `Accept-Encoding`
* **Status/body coherence**

    * 204/304 responses that still carry a body
    * 1xx/204/304 responses with entity headers that must be stripped
* **Cookies**

    * `Set-Cookie` on responses with `Cache-Control: public` (possible cache leak)
    * Cookies without `HttpOnly`/`Secure` (warning)
* **CORS/security**

    * CORS preflight responses missing required `Access-Control-*`
    * Obvious security header omissions in HTML (CSP, nosniff, frame options) – advisory
* **Misc**

    * Duplicate headers with conflicting values
    * Non-ASCII in header names/values

*(Exact rules depend on your implementation; adjust list to match your checks.)*

---

## Wiring

Place it **last** in **post-global** so it sees the final response:

```php
$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  \Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
  new \Infocyph\Webrick\Middleware\ResponseLinterMiddleware(
    mode: 'warn',      // 'warn' | 'throw'
    logLevel: 'warning'
  ),
];
```

Use `'throw'` only in local dev to force corrections.

---

## Output & developer UX

On violation, the linter can:

* **Log** a structured entry (rule id, message, header snapshot, route name)
* **Annotate** the response with a `X-Response-Lint` header (dev only)
* **Throw** an exception (in strict mode) with a clear remediation message

Example log:

```json
{
  "rule": "no-body-with-204",
  "route": "api.users.update",
  "status": 204,
  "hint": "A 204 response must not include a message body."
}
```

---

## Recommended rules (cheatsheet)

| Rule ID                     | Description                                                | Fix                                     |
| --------------------------- | ---------------------------------------------------------- | --------------------------------------- |
| `content-type-json`         | JSON-looking body but no `application/json; charset=UTF-8` | Use `Response::json(...)`               |
| `no-body-with-204`          | Body present on 204/304                                    | Return empty body; strip entity headers |
| `stream-no-length`          | Streaming response has `Content-Length`                    | Remove length; ensure chunked/implicit  |
| `vary-accept-missing`       | Negotiated body but `Vary: Accept` absent                  | Add/make Vary Accumulator add it        |
| `cache-conflict`            | `no-store` with `max-age`                                  | Pick one (usually `no-store`)           |
| `cookie-public-cache`       | `Set-Cookie` with public cacheability                      | Use `private` or `no-store`             |
| `weak-etag-with-encode`     | Weak ETag + encode strategy mismatch                       | Align with compression strategy         |
| `cors-preflight-incomplete` | Preflight missing allow headers                            | Add required `Access-Control-*`         |

---

## Examples

### 1) JSON route missing content type

```php
Route::get('/data', fn() => Response::create(json_encode(['ok'=>true])));
```

**Lint:** `content-type-json`
**Fix:** `Response::json(['ok'=>true])`

---

### 2) Stream with Content-Length

```php
return Response::stream(function(){ yield "..." ; })
  ->withHeader('Content-Length','1234');
```

**Lint:** `stream-no-length`
**Fix:** remove `Content-Length` (streaming responses should not declare a fixed length).

---

### 3) 204 with body

```php
return Response::json(['ok'=>true], 204);
```

**Lint:** `no-body-with-204`
**Fix:** return 200, or keep 204 and **no body**.

---

### 4) Public cache + Set-Cookie

```php
return Response::json($data)
  ->withHeader('Cache-Control','public, max-age=600')
  ->withAddedHeader('Set-Cookie','sid=...; Path=/');
```

**Lint:** `cookie-public-cache`
**Fix:** set `Cache-Control: private, max-age=...` or `no-store`.

---

## Tuning

Constructor/options you might expose:

* `mode`: `'warn' | 'throw'`
* `rules`: enable/disable specific checks (e.g., `['cookie-public-cache' => false]`)
* `treatAsJson`: heuristic (status + body prefix) threshold to flag JSON without correct content type
* `securityProfile`: `'api' | 'web'` – nudges which advisory rules to apply
* `maxHeaderCount`/`maxHeaderBytes`: warn when nearing platform limits

---

## Troubleshooting

| Symptom                              | Likely cause              | Fix                                                |
| ------------------------------------ | ------------------------- | -------------------------------------------------- |
| Legitimate binary flagged as JSON    | Aggressive heuristic      | Loosen `treatAsJson` or whitelist route            |
| Fails only in prod                   | Linter enabled in prod    | Disable or set `mode:'warn'` only for staging      |
| Duplicate `Vary` despite accumulator | Manual `Vary` set earlier | Let accumulator manage; avoid manual concatenation |

---

## Checklist

* [ ] Run Response Linter last in **post-global** (dev/staging)
* [ ] Start with `mode: 'warn'`; use `'throw'` locally to catch mistakes early
* [ ] Enable rules that match your project (API vs HTML site)
* [ ] Fix violations at the **source** (helpers, middleware order, headers)
