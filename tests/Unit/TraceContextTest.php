<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Support\TraceContext;

beforeEach(function () {
    TraceContext::clear();
});

afterEach(function () {
    TraceContext::clear();
});

describe('TraceContext - Basic Functionality', function () {
    test('returns null when not initialized', function () {
        expect(TraceContext::isAvailable())->toBeFalse();
        expect(TraceContext::getTraceId())->toBeNull();
        expect(TraceContext::getSpanId())->toBeNull();
        expect(TraceContext::getRequestId())->toBeNull();
    });

    test('initializes and provides trace context', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123')
            ->withAttribute('trace.span_id', 'def456')
            ->withAttribute('request_id', 'xyz789');

        TraceContext::initialize($request);

        expect(TraceContext::isAvailable())->toBeTrue();
        expect(TraceContext::getTraceId())->toBe('abc123');
        expect(TraceContext::getSpanId())->toBe('def456');
        expect(TraceContext::getRequestId())->toBe('xyz789');
    });

    test('clears context', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);
        expect(TraceContext::isAvailable())->toBeTrue();

        TraceContext::clear();
        expect(TraceContext::isAvailable())->toBeFalse();
        expect(TraceContext::getTraceId())->toBeNull();
    });
});

describe('TraceContext - All Attributes', function () {
    test('provides all trace attributes', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'trace123')
            ->withAttribute('trace.span_id', 'span456')
            ->withAttribute('trace.parent_span_id', 'parent789')
            ->withAttribute('trace.flags', '01')
            ->withAttribute('trace.tracestate', 'congo=ucfJifl5GOE')
            ->withAttribute('request_id', 'req987');

        TraceContext::initialize($request);

        expect(TraceContext::getTraceId())->toBe('trace123');
        expect(TraceContext::getSpanId())->toBe('span456');
        expect(TraceContext::getParentSpanId())->toBe('parent789');
        expect(TraceContext::getFlags())->toBe('01');
        expect(TraceContext::getTraceState())->toBe('congo=ucfJifl5GOE');
        expect(TraceContext::getRequestId())->toBe('req987');
    });

    test('getAll returns complete context', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'trace123')
            ->withAttribute('trace.span_id', 'span456')
            ->withAttribute('request_id', 'req987');

        TraceContext::initialize($request);

        $all = TraceContext::getAll();

        expect($all)->toHaveKey('trace_id');
        expect($all)->toHaveKey('span_id');
        expect($all)->toHaveKey('request_id');
        expect($all['trace_id'])->toBe('trace123');
        expect($all['span_id'])->toBe('span456');
        expect($all['request_id'])->toBe('req987');
    });
});

describe('TraceContext - Formatted Output', function () {
    test('getLogContext returns formatted string', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123')
            ->withAttribute('trace.span_id', 'def456')
            ->withAttribute('request_id', 'xyz789');

        TraceContext::initialize($request);

        $context = TraceContext::getLogContext();

        expect($context)->toBe('trace=abc123 span=def456 request=xyz789');
    });

    test('getLogContext handles missing attributes', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);

        $context = TraceContext::getLogContext();

        expect($context)->toBe('trace=abc123');
        expect($context)->not->toContain('span=');
        expect($context)->not->toContain('request=');
    });

    test('getLogArray returns only non-null values', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123')
            ->withAttribute('request_id', 'xyz789');

        TraceContext::initialize($request);

        $array = TraceContext::getLogArray();

        expect($array)->toHaveKey('trace_id');
        expect($array)->toHaveKey('request_id');
        expect($array)->not->toHaveKey('span_id');
        expect($array['trace_id'])->toBe('abc123');
        expect($array['request_id'])->toBe('xyz789');
    });
});

