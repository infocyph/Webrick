<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\Route;

describe('Route', function () {
    it('creates basic routes', function () {
        $handler = fn () => Response::json(['ok' => true]);
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
        $handler1 = fn () => 'a';
        $handler2 = fn () => 'b';

        $fp1 = Route::fingerprint($handler1);
        $fp2 = Route::fingerprint($handler2);

        expect($fp1)
            ->toBeString()
            ->and($fp2)->toBeString()
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
