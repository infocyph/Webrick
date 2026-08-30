<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\TelemetryOptions;
use Infocyph\Webrick\Support\TraceContext;

beforeEach(function () {
    $this->logger = new TestLogger;
});

describe('TelemetryMiddleware - response telemetry', function () {
    test('adds timing headers', function () {
        $middleware = new TelemetryMiddleware($this->logger);
        $response = $middleware(mockRequest('GET', '/test'), fn() => Response::json(['ok' => true]));

        expect($response->getHeaderLine('X-Response-Time'))->toMatch('/^\d+\.\d+ms$/')
            ->and($response->getHeaderLine('Server-Timing'))->toMatch('/^app;dur=\d+\.\d+$/');
    });

    test('does not add timing headers when disabled', function () {
        $middleware = new TelemetryMiddleware(
            $this->logger,
            addXResponseTime: false,
            addServerTiming: false,
        );
        $response = $middleware(mockRequest('GET', '/test'), fn() => Response::json(['ok' => true]));

        expect($response->hasHeader('X-Response-Time'))->toBeFalse()
            ->and($response->hasHeader('Server-Timing'))->toBeFalse();
    });

    test('can be built from telemetry options', function () {
        $middleware = TelemetryMiddleware::fromOptions(new TelemetryOptions(
            log: $this->logger,
            requestIdHeader: 'Request-Id',
        ));
        $response = $middleware(mockRequest('GET', '/test'), fn() => Response::noContent());

        expect($middleware->options()->requestIdHeader)->toBe('Request-Id')
            ->and($response->hasHeader('Request-Id'))->toBeTrue();
    });
});

describe('TelemetryMiddleware - request IDs', function () {
    test('generates a request ID when missing', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitRequestId: true);
        $response = $middleware(mockRequest('GET', '/test'), fn() => Response::noContent());
        $requestId = $response->getHeaderLine('X-Request-Id');

        expect($requestId)->toHaveLength(32)
            ->and(preg_match('/\A[0-9a-f]{32}\z/D', $requestId))->toBe(1);
    });

    test('respects a valid existing request ID', function () {
        $middleware = new TelemetryMiddleware($this->logger, respectExistingRequestId: true);
        $response = $middleware(
            mockRequest('GET', '/test', ['X-Request-Id' => 'existing-request-id-12345678']),
            fn() => Response::noContent(),
        );

        expect($response->getHeaderLine('X-Request-Id'))->toBe('existing-request-id-12345678');
    });

    test('generates a new request ID when existing IDs are not respected', function () {
        $middleware = new TelemetryMiddleware($this->logger, respectExistingRequestId: false);
        $response = $middleware(
            mockRequest('GET', '/test', ['X-Request-Id' => 'existing-request-id-12345678']),
            fn() => Response::noContent(),
        );

        expect($response->getHeaderLine('X-Request-Id'))->not->toBe('existing-request-id-12345678')
            ->and($response->getHeaderLine('X-Request-Id'))->toHaveLength(32);
    });
});

describe('TelemetryMiddleware - explicit trace context', function () {
    test('attaches request-local context before invoking the application', function () {
        $middleware = new TelemetryMiddleware($this->logger);
        $response = $middleware(
            mockRequest('GET', '/test'),
            function (Request $request): Response {
                $context = TraceContext::require($request);

                return Response::json([
                    'trace_id' => $context->traceId(),
                    'span_id' => $context->spanId(),
                    'request_id' => $context->requestId(),
                    'otel' => $context->otelAvailable(),
                ]);
            },
        );
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])->toHaveLength(32)
            ->and($data['span_id'])->toHaveLength(16)
            ->and($data['request_id'])->not->toBeNull()
            ->and($data['otel'])->toBeFalse();
    });

    test('respects incoming traceparent and records parent span', function () {
        $traceId = str_repeat('a', 32);
        $parentSpanId = str_repeat('b', 16);
        $middleware = new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true);
        $response = $middleware(
            mockRequest('GET', '/test', [
                'traceparent' => "00-{$traceId}-{$parentSpanId}-01",
            ]),
            function (Request $request): Response {
                $context = TraceContext::require($request);

                return Response::json([
                    'trace_id' => $context->traceId(),
                    'span_id' => $context->spanId(),
                    'parent_span_id' => $context->parentSpanId(),
                ]);
            },
        );
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])->toBe($traceId)
            ->and($data['parent_span_id'])->toBe($parentSpanId)
            ->and($data['span_id'])->not->toBe($parentSpanId);
    });

    test('invalid traceparent produces a fresh trace', function () {
        $middleware = new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true);
        $response = $middleware(
            mockRequest('GET', '/test', ['traceparent' => 'invalid-format']),
            function (Request $request): Response {
                return Response::json(['trace_id' => TraceContext::require($request)->traceId()]);
            },
        );
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_id'])->toHaveLength(32)
            ->and($data['trace_id'])->not->toBe('invalid-format');
    });

    test('independent requests receive independent contexts', function () {
        $middleware = new TelemetryMiddleware($this->logger);
        $contexts = [];

        $next = function (Request $request) use (&$contexts): Response {
            $contexts[] = TraceContext::require($request);

            return Response::noContent();
        };

        $middleware(mockRequest('GET', '/first'), $next);
        $middleware(mockRequest('GET', '/second'), $next);

        expect($contexts)->toHaveCount(2)
            ->and($contexts[0])->not->toBe($contexts[1]);
    });
});

describe('TelemetryMiddleware - propagation and logging', function () {
    test('emits traceparent and preserves tracestate when requested', function () {
        $middleware = new TelemetryMiddleware($this->logger, emitTraceparentHeader: true);
        $tracestate = 'congo=ucfJifl5GOE,rojo=00f067aa0ba902b7';
        $response = $middleware(
            mockRequest('GET', '/test', [
                'traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01',
                'tracestate' => $tracestate,
            ]),
            fn() => Response::noContent(),
        );

        expect($response->getHeaderLine('traceparent'))->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/')
            ->and($response->getHeaderLine('tracestate'))->toBe($tracestate);
    });

    test('adds NEL headers only when configured', function () {
        $configured = new TelemetryMiddleware(
            $this->logger,
            nelGroup: 'default',
            nelEndpoint: 'https://nel.example.com/report',
        );
        $plain = new TelemetryMiddleware($this->logger);

        $configuredResponse = $configured(mockRequest('GET', '/'), fn() => Response::noContent());
        $plainResponse = $plain(mockRequest('GET', '/'), fn() => Response::noContent());

        expect($configuredResponse->hasHeader('NEL'))->toBeTrue()
            ->and($configuredResponse->hasHeader('Report-To'))->toBeTrue()
            ->and($plainResponse->hasHeader('NEL'))->toBeFalse()
            ->and($plainResponse->hasHeader('Report-To'))->toBeFalse();
    });

    test('logs access with correlation data', function () {
        $middleware = new TelemetryMiddleware($this->logger);
        $middleware(mockRequest('GET', '/test'), fn() => Response::json(['ok' => true]));

        expect($this->logger->hasInfoRecords())->toBeTrue();
        $record = $this->logger->records[0];
        expect($record['message'])->toContain('GET /test')
            ->and($record['message'])->toContain('trace=')
            ->and($record['message'])->toContain('span=')
            ->and($record['message'])->toContain('[w3c]');
    });
});
