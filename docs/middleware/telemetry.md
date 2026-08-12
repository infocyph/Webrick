# Telemetry Middleware

Comprehensive observability middleware with W3C Trace Context support and optional OpenTelemetry integration for distributed tracing, request correlation and performance monitoring.

## Overview

The Telemetry Middleware provides two modes of operation:

1. **Minimal Mode (Default)** - Zero dependencies, W3C Trace Context only
2. **OpenTelemetry Mode** - Full observability with automatic span export (auto-enabled when SDK is installed)

Both modes provide:
- W3C Trace Context propagation
- Request ID generation and correlation
- Response timing headers
- Access logging with trace correlation
- Global trace context via `TraceContext` helper

---

## Quick Start

### Basic Usage (Minimal Mode)
```php
use Infocyph\Webrick\Middleware\TelemetryMiddleware;

$preGlobal = [
    new TelemetryMiddleware($logger),
];
```

**Response headers:**
```text
X-Response-Time: 45.2ms
Server-Timing: app;dur=45.2
X-Request-Id: 1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p
Trace-Id: a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7
```

**Access log:**
```
127.0.0.1 (direct) "GET /api/users" 200 1234 45.2ms id=abc123 trace=def456 span=789xyz [w3c]
```

---

## Integration with RouterKernel

### Basic Setup

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;

$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: ShardedMatcher::make(),
    register: $register,
    preGlobal: [
        TelemetryMiddleware::class,  // Add as first middleware
        // ... other middleware
    ]
);
```

### With Custom Configuration

```php
use Infocyph\Webrick\Middleware\TelemetryMiddleware;

$preGlobal = [
    new TelemetryMiddleware(
        log: $logger,
        addXResponseTime: true,
        addServerTiming: true,
        emitRequestId: true,
        emitTraceIdHeader: true,
        respectIncomingTraceparent: true
    ),
    // ... other middleware
];

$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: ShardedMatcher::make(),
    register: $register,
    preGlobal: $preGlobal
);
```

### OpenTelemetry Mode (Full Observability)

Install OpenTelemetry SDK:
```bash
composer require open-telemetry/sdk open-telemetry/exporter-otlp
```

Configure OpenTelemetry (in bootstrap):
```php
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\API\Globals;

$exporter = new SpanExporter('http://localhost:4318/v1/traces');
$tracerProvider = new TracerProvider(
    new BatchSpanProcessor($exporter)
);
Globals::registerInitializer(fn() => $tracerProvider);
```

Use middleware (need a simple change):
```php
$preGlobal = [
    new TelemetryMiddleware(
    $logger,
    enableOtelIntegration: true
    ),
];
```

**Access log (note `[otel]` indicator):**
```
127.0.0.1 (direct) "GET /api/users" 200 1234 45.2ms id=abc123 trace=def456 span=789xyz [otel]
```

**Plus:** Spans automatically exported to Jaeger/Zipkin/OTLP collectors!

---

## Constructor Reference

```php
public function __construct(
    ?LoggerInterface $log = null,              // PSR-3 logger (defaults to NullLogger)
    bool $addXResponseTime = true,             // Add X-Response-Time header
    bool $addServerTiming = true,              // Add Server-Timing header
    bool $emitRequestId = true,                // Generate/emit request IDs
    string $requestIdHeader = 'X-Request-Id',  // Request ID header name
    bool $respectExistingRequestId = true,     // Honor incoming request IDs
    ?string $nelGroup = null,                  // NEL group name (null = disabled)
    ?string $nelEndpoint = null,               // NEL reporting endpoint
    int $nelTtlSeconds = 86400,                // NEL policy TTL (seconds)
    bool $nelIncludeSubdomains = true,         // NEL for subdomains
    bool $nelCollectSuccesses = false,         // Report successful requests
    bool $emitTraceIdHeader = true,            // Emit Trace-Id header
    string $traceIdHeader = 'Trace-Id',        // Trace ID header name
    bool $respectIncomingTraceparent = true,   // Honor incoming traceparent
    bool $emitTraceparentHeader = false,       // Emit traceparent header
    bool $enableOtelIntegration = false,        // Enable OpenTelemetry (auto-detected)
    ?string $otelServiceName = null,           // Service name in traces
    ?string $otelServiceVersion = null,        // Service version in traces
)
```

---

## Configuration

### Full Configuration Example
```php
use Infocyph\Webrick\Middleware\TelemetryMiddleware;

