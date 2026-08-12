<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['cache', 'response-cache'])]
#[Bench\Iterations(5)]
#[Bench\Revs(500)]
#[Bench\Warmup(1)]
final class ResponseCacheBench
{
    private ResponseCacheMiddleware $hitMiddleware;

    private ResponseCacheMiddleware $missMiddleware;

    private Request $request;

    public function setUp(): void
    {
        $this->request = Request::fake(uri: 'https://example.test/benchmark');
        $this->hitMiddleware = new ResponseCacheMiddleware(Cache::memory('bench-response-hit'));
        ($this->hitMiddleware)($this->request, self::response(...));
        $this->missMiddleware = new ResponseCacheMiddleware(Cache::memory('bench-response-miss'));
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchHit(): void
    {
        ($this->hitMiddleware)($this->request, self::response(...));
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchMissAndWrite(): void
    {
        $request = $this->request->withUri(
            $this->request->getUri()->withQuery('iteration=' . bin2hex(random_bytes(4))),
        );
        ($this->missMiddleware)($request, self::response(...));
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchReadFailure(): void
    {
        $store = $this->failingStore();
        (new ResponseCacheMiddleware($store))($this->request, self::response(...));
    }

    private static function response(): Response
    {
        return Response::json(['ok' => true]);
    }

    private function failingStore(): CacheInterface
    {
        return new class (Cache::memory('bench-response-failure')) implements CacheInterface {
            public function __construct(private readonly CacheInterface $inner) {}

            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException("read failure for {$key}; default=" . get_debug_type($default));
            }

            public function getItem(string $key): \Psr\Cache\CacheItemInterface
            {
                return $this->inner->getItem($key);
            }

            public function getItems(array $keys = []): iterable
            {
                return $this->inner->getItems($keys);
            }

            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            public function clear(): bool
            {
                return $this->inner->clear();
            }

            public function deleteItem(string $key): bool
            {
                return $this->inner->deleteItem($key);
            }

            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return $this->inner->save($item);
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return $this->inner->getMultiple($keys, $default);
            }

            public function set(string $key, mixed $value, mixed $ttl = null): bool
            {
                return $this->inner->set($key, $value, $ttl);
            }

            public function setMultiple(iterable $values, mixed $ttl = null): bool
            {
                return $this->inner->setMultiple($values, $ttl);
            }

            public function delete(string $key): bool
            {
                return $this->inner->delete($key);
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return $this->inner->deleteMultiple($keys);
            }

            public function has(string $key): bool
            {
                return $this->inner->has($key);
            }

            public function offsetExists(mixed $offset): bool
            {
                return $this->inner->offsetExists($offset);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->inner->offsetGet($offset);
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->inner->offsetSet($offset, $value);
            }

            public function offsetUnset(mixed $offset): void
            {
                $this->inner->offsetUnset($offset);
            }

            public function exportMetrics(): array
            {
                return $this->inner->exportMetrics();
            }

            public function invalidateTag(string $tag): bool
            {
                return $this->inner->invalidateTag($tag);
            }

            public function invalidateTags(array $tags): bool
            {
                return $this->inner->invalidateTags($tags);
            }

            public function remember(string $key, callable $resolver, mixed $ttl = null, array $tags = []): mixed
            {
                return $this->inner->remember($key, $resolver, $ttl, $tags);
            }

            public function setLockProvider(\Infocyph\CacheLayer\Cache\Lock\LockProviderInterface $lockProvider): CacheInterface
            {
                $this->inner->setLockProvider($lockProvider);

                return $this;
            }

            public function setMetricsCollector(\Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface $metrics): CacheInterface
            {
                $this->inner->setMetricsCollector($metrics);

                return $this;
            }

            public function setMetricsExportHook(?callable $hook): CacheInterface
            {
                $this->inner->setMetricsExportHook($hook);

                return $this;
            }

            public function setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool
            {
                return $this->inner->setTagged($key, $value, $tags, $ttl);
            }

            public function useMemcachedLock(?\Memcached $client = null, string $prefix = 'cachelayer:lock:'): CacheInterface
            {
                $this->inner->useMemcachedLock($client, $prefix);

                return $this;
            }

            public function useRedisLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): CacheInterface
            {
                $this->inner->useRedisLock($client, $prefix);

                return $this;
            }

            public function useValkeyLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): CacheInterface
            {
                $this->inner->useValkeyLock($client, $prefix);

                return $this;
            }
        };
    }
}
