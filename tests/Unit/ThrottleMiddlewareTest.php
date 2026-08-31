<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterValue;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Interop\CacheLayer\AtomicCounterAdapter;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class PcntlFileAtomicCounterStore implements AtomicCounterStoreInterface
{
    public string $lastKey = '';

    public function __construct(private readonly string $path) {}

    public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        return $this->change($key, -$by, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        $this->mutate($key, static fn(): ?array => null);

        return true;
    }

    public function get(string $key): ?int
    {
        return $this->mutate($key, static fn(?array $entry): ?array => $entry)['value'] ?? null;
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        return $this->change($key, $by, $ttlSeconds);
    }

    private function change(string $key, int $by, ?int $ttlSeconds): AtomicCounterValue
    {
        $first = false;
        $entry = $this->mutate($key, static function (?array $current) use ($by, $ttlSeconds, &$first): array {
            $first = $current === null;
            $value = ($current['value'] ?? 0) + $by;

            return [
                'value' => $value,
                'expires' => $current['expires'] ?? ($ttlSeconds === null ? null : time() + $ttlSeconds),
            ];
        });

        return new AtomicCounterValue($entry['value'], $first);
    }

    /**
     * @param Closure(?array{value:int,expires:?int}):(?array{value:int,expires:?int}) $callback
     * @return array{value:int,expires:?int}|null
     */
    private function mutate(string $key, Closure $callback): ?array
    {
        $this->lastKey = $key;
        $handle = fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock atomic-counter fixture.');
        }

        try {
            rewind($handle);
            $raw = stream_get_contents($handle);
            $map = is_string($raw) && $raw !== '' ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];
            $entry = is_array($map[$key] ?? null) ? $map[$key] : null;
            if (is_array($entry) && is_int($entry['expires'] ?? null) && $entry['expires'] <= time()) {
                $entry = null;
            }

            $entry = $callback($entry);
            if ($entry === null) {
                unset($map[$key]);
            } else {
                $map[$key] = $entry;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($map, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $entry;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

describe('ThrottleMiddleware', function () {
    beforeEach(function () {
        $this->cacheNamespace = 'throttle-' . bin2hex(random_bytes(8));
        $this->cachePath = sys_get_temp_dir() . '/webrick-test-' . $this->cacheNamespace;
        $this->cache = testCache($this->cacheNamespace);
        $this->middleware = new ThrottleMiddleware(
            max: 3,
            window: 60,
            pool: $this->cache,
            allowApproximateFallback: true,
        );
    });

    afterEach(function () {
        $this->cache->clear();
        cleanTestCache($this->cachePath);
    });

    it('allows requests within limit', function () {
        $middleware = new ThrottleMiddleware(
            max: 3,
            window: 60,
            pool: $this->cache,
            allowApproximateFallback: true,
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

        for ($i = 0; $i < 3; $i++) {
            ($this->middleware)($request, $next);
        }

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
        $middleware = new ThrottleMiddleware(
            max: 5,
            window: 60,
            pool: $this->cache,
            allowApproximateFallback: true,
        );

        $request = mockRequest('GET', '/test');
        $next = fn () => Response::json(['ok' => true]);

        $request1 = $request->withAttribute('rate_cost.thm', 2);
        $response1 = $middleware($request1, $next);
        expect($response1)->toHaveStatus(200);

        $response2 = $middleware($request1, $next);
        expect($response2)->toHaveStatus(200);

        expect(fn() => $middleware($request1, $next))
            ->toThrow(HttpException::class);
    });

    it('allows bypass via callback', function () {
        $middleware = new ThrottleMiddleware(
            max: 1,
            window: 60,
            pool: $this->cache,
            bypass: fn ($req) => $req->getHeaderLine('X-Admin-Key') === 'secret',
            allowApproximateFallback: true,
        );

        $request = mockRequest('GET', '/test', ['X-Admin-Key' => 'secret']);
        $next = fn () => Response::json(['ok' => true]);

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
        $middleware = new ThrottleMiddleware(
            max: 2,
            window: 60,
            counterStore: new AtomicCounterAdapter($counter),
        );
        $request = mockRequest('GET', '/atomic');
        $next = static fn () => Response::json(['ok' => true]);

        expect($middleware($request, $next))->toHaveHeader('X-RateLimit-Remaining', '1')
            ->and($middleware($request, $next))->toHaveHeader('X-RateLimit-Remaining', '0')
            ->and($counter->ttl)->toBeGreaterThanOrEqual(1)
            ->and(fn () => $middleware($request, $next))->toThrow(HttpException::class)
            ->and($counter->value)->toBe(3);
    });

    it('uses versioned bounded keys and isolates scopes clients and windows', function () {
        $path = tempnam(sys_get_temp_dir(), 'webrick-counter-');
        expect($path)->toBeString();
        $store = new PcntlFileAtomicCounterStore($path);
        $counter = new AtomicCounterAdapter($store);
        $next = static fn() => Response::json(['ok' => true]);
        $global = new ThrottleMiddleware(max: 10, window: 60, counterStore: $counter, scope: 'global');
        $login = new ThrottleMiddleware(max: 10, window: 60, counterStore: $counter, scope: 'login');
        $firstWindow = time();
        $firstClient = (new Request('GET', 'http://localhost/scope', ['REQUEST_TIME' => $firstWindow]))
            ->withAttribute('client_ip', '192.0.2.1');
        $otherClient = (new Request('GET', 'http://localhost/scope', ['REQUEST_TIME' => $firstWindow]))
            ->withAttribute('client_ip', '192.0.2.2');
        $nextWindowClient = (new Request('GET', 'http://localhost/scope', ['REQUEST_TIME' => $firstWindow + 60]))
            ->withAttribute('client_ip', '192.0.2.1');

        try {
            $global($firstClient, $next);
            $globalKey = $store->lastKey;
            $login($firstClient, $next);
            $loginKey = $store->lastKey;
            $global($otherClient, $next);
            $otherClientKey = $store->lastKey;
            $global($nextWindowClient, $next);
            $nextWindowKey = $store->lastKey;

            expect($globalKey)->toStartWith('webrick.th.v2.')
                ->and(strlen($globalKey))->toBeLessThanOrEqual(64)
                ->and(array_unique([$globalKey, $loginKey, $otherClientKey, $nextWindowKey]))->toHaveCount(4);
        } finally {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException("Unable to remove atomic-counter fixture: {$path}");
            }
        }
    });

    it('reserves atomic capacity correctly across concurrent workers', function () {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for the multi-process throttle test.');
        }

        $directory = sys_get_temp_dir() . '/webrick-throttle-' . bin2hex(random_bytes(6));
        expect(mkdir($directory, 0700))->toBeTrue();
        $counterPath = $directory . '/counter.json';
        $workers = 4;
        $attemptsPerWorker = 50;
        $limit = 100;
        $children = [];

        try {
            for ($worker = 0; $worker < $workers; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork throttle test worker.');
                }
                if ($pid > 0) {
                    $children[] = $pid;

                    continue;
                }

                $allowed = 0;
                $rejected = 0;
                $middleware = new ThrottleMiddleware(
                    max: $limit,
                    window: 60,
                    counterStore: new AtomicCounterAdapter(new PcntlFileAtomicCounterStore($counterPath)),
                    scope: 'concurrency',
                );
                $request = mockRequest('GET', '/concurrency')->withAttribute('client_ip', '198.51.100.10');
                $next = static fn() => Response::json(['ok' => true]);

                for ($attempt = 0; $attempt < $attemptsPerWorker; $attempt++) {
                    try {
                        $middleware($request, $next);
                        ++$allowed;
                    } catch (HttpException $exception) {
                        if ($exception->getStatusCode() !== 429) {
                            throw $exception;
                        }
                        ++$rejected;
                    }
                }

                file_put_contents($directory . '/result-' . getmypid(), $allowed . ',' . $rejected, LOCK_EX);
                pcntl_exec(PHP_BINARY, ['-r', '']);
                throw new RuntimeException('Unable to terminate throttle test worker.');
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
            }

            $allowed = 0;
            $rejected = 0;
            foreach (glob($directory . '/result-*') ?: [] as $result) {
                [$childAllowed, $childRejected] = array_map('intval', explode(',', (string) file_get_contents($result)));
                $allowed += $childAllowed;
                $rejected += $childRejected;
            }

            $counterMap = json_decode((string) file_get_contents($counterPath), true, flags: JSON_THROW_ON_ERROR);
            $counterEntry = array_values($counterMap)[0] ?? null;

            expect($allowed)->toBe($limit)
                ->and($rejected)->toBe(($workers * $attemptsPerWorker) - $limit)
                ->and($counterMap)->toHaveCount(1)
                ->and($counterEntry['value'] ?? null)->toBe($workers * $attemptsPerWorker);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file) && !unlink($file)) {
                    throw new RuntimeException("Unable to remove throttle test fixture: {$file}");
                }
            }
            if (is_dir($directory) && !rmdir($directory)) {
                throw new RuntimeException("Unable to remove throttle test directory: {$directory}");
            }
        }
    });
});
