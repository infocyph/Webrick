<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\TraceContext;

beforeEach(function () {
    TraceContext::clear();
    $this->logger = new TestLogger;
});

afterEach(function () {
    TraceContext::clear();
});

describe('TelemetryMiddleware - Basic Functionality', function () {
    test('adds X-Response-Time header', function () {
        $middleware = new TelemetryMiddleware($this->logger, addXResponseTime: true);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Response-Time'))
            ->toBeTrue()
            ->and($response->getHeaderLine('X-Response-Time'))->toMatch('/^\d+\.\d+ms$/');
    });

    test('adds Server-Timing header', function () {
        $middleware = new TelemetryMiddleware($this->logger, addServerTiming: true);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Server-Timing'))
            ->toBeTrue()
            ->and($response->getHeaderLine('Server-Timing'))->toMatch('/^app;dur=\d+\.\d+$/');
    });

    test('does not add headers when disabled', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            addXResponseTime: false,
            addServerTiming: false
        );

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Response-Time'))
            ->toBeFalse()
            ->and($response->hasHeader('Server-Timing'))->toBeFalse();
    });
});

describe('TelemetryMiddleware - Request ID', function () {
    test('generates request ID when missing', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitRequestId: true);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Request-Id'))->toBeTrue();
        $requestId = $response->getHeaderLine('X-Request-Id');
        expect($requestId)
            ->toHaveLength(32)
            ->and(ctype_xdigit($requestId))->toBeTrue();
    });

    test('respects existing request ID', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            emitRequestId: true,
            respectExistingRequestId: true
        );

        $existingId = 'existing-request-id-12345678';
        $request = mockRequest('GET', '/test', headers: [
            'X-Request-Id' => $existingId,
        ]);
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->getHeaderLine('X-Request-Id'))->toBe($existingId);
    });

    test('generates new ID when not respecting existing', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            emitRequestId: true,
            respectExistingRequestId: false
        );

        $existingId = 'existing-request-id-12345678';
        $request = mockRequest('GET', '/test', headers: [
            'X-Request-Id' => $existingId,
        ]);
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->getHeaderLine('X-Request-Id'))->not
            ->toBe($existingId)
            ->and($response->getHeaderLine('X-Request-Id'))->toHaveLength(32);
    });

    test('uses custom request ID header name', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            emitRequestId: true,
            requestIdHeader: 'X-Custom-Request-Id'
        );

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Custom-Request-Id'))
            ->toBeTrue()
            ->and($response->hasHeader('X-Request-Id'))->toBeFalse();
    });
});

describe('TelemetryMiddleware - Trace Context', function () {
    test('generates trace ID when missing', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn ($r) => Response::json([
            'trace_id' => $r->getAttribute('trace.trace_id'),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])
            ->toHaveLength(32)
            ->and(ctype_xdigit($data['trace_id']))->toBeTrue();
    });

    test('generates span ID for each request', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn ($r) => Response::json([
            'span_id' => $r->getAttribute('trace.span_id'),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['span_id'])
            ->toHaveLength(16)
            ->and(ctype_xdigit($data['span_id']))->toBeTrue();
    });

    test('respects incoming traceparent header', function () {
        $middleware = new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true);

        $incomingTraceId = str_repeat('a', 32);
        $incomingSpanId = str_repeat('b', 16);
        $request = mockRequest('GET', '/test', headers: [
            'traceparent' => "00-{$incomingTraceId}-{$incomingSpanId}-01",
        ]);
        $next = fn ($r) => Response::json([
            'trace_id' => $r->getAttribute('trace.trace_id'),
            'parent_span_id' => $r->getAttribute('trace.parent_span_id'),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])
            ->toBe($incomingTraceId)
            ->and($data['parent_span_id'])->toBe($incomingSpanId);
    });

    test('validates traceparent format', function () {
        $middleware = new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true);

        $request = mockRequest('GET', '/test', headers: [
            'traceparent' => 'invalid-format',
        ]);
        $next = fn ($r) => Response::json([
            'trace_id' => $r->getAttribute('trace.trace_id'),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        // Should generate new trace ID due to invalid format
        expect($data['trace_id'])
            ->toHaveLength(32)
            ->and($data['trace_id'])->not->toBe('invalid-format');
    });

    test('emits Trace-Id header in response', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitTraceIdHeader: true);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Trace-Id'))
            ->toBeTrue()
            ->and($response->getHeaderLine('Trace-Id'))->toHaveLength(32);
    });

    test('emits traceparent header when enabled', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitTraceparentHeader: true);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('traceparent'))
            ->toBeTrue()
            ->and($response->getHeaderLine('traceparent'))->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/');
    });

    test('preserves tracestate header', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitTraceparentHeader: true);

        $tracestate = 'congo=ucfJifl5GOE,rojo=00f067aa0ba902b7';
        $request = mockRequest('GET', '/test', headers: [
            'traceparent' => '00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01',
            'tracestate' => $tracestate,
        ]);
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('tracestate'))
            ->toBeTrue()
            ->and($response->getHeaderLine('tracestate'))->toBe($tracestate);
    });
});

