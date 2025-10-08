# Request Limits

Fail fast on requests that are too large or too slow. This middleware guards your app (and upstream services) by enforcing **body size caps**, **upload timeouts**, and optional **header limits** before the handler runs.

---

## What it does

* Rejects requests whose **Content-Length** exceeds a configured **maxBodyBytes**
* Aborts requests that take longer than **maxUploadSeconds** to arrive (slowloris-style drips)
* Optionally caps **header size/count** to avoid header abuse
* Returns clear error responses (e.g., **413 Payload Too Large**, **408 Request Timeout**) with retry guidance
* Plays nicely with throttling and caching middleware (run **early**)

---

## Wiring

Place in **pre-global** before anything expensive:

```php
$preGlobal = [
  \Infocyph\Webrick\Middleware\RequestLimitsMiddleware::class,
  // throttle, cookies, negotiation, validators...
];
```

If you need custom limits:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\RequestLimitsMiddleware(
    maxBodyBytes: 10 * 1024 * 1024,   // 10 MiB
    maxUploadSeconds: 30,             // timeout while reading body
    maxHeaderBytes: 32 * 1024,        // optional
    maxHeaderCount: 100                // optional
);
```

*(Adjust constructor names to your implementation.)*

---

## Typical limits

| Context             |    `maxBodyBytes` | `maxUploadSeconds` | Notes                                                  |
| ------------------- | ----------------: | -----------------: | ------------------------------------------------------ |
| Public JSON APIs    |           1–2 MiB |             15–30s | Keep tight to block abuse                              |
| Auth/Login          |       128–256 KiB |             10–20s | Small forms; protect brute-force                       |
| File Uploads        | handled elsewhere |                n/a | Prefer dedicated upload endpoints with explicit checks |
| Admin/Internal APIs |          5–10 MiB |             30–60s | Depends on use cases                                   |

---

## Behavior & status codes

* **Too big**: if `Content-Length` (or measured bytes) exceeds `maxBodyBytes` → **413 Payload Too Large**

    * Response may include `Retry-After` or a JSON error with guidance
* **Too slow**: if body doesn’t arrive in time → **408 Request Timeout** (or **400** depending on policy)
* **Header abuse**: exceed header limits → **400 Bad Request** with an explanatory code

> Prefer rejecting **before** reading entire bodies to save CPU and bandwidth.

---

## Working with proxies & servers

* **Nginx/Apache limits** (e.g., `client_max_body_size`, `LimitRequestBody`) should be aligned with or slightly **stricter** than app limits for early drops at the edge.
* If your reverse proxy buffers uploads, the app may see the payload all at once—app limits still help as a second line.
* Ensure **timeout** settings (proxy/connect/read) are compatible with `maxUploadSeconds`.

---

## File uploads: recommended pattern

Instead of high global limits, create a **dedicated upload route** with explicit checks:

```php
Route::post('/upload', function ($r) {
    $f = $r->file('avatar');
    if (!$f || $f->getError()) {
        return Response::json(['error'=>'invalid upload'], 400);
    }
    if ($f->getSize() > 2 * 1024 * 1024) {
        return Response::json(['error'=>'file too large'], 413);
    }
    // validate mime/extension; move to storage
    return Response::json(['ok'=>true], 201);
}, ['middleware' => ['throttle:5,60']]);
```

Keep **global** limits modest; scale per-endpoint where needed.

---

## Observability

Expose counters/timers:

* `requests_limited_total{reason="body_too_large"}`
* `requests_limited_total{reason="timeout"}`
* `request_body_bytes{route="..."} (histogram)`

Optional: emit `Server-Timing: request-limits;dur=…` for investigations.

---

## Error format (example)

```json
{
  "error": {
    "code": "E_BODY_TOO_LARGE",
    "message": "Payload exceeds 1048576 bytes",
    "limit": 1048576
  }
}
```

Standardize across your API so client libraries can respond intelligently.

---

## Troubleshooting

| Symptom                       | Likely cause              | Fix                                                                 |
| ----------------------------- | ------------------------- | ------------------------------------------------------------------- |
| Users hit 413 on normal forms | Limit too strict          | Raise `maxBodyBytes` slightly; compress forms; remove unused fields |
| Random 408s on mobile         | Aggressive timeout        | Increase `maxUploadSeconds`; verify proxy timeouts                  |
| Limits ignored in production  | Proxy terminating earlier | Align Nginx/Apache limits with app settings; document expectations  |
| Large JSON blows memory       | Full buffering            | Prefer streaming parsers for huge payloads; re-design endpoints     |

---

## Checklist

* [ ] Add RequestLimits early in **pre-global**
* [ ] Set tight defaults for public APIs; per-endpoint overrides for uploads
* [ ] Align proxy/web-server limits and timeouts
* [ ] Emit clear error shapes and metrics
* [ ] Load test with realistic slow clients to validate thresholds
