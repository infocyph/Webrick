<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestExecution;

function runtime_test_context(string $id, object $native): RuntimeRequestContext
{
    return new RuntimeRequestContext(
        new RoutingInput('GET', '/' . $id),
        static fn(): Request => Request::fake(query: ['id' => $id], uri: '/' . $id),
        new RuntimeCapabilities('test', persistent: true, concurrent: true),
        nativeRequest: $native,
    );
}

test('runtime context materializes its request exactly once', function (): void {
    $calls = 0;
    $context = new RuntimeRequestContext(
        new RoutingInput('GET', '/once'),
        static function () use (&$calls): Request {
            $calls++;

            return Request::fake(uri: '/once');
        },
        new RuntimeCapabilities('test', persistent: true),
    );

    $first = $context->request();
    $second = $context->request();

    expect($first)->toBe($second)
        ->and($calls)->toBe(1);
});

test('runtime contexts keep native handles request local while sharing the semantic scope label', function (): void {
    $nativeA = (object) ['id' => 'a'];
    $nativeB = (object) ['id' => 'b'];
    $a = runtime_test_context('a', $nativeA);
    $b = runtime_test_context('b', $nativeB);

    expect($a->nativeRequest)->toBe($nativeA)
        ->and($b->nativeRequest)->toBe($nativeB)
        ->and($a->nativeRequest)->not->toBe($b->nativeRequest)
        ->and($a->scopeId())->toBe(RuntimeRequestContext::REQUEST_SCOPE)
        ->and($b->scopeId())->toBe(RuntimeRequestContext::REQUEST_SCOPE)
        ->and($a->scopeId())->toBe($b->scopeId())
        ->and($a->request()->query('id'))->toBe('a')
        ->and($b->request()->query('id'))->toBe('b');
});

test('interleaved fibers do not cross contaminate runtime requests', function (): void {
    $a = runtime_test_context('fiber-a', (object) ['id' => 'native-a']);
    $b = runtime_test_context('fiber-b', (object) ['id' => 'native-b']);

    $fiberA = new Fiber(static function () use ($a): string {
        $before = $a->request()->query('id');
        Fiber::suspend();

        return $before . ':' . $a->request()->query('id');
    });
    $fiberB = new Fiber(static function () use ($b): string {
        $before = $b->request()->query('id');
        Fiber::suspend();

        return $before . ':' . $b->request()->query('id');
    });

    $fiberA->start();
    $fiberB->start();
    $fiberB->resume();
    $fiberA->resume();

    expect($fiberA->getReturn())->toBe('fiber-a:fiber-a')
        ->and($fiberB->getReturn())->toBe('fiber-b:fiber-b');
});

test('runtime execution metadata stays request local and observes authoritative cancellation live', function (): void {
    $cancelled = false;
    $reason = null;
    $execution = new RuntimeRequestExecution(
        requestId: 'runtime-request-42',
        startMonotonicNanoseconds: 1_000,
        deadlineMonotonicNanoseconds: 5_000,
        cancelled: static function () use (&$cancelled): bool {
            return $cancelled;
        },
        cancellationReason: static function () use (&$reason): ?string {
            return $reason;
        },
    );
    $context = new RuntimeRequestContext(
        new RoutingInput('GET', '/execution'),
        static fn(): Request => Request::fake(uri: '/execution'),
        new RuntimeCapabilities(
            'test-runtime',
            persistent: true,
            concurrent: true,
            nativeRequestStreaming: true,
            cancellationVisibility: true,
            transportDrainVisibility: true,
        ),
        execution: $execution,
    );

    expect($execution->requestId)->toBe('runtime-request-42')
        ->and($execution->startMonotonicNanoseconds)->toBe(1_000)
        ->and($execution->deadlineMonotonicNanoseconds)->toBe(5_000)
        ->and($execution->cancellationVisible())->toBeTrue()
        ->and($execution->cancelled())->toBeFalse()
        ->and($execution->cancellationReason())->toBeNull();

    $request = $context->request();
    expect($request->getAttribute(RuntimeRequestExecution::ATTRIBUTE))->toBe($execution)
        ->and($request->getAttribute(RuntimeCapabilities::ATTRIBUTE))->toBe($context->capabilities)
        ->and($context->capabilities->nativeRequestStreaming)->toBeTrue()
        ->and($context->capabilities->cancellationVisibility)->toBeTrue()
        ->and($context->capabilities->transportDrainVisibility)->toBeTrue();

    $cancelled = true;
    $reason = 'client-disconnected';

    expect($execution->cancelled())->toBeTrue()
        ->and($execution->cancellationReason())->toBe('client-disconnected');
});

test('runtime execution bridge is absent by default and legacy capability defaults stay conservative', function (): void {
    $context = runtime_test_context('plain', (object) []);

    expect($context->execution)->toBeNull()
        ->and($context->request()->getAttribute(RuntimeRequestExecution::ATTRIBUTE))->toBeNull()
        ->and($context->capabilities->nativeRequestStreaming)->toBeFalse()
        ->and($context->capabilities->cancellationVisibility)->toBeFalse()
        ->and($context->capabilities->transportDrainVisibility)->toBeFalse();
});

test('runtime execution metadata rejects invalid identity and monotonic bounds', function (): void {
    expect(fn(): RuntimeRequestExecution => new RuntimeRequestExecution(requestId: "bad\nrequest"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn(): RuntimeRequestExecution => new RuntimeRequestExecution(startMonotonicNanoseconds: -1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn(): RuntimeRequestExecution => new RuntimeRequestExecution(
            startMonotonicNanoseconds: 10,
            deadlineMonotonicNanoseconds: 9,
        ))->toThrow(InvalidArgumentException::class);
});

test('completed runtime contexts do not retain materialized requests globally', function (): void {
    $context = runtime_test_context('collect', (object) []);
    $request = $context->request();
    $reference = WeakReference::create($request);

    unset($request, $context);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});