describe('TraceContext - W3C Traceparent', function () {
    test('getTraceparent returns valid format', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16))
            ->withAttribute('trace.flags', '01');

        TraceContext::initialize($request);

        $traceparent = TraceContext::getTraceparent();

        expect($traceparent)->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/');
        expect($traceparent)->toBe('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01');
    });

    test('getTraceparent returns null when incomplete', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);

        expect(TraceContext::getTraceparent())->toBeNull();
    });

    test('getTraceparent uses default flags when missing', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16));

        TraceContext::initialize($request);

        $traceparent = TraceContext::getTraceparent();

        expect($traceparent)->toContain('-01'); // Default sampled flag
    });
});

describe('TraceContext - Propagation Headers', function () {
    test('getPropagationHeaders returns complete headers', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16))
            ->withAttribute('trace.flags', '01')
            ->withAttribute('trace.tracestate', 'congo=test')
            ->withAttribute('request_id', 'req123');

        TraceContext::initialize($request);

        $headers = TraceContext::getPropagationHeaders();

        expect($headers)->toHaveKey('traceparent');
        expect($headers)->toHaveKey('tracestate');
        expect($headers)->toHaveKey('X-Trace-Id');
        expect($headers)->toHaveKey('X-Request-Id');
        expect($headers['traceparent'])->toBe('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01');
        expect($headers['tracestate'])->toBe('congo=test');
        expect($headers['X-Trace-Id'])->toBe(str_repeat('a', 32));
        expect($headers['X-Request-Id'])->toBe('req123');
    });

    test('getPropagationHeaders excludes request ID when specified', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32))
            ->withAttribute('trace.span_id', str_repeat('b', 16))
            ->withAttribute('request_id', 'req123');

        TraceContext::initialize($request);

        $headers = TraceContext::getPropagationHeaders(includeRequestId: false);

        expect($headers)->toHaveKey('traceparent');
        expect($headers)->toHaveKey('X-Trace-Id');
        expect($headers)->not->toHaveKey('X-Request-Id');
    });

    test('getPropagationHeaders handles missing attributes', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.trace_id', str_repeat('a', 32));

        TraceContext::initialize($request);

        $headers = TraceContext::getPropagationHeaders();

        expect($headers)->toHaveKey('X-Trace-Id');
        expect($headers)->not->toHaveKey('traceparent'); // Missing span_id
    });
});

describe('TraceContext - Mode Detection', function () {
    test('isOtelMode returns false in minimal mode', function () {
        $request = Request::create('GET', '/');
        TraceContext::initialize($request, false);

        expect(TraceContext::isOtelMode())->toBeFalse();
    });

    test('isOtelMode returns true in OTel mode', function () {
        $request = Request::create('GET', '/');
        TraceContext::initialize($request, true);

        expect(TraceContext::isOtelMode())->toBeTrue();
    });
});

describe('TraceContext - Sampling', function () {
    test('isSampled returns true when flags indicate sampling', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.flags', '01'); // Sampled

        TraceContext::initialize($request);

        expect(TraceContext::isSampled())->toBeTrue();
    });

    test('isSampled returns false when not sampled', function () {
        $request = Request::create('GET', '/')
            ->withAttribute('trace.flags', '00'); // Not sampled

        TraceContext::initialize($request);

        expect(TraceContext::isSampled())->toBeFalse();
    });

    test('isSampled returns false when flags missing', function () {
        $request = Request::create('GET', '/');
        TraceContext::initialize($request);

        expect(TraceContext::isSampled())->toBeFalse();
    });
});

describe('TraceContext - Request Access', function () {
    test('getRequest returns initialized request', function () {
        $request = Request::create('GET', '/test')
            ->withAttribute('trace.trace_id', 'abc123');

        TraceContext::initialize($request);

        $retrievedRequest = TraceContext::getRequest();

        expect($retrievedRequest)->toBe($request);
        expect($retrievedRequest->getPath())->toBe('/test');
    });

    test('getRequest returns null when not initialized', function () {
        expect(TraceContext::getRequest())->toBeNull();
    });
});
