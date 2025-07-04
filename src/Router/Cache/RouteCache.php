<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Cache;

use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Router\Compile\CompiledRoutes;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Tiny façade storing the compiled route collection
 * in an Intermix **file-backed** cache pool.
 */
final class RouteCache
{
    private const KEY = 'compiled_routes';

    private CacheItemPoolInterface $pool;

    public function __construct(?string $dir = null)
    {
        // namespaced pool keeps cache separate from other components
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
            ->expiresAfter(null)   // permanent until manual clear
            ->save();
    }

    public function clear(): void
    {
        $this->pool->deleteItem(self::KEY);
    }
}
