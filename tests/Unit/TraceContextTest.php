<?php

declare(strict_types=1);

use Infocyph\Webrick\Support\TraceContext;

beforeEach(function () {
    TraceContext::clear();
});

afterEach(function () {
    TraceContext::clear();
});

describe('TraceContext - Basic Functionality', function () {
    test('returns null when not initialized', function () {
        expect(TraceContext::isAvailable())
            ->toBeFalse()
            ->and(TraceContext::getTraceId())->toBeNull()
            ->and(TraceContext::getSpanId())->toBeNull()
            ->and(TraceContext::getRequestId())->toBeNull();
    });

    test('initializes and provides trace context', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123')
            ->withAttribute('trace.span_id', 'def456')
            ->withAttribute('request_id', 'xyz789');

        TraceContext::initialize($request);

        expect(TraceContext::isAvailable())
            ->toBeTrue()
            ->and(TraceContext::getTraceId())->toBe('abc123')
            ->and(TraceContext::getSpanId())->toBe('def456')
            ->and(TraceContext::getRequestId())->toBe('xyz789');
    });

    test('clears context', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);
        expect(TraceContext::isAvailable())->toBeTrue();

        TraceContext::clear();
        expect(TraceContext::isAvailable())
            ->toBeFalse()
            ->and(TraceContext::getTraceId())->toBeNull();
    });
});

describe('TraceContext - All Attributes', function () {
    test('provides all trace attributes', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'trace123')
            ->withAttribute('trace.span_id', 'span456')
            ->withAttribute('trace.parent_span_id', 'parent789')
            ->withAttribute('trace.flags', '01')
            ->withAttribute('trace.tracestate', 'congo=ucfJifl5GOE')
            ->withAttribute('request_id', 'req987');

        TraceContext::initialize($request);

        expect(TraceContext::getTraceId())
            ->toBe('trace123')
            ->and(TraceContext::getSpanId())->toBe('span456')
            ->and(TraceContext::getParentSpanId())->toBe('parent789')
            ->and(TraceContext::getFlags())->toBe('01')
            ->and(TraceContext::getTraceState())->toBe('congo=ucfJifl5GOE')
            ->and(TraceContext::getRequestId())->toBe('req987');
    });

    test('getAll returns complete context', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'trace123')
            ->withAttribute('trace.span_id', 'span456')
            ->withAttribute('request_id', 'req987');

        TraceContext::initialize($request);

        $all = TraceContext::getAll();

        expect($all)
            ->toHaveKey('trace_id')
            ->and($all)->toHaveKey('span_id')
            ->and($all)->toHaveKey('request_id')
            ->and($all['trace_id'])->toBe('trace123')
            ->and($all['span_id'])->toBe('span456')
            ->and($all['request_id'])->toBe('req987');
    });
});

describe('TraceContext - Formatted Output', function () {
    test('getLogContext returns formatted string', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123')
            ->withAttribute('trace.span_id', 'def456')
            ->withAttribute('request_id', 'xyz789');

        TraceContext::initialize($request);

        $context = TraceContext::getLogContext();

        expect($context)->toBe('trace=abc123 span=def456 request=xyz789');
    });

    test('getLogContext handles missing attributes', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);

        $context = TraceContext::getLogContext();

        expect($context)
            ->toBe('trace=abc123')
            ->and($context)->not
            ->toContain('span=')
            ->and($context)->not->toContain('request=');
    });

    test('getLogArray returns only non-null values', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123')
            ->withAttribute('request_id', 'xyz789');

        TraceContext::initialize($request);

        $array = TraceContext::getLogArray();

        expect($array)
            ->toHaveKey('trace_id')
            ->and($array)->toHaveKey('request_id')
            ->and($array)->not
            ->toHaveKey('span_id')
            ->and($array['trace_id'])->toBe('abc123')
            ->and($array['request_id'])->toBe('xyz789');
    });
});

describe('TraceContext - W3C Traceparent', function () {
    test('getTraceparent returns valid format', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16))
            ->withAttribute('trace.flags', '01');

        TraceContext::initialize($request);

        $traceparent = TraceContext::getTraceparent();

        expect($traceparent)
            ->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/')
            ->and($traceparent)->toBe('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01');
    });

    test('getTraceparent returns null when incomplete', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);

        expect(TraceContext::getTraceparent())->toBeNull();
    });

    test('getTraceparent uses default flags when missing', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16));

        TraceContext::initialize($request);

        $traceparent = TraceContext::getTraceparent();

        expect($traceparent)->toContain('-01'); // Default sampled flag
    });
});

describe('TraceContext - Propagation Headers', function () {
    test('getPropagationHeaders returns complete headers', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16))
            ->withAttribute('trace.flags', '01')
            ->withAttribute('trace.tracestate', 'congo=test')
            ->withAttribute('request_id', 'req123');

        TraceContext::initialize($request);

        $headers = TraceContext::getPropagationHeaders();

        expect($headers)
            ->toHaveKey('traceparent')
            ->and($headers)->toHaveKey('tracestate')
            ->and($headers)->toHaveKey('X-Trace-Id')
            ->and($headers)->toHaveKey('X-Request-Id')
            ->and($headers['traceparent'])->toBe('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01')
            ->and($headers['tracestate'])->toBe('congo=test')
            ->and($headers['X-Trace-Id'])->toBe(str_repeat('a', 32))
            ->and($headers['X-Request-Id'])->toBe('req123');
    });

    test('getPropagationHeaders excludes request ID when specified', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16))
            ->withAttribute('request_id', 'req123');

        TraceContext::initialize($request);

        $headers = TraceContext::getPropagationHeaders(includeRequestId: false);

        expect($headers)
            ->toHaveKey('traceparent')
            ->and($headers)->toHaveKey('X-Trace-Id')
            ->and($headers)->not->toHaveKey('X-Request-Id');
    });
});

describe('TraceContext - Mode Detection', function () {
    test('isOtelMode returns false in minimal mode', function () {
        $request = mockRequest('GET', '/');
        TraceContext::initialize($request, false);

        expect(TraceContext::isOtelMode())->toBeFalse();
    });

    test('isOtelMode returns true in OTel mode', function () {
        $request = mockRequest('GET', '/');
        TraceContext::initialize($request, true);

        expect(TraceContext::isOtelMode())->toBeTrue();
    });
});

describe('TraceContext - Sampling', function () {
    test('isSampled returns true when flags indicate sampling', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.flags', '01'); // Sampled

        TraceContext::initialize($request);

        expect(TraceContext::isSampled())->toBeTrue();
    });

    test('isSampled returns false when not sampled', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.flags', '00'); // Not sampled

        TraceContext::initialize($request);

        expect(TraceContext::isSampled())->toBeFalse();
    });
});
