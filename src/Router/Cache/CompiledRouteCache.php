<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Cache;

use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Cache\CacheItemPoolInterface as Psr6Pool;
use Psr\SimpleCache\CacheInterface as Psr16Cache;
use RuntimeException;

/**
 * ONE thing only: keep the compiled-route array between boots.
 *
 * Hot-path bucket caching is handled by {@see \Infocyph\Webrick\Router\Matching\UnifiedMatcher}.
 *
 * @psalm-type RouteList = list<CompiledRoute>
 */
final class CompiledRouteCache
{
    public const KEY = 'webrick.routes.compiled';

    /** @param Psr6Pool|Psr16Cache $store */
    public function __construct(
        private Psr6Pool|Psr16Cache $store,
        private string $key = self::KEY,
    ) {
    }

    /* ----------------------------------------------------------------- */
    /** @return RouteList|null */
    public function load(): ?array
    {
        $payload = $this->store instanceof Psr6Pool
            ? ($this->store->getItem($this->key)->get() ?? null)
            : $this->store->get($this->key, null);

        return $payload === null ? null : $this->decode($payload);
    }

    /** @param RouteList $routes */
    public function save(array $routes): void
    {
        $payload = $this->encode($routes);

        if ($this->store instanceof Psr6Pool) {
            $item = $this->store->getItem($this->key);
            $item->set($payload);
            $this->store->save($item);
            return;
        }
        $this->store->set($this->key, $payload);
    }

    public function clear(): void
    {
        $this->store instanceof Psr6Pool
            ? $this->store->deleteItem($this->key)
            : $this->store->delete($this->key);
    }

    /**
     * Load or lazily compute & persist.
     * @param callable():RouteList $builder
     */
    public function remember(callable $builder): array
    {
        if ($cached = $this->load()) {
            return $cached;
        }
        $routes = $builder();
        $this->save($routes);
        return $routes;
    }

    /* ----------------------------------------------------------------- */
    /** @param RouteList $routes */
    private function encode(array $routes): string
    {
        return ValueSerializer::serialize($routes);   // fully closure-safe
    }

    /** @return RouteList */
    private function decode(string $payload): array
    {
        $data = ValueSerializer::unserialize($payload);
        if (!\is_array($data)) {
            throw new RuntimeException('Corrupted route-cache payload.');
        }
        /** @var RouteList $data */
        return $data;
    }
}
