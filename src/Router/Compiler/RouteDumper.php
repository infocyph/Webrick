<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compiler;

use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Router\RouteCollection;

/**
 * Dumps a RouteCollection into Intermix cache for ultra-fast restart.
 *
 * Key design points
 * -----------------
 * • Uses Intermix’s ValueSerializer (built into Cache) — safe for
 *   PHP 8.4 readonly objects and enums.
 * • Stores three arrays: staticRoutes, dynamicRoutes, namedRoutes.
 * • warmUp() called at application boot if cache miss.
 */
final class RouteDumper
{
    private const CACHE_KEY = 'webrick.routes.bin';

    public function __construct(
        private readonly Cache $cache = new Cache()   // filesystem pool by default
    ) {}

    /* -----------------------------------------------------------------
       Load from cache or warm-compile from source
       ----------------------------------------------------------------- */
    public function load(RouteCollection $routes, callable $compiler): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($item->isHit()) {
            /** @var array{staticRoutes:array,dynamicRoutes:array,namedRoutes:array} $data */
            $data = $item->get();
            $routes->hydrate(
                $data['staticRoutes'],
                $data['dynamicRoutes'],
                $data['namedRoutes']
            );
            return;
        }

        // cache miss – compile via provided closure
        $compiler($routes);
        $this->warmUp($routes);
    }

    /* -----------------------------------------------------------------
       Persist current RouteCollection parts into cache
       ----------------------------------------------------------------- */
    public function warmUp(RouteCollection $routes): void
    {
        $data = [
            'staticRoutes'  => $routes->exportStatic(),
            'dynamicRoutes' => $routes->exportDynamic(),
            'namedRoutes'   => $routes->exportNamed(),
        ];

        $this->cache->getItem(self::CACHE_KEY)->set($data);
        $this->cache->save();
    }

    /* -----------------------------------------------------------------
       Clear cache – used by Dev watcher or CLI
       ----------------------------------------------------------------- */
    public function clear(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    /* -----------------------------------------------------------------
       For CLI diagnostics
       ----------------------------------------------------------------- */
    public function isWarmed(): bool
    {
        return $this->cache->getItem(self::CACHE_KEY)->isHit();
    }
}
