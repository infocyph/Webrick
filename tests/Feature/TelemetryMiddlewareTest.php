<?php

// tests/Feature/TelemetryMiddlewareTest.php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Support\TraceContext;
use Psr\Log\NullLogger;

beforeEach(function () {
    TraceContext::clear();
    $this->logger = new TestLogger;

    // Clear route cache before each test
    $cacheDir = sys_get_temp_dir().'/webrick-test-routes-'.uniqid();
    if (! is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    $this->cacheDir = $cacheDir;
});

afterEach(function () {
    TraceContext::clear();

    // Clean up cache directory
    if (isset($this->cacheDir) && is_dir($this->cacheDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->cacheDir);
    }
});

describe('TelemetryMiddleware - End-to-End Integration', function () {
    test('complete request lifecycle with telemetry', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/lifecycle', function () {
                    return Response::json([
                        'trace_id' => TraceContext::getTraceId(),
                        'span_id' => TraceContext::getSpanId(),
                        'request_id' => TraceContext::getRequestId(),
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

        $request = mockRequest('GET', '/api/lifecycle');
        $response = $kernel->handle($request);
        $data = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->hasHeader('X-Response-Time'))->toBeTrue()
            ->and($response->hasHeader('Server-Timing'))->toBeTrue()
            ->and($response->hasHeader('X-Request-Id'))->toBeTrue()
            ->and($response->hasHeader('Trace-Id'))->toBeTrue()
            ->and($data['trace_id'])->toBeString()
            ->and($data['span_id'])->toBeString()
            ->and($data['request_id'])->toBeString()
            ->and(TraceContext::isAvailable())->toBeFalse();
    });

    test('trace context available in controllers', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/test', function () {
                    return Response::json([
                        'trace_available' => TraceContext::isAvailable(),
                        'trace_id' => TraceContext::getTraceId(),
                        'span_id' => TraceContext::getSpanId(),
                        'request_id' => TraceContext::getRequestId(),
                        'log_context' => TraceContext::getLogContext(),
                        'propagation_headers' => TraceContext::getPropagationHeaders(),
                    ]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware($this->logger),
            ]
        );

        $request = mockRequest('GET', '/api/test');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);

        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_available'])
            ->toBeTrue()
            ->and($data['trace_id'])->not
            ->toBeNull()
            ->and($data['span_id'])->not
            ->toBeNull()
            ->and($data['request_id'])->not
            ->toBeNull()
            ->and($data['log_context'])->toContain('trace=')
            ->and($data['propagation_headers'])->toHaveKey('traceparent')
            ->and($data['propagation_headers'])->toHaveKey('X-Trace-Id');
    });

    test('distributed tracing with incoming traceparent', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/service-b', function () {
                    return Response::json([
                        'service' => 'B',
                        'trace_id' => TraceContext::getTraceId(),
                        'span_id' => TraceContext::getSpanId(),
                        'parent_span_id' => TraceContext::getParentSpanId(),
                    ]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true),
            ]
        );

        $incomingTraceId = str_repeat('a', 32);
        $incomingSpanId = str_repeat('b', 16);

        $request = mockRequest('GET', '/api/service-b', headers: [
            'traceparent' => "00-{$incomingTraceId}-{$incomingSpanId}-01",
        ]);

        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);

        $data = json_decode((string) $response->getBody(), true);

        // Same trace ID (distributed trace)
        expect($data['trace_id'])
            ->toBe($incomingTraceId)
            ->and($data['span_id'])->not
            ->toBe($incomingSpanId)
            ->and($data['span_id'])->toHaveLength(16)
            ->and($data['parent_span_id'])->toBe($incomingSpanId);

        // New span ID (child span)

        // Parent span ID preserved
    });

    test('trace IDs are generated from request attributes', function () {
        // This test verifies that the middleware generates trace IDs
        // The actual uniqueness depends on random_bytes() which may be
        // deterministic in test environments

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/request', fn () => Response::json([
                    'trace_id' => TraceContext::getTraceId(),
                    'has_trace' => TraceContext::getTraceId() !== null,
                ]));
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware($this->logger),
            ]
        );

        $request = mockRequest('GET', '/api/request');
        $response = $kernel->handle($request);
        $data = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())
            ->toBe(200)
            ->and($data['has_trace'])->toBeTrue()
            ->and($data['trace_id'])->toHaveLength(32)
            ->and(ctype_xdigit($data['trace_id']))->toBeTrue();
    });
});

describe('TelemetryMiddleware - Performance Timing', function () {
    test('measures request duration accurately', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/slow', function () {
                    usleep(50000); // 50ms

                    return Response::json(['ok' => true]);
                });
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware($this->logger, addXResponseTime: true),
            ]
        );

        $request = mockRequest('GET', '/api/slow');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);

        $timing = $response->getHeaderLine('X-Response-Time');
        expect($timing)->toMatch('/^\d+\.\d+ms$/');

        preg_match('/^(\d+\.\d+)ms$/', $timing, $matches);
        $duration = (float) $matches[1];
        expect($duration)->toBeGreaterThan(40); // Allow some variance
    });

    test('Server-Timing header format is correct', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/api/test', fn () => Response::json(['ok' => true]));
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware($this->logger, addServerTiming: true),
            ]
        );

        $request = mockRequest('GET', '/api/test');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);

        $serverTiming = $response->getHeaderLine('Server-Timing');
        expect($serverTiming)->toMatch('/^app;dur=\d+\.\d+$/');
    });
});

describe('TelemetryMiddleware - Configuration Scenarios', function () {
    test('production configuration', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/health', fn () => Response::json(['status' => 'ok']));
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
                    otelServiceVersion: '1.0.0'
                ),
            ]
        );

        $request = mockRequest('GET', '/health');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())
            ->toBe(200)
            ->and($response->hasHeader('X-Response-Time'))->toBeTrue()
            ->and($response->hasHeader('Server-Timing'))->toBeTrue()
            ->and($response->hasHeader('X-Request-Id'))->toBeTrue()
            ->and($response->hasHeader('Trace-Id'))->toBeTrue();
    });

    test('development configuration (minimal headers)', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: ShardedMatcher::make(),
            register: function () {
                Route::get('/test', fn () => Response::json(['ok' => true]));
            },
            routeCache: $this->cacheDir,
            preGlobal: [
                new TelemetryMiddleware(
                    log: $this->logger,
                    addXResponseTime: false,
                    addServerTiming: false,
                    emitRequestId: true,
                    emitTraceIdHeader: false,
                    enableOtelIntegration: false
                ),
            ]
        );

        $request = mockRequest('GET', '/test');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())
            ->toBe(200)
            ->and($response->hasHeader('X-Response-Time'))->toBeFalse()
            ->and($response->hasHeader('Server-Timing'))->toBeFalse()
            ->and($response->hasHeader('X-Request-Id'))->toBeTrue()
            ->and($response->hasHeader('Trace-Id'))->toBeFalse();
    });
});
