# Request Limits

Fail fast on requests that are too large or too slow. This middleware guards your app (and upstream services) by enforcing **body size caps**, **upload timeouts** and optional **header limits** before the handler runs.

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

## Configuration

```php
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;

$preGlobal[] = new RequestLimitsMiddleware(
    maxHeaderBytes: 8192,                           // Max total header size
    maxHeaderCount: 100,                            // Max number of header fields
    maxBodyBytes: null,                             // Defaults to ini_get('post_max_size')
    bodyLimitVerbs: ['POST', 'PUT', 'PATCH', 'DELETE'],
    violateOnUnknownBody: true                      // Reject if no Content-Length
);
```

### Constructor Parameters

| Parameter             | Type            | Default                    | Description                               |
| --------------------- | --------------- | -------------------------- | ----------------------------------------- |
| `maxHeaderBytes`      | `int`           | `8192`                     | Maximum total size of all headers         |
| `maxHeaderCount`      | `int`           | `100`                      | Maximum number of header fields           |
| `maxBodyBytes`        | `int\|null`     | `post_max_size` from ini   | Maximum request body size                 |
| `bodyLimitVerbs`      | `array<string>` | `['POST', 'PUT', ...]`     | Methods to apply body size limit          |
| `violateOnUnknownBody`| `bool`          | `true`                     | Reject chunked/unknown size bodies        |

---

## What Gets Checked

### 1. Header Field Count (431 Payload Too Large)

Counts each header name-value pair:

```
Content-Type: application/json   // Count: 1
Accept: text/html                // Count: 2
Accept: application/json         // Count: 3 (if sent twice)
X-Custom: value                  // Count: 4
```

**Why limit**: Prevents header-based DoS attacks.

### 2. Header Byte Size (431 Request Header Fields Too Large)

Sums the byte length of all `Name: value` pairs:

```
Content-Type: application/json   // ~31 bytes
Authorization: Bearer eyJ...     // ~200 bytes (JWT)
```

**Why limit**: Prevents memory exhaustion from huge headers.

### 3. Body Size (413 Payload Too Large)

Based on `Content-Length` header:

```
POST /upload
Content-Length: 10485760  // 10MB

Body: [10MB of data]
```

**Why limit**: Prevents disk/memory exhaustion, protects upload handlers.