$preGlobal[] = new TelemetryMiddleware(
    log: $logger,                              // PSR-3 logger
    
    // Response timing
    addXResponseTime: true,                    // X-Response-Time header
    addServerTiming: true,                     // Server-Timing header
    
    // Request ID
    emitRequestId: true,                       // Generate/propagate request IDs
    requestIdHeader: 'X-Request-Id',           // Header name
    respectExistingRequestId: true,            // Honor incoming IDs
    
    // Network Error Logging (NEL)
    nelGroup: 'default',                       // NEL group name
    nelEndpoint: 'https://nel.example.com/report',  // NEL collector
    nelTtlSeconds: 86400,                      // NEL policy TTL (24 hours)
    nelIncludeSubdomains: true,                // NEL for subdomains
    nelCollectSuccesses: false,                // Log successful requests (bandwidth)
    
    // W3C Trace Context
    emitTraceIdHeader: true,                   // Trace-Id header in response
    traceIdHeader: 'Trace-Id',                 // Header name
    respectIncomingTraceparent: true,          // Honor incoming traceparent
    emitTraceparentHeader: false,              // Emit traceparent header (opt-in)
    
    // OpenTelemetry (auto-detected)
    enableOtelIntegration: true,               // Enable OTel mode if SDK available
    otelServiceName: 'my-awesome-api',         // Service name in traces
    otelServiceVersion: '2.0.0',               // Service version
);
```

### Minimal Configuration (Performance-Focused)
```php
$preGlobal[] = new TelemetryMiddleware(
    log: $logger,
    addXResponseTime: false,      // Skip header
    addServerTiming: false,       // Skip header
    emitRequestId: true,          // Keep for correlation
    emitTraceIdHeader: true,      // Keep for correlation
);
```

---

## Features

### 1. W3C Trace Context

**Standard compliance** with W3C Trace Context specification:
```text
traceparent: 00-a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7-1234567890abcdef-01
tracestate: congo=ucfJifl5GOE,rojo=00f067aa0ba902b7
```

**Format:** `version-traceid-spanid-flags`

**Middleware behavior:**
- Generates new `trace-id` (32 hex chars) if missing
- Creates new `span-id` (16 hex chars) per request
- Respects incoming `traceparent`/`tracestate` headers
- Validates trace IDs (non-zero, correct length)
- Propagates to downstream services

**Request attributes:**
```php
$r->getAttribute('trace.trace_id');       // a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7
$r->getAttribute('trace.span_id');        // 1234567890abcdef
$r->getAttribute('trace.parent_span_id'); // 0000000000000000
$r->getAttribute('trace.flags');          // 01
$r->getAttribute('trace.tracestate');     // congo=ucfJifl5GOE
```

### 2. Request ID Correlation

**Unique identifier per request:**
```text
X-Request-Id: 1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p
```

**Generation:**
- If missing: `bin2hex(random_bytes(16))` (32 hex chars)
- If present: Forwards existing ID from load balancers/proxies
- Stored as: `$r->getAttribute('request_id')`

**Use cases:**
- Support tickets (users report request ID)
- Log correlation within single request
- Request-specific debugging

### 3. Response Timing Headers

#### X-Response-Time
```text
X-Response-Time: 45.2ms
```

Human-readable response time in milliseconds.

#### Server-Timing
```text
Server-Timing: app;dur=45.2
```

W3C Server Timing API format. Can be extended by other middleware:
```text
Server-Timing: cache;dur=2.3, db;dur=12.5, app;dur=45.2
```

**Browser DevTools integration:**
- Visible in Network tab → Timing
- Programmatically accessible via Performance API

### 4. Network Error Logging (NEL)

**Browser error reporting configuration:**
```text
NEL: {"group":"default","max_age":86400,"include_subdomains":true,"success_fraction":0.0,"failure_fraction":1.0}
Report-To: {"group":"default","max_age":86400,"endpoints":[{"url":"https://nel.example.com/report"}]}
```

**What it monitors:**
- DNS failures
- TCP connection errors
- TLS handshake failures
- HTTP protocol errors
- Request timeouts

**Configuration:**
- `success_fraction: 0.0` = Only report failures (saves bandwidth)
- `failure_fraction: 1.0` = Report all failures

### 5. Access Logging

**Structured log entries with trace correlation:**

**Minimal mode:**
```
127.0.0.1 (direct) "GET /api/users" 200 1234 45.2ms id=abc123 trace=def456 span=789xyz [w3c]
```

**OpenTelemetry mode:**
```
127.0.0.1 (direct) "GET /api/users" 200 1234 45.2ms id=abc123 trace=def456 span=789xyz [otel]
```

**Log components:**
- Client IP (with proxy detection)
- Connection type (`direct` or `proxy`)
- HTTP method and path
- Status code and response size
- Request duration in milliseconds
- Request ID
- Trace ID and Span ID
- Mode indicator (`[w3c]` or `[otel]`)

---

## Global Trace Context Access

The `TraceContext` helper provides universal access to trace IDs throughout your application.

### Basic Usage
```php
use Infocyph\Webrick\Support\TraceContext;

