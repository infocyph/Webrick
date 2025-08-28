<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use ArrayIterator;
use Infocyph\Webrick\Interfaces\RouteInterface;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * In-memory container for Route DTOs.
 *
 * • Build phase – add(), addAlias(), remove(), clear() – mutate indices
 * • Freeze phase – compile() → CompiledCollection – locks further mutation
 * • Constant-time look-ups by name / alias / handler / path
 * • Flat alias index (key => [path, domain]) for fast URL generation & cache export
 */
final class Collection implements IteratorAggregate
{
    /** @var list<RouteInterface> */
    private array $routes = [];

    /** Indices for O(1) look-ups */
    /** @var array<string,RouteInterface> */
    private array $byName = [];                 // primary name  => Route
    /** @var array<string,RouteInterface> */
    private array $aliases = [];                // alias name    => Route
    /** @var array<string,list<RouteInterface>> */
    private array $byHandler = [];              // handler key   => list<Route>
    /** @var array<string,RouteInterface> */
    private array $byPath = [];                 // path          => Route

    /** State flags */
    private bool $dirty = false;                // builder changed since last compile
    private bool $frozen = false;               // no further mutation allowed
    private ?CompiledCollection $compiled = null;

    /**
     * Cached flat name index for URL helpers & cache writers:
     *   name_or_alias => [path, domain]
     * @var array<string, array{0:string,1:?string}>|null
     */
    private ?array $aliasIndex = null;

    /* ---------------------------------------------------------------------
     *  Core mutators  (disabled after ->compile())
     * ------------------------------------------------------------------ */

    public function add(RouteInterface $route): void
    {
        $this->assertMutable();

        $this->routes[] = $route;
        $this->dirty = true;
        $this->aliasIndex = null; // invalidate cache

        // ---- primary name index -----------------------------------------
        if (($name = $route->getName()) !== null && $name !== '') {
            // prevent overwrite/ambiguity with existing primary or alias
            if ((isset($this->byName[$name]) && $this->byName[$name] !== $route)
                || isset($this->aliases[$name])) {
                throw new LogicException("Duplicate route name: {$name}");
            }
            $this->byName[$name] = $route;
        }

        $this->byPath[$route->getPath()] = $route;
        $this->byHandler[$route->getHandlerId()][] = $route;
    }

    /**
     * Register a symbolic alias for the given route's name.
     * Aliases must be unique across all primary names and aliases.
     */
    public function addAlias(RouteInterface $route, string $alias): void
    {
        $this->assertMutable();

        $alias = trim($alias);
        if ($alias === '') {
            throw new LogicException('Alias cannot be empty.');
        }

        // forbid clashes with primary names
        if (isset($this->byName[$alias])) {
            throw new LogicException("Alias '{$alias}' conflicts with an existing route name.");
        }
        // forbid clashes with another alias (unless it's the same route ref)
        if (isset($this->aliases[$alias]) && $this->aliases[$alias] !== $route) {
            throw new LogicException("Alias '{$alias}' is already in use.");
        }

        // also avoid alias == its own primary name (pointless / confusing)
        if (($route->getName() ?? '') === $alias) {
            throw new LogicException("Alias '{$alias}' duplicates the route's primary name.");
        }

        $this->aliases[$alias] = $route;
        $this->aliasIndex = null; // invalidate cache
    }

    /**
     * Convenience: add multiple aliases.
     * @param string[] $aliases
     */
    public function addAliases(RouteInterface $route, array $aliases): void
    {
        foreach ($aliases as $a) {
            $this->addAlias($route, (string)$a);
        }
    }

    public function remove(RouteInterface $route): void
    {
        $this->assertMutable();

        // remove from list
        $this->routes = array_values(
            array_filter($this->routes, static fn ($r) => $r !== $route),
        );

        // rebuild main indices
        $this->rebuildIndices();

        // purge aliases pointing to the removed route
        foreach ($this->aliases as $name => $r) {
            if ($r === $route) {
                unset($this->aliases[$name]);
            }
        }

        $this->dirty = true;
        $this->aliasIndex = null;
    }

    public function clear(): void
    {
        $this->assertMutable();

        $this->routes = [];
        $this->byName = [];
        $this->byHandler = [];
        $this->byPath = [];
        $this->aliases = [];
        $this->dirty = true;
        $this->aliasIndex = null;
    }

