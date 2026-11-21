<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Router\Route\CompiledRoute;

describe('Route', function () {
    it('creates basic routes', function () {
        $handler = fn (): Response => Response::json(['ok' => true]);
        $route = new Route('GET', '/users', $handler);

        expect($route)
            ->getMethod()->toBe('GET')
            ->getPath()->toBe('/users')
            ->getHandler()->toBe($handler);
    });

    it('detects dynamic routes', function () {
        $static = new Route('GET', '/about', fn () => 'static');
        $dynamic = new Route('GET', '/users/{id}', fn () => 'dynamic');

        expect($static->isDynamic())
            ->toBeFalse()
            ->and($dynamic->isDynamic())->toBeTrue();
    });

    it('is immutable', function () {
        $r1 = new Route('GET', '/test', fn () => 'test');
        $r2 = $r1->withName('test.route');

        expect($r1->getName())
            ->toBeNull()
            ->and($r2->getName())->toBe('test.route')
            ->and($r1)->not->toBe($r2);
    });

    it('can add middleware', function () {
        $route = new Route('GET', '/test', fn () => 'test');
        $route = $route->withMiddleware(['AuthMiddleware', 'ThrottleMiddleware']);

        expect($route->getMiddlewares())->toBe(['AuthMiddleware', 'ThrottleMiddleware']);
    });

    it('can set domain', function () {
        $route = new Route('GET', '/api', fn () => 'api');
        $route = $route->withDomain('api.example.com');

        expect($route->getDomain())->toBe('api.example.com');
    });

    it('generates unique handler fingerprint', function () {
        $handler1 = fn (): string => 'a';
        $handler2 = fn (): string => 'b';

        $fp1 = Route::fingerprint($handler1);
        $fp2 = Route::fingerprint($handler2);

        expect($fp1)->not->toBe('')
            ->and($fp2)->not->toBe('')
            ->and($fp1)->not->toBe($fp2);
    });

    it('fingerprints array handlers', function () {
        $fp1 = Route::fingerprint(['Controller', 'method']);
        $fp2 = Route::fingerprint(['Controller', 'otherMethod']);

        expect($fp1)
            ->toContain('Controller::method')
            ->and($fp2)->toContain('Controller::otherMethod');
    });
});

test('compiled route state rejects malformed method descriptors without probing missing offsets', function (array $handler): void {
    expect(fn(): CompiledRoute => CompiledRoute::__set_state([
        'method' => 'GET',
        'path' => '/state',
        'handler' => $handler,
    ]))->toThrow(UnexpectedValueException::class, 'Invalid handler');
})->with([
    'named keys' => [['class' => stdClass::class, 'method' => 'handle']],
    'missing class offset' => [[1 => 'handle', 2 => stdClass::class]],
    'missing method offset' => [[0 => stdClass::class, 2 => 'handle']],
]);

test('compiled route state restores callables and unresolved method descriptors', function (string $kind): void {
    $handler = match ($kind) {
        'closure' => static fn(): string => 'state',
        'reversed-array' => [1 => 'json', 0 => Response::class],
        default => 'strlen',
    };
    $middleware = [1 => 'handle', 0 => stdClass::class];
    $route = CompiledRoute::__set_state([
        'method' => 'GET',
        'path' => '/state',
        'handler' => $handler,
        'middleware' => [$middleware],
        'dynamic' => false,
        'regex' => '',
        'variables' => [],
        'index' => 123,
        'segments' => [],
    ]);

    expect($route->getHandler())->toBe($handler)
        ->and($route->getMiddleware())->toBe([[stdClass::class, 'handle']]);
})->with([
    'closure',
    'reversed-array',
    'function',
]);