// In controllers
$traceId = TraceContext::getTraceId();      // 32 hex chars
$spanId = TraceContext::getSpanId();        // 16 hex chars
$requestId = TraceContext::getRequestId();  // 32 hex chars

// Get all context
$context = TraceContext::getAll();
// ['trace_id' => 'abc...', 'span_id' => 'def...', 'request_id' => 'xyz...']

// Formatted for logging
$context = TraceContext::getLogContext();
// "trace=abc123 span=def456 request=xyz789"
```

### In Controllers
```php
final class UserController
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function show(int $id): Response
    {
        $this->logger->info('Fetching user', [
            'user_id' => $id,
            'trace_id' => TraceContext::getTraceId(),
            'request_id' => TraceContext::getRequestId(),
        ]);

        $user = UserRepository::find($id);
        return Response::json($user);
    }
}
```

### In Database Queries

Add trace context as SQL comments for query tracking:
```php
final class UserRepository
{
    public static function find(int $id): ?array
    {
        $context = TraceContext::getLogContext();
        
        $sql = "
            /* {$context} */
            SELECT * FROM users WHERE id = ?
        ";

        return DB::queryOne($sql, [$id]);
    }
}
```

**MySQL slow query log:**
```sql
/* trace=a4c9e2b8... span=def456... request=xyz789... */
SELECT * FROM users WHERE id = 42;
```

### In Cache Operations
```php
final class CacheService
{
    public function get(string $key): mixed
    {
        $value = Cache::get($key);

        $this->logger->debug($value ? 'Cache hit' : 'Cache miss', array_merge(
            ['key' => $key, 'hit' => $value !== null],
            TraceContext::getLogArray()  // Adds trace_id, span_id, request_id
        ));

        return $value;
    }
}
```

### In Exception Handlers
```php
final class ExceptionHandler
{
    public function handle(\Throwable $e): Response
    {
        $context = TraceContext::getAll();

        $this->logger->error('Unhandled exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace_id' => $context['trace_id'],
            'span_id' => $context['span_id'],
            'request_id' => $context['request_id'],
        ]);

        // Return trace ID to client for support
        return Response::json([
            'error' => 'Internal server error',
            'trace_id' => $context['trace_id'],
            'request_id' => $context['request_id'],
            'message' => 'Please provide this trace ID to support',
        ], 500);
    }
}
```

### Propagate to External Services
```php
final class PaymentService
{
    public function charge(int $amount): array
    {
        $response = $this->http->post('https://api.payment.com/charge', [
            'headers' => TraceContext::getPropagationHeaders(),
            'json' => ['amount' => $amount],
        ]);

        return json_decode($response->getBody(), true);
    }
}
```

**Headers sent:**
```text
traceparent: 00-a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7-1234567890abcdef-01
X-Trace-Id: a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7
X-Request-Id: xyz789...
```

### Available Methods
```php
TraceContext::getTraceId();              // Trace ID (32 hex)
TraceContext::getSpanId();               // Span ID (16 hex)
TraceContext::getRequestId();            // Request ID (32 hex)
TraceContext::getParentSpanId();         // Parent span ID
TraceContext::getFlags();                // Trace flags (01 = sampled)
TraceContext::getTraceState();           // Trace state (vendor data)

