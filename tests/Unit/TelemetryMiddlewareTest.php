<?php
// tests/Unit/TelemetryMiddlewareTest.php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\TraceContext;

beforeEach(function () {
    TraceContext::clear();
    $this->logger = new TestLogger();
});

afterEach(function () {
    TraceContext::clear();
});

describe('TelemetryMiddleware - Basic Functionality', function () {
    test('adds X-Response-Time header', function () {
        $middleware = new TelemetryMiddleware($this->logger, addXResponseTime: true);

        $request = Request::create('GET', '/test');
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Response-Time'))->toBeTrue();
        expect($response->getHeaderLine('X-Response-Time'))->toMatch('/^\d+\.\d+ms$/');
    });

    test('adds Server-Timing header', function () {
        $middleware = new TelemetryMiddleware($this->logger, addServerTiming: true);

        $request = Request::create('GET', '/test');
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Server-Timing'))->toBeTrue();
        expect($response->getHeaderLine('Server-Timing'))->toMatch('/^app;dur=\d+\.\d+$/');
    });

    test('does not add headers when disabled', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            addXResponseTime: false,
            addServerTiming: false
        );

        $request = Request::create('GET', '/test');
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Response-Time'))->toBeFalse();
        expect($response->hasHeader('Server-Timing'))->toBeFalse();
    });
});

describe('TelemetryMiddleware - Request ID', function () {
    test('generates request ID when missing', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitRequestId: true);

        $request = Request::create('GET', '/test');
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('X-Request-Id'))->toBeTrue();
        $requestId = $response->getHeaderLine('X-Request-Id');
        expect($requestId)->toHaveLength(32);
        expect(ctype_xdigit($requestId))->toBeTrue();
    });

    test('respects existing request ID', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            emitRequestId: true,
            respectExistingRequestId: true
        );

        $existingId = 'existing-request-id-12345678';
        $request = Request::create('GET', '/test', headers: [
            'X-Request-Id' => $existingId
        ]);
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->getHeaderLine('X-Request-Id'))->toBe($existingId);
    });
});

describe('TelemetryMiddleware - Trace Context', function () {
    test('generates trace ID when missing', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = Request::create('GET', '/test');
        $next = fn(Request $r) => Response::json([
            'trace_id' => $r->getAttribute('trace.trace_id')
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])->toHaveLength(32);
        expect(ctype_xdigit($data['trace_id']))->toBeTrue();
    });

    test('respects incoming traceparent header', function () {
        $middleware = new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true);

        $incomingTraceId = str_repeat('a', 32);
        $incomingSpanId = str_repeat('b', 16);
        $request = Request::create('GET', '/test', headers: [
            'traceparent' => "00-{$incomingTraceId}-{$incomingSpanId}-01"
        ]);
        $next = fn(Request $r) => Response::json([
            'trace_id' => $r->getAttribute('trace.trace_id'),
            'parent_span_id' => $r->getAttribute('trace.parent_span_id')
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])->toBe($incomingTraceId);
        expect($data['parent_span_id'])->toBe($incomingSpanId);
    });
});

describe('TelemetryMiddleware - Access Logging', function () {
    test('logs request with trace context', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = Request::create('GET', '/test');
        $next = fn() => Response::json(['ok' => true]);

        $middleware($request, $next);

        expect($this->logger->hasInfoRecords())->toBeTrue();
        $record = $this->logger->records[0];
        expect($record['message'])->toContain('GET /test');
        expect($record['message'])->toContain('200');
        expect($record['message'])->toContain('trace=');
        expect($record['message'])->toContain('span=');
        expect($record['message'])->toContain('[w3c]');
    });
});

describe('TelemetryMiddleware - TraceContext Integration', function () {
    test('initializes TraceContext for application use', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = Request::create('GET', '/test');
        $next = fn(Request $r) => Response::json([
            'trace_available' => TraceContext::isAvailable(),
            'trace_id' => TraceContext::getTraceId(),
            'span_id' => TraceContext::getSpanId(),
            'request_id' => TraceContext::getRequestId()
        ]);

        $response = $middleware($request, $next);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_available'])->toBeTrue();
        expect($data['trace_id'])->toHaveLength(32);
        expect($data['span_id'])->toHaveLength(16);
        expect($data['request_id'])->toHaveLength(32);
    });

    test('clears TraceContext after request', function () {
        $middleware = new TelemetryMiddleware($this->logger);

        $request = Request::create('GET', '/test');
        $next = fn() => Response::json(['ok' => true]);

        $middleware($request, $next);

        // After middleware execution, TraceContext should be cleared
        expect(TraceContext::isAvailable())->toBeFalse();
        expect(TraceContext::getTraceId())->toBeNull();
    });
});
