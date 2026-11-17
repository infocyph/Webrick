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

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function setUp(): void
    {
        $this->request = Request::fake(uri: 'https://example.test/benchmark');
        $this->hitMiddleware = new ResponseCacheMiddleware(Cache::memory('bench-response-hit'));
        ($this->hitMiddleware)($this->request, self::response(...));
        $this->missMiddleware = new ResponseCacheMiddleware(Cache::memory('bench-response-miss'));
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    #[Bench\BeforeMethods('setUp')]
    public function benchHit(): void
    {
        ($this->hitMiddleware)($this->request, self::response(...));
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    #[Bench\BeforeMethods('setUp')]
    public function benchMissAndWrite(): void
    {
        $request = $this->request->withUri(
            $this->request->getUri()->withQuery('iteration=' . bin2hex(random_bytes(4))),
        );
        ($this->missMiddleware)($request, self::response(...));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    #[Bench\BeforeMethods('setUp')]
    public function benchReadFailure(): void
    {
        $store = $this->failingStore();
        (new ResponseCacheMiddleware($store))($this->request, self::response(...));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private static function response(): Response
    {
        return Response::json(['ok' => true]);
    }

    private function failingStore(): CacheInterface
    {
        return new class (Cache::memory('bench-response-failure')) implements CacheInterface {
            public function __construct(private readonly CacheInterface $inner) {}

            /**
             * @throws \RuntimeException
             */
            #[\Override]
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException("read failure for {$key}; default=" . get_debug_type($default));
            }

            #[\Override]
            public function getItem(string $key): \Psr\Cache\CacheItemInterface
            {
                return $this->inner->getItem($key);
            }

            /** @return iterable<string, \Psr\Cache\CacheItemInterface> */
            #[\Override]
            public function getItems(array $keys = []): iterable
            {
                /** @var iterable<string, \Psr\Cache\CacheItemInterface> $items */
                $items = $this->inner->getItems($keys);

                return $items;
            }

            #[\Override]
            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            #[\Override]
            public function clear(): bool
            {
                return $this->inner->clear();
            }

            #[\Override]
            public function deleteItem(string $key): bool
            {
                return $this->inner->deleteItem($key);
            }

            #[\Override]
            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            #[\Override]
            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return $this->inner->save($item);
            }

            #[\Override]
            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            #[\Override]
            public function commit(): bool
            {
                return $this->inner->commit();
            }

            #[\Override]
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return $this->inner->getMultiple($keys, $default);
            }

            #[\Override]
            public function set(string $key, mixed $value, mixed $ttl = null): bool
            {
                return $this->inner->set($key, $value, $ttl);
            }

            /** @param iterable<mixed,mixed> $values */
            #[\Override]
            public function setMultiple(iterable $values, mixed $ttl = null): bool
            {
                return $this->inner->setMultiple($values, $ttl);
            }

            #[\Override]
            public function delete(string $key): bool
            {
                return $this->inner->delete($key);
            }

            #[\Override]
            public function deleteMultiple(iterable $keys): bool
            {
                return $this->inner->deleteMultiple($keys);
            }

            #[\Override]
            public function has(string $key): bool
            {
                return $this->inner->has($key);
            }

            #[\Override]
            public function offsetExists(mixed $offset): bool
            {
                return $this->inner->offsetExists($offset);
            }

            #[\Override]
            public function offsetGet(mixed $offset): mixed
            {
                return $this->inner->offsetGet($offset);
            }

            #[\Override]
            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->inner->offsetSet($offset, $value);
            }

            #[\Override]
            public function offsetUnset(mixed $offset): void
            {
                $this->inner->offsetUnset($offset);
            }

            #[\Override]
            public function exportMetrics(): array
            {
                return $this->inner->exportMetrics();
            }

            #[\Override]
            public function invalidateTag(string $tag): bool
            {
                return $this->inner->invalidateTag($tag);
            }

            #[\Override]
            public function invalidateTags(array $tags): bool
            {
                return $this->inner->invalidateTags($tags);
            }

            #[\Override]
            public function remember(string $key, callable $resolver, mixed $ttl = null, array $tags = []): mixed
            {
                return $this->inner->remember($key, $resolver, $ttl, $tags);
            }

            #[\Override]
            public function setLockProvider(\Infocyph\CacheLayer\Cache\Lock\LockProviderInterface $lockProvider): CacheInterface
            {
                $this->inner->setLockProvider($lockProvider);

                return $this;
            }

            #[\Override]
            public function setMetricsCollector(\Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface $metrics): CacheInterface
            {
                $this->inner->setMetricsCollector($metrics);

                return $this;
            }

            #[\Override]
            public function setMetricsExportHook(?callable $hook): CacheInterface
            {
                $this->inner->setMetricsExportHook($hook);

                return $this;
            }

            #[\Override]
            public function setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool
            {
                return $this->inner->setTagged($key, $value, $tags, $ttl);
            }

            #[\Override]
            public function useMemcachedLock(?\Memcached $client = null, string $prefix = 'cachelayer:lock:'): CacheInterface
            {
                $this->inner->useMemcachedLock($client, $prefix);

                return $this;
            }

            #[\Override]
            public function useRedisLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): CacheInterface
            {
                $this->inner->useRedisLock($client, $prefix);

                return $this;
            }

            #[\Override]
            public function useValkeyLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): CacheInterface
            {
                $this->inner->useValkeyLock($client, $prefix);

                return $this;
            }
        };
    }
}