TraceContext::getAll();                  // All context as array
TraceContext::getLogContext();           // Formatted string for logs
TraceContext::getLogArray();             // Array for structured logging
TraceContext::getTraceparent();          // W3C traceparent header value
TraceContext::getPropagationHeaders();   // HTTP headers for propagation

TraceContext::isAvailable();             // Check if context available
TraceContext::isOtelMode();              // Check if OTel mode active
TraceContext::isSampled();               // Check if trace is sampled
```

---

## OpenTelemetry Integration

### Automatic Detection

The middleware automatically detects OpenTelemetry SDK at runtime:
```php
// Checks for these classes:
- OpenTelemetry\API\Globals
- OpenTelemetry\API\Trace\SpanKind
- Infocyph\Webrick\Support\OpenTelemetryHandler

// If found: Full OTel mode
// If not found: Minimal W3C mode
```

**No configuration needed!** Just install the SDK and it works.

### What OpenTelemetry Mode Adds

| Feature | Minimal Mode | OTel Mode |
|---------|--------------|-----------|
| W3C Trace Context | ✅ Yes | ✅ Yes |
| Request/Trace IDs | ✅ Yes | ✅ Yes |
| Timing Headers | ✅ Yes | ✅ Yes |
| Access Logging | ✅ Yes | ✅ Yes |
| **Span Creation** | ❌ No | ✅ Yes |
| **Span Export** | ❌ No | ✅ Jaeger/Zipkin/OTLP |
| **Span Attributes** | ❌ No | ✅ HTTP semantics |
| **Exception Recording** | ❌ No | ✅ Full stack traces |
| **Distributed Tracing UI** | ❌ No | ✅ Yes |

### Span Attributes (OTel Mode)

Automatically added to spans following OpenTelemetry semantic conventions:

**HTTP attributes:**
- `http.method` - Request method (GET, POST, etc.)
- `http.target` - Request path (/api/users/42)
- `http.scheme` - Protocol (http, https)
- `http.host` - Hostname (api.example.com)
- `http.url` - Full URL
- `http.status_code` - Response status (200, 404, etc.)
- `http.user_agent` - Client user agent
- `http.route` - Route name (users.show)
- `http.request_content_length` - Request size
- `http.response_content_length` - Response size

**Network attributes:**
- `net.peer.ip` - Client IP address
- `net.host.port` - Server port
- `http.flavor` - HTTP version (1.1, 2.0)

**Custom attributes:**
- `enduser.id` - Authenticated user ID (if available)
- `enduser.role` - User role (if available)
- `client.type` - Client type (web, mobile, api)
- `api.version` - API version (if versioned)

### Observability Backend Examples

#### Jaeger UI
```
Service: my-awesome-api
Trace: a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7
├─ Span: GET users.show (45.2ms)
   ├─ http.method: GET
   ├─ http.route: users.show
   ├─ http.status_code: 200
   ├─ net.peer.ip: 192.168.1.100
   └─ enduser.id: 42
```

#### Zipkin UI
```
my-awesome-api: GET /api/users/42
Duration: 45.2ms
Tags:
  http.method: GET
  http.target: /api/users/42
  http.status_code: 200
  enduser.id: 42
```

### Force Minimal Mode

Disable OpenTelemetry even if SDK is present:
```php
new TelemetryMiddleware(
    log: $logger,
    enableOtelIntegration: false  // Force minimal mode
)
```

---

## Structured Logging Integration

### Monolog Processor

Automatically add trace context to all log entries:
```php
use Infocyph\Webrick\Support\TraceContext;
use Monolog\Processor\ProcessorInterface;

final class TraceContextProcessor implements ProcessorInterface
{
    public function __invoke(array $record): array
    {
        if (TraceContext::isAvailable()) {
            $record['extra'] = array_merge(
                $record['extra'],
                TraceContext::getLogArray()
            );
            $record['extra']['otel_mode'] = TraceContext::isOtelMode();
        }

        return $record;
    }
}

