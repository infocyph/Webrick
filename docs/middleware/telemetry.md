
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
