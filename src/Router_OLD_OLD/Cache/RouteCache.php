<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Cache;

use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Router\Compiled\CompiledRoutes;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Tiny façade that stores the *compiled* RouteCollection
 * in a file-backed Intermix cache pool.
 */
final class RouteCache
{
    private CacheItemPoolInterface $pool;
    private const KEY = 'compiled_routes';

    public function __construct(
        ?string $dir = null,
    ) {
        /* uses Intermix built-in file backend */
        $this->pool = Cache::file(namespace: 'router', dir: $dir);
    }


    public function load(): ?CompiledRoutes
    {
        $item = $this->pool->getItem(self::KEY);
        return $item->isHit() ? $item->get() : null;
    }


    public function store(CompiledRoutes $routes): void
    {
        $this->pool
            ->getItem(self::KEY)
            ->set($routes)
            ->expiresAfter(null)      // permanent until manual clear
            ->save();
    }

    public function clear(): void
    {
        $this->pool->deleteItem(self::KEY);
    }
}