// Register with Monolog
$logger->pushProcessor(new TraceContextProcessor());
```

**Every log entry now includes:**
```json
{
  "message": "User login successful",
  "level": "info",
  "extra": {
    "trace_id": "a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7",
    "span_id": "1234567890abcdef",
    "request_id": "xyz789...",
    "otel_mode": false
  }
}
```

### ELK Stack (Elasticsearch + Kibana)

**Search all logs from a single request:**
```
trace_id:"a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7"
```

**Returns:**
- Controller logs
- Repository/database logs
- Cache operation logs
- External API call logs
- Error logs

**All correlated by trace ID!**

---

## Use Cases

### 1. User Support Tickets
```php
// Error response includes trace ID
return Response::json([
    'error' => 'Payment failed',
    'trace_id' => TraceContext::getTraceId(),
    'message' => 'Please provide this ID to support'
], 500);
```

**User reports:** "My payment failed, trace ID: a4c9e2b8..."

**Support searches:**
```
trace_id:"a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7"
```

**Finds:** Complete request flow with all logs!

### 2. Performance Debugging

**Slow query log:**
```sql
/* trace=a4c9e2b8... span=def456... request=xyz789... */
SELECT * FROM orders WHERE status = 'pending' AND created_at > '2024-01-01';
Duration: 2.5 seconds
```

**Correlation:**
1. Find slow query in database logs
2. Extract trace ID from SQL comment
3. Search application logs for that trace ID
4. See what triggered the slow query

### 3. Distributed Tracing

**Service A (API Gateway):**
```php
// Middleware generates trace context
// trace_id: a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7
// span_id: 1234567890abcdef
```

**Service B (User Service):**
```php
// Receives traceparent header
// Creates child span with same trace_id
// New span_id: fedcba0987654321
```

**Service C (Payment Service):**
```php
// Receives traceparent from Service B
// Creates child span with same trace_id
// New span_id: abcd1234efgh5678
```

**Result:** Complete trace across all services in Jaeger/Zipkin!

### 4. Error Rate Monitoring

**Prometheus query:**
```promql
rate(http_requests_total{status=~"5.."}[5m])
```

**Spike detected → Trace IDs from logs → Root cause analysis**

---

## Performance

### Overhead

**Minimal mode:**
- ~0.1ms per request
- ~100 bytes memory

**OpenTelemetry mode:**
- ~1-2ms per request
- ~100KB memory per span
- Async export (non-blocking)

### Optimization

**Use batch span processor in production:**
```php
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;

$processor = new BatchSpanProcessor($exporter, [
    'maxQueueSize' => 2048,
    'scheduledDelayMillis' => 5000,
    'exportTimeoutMillis' => 30000,
    'maxExportBatchSize' => 512,
]);
```

**Sampling:**
```php
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;

// Sample 10% of traces
$sampler = new ParentBased(
    new TraceIdRatioBasedSampler(0.1)
);
```

---

## Disabling Features

### Minimal Overhead Configuration

For maximum performance when you only need basic trace correlation:

```php
$preGlobal = [
    new TelemetryMiddleware(
        log: $logger,
        addXResponseTime: false,      // No timing header
        addServerTiming: false,       // No server-timing
        emitRequestId: false,         // No request ID header
        emitTraceIdHeader: false,     // No trace header
        enableOtelIntegration: false  // No OTel overhead
    )
];
```

This configuration:
- ✅ Still generates trace IDs internally
- ✅ Still makes TraceContext available
- ✅ Still logs access with trace correlation
- ❌ Doesn't emit response headers
- ❌ Doesn't create OpenTelemetry spans

### Disable Specific Features

```php
// Disable only timing headers (keep trace context)
new TelemetryMiddleware(
    log: $logger,
    addXResponseTime: false,
    addServerTiming: false
)

// Disable Network Error Logging
new TelemetryMiddleware(
    log: $logger,
    nelGroup: null,      // Disables NEL
    nelEndpoint: null
)

