<?php
// tests/Feature/TelemetryMiddlewareTest.php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Support\TraceContext;

beforeEach(function () {
    TraceContext::clear();
    $this->logger = new TestLogger();
});

afterEach(function () {
    TraceContext::clear();
});

describe('TelemetryMiddleware - End-to-End Integration', function () {
    test('complete request lifecycle with telemetry', function () {
        $kernel = new RouterKernel();

        // Set pre-global middleware (matches your index.php pattern)
        $preGlobal = [
            new TelemetryMiddleware($this->logger)
        ];

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('preGlobal');
        $property->setAccessible(true);
        $property->setValue($kernel, $preGlobal);

        $kernel->get('/users/{id:int}', fn($r, int $id) => Response::json([
            'id' => $id,
            'name' => 'John Doe',
            'trace_id' => TraceContext::getTraceId(),
            'request_id' => TraceContext::getRequestId()
        ]));

        $request = Request::create('GET', '/users/42');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);
        expect($response->hasHeader('X-Response-Time'))->toBeTrue();
        expect($response->hasHeader('X-Request-Id'))->toBeTrue();
        expect($response->hasHeader('Trace-Id'))->toBeTrue();

        $data = json_decode((string) $response->getBody(), true);
        expect($data['id'])->toBe(42);
        expect($data['trace_id'])->toHaveLength(32);
        expect($data['request_id'])->toHaveLength(32);
    });

    test('trace context available in controllers', function () {
        $kernel = new RouterKernel();

        $preGlobal = [
            new TelemetryMiddleware($this->logger)
        ];

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('preGlobal');
        $property->setAccessible(true);
        $property->setValue($kernel, $preGlobal);

        $kernel->get('/api/test', function () {
            return Response::json([
                'trace_available' => TraceContext::isAvailable(),
                'trace_id' => TraceContext::getTraceId(),
                'span_id' => TraceContext::getSpanId(),
                'request_id' => TraceContext::getRequestId(),
                'log_context' => TraceContext::getLogContext(),
                'propagation_headers' => TraceContext::getPropagationHeaders()
            ]);
        });

        $request = Request::create('GET', '/api/test');
        $response = $kernel->handle($request);
        $data = json_decode((string) $response->getBody(), true);

        expect($data['trace_available'])->toBeTrue();
        expect($data['trace_id'])->not->toBeNull();
        expect($data['span_id'])->not->toBeNull();
        expect($data['request_id'])->not->toBeNull();
        expect($data['log_context'])->toContain('trace=');
        expect($data['propagation_headers'])->toHaveKey('traceparent');
        expect($data['propagation_headers'])->toHaveKey('X-Trace-Id');
    });

    test('distributed tracing with incoming traceparent', function () {
        $kernel = new RouterKernel();

        $preGlobal = [
            new TelemetryMiddleware($this->logger, respectIncomingTraceparent: true)
        ];

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('preGlobal');
        $property->setAccessible(true);
        $property->setValue($kernel, $preGlobal);

        $kernel->get('/api/service-b', function () {
            return Response::json([
                'service' => 'B',
                'trace_id' => TraceContext::getTraceId(),
                'span_id' => TraceContext::getSpanId(),
                'parent_span_id' => TraceContext::getParentSpanId()
            ]);
        });

        $incomingTraceId = str_repeat('a', 32);
        $incomingSpanId = str_repeat('b', 16);

        $request = Request::create('GET', '/api/service-b', headers: [
            'traceparent' => "00-{$incomingTraceId}-{$incomingSpanId}-01"
        ]);

        $response = $kernel->handle($request);
        $data = json_decode((string) $response->getBody(), true);

        // Same trace ID (distributed trace)
        expect($data['trace_id'])->toBe($incomingTraceId);

        // New span ID (child span)
        expect($data['span_id'])->not->toBe($incomingSpanId);
        expect($data['span_id'])->toHaveLength(16);

        // Parent span ID preserved
        expect($data['parent_span_id'])->toBe($incomingSpanId);
    });
});
