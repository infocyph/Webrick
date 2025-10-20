<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Response\Response;

describe('ThrottleMiddleware', function () {
    beforeEach(function () {
        $this->cache = testCache('throttle');
        $this->middleware = new ThrottleMiddleware(
            max: 3,
            window: 60,
            pool: $this->cache
        );
    });

    afterEach(function () {
        cleanTestCache(sys_get_temp_dir() . '/webrick-test-throttle');
    });

    it('allows requests within limit', function () {
        // Fresh cache and middleware for this test
        $cache = testCache('throttle-allow-' . uniqid());
        $middleware = new ThrottleMiddleware(
            max: 3,
            window: 60,
            pool: $cache
        );

        $request = mockRequest('GET', '/test');
        $called = false;

        $next = function ($req) use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        };

        $response = $middleware($request, $next);

        expect($called)->toBeTrue();
        expect($response)->toHaveStatus(200);
        expect($response)->toHaveHeader('X-RateLimit-Limit', '3');
        expect($response)->toHaveHeader('X-RateLimit-Remaining', '2');
    });

    it('blocks requests over limit', function () {
        $request = mockRequest('GET', '/test');
        $next = fn($req) => Response::json(['ok' => true]);

        // Make 3 requests (limit)
        for ($i = 0; $i < 3; $i++) {
            ($this->middleware)($request, $next);
        }

        // 4th request should be throttled
        $response = ($this->middleware)($request, $next);

        expect($response)
            ->toHaveStatus(429)
            ->toHaveHeader('X-RateLimit-Remaining', '0')
            ->toHaveHeader('Retry-After');
    });

    it('respects per-request cost', function () {
        // Fresh cache for this test
        $cache = testCache('throttle-cost-' . uniqid());
        $middleware = new ThrottleMiddleware(
            max: 5,
            window: 60,
            pool: $cache
        );

        $request = mockRequest('GET', '/test');
        $next = fn($req) => Response::json(['ok' => true]);

        // Request with cost 2 (total: 2)
        $request1 = $request->withAttribute('rate_cost.thm', 2);
        $response1 = $middleware($request1, $next);
        expect($response1)->toHaveStatus(200);

        // Request with cost 2 again (total: 4)
        $response2 = $middleware($request1, $next);
        expect($response2)->toHaveStatus(200);

        // Request with cost 2 more (total: 6, exceeds limit of 5)
        $response3 = $middleware($request1, $next);
        expect($response3)->toHaveStatus(429);
    });

    it('allows bypass via callback', function () {
        $middleware = new ThrottleMiddleware(
            max: 1,
            window: 60,
            pool: $this->cache,
            bypass: fn($req) => $req->getHeaderLine('X-Admin-Key') === 'secret'
        );

        $request = mockRequest('GET', '/test', ['X-Admin-Key' => 'secret']);
        $next = fn($req) => Response::json(['ok' => true]);

        // Should not be throttled even after multiple requests
        for ($i = 0; $i < 5; $i++) {
            $response = $middleware($request, $next);
            expect($response)->toHaveStatus(200);
        }
    });
});