// Disable request ID propagation (but keep trace IDs)
new TelemetryMiddleware(
    log: $logger,
    emitRequestId: false
)
```

---

## Troubleshooting

### Check OpenTelemetry Mode
```php
use Infocyph\Webrick\Support\TraceContext;

if (TraceContext::isOtelMode()) {
    echo "✅ OpenTelemetry mode active\n";
} else {
    echo "ℹ️ Minimal mode (W3C only)\n";
}
```

### Verify Trace Context
```php
use Infocyph\Webrick\Support\TraceContext;

var_dump([
    'available' => TraceContext::isAvailable(),
    'trace_id' => TraceContext::getTraceId(),
    'span_id' => TraceContext::getSpanId(),
    'request_id' => TraceContext::getRequestId(),
    'otel_mode' => TraceContext::isOtelMode(),
]);
```

### Debug OpenTelemetry Setup
```bash
# Check if SDK is installed
composer show open-telemetry/sdk

# Check class availability
php -r "var_dump(class_exists('OpenTelemetry\\API\\Globals'));"

# Check tracer provider
php -r "var_dump(\OpenTelemetry\API\Globals::tracerProvider());"
```

---

## Best Practices

### 1. Middleware Order (CRITICAL)

**⚠️ ALWAYS place TelemetryMiddleware FIRST or VERY EARLY in preGlobal:**

```php
// ✅ Correct - Telemetry captures everything
$preGlobal = [
    TelemetryMiddleware::class,           // FIRST - captures all timing
    GatewayHardeningMiddleware::class,
    MaintenanceModeMiddleware::class,
    RequestLimitsMiddleware::class,
    // ... other middleware
];

// ❌ Wrong - Misses early middleware timing
$preGlobal = [
    GatewayHardeningMiddleware::class,
    MaintenanceModeMiddleware::class,
    TelemetryMiddleware::class,           // TOO LATE
];
```

**Why first?**
- Starts timing before any other processing
- Wraps all middleware in try-catch for exception tracking (OTel mode)
- Initializes TraceContext before other middleware/controllers need it
- Captures complete request lifecycle in timing headers

### 1. Always Use in preGlobal

### 1. Always Use in preGlobal
```php
// ✅ Correct - Captures full request lifecycle
$preGlobal = [
    TelemetryMiddleware::class,
    // ... other middleware
];

// ❌ Wrong - Misses pre-processing time
$postGlobal = [
    TelemetryMiddleware::class,
];
```

### 3. Use TraceContext Everywhere
```php
// ✅ Good - Consistent correlation
$logger->info('Action', [
    'user_id' => $userId,
    'trace_id' => TraceContext::getTraceId(),
]);

// ❌ Bad - No correlation
$logger->info('Action', ['user_id' => $userId]);
```

### 4. Add Trace Context to SQL Comments
```php
// ✅ Good - Queryable in slow query log
$sql = "/* " . TraceContext::getLogContext() . " */ SELECT * FROM users";

// ❌ Bad - Can't correlate slow queries
$sql = "SELECT * FROM users";
```

### 5. Return Trace IDs in Error Responses
```php
// ✅ Good - User can report trace ID for support
return Response::json([
    'error' => 'Something went wrong',
    'trace_id' => TraceContext::getTraceId(),
], 500);

// ❌ Bad - No way to find error in logs
return Response::json(['error' => 'Something went wrong'], 500);
```

### 6. Enable OpenTelemetry in Staging/Production
```php
// Development: Minimal mode (faster)
if ($_ENV['APP_ENV'] === 'development') {
    $middleware = new TelemetryMiddleware(
        log: $logger,
        enableOtelIntegration: false
    );
} else {
    // Staging/Production: Full observability
    $middleware = new TelemetryMiddleware(
        log: $logger,
        enableOtelIntegration: true
    );
}
```

---

## See Also

- [W3C Trace Context Specification](https://www.w3.org/TR/trace-context/)
- [OpenTelemetry PHP Documentation](https://opentelemetry.io/docs/instrumentation/php/)
- [OpenTelemetry Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/http/)
- [Network Error Logging (NEL)](https://www.w3.org/TR/network-error-logging/)
- [Server Timing API](https://www.w3.org/TR/server-timing/)
