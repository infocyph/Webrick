# Telemetry

Give every request a traceable identity and surface timing/diagnostics to logs and clients. The Telemetry middleware standardizes **request IDs**, **correlation IDs**, and **server-timing** hints so you can follow a request across services and spot latency hot spots.

---

## What it does

* **Assigns a Request ID** if one isn’t present (e.g., `X-Request-Id` header)
* **Propagates Correlation/Trace IDs** from trusted upstreams (e.g., `X-Correlation-Id`, `traceparent`)
* **Emits response headers**:

    * `X-Request-Id: <id>`
    * `X-Correlation-Id: <id>` (when available)
    * Optional `Server-Timing: <metric>;dur=<ms>` entries (encode stages like `route`, `throttle`, `encode`)
* **Exposes IDs to your logger** so all logs share the same tags
* **Measures** coarse-grained phase durations (request start → handler → post-globals)

> If you already use an APM (OpenTelemetry), this middleware should integrate rather than compete. Use it as the baseline in environments without full tracing.

---

## Wiring

Place early in **pre-global** so everything downstream inherits the context:

```php
$preGlobal = [
  \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
  \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
  // RequestLimits, Throttle, Cookies, ...
];
```

If you need custom behavior (header names, ID generator, trusted proxies), instantiate with options:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\TelemetryMiddleware(
  requestIdHeader: 'X-Request-Id',
  correlationHeaders: ['X-Correlation-Id', 'traceparent'],
  trustProxy: true,          // only if your proxy injects/validates these headers
  idFactory: static fn () => bin2hex(random_bytes(16)), // 32-hex chars
  enableServerTiming: true
);
```

*(Adjust constructor names to your implementation.)*

---

## Request/response headers

### Inbound (accepted)

* `X-Request-Id` – if present (and trusted), use as the request ID
* `X-Correlation-Id` – used to correlate across services
* `traceparent` / `tracestate` – W3C Trace Context (if you integrate OTEL)

### Outbound (added)

* `X-Request-Id` – always set
* `X-Correlation-Id` – echoed when at least one inbound correlation value exists
* `Server-Timing` – optional metrics like `telemetry;desc="total";dur=57.3`, `route;dur=2.1`

> Don’t leak internal details: keep IDs opaque (random) and durations coarse.

---

## Logging integration

Most loggers allow **context** (key/value pairs):

```php
$logger->info('User fetched profile', [
  'request_id'    => $telemetry->requestId(),
  'correlation_id'=> $telemetry->correlationId(),
  'route'         => $r->getAttribute('route.name') ?? null,
  'status'        => 200,
  'remote_ip'     => $r->server('REMOTE_ADDR'),
]);
```

If the middleware sets a **PSR-3 scoped logger** or attaches IDs to the request attributes, fetch them from there in controllers and error handlers.

---

## Server-Timing (optional)

Expose high-level timings to clients and devtools (Network tab):

* `telemetry` – total (request → response emit)
* `route` – router match + handler time
* `encode` – compression/serialization time
* `cache` – response cache hit/miss + evaluate duration
* `throttle` – time in limiter

Example header:

```
Server-Timing: telemetry;dur=58.9, route;dur=2.3, encode;dur=1.1
```

> Use sparingly in production; overly detailed timing may become noisy. Great in staging/dev or behind auth.

---

## Trust model

Only **trust** incoming IDs from:

* Your own reverse proxies or service mesh (where headers cannot be spoofed by the internet)
* Authenticated internal services

If `trustProxy` is **false**, ignore inbound IDs and always generate new ones; you can still **echo** them to upstream by writing to logs or forwarding in later hops.

---

## ID format

* Prefer **128-bit random** (hex) or **ULID** for sortable randomness.
* Avoid incremental or guessable IDs.
* Keep under common header limits (usually < 128 chars is plenty).

Sample generator:

```php
$idFactory = static fn() => bin2hex(random_bytes(16)); // 32-char hex
```

---

## Error handling

On exceptions:

* Ensure the **same IDs** are present in error responses and logs.
* Optionally include a stable **error code** so support can search logs quickly:

    * `X-Error-Id: <same-as-request-id>` or a separate `X-Error-Code`

Pair with a global exception handler that logs stack traces **once** with the request/correlation IDs.

---

## Observability & metrics

Record counters and histograms (names are illustrative):

* `http_requests_total{route="...",status="200"}`
* `http_request_duration_seconds{route="..."} (histogram)`
* `throttle_wait_seconds` (if limiter queues)
* `encode_duration_seconds` (compression)

If exporting to Prometheus or OTEL, ensure the middleware can **hook** a timer/stopwatch at start and finish.

---

## Examples

### Minimal: generate IDs and expose headers

```php
$preGlobal[] = \Infocyph\Webrick\Middleware\TelemetryMiddleware::class;
```

Response headers:

```
X-Request-Id: 3a9d0f2c9f1e4e98a2e7f0c9b4d3a6c1
```

### With correlation and server timing

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\TelemetryMiddleware(
  correlationHeaders: ['X-Correlation-Id', 'traceparent'],
  enableServerTiming: true
);
```

Headers:

```
X-Request-Id: 2e9f...
X-Correlation-Id: b1a7...
Server-Timing: telemetry;dur=46.2, route;dur=1.8
```

---

## Troubleshooting

| Symptom                               | Likely cause                                 | Fix                                                                  |
| ------------------------------------- | -------------------------------------------- | -------------------------------------------------------------------- |
| Duplicate/blank request IDs           | Logger not bound to per-request context      | Ensure middleware injects IDs into context accessible by your logger |
| Spoofed correlation IDs from internet | trustProxy enabled without a secure proxy    | Disable `trustProxy` or sanitize inbound headers                     |
| Missing `Server-Timing` in browser    | Feature disabled or header stripped by proxy | Enable `enableServerTiming`; ensure proxy doesn’t drop it            |
| Hard to trace multi-hop calls         | Each service generates new IDs               | Propagate `X-Request-Id`/`traceparent` to downstream services        |

---

## Checklist

* [ ] Place Telemetry early in **pre-global**
* [ ] Generate strong random **request IDs**; echo in `X-Request-Id`
* [ ] Optionally propagate **correlation/trace** headers from trusted upstreams
* [ ] Integrate IDs with your **logger** and global error handler
* [ ] (Optional) Enable **Server-Timing** for coarse phase durations
* [ ] Sanitize/ignore untrusted inbound IDs in public-facing deployments