describe('TelemetryMiddleware - Network Error Logging (NEL)', function () {
    test('adds NEL headers when configured', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            nelGroup: 'default',
            nelEndpoint: 'https://nel.example.com/report'
        );

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('NEL'))
            ->toBeTrue()
            ->and($response->hasHeader('Report-To'))->toBeTrue();

        $nel = json_decode($response->getHeaderLine('NEL'), true);
        expect($nel['group'])
            ->toBe('default')
            ->and($nel['max_age'])->toBe(86400);

        $reportTo = json_decode($response->getHeaderLine('Report-To'), true);
        expect($reportTo['endpoints'][0]['url'])->toBe('https://nel.example.com/report');
    });

    test('does not add NEL headers when not configured', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('NEL'))
            ->toBeFalse()
            ->and($response->hasHeader('Report-To'))->toBeFalse();
    });
});

describe('TelemetryMiddleware - Access Logging', function () {
    test('logs request with trace context', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $middleware($request, $next);

        expect($this->logger->hasInfoRecords())->toBeTrue();
        $record = $this->logger->records[0];
        expect($record['message'])
            ->toContain('GET /test')
            ->and($record['message'])->toContain('200')
            ->and($record['message'])->toContain('trace=')
            ->and($record['message'])->toContain('span=')
            ->and($record['message'])->toContain('[w3c]');
    });

    test('logs correct HTTP method and path', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('POST', '/api/users');
        $next = fn () => Response::json(['id' => 1], 201);

        $middleware($request, $next);

        $record = $this->logger->records[0];
        expect($record['message'])
            ->toContain('POST /api/users')
            ->and($record['message'])->toContain('201');
    });
});

describe('TelemetryMiddleware - TraceContext Integration', function () {
    test('initializes TraceContext for application use', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json([
            'trace_available' => TraceContext::isAvailable(),
            'trace_id' => TraceContext::getTraceId(),
            'span_id' => TraceContext::getSpanId(),
            'request_id' => TraceContext::getRequestId(),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_available'])
            ->toBeTrue()
            ->and($data['trace_id'])->toHaveLength(32)
            ->and($data['span_id'])->toHaveLength(16)
            ->and($data['request_id'])->not
            ->toBeNull()
            ->and($data['request_id'])->not->toBeEmpty();
        // Request ID might be variable length, just check it exists
    });

    test('clears TraceContext after request', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $middleware($request, $next);

        // After middleware execution, TraceContext should be cleared
        expect(TraceContext::isAvailable())
            ->toBeFalse()
            ->and(TraceContext::getTraceId())->toBeNull();
    });
});

describe('TelemetryMiddleware - Exception Handling', function () {
    test('clears TraceContext even when exception occurs', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn () => throw new RuntimeException('Test exception');

        try {
            $middleware($request, $next);
        } catch (RuntimeException $e) {
            // Expected
        }

        // TraceContext should be cleared even after exception
        expect(TraceContext::isAvailable())->toBeFalse();
    });
});

describe('TelemetryMiddleware - OpenTelemetry Detection', function () {
    test('detects minimal mode when OTel SDK not available', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json([
            'otel_mode' => TraceContext::isOtelMode(),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        // In test environment without OTel SDK
        expect($data['otel_mode'])->toBeFalse();
    });

    test('can force minimal mode', function () {
        $middleware = new TelemetryMiddleware($this->logger, enableOtelIntegration: false);

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json([
            'otel_mode' => TraceContext::isOtelMode(),
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['otel_mode'])->toBeFalse();
    });
});
