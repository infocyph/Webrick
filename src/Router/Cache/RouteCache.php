<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Cache;

use DateInterval;
use DateTimeInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Cache\CacheItemPoolInterface as Psr6Pool;
use Psr\SimpleCache\CacheInterface as Psr16Cache;
use RuntimeException;

/**
 * Persists a compiled-route table in any PSR-6 **or** PSR-16 cache store.
 *
 * @psalm-type RouteList = list<CompiledRoute>
 */
final class RouteCache
{
    public const DEFAULT_KEY = 'webrick.router.compiled';

    /** @param Psr6Pool|Psr16Cache $store */
    public function __construct(
        private readonly Psr6Pool|Psr16Cache $store,
        private readonly string $key = self::DEFAULT_KEY,
    ) {
    }

    /* -----------------------------------------------------------------
     * Public API
     * ----------------------------------------------------------------*/

    /** @return RouteList|null */
    public function load(): ?array
    {
        if ($this->store instanceof Psr6Pool) {
            $item = $this->store->getItem($this->key);
            return $item->isHit() ? $this->decode($item->get()) : null;
        }

        return $this->store->has($this->key)
            ? $this->decode($this->store->get($this->key))
            : null;
    }

    /**
     * @param RouteList                                   $routes
     * @param int|DateInterval|DateTimeInterface|null $ttl PSR-16 TTL semantics
     */
    public function save(array $routes, int|DateInterval|DateTimeInterface|null $ttl = null): void
    {
        $payload = $this->encode($routes);

        if ($this->store instanceof Psr6Pool) {
            $item = $this->store->getItem($this->key);
            $item->set($payload);

            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }

            $this->store->save($item);
            return;
        }

        // PSR-16 path
        $this->store->set($this->key, $payload, $ttl);
    }

    public function clear(): void
    {
        if ($this->store instanceof Psr6Pool) {
            $this->store->deleteItem($this->key);
            return;
        }

        $this->store->delete($this->key);
    }

    /* -----------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /** @param RouteList $routes */
    private function encode(array $routes): string
    {
        // ValueSerializer is faster and safer than PHP’s native serialize()
        return ValueSerializer::serialize($routes);
    }

    /** @return RouteList */
    private function decode(mixed $payload): array
    {
        if (!\is_string($payload) || $payload === '') {
            return [];
        }

        $data = ValueSerializer::unserialize($payload);

        if (!\is_array($data)) {
            throw new RuntimeException('Corrupted route-cache payload.');
        }

        /** @var RouteList $data */
        return $data;
    }
}
