<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Support\TraceContext;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->logger = new TestLogger;

    $cacheDir = sys_get_temp_dir() . '/webrick-test-routes-' . uniqid();
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    $this->cacheDir = $cacheDir;
});

afterEach(function () {
    if (isset($this->cacheDir) && is_dir($this->cacheDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->cacheDir);
    }
});

describe('TelemetryMiddleware - End-to-End Integration', function () {
    test('complete request lifecycle with explicit request context', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/lifecycle', function (Request $request) {
                    $context = TraceContext::require($request);

                    return Response::json([
                        'trace_id' => $context->traceId(),
                        'span_id' => $context->spanId(),
                        'request_id' => $context->requestId(),
                    ]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware(
                    log: $this->logger,
                    addXResponseTime: true,
                    addServerTiming: true,
                    emitRequestId: true,
                    emitTraceIdHeader: true,
                ),
            ],
        );

        $response = $kernel->handle(mockRequest('GET', '/api/lifecycle'));
        $data = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->hasHeader('X-Response-Time'))->toBeTrue()
            ->and($response->hasHeader('Server-Timing'))->toBeTrue()
            ->and($response->hasHeader('X-Request-Id'))->toBeTrue()
            ->and($response->hasHeader('Trace-Id'))->toBeTrue()
            ->and($data['trace_id'])->toBeString()
            ->and($data['span_id'])->toBeString()
            ->and($data['request_id'])->toBeString();
    });

    test('explicit trace context helpers are available in handlers', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/test', function (Request $request) {
                    $context = TraceContext::require($request);

                    return Response::json([
                        'trace_id' => $context->traceId(),
                        'span_id' => $context->spanId(),
                        'request_id' => $context->requestId(),
                        'log_context' => $context->logContext(),
                        'propagation_headers' => $context->propagationHeaders(),
                    ]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [new TelemetryMiddleware($this->logger)],
        );

        $response = $kernel->handle(mockRequest('GET', '/api/test'));
        $data = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($data['trace_id'])->not->toBeNull()
            ->and($data['span_id'])->not->toBeNull()
            ->and($data['request_id'])->not->toBeNull()
            ->and($data['log_context'])->toContain('trace=')
            ->and($data['propagation_headers'])->toHaveKey('traceparent')
            ->and($data['propagation_headers'])->toHaveKey('X-Trace-Id');
    });

    test('distributed tracing preserves trace and parent span', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/service-b', function (Request $request) {
                    $context = TraceContext::require($request);

                    return Response::json([
                        'trace_id' => $context->traceId(),
                        'span_id' => $context->spanId(),
                        'parent_span_id' => $context->parentSpanId(),
                    ]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true)],
        );

        $incomingTraceId = str_repeat('a', 32);
        $incomingSpanId = str_repeat('b', 16);
        $response = $kernel->handle(mockRequest('GET', '/api/service-b', headers: [
            'traceparent' => "00-{$incomingTraceId}-{$incomingSpanId}-01",
        ]));
        $data = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($data['trace_id'])->toBe($incomingTraceId)
            ->and($data['span_id'])->not->toBe($incomingSpanId)
            ->and($data['span_id'])->toHaveLength(16)
            ->and($data['parent_span_id'])->toBe($incomingSpanId);
    });

    test('trace IDs are generated per request context', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/request', function (Request $request) {
                    $traceId = TraceContext::require($request)->traceId();

                    return Response::json([
                        'trace_id' => $traceId,
                        'has_trace' => $traceId !== null,
                    ]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [new TelemetryMiddleware($this->logger)],
        );

        $response = $kernel->handle(mockRequest('GET', '/api/request'));
        $data = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($data['has_trace'])->toBeTrue()
            ->and($data['trace_id'])->toHaveLength(32)
            ->and(preg_match('/\A[0-9a-f]{32}\z/D', $data['trace_id']))->toBe(1);
    });
});

describe('TelemetryMiddleware - Performance Timing', function () {
    test('measures request duration accurately', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/slow', function () {
                    usleep(50000);

                    return Response::json(['ok' => true]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [new TelemetryMiddleware($this->logger, addXResponseTime: true)],
        );

        $response = $kernel->handle(mockRequest('GET', '/api/slow'));
        $timing = $response->getHeaderLine('X-Response-Time');
        preg_match('/^(\d+\.\d+)ms$/', $timing, $matches);

        expect($response->getStatusCode())->toBe(200)
            ->and($timing)->toMatch('/^\d+\.\d+ms$/')
            ->and((float) $matches[1])->toBeGreaterThan(40);
    });

    test('Server-Timing header format is correct', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/test', fn() => Response::json(['ok' => true]));
            },
            routeCache: $this->cacheDir,
            preGlobal: [new TelemetryMiddleware($this->logger, addServerTiming: true)],
        );

        $response = $kernel->handle(mockRequest('GET', '/api/test'));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Server-Timing'))->toMatch('/^app;dur=\d+\.\d+$/');
    });
});

describe('TelemetryMiddleware - Configuration Scenarios', function () {
    test('production configuration', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/health', fn() => Response::json(['status' => 'ok']));
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware(
                    log: $this->logger,
                    addXResponseTime: true,
                    addServerTiming: true,
                    emitRequestId: true,
                    respectExistingRequestId: true,
                    emitTraceIdHeader: true,
                    respectIncomingTraceparent: true,
                    enableOtelIntegration: true,
                    otelServiceName: 'production-api',
                    otelServiceVersion: '1.0.0',
                ),
            ],
        );

        $response = $kernel->handle(mockRequest('GET', '/health'));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->hasHeader('X-Response-Time'))->toBeTrue()
            ->and($response->hasHeader('Server-Timing'))->toBeTrue()
            ->and($response->hasHeader('X-Request-Id'))->toBeTrue()
            ->and($response->hasHeader('Trace-Id'))->toBeTrue();
    });

    test('development configuration with minimal response telemetry', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/test', fn() => Response::json(['ok' => true]));
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware(
                    log: $this->logger,
                    addXResponseTime: false,
                    addServerTiming: false,
                    emitRequestId: true,
                    emitTraceIdHeader: false,
                    enableOtelIntegration: false,
                ),
            ],
        );

        $response = $kernel->handle(mockRequest('GET', '/test'));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->hasHeader('X-Response-Time'))->toBeFalse()
            ->and($response->hasHeader('Server-Timing'))->toBeFalse()
            ->and($response->hasHeader('X-Request-Id'))->toBeTrue()
            ->and($response->hasHeader('Trace-Id'))->toBeFalse();
    });
});
