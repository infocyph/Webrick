<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterValue;
use Infocyph\Webrick\Exceptions\HttpException;
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
        cleanTestCache(sys_get_temp_dir().'/webrick-test-throttle');
    });

    it('allows requests within limit', function () {
        // Fresh cache and middleware for this test
        $cache = testCache('throttle-allow-'.uniqid());
        $middleware = new ThrottleMiddleware(
            max: 3,
            window: 60,
            pool: $cache
        );

        $request = mockRequest('GET', '/test');
        $called = false;

        $next = function () use (&$called) {
            $called = true;

            return Response::json(['ok' => true]);
        };

        $response = $middleware($request, $next);

        expect($called)
            ->toBeTrue()
            ->and($response)->toHaveStatus(200)
            ->and($response)->toHaveHeader('X-RateLimit-Limit', '3')
            ->and($response)->toHaveHeader('X-RateLimit-Remaining', '2');
    });

    it('blocks requests over limit', function () {
        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        // Make 3 requests (limit)
        for ($i = 0; $i < 3; $i++) {
            ($this->middleware)($request, $next);
        }

        // 4th request should be throttled
        try {
            ($this->middleware)($request, $next);
            $this->fail('Expected throttle middleware to throw an HttpException.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())
                ->toBe(429)
                ->and($exception->getHeaders())
                ->toMatchArray([
                    'X-RateLimit-Remaining' => '0',
                ])
                ->and($exception->getHeaders())
                ->toHaveKey('Retry-After');
        }
    });

    it('respects per-request cost', function () {
        // Fresh cache for this test
        $cache = testCache('throttle-cost-'.uniqid());
        $middleware = new ThrottleMiddleware(
            max: 5,
            window: 60,
            pool: $cache
        );

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        // Request with cost 2 (total: 2)
        $request1 = $request->withAttribute('rate_cost.thm', 2);
        $response1 = $middleware($request1, $next);
        expect($response1)->toHaveStatus(200);

        // Request with cost 2 again (total: 4)
        $response2 = $middleware($request1, $next);
        expect($response2)->toHaveStatus(200);

        // Request with cost 2 more (total: 6, exceeds limit of 5)
        expect(fn() => $middleware($request1, $next))
            ->toThrow(HttpException::class);
    });

    it('allows bypass via callback', function () {
        $middleware = new ThrottleMiddleware(
            max: 1,
            window: 60,
            pool: $this->cache,
            bypass: fn ($req) => $req->getHeaderLine('X-Admin-Key') === 'secret'
        );

        $request = mockRequest('GET', '/test', ['X-Admin-Key' => 'secret']);
        $next = fn () => Response::json(['ok' => true]);

        // Should not be throttled even after multiple requests
        for ($i = 0; $i < 5; $i++) {
            $response = $middleware($request, $next);
            expect($response)->toHaveStatus(200);
        }
    });

    it('uses the atomic counter fast path when configured', function () {
        $counter = new class implements AtomicCounterStoreInterface {
            public string $key = '';

            public ?int $ttl = null;

            public int $value = 0;

            public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
            {
                $this->key = $key;
                $this->ttl = $ttlSeconds;
                $this->value -= $by;

                return new AtomicCounterValue($this->value, false);
            }

            public function delete(string $key): bool
            {
                $this->key = $key;
                $this->value = 0;

                return true;
            }

            public function get(string $key): ?int
            {
                return $key === $this->key ? $this->value : null;
            }

            public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
            {
                $this->key = $key;
                $this->ttl = $ttlSeconds;
                $this->value += $by;

                return new AtomicCounterValue($this->value, $this->value === $by);
            }
        };
        $middleware = new ThrottleMiddleware(max: 2, window: 60, counterStore: $counter);
        $request = mockRequest('GET', '/atomic');
        $next = static fn () => Response::json(['ok' => true]);

        expect($middleware($request, $next))->toHaveHeader('X-RateLimit-Remaining', '1')
            ->and($middleware($request, $next))->toHaveHeader('X-RateLimit-Remaining', '0')
            ->and($counter->ttl)->toBeGreaterThanOrEqual(1)
            ->and(fn () => $middleware($request, $next))->toThrow(HttpException::class)
            ->and($counter->value)->toBe(3);
    });
});
