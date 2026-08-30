<?php

declare(strict_types=1);

use Infocyph\Webrick\Support\RequestContext;
use Infocyph\Webrick\Support\TraceContext;

describe('TraceContext request-local behavior', function () {
    it('returns an explicit context without retaining process-global state', function () {
        $request = mockRequest('GET', '/')
            ->withAttribute('trace.trace_id', 'trace-a')
            ->withAttribute('trace.span_id', 'span-a')
            ->withAttribute('request_id', 'request-a');

        $context = TraceContext::initialize($request);
        TraceContext::clear();

        expect($context)
            ->toBeInstanceOf(RequestContext::class)
            ->and($context->traceId())->toBe('trace-a')
            ->and($context->spanId())->toBe('span-a')
            ->and($context->requestId())->toBe('request-a');
    });

    it('attaches independent contexts to independent requests', function () {
        $first = TraceContext::attach(
            mockRequest('GET', '/first')
                ->withAttribute('trace.trace_id', 'first-trace')
                ->withAttribute('request_id', 'first-request'),
        );
        $second = TraceContext::attach(
            mockRequest('GET', '/second')
                ->withAttribute('trace.trace_id', 'second-trace')
                ->withAttribute('request_id', 'second-request'),
        );

        $firstContext = TraceContext::require($first);
        $secondContext = TraceContext::require($second);

        expect($firstContext)->not->toBe($secondContext)
            ->and($firstContext->traceId())->toBe('first-trace')
            ->and($firstContext->requestId())->toBe('first-request')
            ->and($secondContext->traceId())->toBe('second-trace')
            ->and($secondContext->requestId())->toBe('second-request');
    });

    it('provides log, sampling, and propagation helpers from explicit context', function () {
        $request = TraceContext::attach(
            mockRequest('GET', '/')
                ->withAttribute('trace.trace_id', str_repeat('a', 32))
                ->withAttribute('trace.span_id', str_repeat('b', 16))
                ->withAttribute('trace.flags', '01')
                ->withAttribute('trace.tracestate', 'congo=test')
                ->withAttribute('request_id', 'req-123'),
            true,
        );
        $context = TraceContext::require($request);

        expect($context->otelAvailable())->toBeTrue()
            ->and($context->sampled())->toBeTrue()
            ->and($context->traceParent())->toBe('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01')
            ->and($context->logContext())->toBe('trace=' . str_repeat('a', 32) . ' span=' . str_repeat('b', 16) . ' request=req-123')
            ->and($context->propagationHeaders())
            ->toMatchArray([
                'traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01',
                'tracestate' => 'congo=test',
                'X-Trace-Id' => str_repeat('a', 32),
                'X-Request-Id' => 'req-123',
            ]);
    });

    it('fails explicitly when a request has no context', function () {
        expect(fn() => TraceContext::require(mockRequest('GET', '/')))
            ->toThrow(LogicException::class);
    });
});