**Important**: Does NOT pre-reject `Transfer-Encoding: chunked` (can't know size upfront).

### 4. Transfer-Encoding Handling

```php
// Known transfer codings (bypassed for pre-check):
- "identity"   // No transformation
- "trailers"   // HTTP/2 legitimate use

// Actual codings (allow through, can't know size):
- "chunked"
- "compress", "deflate", "gzip" (rare in requests)
```

If `Transfer-Encoding: chunked`, middleware allows the request (validates later if needed).

---

## HTTP/2 Safety

**Never emits `Connection: close` for HTTP/2**:

```php
// Only for HTTP/1.x
if (str_starts_with($protocol, 'HTTP/1.')) {
    return $response->withHeader('Connection', 'close');
}
```

HTTP/2 doesn't use `Connection` header; middleware respects this.

---

## Typical Limits by Use Case

| Use Case          | Headers | Header Bytes | Body Size  |
| ----------------- | ------: | -----------: | ---------: |
| Public API        |     50  |        4 KiB |     1 MiB  |
| JSON API          |     50  |        8 KiB |     2 MiB  |
| File Uploads      |     50  |        8 KiB |   100 MiB  |
| Auth/Login        |     30  |        2 KiB |   256 KiB  |
| Admin Interface   |    100  |       16 KiB |    10 MiB  |
| Webhooks          |     50  |        4 KiB |     5 MiB  |

---

## Examples

### Strict Limits (Public API)

```php
new RequestLimitsMiddleware(
    maxHeaderBytes: 4096,       // 4KB headers
    maxHeaderCount: 50,
    maxBodyBytes: 1_048_576,    // 1MB body
    bodyLimitVerbs: ['POST', 'PUT', 'PATCH', 'DELETE']
);
```

### Relaxed Limits (File Upload Endpoint)

```php
// Use different limits per route
Route::post('/upload', [UploadController::class, 'store'], [
    'middleware' => [
        new RequestLimitsMiddleware(
            maxBodyBytes: 104_857_600  // 100MB for uploads
        )
    ]
]);
```

### Conservative (High Security)

```php
new RequestLimitsMiddleware(
    maxHeaderBytes: 2048,       // 2KB
    maxHeaderCount: 30,
    maxBodyBytes: 262_144,      // 256KB
    violateOnUnknownBody: true  // Reject chunked
);
```

---

## Error Responses

### 413 Payload Too Large

```json
HTTP/1.1 413 Payload Too Large
Content-Type: application/json

{
  "error": {
    "code": "E_BODY_TOO_LARGE",
    "message": "Request body exceeds 1048576 bytes",
    "limit": 1048576,
    "received": 2097152
  }
}
```

### 431 Request Header Fields Too Large

```json
HTTP/1.1 431 Request Header Fields Too Large

{
  "error": {
    "code": "E_HEADERS_TOO_LARGE",
    "message": "Total header size exceeds 8192 bytes",
    "limit": 8192,
    "received": 10240
  }
}
```

---

## Interaction with Web Server Limits

### Nginx

Align limits:

```nginx
# nginx.conf
client_max_body_size 10M;       # Should be >= maxBodyBytes
client_header_buffer_size 4k;
large_client_header_buffers 4 8k;  # Max 32KB total
```

**Recommendation**: Make Nginx limits slightly **stricter** to fail fast at the edge.

### Apache

```apache
# httpd.conf
LimitRequestBody 10485760        # 10MB (should be >= maxBodyBytes)
LimitRequestFields 50            # Max header count
LimitRequestFieldSize 8190       # Max single header size
LimitRequestLine 8190            # Max request line size
```

### PHP INI

```ini
post_max_size = 10M
upload_max_filesize = 10M
max_input_vars = 1000
```

**Chain**: Nginx/Apache → PHP INI → Middleware

Each layer should be compatible (upstream stricter or equal).

---

## Bypass for Specific Routes

```php
// Global limits
$preGlobal[] = new RequestLimitsMiddleware(maxBodyBytes: 1_048_576);

// Override for file upload
Route::post('/files/upload', [FileController::class, 'upload'], [
    'middleware' => [
        new RequestLimitsMiddleware(maxBodyBytes: 104_857_600)  // 100MB
    ]
]);
```

**Important**: Per-route middleware runs **after** pre-global, so set global to highest needed or skip global for body limits.

---

## Security Considerations

### Slowloris Protection

Limit request **duration**, not just size:

```php
// In conjunction with web server timeouts
new RequestLimitsMiddleware(
    maxBodyBytes: 10_485_760,
    // Web server should also set:
    // - Nginx: client_body_timeout 30s
    // - Apache: Timeout 30
);
```

### Memory Exhaustion

Even with limits, buffering large requests consumes memory:

```php
// Prefer streaming/chunked processing for large uploads
// Use temporary files, not memory buffering
move_uploaded_file($file->getTmpName(), $destination);
```

### Header Injection

Limit header count to prevent:
- Header injection attacks
- Cache poisoning via many `Vary` headers

```php
maxHeaderCount: 50  // Reasonable for most apps
```

---

## Monitoring

### Metrics to Track

```php
request_limits_violations_total{reason="body_too_large"}
request_limits_violations_total{reason="headers_too_large"}
request_limits_violations_total{reason="too_many_headers"}
request_body_size_bytes (histogram)
request_header_size_bytes (histogram)
```

### Logging

```php
// In middleware or wrapper
if ($bodyTooLarge) {
    $logger->warning('Request body too large', [
        'path' => $request->getPath(),
        'ip' => $request->getAttribute('client_ip'),
        'content_length' => $request->getHeaderLine('Content-Length'),
        'limit' => $maxBodyBytes
    ]);
}
```

---

## Troubleshooting

### Issue: Legitimate uploads rejected

**Symptoms**: Users can't upload valid files

**Causes**:
1. `maxBodyBytes` too strict
2. Web server limit lower than app limit
3. Chunked encoding blocked

**Debug**:
```bash
# Check actual file size
ls -lh upload.jpg  # 5.2M

# Check Content-Length sent
curl -v -F "file=@upload.jpg" http://localhost/upload 2>&1 | grep Content-Length

# Test with explicit limit
curl -v -F "file=@upload.jpg" \
  --max-filesize 10485760 \
  http://localhost/upload
```

**Fix**:
```php
// Increase limit for upload endpoint
Route::post('/upload', /* ... */, [
    'middleware' => [
        new RequestLimitsMiddleware(maxBodyBytes: 20_971_520)  // 20MB
    ]
]);
```

### Issue: Headers rejected in production

**Symptoms**: Requests work in dev, fail in prod with 431

**Causes**:
1. Large JWTs in `Authorization` header
2. Many cookies
3. Verbose `User-Agent` strings

**Debug**:
```php
// Log header sizes
$totalSize = 0;
foreach ($request->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        $size = strlen("{$name}: {$value}\r\n");
        $totalSize += $size;
        error_log("{$name}: {$size} bytes");
    }
}
error_log("Total: {$totalSize} bytes");
```

**Fix**:
```php
// Increase header limit
new RequestLimitsMiddleware(
    maxHeaderBytes: 16384  // 16KB (was 8KB)
);
```

### Issue: Chunked requests fail

**Symptoms**: Streaming uploads always return 413

**Cause**: `violateOnUnknownBody: true` blocks chunked encoding

**Fix**:
```php
new RequestLimitsMiddleware(
    violateOnUnknownBody: false  // Allow chunked
);
```

**Alternative**: Validate size as data streams in, not upfront.

---

## Best Practices

### ✅ **Do**

1. **Set explicit limits**
   ```php
   maxBodyBytes: 10_485_760  // Explicit 10MB
   ```

2. **Align with upstream**
   ```nginx
   client_max_body_size 10M;  # Nginx
   ```
   ```php
   maxBodyBytes: 10_485_760   # App
   ```

3. **Use different limits per route**
   ```php
   // Strict for API, relaxed for uploads
   Route::post('/api/*', middleware: [new RequestLimitsMiddleware(maxBodyBytes: 1_048_576)]);
   Route::post('/upload', middleware: [new RequestLimitsMiddleware(maxBodyBytes: 104_857_600)]);
   ```

4. **Monitor violations**
   ```php
   if ($violated) {
       $metrics->increment('request_limits_violations', ['reason' => $reason]);
   }
   ```

5. **Provide clear errors**
   ```json
   {
     "error": "Request too large",
     "limit": "10MB",
     "documentation": "https://docs.example.com/api-limits"
   }
   ```

### ❌ **Don't**

1. **Don't use unlimited**
   ```php
   maxBodyBytes: null  // ❌ Dangerous
   maxBodyBytes: PHP_INT_MAX  // ❌ Same
   ```

2. **Don't block chunked unnecessarily**
   ```php
   violateOnUnknownBody: true  // ❌ Breaks streaming
   ```

3. **Don't mismatch upstream/downstream**
   ```nginx
   client_max_body_size 1M;  # Nginx
   ```
   ```php
   maxBodyBytes: 104_857_600  # 100MB (never reached!)
   ```

4. **Don't ignore limits on internal endpoints**
   ```php
   // ❌ Even internal endpoints should have limits
   Route::post('/internal/webhook', /* no limits */);

   // ✅ Set reasonable limits
   Route::post('/internal/webhook', middleware: [
       new RequestLimitsMiddleware(maxBodyBytes: 10_485_760)
   ]);
   ```

---

## Summary

**RequestLimitsMiddleware protects against**:
- ✅ Memory exhaustion (large bodies/headers)
- ✅ Disk exhaustion (large uploads)
- ✅ CPU exhaustion (parsing huge headers)
- ✅ Application-level DoS

**Key configuration**:
1. **maxBodyBytes**: Size per endpoint type (API vs uploads)
2. **maxHeaderBytes**: Usually 8KB sufficient
3. **maxHeaderCount**: Usually 50-100 sufficient
4. **Alignment**: Upstream (Nginx) ≤ App limits

**Golden rule**: Set explicit, per-use-case limits and monitor violations.


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
