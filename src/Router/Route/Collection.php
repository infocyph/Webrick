<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use ArrayIterator;
use Infocyph\Webrick\Router\Contracts\RouteInterface;
use IteratorAggregate;
use Traversable;

/**
 * In-memory container for Route DTOs.
 *
 * • add(), remove(), clear() – mutate collection and indices
 * • all(), findByName(), findByHandler(), findAllByHandler() – O(1) lookups
 * • IteratorAggregate for foreach
 * • dirty-flag so compilers/caches know when to re-run
 */
final class Collection implements IteratorAggregate
{
    /** @var RouteInterface[] */
    private array $routes = [];

    private bool $dirty = false;

    /** @var array<string,RouteInterface> */
    private array $byName = [];

    /** @var array<string,RouteInterface[]> */
    private array $byHandler = [];

    /* -----------------------------------------------------------------
     *  Core mutators
     * ----------------------------------------------------------------*/

    public function add(RouteInterface $route): void
    {
        $this->routes[] = $route;
        $this->dirty    = true;

        $name = $route->getName();
        if ($name !== null && $name !== '') {
            $this->byName[$name] = $route;
        }

        $handlerKey = self::normaliseHandler($route->getHandler());
        $this->byHandler[$handlerKey][] = $route;
    }

    /**
     * Remove a route instance from collection.
     */
    public function remove(RouteInterface $route): void
    {
        $this->routes = array_filter(
            $this->routes,
            fn (RouteInterface $r) => $r !== $route
        );
        $this->rebuildIndices();
        $this->dirty = true;
    }

    /**
     * Remove all routes.
     */
    public function clear(): void
    {
        $this->routes     = [];
        $this->byName     = [];
        $this->byHandler  = [];
        $this->dirty      = true;
    }

    /* -----------------------------------------------------------------
     *  Hot-path accessors
     * ----------------------------------------------------------------*/

    /** @return RouteInterface[] */
    public function all(): array
    {
        return $this->routes;
    }

    public function dirty(): bool
    {
        return $this->dirty;
    }

    public function markClean(): void
    {
        $this->dirty = false;
    }

    /* -----------------------------------------------------------------
     *  Constant-time look-ups
     * ----------------------------------------------------------------*/

    public function findByName(string $name): ?RouteInterface
    {
        return $this->byName[$name] ?? null;
    }

    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        $key = self::normaliseHandler($handler);
        return $this->byHandler[$key][0] ?? null;
    }

    /** @return RouteInterface[] */
    public function findAllByHandler(callable|string $handler): array
    {
        $key = self::normaliseHandler($handler);
        return $this->byHandler[$key] ?? [];
    }

    /* -----------------------------------------------------------------
     *  IteratorAggregate
     * ----------------------------------------------------------------*/

    /** @return Traversable<int,RouteInterface> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->routes);
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------*/

    /**
     * Rebuild both byName and byHandler indices from scratch.
     */
    private function rebuildIndices(): void
    {
        $this->byName    = [];
        $this->byHandler = [];

        foreach ($this->routes as $route) {
            $name = $route->getName();
            if ($name !== null && $name !== '') {
                $this->byName[$name] = $route;
            }
            $handlerKey = self::normaliseHandler($route->getHandler());
            $this->byHandler[$handlerKey][] = $route;
        }
    }

    /**
     * Turn any callable spec into a stable scalar key:
     *  • "Class@method" strings stay as-is
     *  • ["Class", "method"]  →  "Class::method"
     *  • [$obj, "method"]     →  "Class::method"
     *  • invokable object     →  "Class::__invoke"
     *  • Closure              →  "closure@<id>"
     *
     * @param callable|string $h
     * @return string
     */
    private static function normaliseHandler(callable|string $h): string
    {
        if (is_string($h)) {
            return $h;
        }

        if (is_array($h) && isset($h[0], $h[1])) {
            $class = is_object($h[0]) ? $h[0]::class : $h[0];
            return $class . '::' . $h[1];
        }

        if (is_object($h) && ! ($h instanceof \Closure)) {
            return $h::class . '::__invoke';
        }

        return 'closure@' . spl_object_id($h);
    }
}
