<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Middleware\Maintenance\MaintenancePreRoutingGate;
use Infocyph\Webrick\Middleware\Maintenance\MemoryMaintenanceState;
use Infocyph\Webrick\Router\Runtime\RoutingInput;

test('inactive maintenance gate passes without a response', function (): void {
    $gate = new MaintenancePreRoutingGate(state: new MemoryMaintenanceState());

    expect($gate->evaluate(new RoutingInput('GET', '/')))->toBeNull();
});

test('active maintenance gate returns requestless parity response', function (): void {
    $state = new MemoryMaintenanceState();
    $state->enable('Deploying');
    $gate = new MaintenancePreRoutingGate(retryAfter: 120, state: $state);

    $response = $gate->evaluate(new RoutingInput('GET', '/orders'));

    expect($response)->not->toBeNull()
        ->and($response?->getStatusCode())->toBe(503)
        ->and((string) $response?->getBody())->toBe("503 Service Unavailable\nDeploying")
        ->and($response?->getHeaderLine('Retry-After'))->toBe('120')
        ->and($response?->getHeaderLine('Content-Type'))->toBe(MediaTypeEnum::PLAIN->value)
        ->and($response?->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($response?->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response?->getHeaderLine('Vary'))->toBe('Accept');
});

test('maintenance bypasses are bounded exact and canonical', function (): void {
    $state = new MemoryMaintenanceState();
    $state->enable('Deploying');
    $gate = new MaintenancePreRoutingGate(state: $state, bypassPaths: ['/health/../ready']);

    expect($gate->evaluate(new RoutingInput('GET', '/ready')))->toBeNull()
        ->and($gate->evaluate(new RoutingInput('GET', '/ready/deep')))->not->toBeNull();
});

test('maintenance bypass configuration is bounded', function (): void {
    $paths = [];
    for ($i = 0; $i < 33; $i++) {
        $paths[] = '/health-' . $i;
    }

    expect(fn() => new MaintenancePreRoutingGate(bypassPaths: $paths))
        ->toThrow(InvalidArgumentException::class);
});

test('interleaved fibers keep maintenance gate state isolated', function (): void {
    $stateA = new MemoryMaintenanceState();
    $stateB = new MemoryMaintenanceState();
    $stateA->enable('A');
    $stateB->enable('B');
    $gateA = new MaintenancePreRoutingGate(state: $stateA);
    $gateB = new MaintenancePreRoutingGate(state: $stateB);
    $routing = new RoutingInput('GET', '/');

    $fiberA = new Fiber(static function () use ($gateA, $routing): string {
        $before = (string) $gateA->evaluate($routing)?->getBody();
        Fiber::suspend();

        return $before . '|' . (string) $gateA->evaluate($routing)?->getBody();
    });
    $fiberB = new Fiber(static function () use ($gateB, $routing): string {
        $before = (string) $gateB->evaluate($routing)?->getBody();
        Fiber::suspend();

        return $before . '|' . (string) $gateB->evaluate($routing)?->getBody();
    });

    $fiberA->start();
    $fiberB->start();
    $fiberB->resume();
    $fiberA->resume();

    expect($fiberA->getReturn())->toBe("503 Service Unavailable\nA|503 Service Unavailable\nA")
        ->and($fiberB->getReturn())->toBe("503 Service Unavailable\nB|503 Service Unavailable\nB");
});
