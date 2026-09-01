<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;

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

test('runtime contexts keep native handles and scope ids request local', function (): void {
    $nativeA = (object) ['id' => 'a'];
    $nativeB = (object) ['id' => 'b'];
    $a = runtime_test_context('a', $nativeA);
    $b = runtime_test_context('b', $nativeB);

    expect($a->nativeRequest)->toBe($nativeA)
        ->and($b->nativeRequest)->toBe($nativeB)
        ->and($a->nativeRequest)->not->toBe($b->nativeRequest)
        ->and($a->scopeId())->not->toBe($b->scopeId())
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

test('completed runtime contexts do not retain materialized requests globally', function (): void {
    $context = runtime_test_context('collect', (object) []);
    $request = $context->request();
    $reference = WeakReference::create($request);

    unset($request, $context);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});