    /* ---------------------------------------------------------------------
     *  Freeze & compile
     * ------------------------------------------------------------------ */

    public function compile(): CompiledCollection
    {
        if ($this->compiled !== null && !$this->dirty) {
            return $this->compiled; // idempotent, no rebuild needed
        }

        $compiledRoutes = array_map(
            static fn (RouteInterface $r): CompiledRoute => CompiledRoute::fromRoute($r),
            $this->routes,
        );

        $this->compiled = new CompiledCollection($compiledRoutes);
        $this->dirty = false;
        $this->frozen = true;

        return $this->compiled;
    }

    /* ---------------------------------------------------------------------
     *  Hot-path accessors
     * ------------------------------------------------------------------ */

    /** @return list<RouteInterface> */
    public function all(): array
    {
        return $this->routes;
    }

    public function dirty(): bool
    {
        return $this->dirty;
    }

    /** True once ->compile() has been called. */
    public function frozen(): bool
    {
        return $this->frozen;
    }

    public function findByName(string $name): ?RouteInterface
    {
        // primary name first
        if (isset($this->byName[$name])) {
            return $this->byName[$name];
        }
        // then alias map
        return $this->aliases[$name] ?? null;
    }

    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        $id = Route::fingerprint($handler); // static helper lives in Route
        return $this->byHandler[$id][0] ?? null;
    }

    /** @return list<RouteInterface> */
    public function findAllByHandler(callable|string $handler): array
    {
        $id = Route::fingerprint($handler);
        return $this->byHandler[$id] ?? [];
    }

    public function hasPath(string $path): bool
    {
        return isset($this->byPath[$path]);
    }

    /* ---------------------------------------------------------------------
     *  Flat alias index (name_or_alias => [path, domain])
     * ------------------------------------------------------------------ */

    /**
     * Build (lazily) a flattened index of all keys (primary names + aliases)
     * pointing to the pair [path, domain]. Intended for URL helpers and cache.
     *
     * @return array<string, array{0:string,1:?string}>
     */
    public function aliasIndex(): array
    {
        if ($this->aliasIndex !== null) {
            return $this->aliasIndex;
        }

        $out = [];

        // primary names
        foreach ($this->byName as $name => $route) {
            $out[$name] = [$route->getPath(), $route->getDomain()];
        }

        // aliases
        foreach ($this->aliases as $alias => $route) {
            // if somehow an alias equals a primary name, keep primary (paranoia)
            if (!isset($out[$alias])) {
                $out[$alias] = [$route->getPath(), $route->getDomain()];
            }
        }

        return $this->aliasIndex = $out;
    }

    /**
     * Resolve any key (primary or alias) into [path, domain].
     *
     * @return array{0:string,1:?string}|null
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();
        return $idx[$name] ?? null;
    }

    public function namePath(string $name): ?string
    {
        $r = $this->findByName($name);
        return $r?->getPath();
    }

    public function nameDomain(string $name): ?string
    {
        $r = $this->findByName($name);
        return $r?->getDomain();
    }

    /* ---------------------------------------------------------------------
     *  IteratorAggregate
     * ------------------------------------------------------------------ */

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->routes);
    }

    /* ---------------------------------------------------------------------
     *  Internals
     * ------------------------------------------------------------------ */

    private function rebuildIndices(): void
    {
        $this->byName = [];
        $this->byHandler = [];
        $this->byPath = [];

        foreach ($this->routes as $route) {
            if (($name = $route->getName()) !== null && $name !== '') {
                if ((isset($this->byName[$name]) && $this->byName[$name] !== $route)
                    || isset($this->aliases[$name])) {
                    throw new LogicException("Duplicate route name during rebuild: {$name}");
                }
                $this->byName[$name] = $route;
            }
            $this->byHandler[$route->getHandlerId()][] = $route;
            $this->byPath[$route->getPath()] = $route;
        }

        // prune alias map: drop aliases whose routes are no longer present
        // or that conflict with primary names after rebuild
        foreach (array_keys($this->aliases) as $alias) {
            $r = $this->aliases[$alias];
            if (!in_array($r, $this->routes, true) || isset($this->byName[$alias])) {
                unset($this->aliases[$alias]);
            }
        }

        $this->aliasIndex = null; // invalidate cached flat index
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Route collection already compiled – further mutation prohibited.');
        }
    }
}
