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
 *  • `add()`   – append a (possibly mutated) Route
 *  • `all()`   – materialise as plain array
 *  • Iterator  – `foreach ($collection as $r) { … }`
 *  • Dirty-flag so compilers / caches know when to re-run
 *
 * Extra sugar:
 *  • `findByName()`     – constant-time lookup by route-name
 *  • `findByHandler()`  – constant-time lookup by callable / class
 */
final class Collection implements IteratorAggregate
{
    /** @var RouteInterface[] */
    private array $routes = [];

    private bool $dirty = false;

    /** @var array<string,RouteInterface> name ⇒ route */
    private array $byName = [];

    /** @var array<string,RouteInterface[]> handler-key ⇒ [routes] */
    private array $byHandler = [];

    /* -----------------------------------------------------------------
     *  Core mutator
     * ----------------------------------------------------------------*/

    public function add(RouteInterface $route): void
    {
        $this->routes[] = $route;
        $this->dirty    = true;

        // ---- build / update indices ---------------------------------
        if (($name = $route->getName()) !== null && $name !== '') {
            $this->byName[$name] = $route;
        }

        $handlerKey = self::normaliseHandler($route->getHandler());
        $this->byHandler[$handlerKey][] = $route;
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
     *  Constant-time look-ups for Url-building helpers
     * ----------------------------------------------------------------*/

    public function findByName(string $name): ?RouteInterface
    {
        return $this->byName[$name] ?? null;
    }

    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        $key = self::normaliseHandler($handler);

        // If multiple routes share the same handler, return the *first* one
        return $this->byHandler[$key][0] ?? null;
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
     * Turn *any* callable spec into a stable scalar key:
     *  • "Class@method" strings stay as-is
     *  • ["Class", "method"]  →  "Class::method"
     *  • [$obj, "method"]     →  "Class::method"
     *  • invokable object     →  "Class::__invoke"
     *  • Closure              →  "closure@<id>"
     */
    private static function normaliseHandler(callable|string $h): string
    {
        // string callables ("MyController@action" or "globalFunc")
        if (is_string($h)) {
            return $h;
        }

        // ["Class", "method"]  OR  [$obj, "method"]
        if (is_array($h) && isset($h[0], $h[1])) {
            $class = is_object($h[0]) ? $h[0]::class : $h[0];
            return $class . '::' . $h[1];
        }

        // invokable object
        if (is_object($h) && !($h instanceof \Closure)) {
            return $h::class . '::__invoke';
        }

        // Closure – falls back to unique object id
        return 'closure@' . spl_object_id($h);
    }
}
