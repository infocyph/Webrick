# Telemetry Middleware

Captures timing and request/response metadata for observability.

## What it records (typical)
- Start/end timestamps, duration
- Route name, handler class/method
- Status code, size, encoding
- Optional user/session identifiers (if configured)

## Usage
Attach in **preGlobal** (to start timers) and let it close on response:

```php
preGlobal: [
  \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
]
```

### Export
Hook into your logger/Metrics exporter (e.g., Prometheus, OpenTelemetry) via the middleware configuration.


---

## Configuration

```php
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Psr\Log\LoggerInterface;

$preGlobal[] = new TelemetryMiddleware(
    log: $logger,                              // PSR-3 logger
    addXResponseTime: true,                    // X-Response-Time header
    addServerTiming: true,                     // Server-Timing header
    emitRequestId: true,                       // Generate/propagate request IDs
    requestIdHeader: 'X-Request-Id',           // Header name
    respectExistingRequestId: true,            // Honor incoming IDs
    nelGroup: 'default',                       // NEL group name
    nelEndpoint: 'https://nel.example.com/report',  // NEL collector
    nelTtlSeconds: 86400,                      // NEL policy TTL
    nelIncludeSubdomains: true,                // NEL for subdomains
    nelCollectSuccesses: false,                // Log successful requests (bandwidth)
    emitTraceIdHeader: true,                   // Trace-Id header
    traceIdHeader: 'Trace-Id',                 // Header name
    respectIncomingTraceparent: true,          // Honor W3C traceparent
    emitTraceparentHeader: false               // Emit traceparent (opt-in)
);
```

---

## Features

### 1. W3C Trace Context

**Pure W3C implementation** (no OpenTelemetry dependency):

```
traceparent: 00-<trace-id>-<span-id>-<flags>
tracestate: vendor1=value1,vendor2=value2
```

**Middleware behavior**:
- Generates new `trace-id` if missing
- Creates new `span-id` per request
- Respects incoming `traceparent`/`tracestate`
- Propagates to response headers (if enabled)

### 2. Request ID

```
X-Request-Id: 3f4a7b2c1e9d8a5f
```

- Generated if missing: `bin2hex(random_bytes(16))`
- Respects incoming IDs from load balancers
- Stored as request attribute: `$r->getAttribute('request_id')`

### 3. Timing Headers

**X-Response-Time**:
```
X-Response-Time: 45.2ms
```

**Server-Timing**:
```
Server-Timing: app;dur=45.2
```

Can be extended by other middleware (compression, cache, etc.).

### 4. Network Error Logging (NEL)

**NEL Header**:
```json
NEL: {
  "group": "default",
  "max_age": 86400,
  "include_subdomains": true,
  "success_fraction": 0.0,
  "failure_fraction": 1.0
}
```

**Report-To Header**:
```json
Report-To: {
  "group": "default",
  "max_age": 86400,
  "endpoints": [{"url": "https://nel.example.com/report"}]
}
```

### 5. Access Logging

Structured log entry:

```
203.0.113.10 (direct) "GET /api/users" 200 1234 45.2ms id=3f4a7b2c trace=abc123 span=def456
```

**Includes**:
- Client IP (with proxy detection)
- Method + path
- Status + size
- Duration
- Request ID
- Trace/Span IDs

---

## Request Attributes

Middleware attaches:

```php
$r->getAttribute('trace.trace_id');       // W3C trace ID
$r->getAttribute('trace.span_id');        // Current span ID
$r->getAttribute('trace.parent_span_id'); // Parent span
$r->getAttribute('trace.flags');          // Trace flags
$r->getAttribute('trace.tracestate');     // Trace state
$r->getAttribute('request_id');           // Request ID
```

