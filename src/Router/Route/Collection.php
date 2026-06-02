<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use ArrayIterator;
use Infocyph\Webrick\Interfaces\RouteInterface;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * In-memory collection of Route DTOs used during router build-time.
 *
 * Responsibilities:
 *  - Accept RouteInterface instances during registration (add, addAlias, addAliases).
 *  - Provide fast lookups by primary name, alias, handler id and path.
 *  - Produce a compiled, immutable CompiledCollection via compile().
 *  - Expose a flat alias index for URL helper generation and cache export.
 *
 * Lifecycle:
 *  - Build phase: collection is mutable and indices are kept up-to-date.
 *  - Freeze phase: compile() produces a CompiledCollection and prevents further mutation.
 *
 * @implements IteratorAggregate<int, RouteInterface>
 */
final class Collection implements IteratorAggregate
{
    /**
     * Alias index: alias name => RouteInterface.
     *
     * @var array<string, RouteInterface>
     */
    private array $aliases = [];

    /**
     * Lazily-built flat alias index used by URL helpers and cache writers.
     *
     * Shape: name_or_alias => [path, domain|null]
     *
     * @var array<string, array{0:string,1:?string}>|null
     */
    private ?array $aliasIndex = null;

    /**
     * Handler index: handler fingerprint => list of RouteInterface.
     *
     * @var array<string, list<RouteInterface>>
     */
    private array $byHandler = [];

    /* ---------------------------------------------------------------------
     *  Indices for O(1) lookups (kept in-sync during build phase)
     * ------------------------------------------------------------------ */

    /**
     * Primary name index: primary name => RouteInterface.
     *
     * @var array<string, RouteInterface>
     */
    private array $byName = [];

    /**
     * Path index: path => RouteInterface.
     *
     * @var array<string, RouteInterface>
     */
    private array $byPath = [];

    /**
     * Cached compiled representation returned by compile().
     */
    private ?CompiledCollection $compiled = null;

    /* ---------------------------------------------------------------------
     *  State flags
     * ------------------------------------------------------------------ */
    /**
     * True when the collection has changed since the last compile().
     */
    private bool $dirty = false;

    /**
     * True after compile() has been called; further mutation is prohibited.
     */
    private bool $frozen = false;

    /**
     * Ordered list of routes in registration order.
     *
     * @var list<RouteInterface>
     */
    private array $routes = [];

    /* ---------------------------------------------------------------------
     *  Core mutators  (disabled after ->compile())
     * ------------------------------------------------------------------ */
    /**
     * Add a route to the collection and update indices.
     *
     * Behaviour:
     *  - Updates primary name, path and handler indices.
     *  - Marks the collection dirty and invalidates the flat alias cache.
     *  - Throws LogicException when a primary name clashes with an existing
     *    primary name or alias.
     *
     * @param RouteInterface $route Route instance to add.
     *
     * @throws LogicException When route primary name conflicts with existing name/alias.
     */
    public function add(RouteInterface $route): void
    {
        $this->assertMutable();

        $this->routes[] = $route;
        $this->dirty = true;
        $this->aliasIndex = null; // invalidate flat alias cache

        // ---- primary name index -----------------------------------------
        if (($name = $route->getName()) !== null && $name !== '') {
            // prevent overwrite/ambiguity with existing primary or alias
            if ((isset($this->byName[$name]) && $this->byName[$name] !== $route)
                || isset($this->aliases[$name])) {
                throw new LogicException("Duplicate route name: {$name}");
            }
            $this->byName[$name] = $route;
        }

        // Path and handler indices
        $this->byPath[$route->getPath()] = $route;
        $this->byHandler[$route->getHandlerId()][] = $route;
    }

    /**
     * Register a symbolic alias for the given route's name.
     *
     * Aliases are unique across primary names and other aliases.
     *
     * @param RouteInterface $route Target route the alias will reference.
     * @param string $alias Alias string to register (trimmed).
     *
     * @throws LogicException When alias is empty or conflicts with existing names/aliases.
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
        // forbid clashes with another alias (unless it points to the same route)
        if (isset($this->aliases[$alias]) && $this->aliases[$alias] !== $route) {
            throw new LogicException("Alias '{$alias}' is already in use.");
        }

        // avoid alias matching its own primary name
        if (($route->getName() ?? '') === $alias) {
            throw new LogicException("Alias '{$alias}' duplicates the route's primary name.");
        }

        $this->aliases[$alias] = $route;
        $this->aliasIndex = null; // invalidate flat alias cache
    }

    /**
     * Convenience: register multiple aliases for a route.
     *
     * @param RouteInterface $route Target route.
     * @param string[] $aliases List of alias strings.
     */
    public function addAliases(RouteInterface $route, array $aliases): void
    {
        foreach ($aliases as $a) {
            $this->addAlias($route, (string) $a);
        }
    }

    /* ---------------------------------------------------------------------
     *  Flat alias index (name_or_alias => [path, domain])
     * ------------------------------------------------------------------ */

    /**
     * Build and return a flattened index mapping primary names and aliases to
     * the tuple [path, domain]. Built lazily and cached until collection mutates.
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

        // aliases (keep primary name when conflict arises)
        foreach ($this->aliases as $alias => $route) {
            if (!isset($out[$alias])) {
                $out[$alias] = [$route->getPath(), $route->getDomain()];
            }
        }

        return $this->aliasIndex = $out;
    }

    /* ---------------------------------------------------------------------
     *  Hot-path accessors
     * ------------------------------------------------------------------ */

    /**
     * Return all registered RouteInterface instances in insertion order.
     *
     * @return list<RouteInterface>
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * Clear the entire collection and reset indices.
     */
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

    /**
     * Compile the current routes into an immutable CompiledCollection.
     *
     * The method is idempotent and will return a cached CompiledCollection
     * when the collection has not changed since the last compile.
     *
     * @return CompiledCollection Compiled representation of registered routes.
     */
    public function compile(): CompiledCollection
    {
        if ($this->compiled !== null && !$this->dirty) {
            return $this->compiled; // idempotent, no rebuild needed
        }

        $compiledRoutes = array_map(
            CompiledRoute::fromRoute(...),
            $this->routes,
        );

        $this->compiled = new CompiledCollection($compiledRoutes);
        $this->dirty = false;
        $this->frozen = true;

        return $this->compiled;
    }

    /**
     * Whether the collection has uncompiled changes.
     */
    public function dirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Find all routes that match the given handler fingerprint.
     *
     * @param callable|string $handler Handler descriptor.
     * @return list<RouteInterface> List of matching routes (may be empty).
     */
    public function findAllByHandler(callable|string $handler): array
    {
        $id = Route::fingerprint($handler);

        return $this->byHandler[$id] ?? [];
    }

    /**
     * Find a single route that matches the given handler fingerprint.
     *
     * @param callable|string $handler Handler descriptor (callable or "Class::method" string).
     * @return RouteInterface|null First matching route or null when none found.
     */
    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        $id = Route::fingerprint($handler); // helper in Route class

        return $this->byHandler[$id][0] ?? null;
    }

    /**
     * Find a route by primary name or alias.
     *
     * Primary names take precedence over aliases.
     *
     * @param string $name Route primary name or alias.
     * @return RouteInterface|null Found route or null when absent.
     */
    public function findByName(string $name): ?RouteInterface
    {
        // then alias map
        return $this->byName[$name] ?? $this->aliases[$name] ?? null;
    }

    /**
     * Whether the collection has been frozen via compile().
     *
     * @return bool True once compile() has been called.
     */
    public function frozen(): bool
    {
        return $this->frozen;
    }

    /* ---------------------------------------------------------------------
     *  IteratorAggregate
     * ------------------------------------------------------------------ */

    /**
     * Return an iterator over the registered routes (preserves registration order).
     *
     * @return Traversable|ArrayIterator<RouteInterface>
     */
    /**
     * @return Traversable<int, RouteInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->routes);
    }

    /**
     * Determine whether a route with the given path exists.
     *
     * @param string $path Route path (absolute).
     * @return bool True when a route for the path exists.
     */
    public function hasPath(string $path): bool
    {
        return isset($this->byPath[$path]);
    }

    /**
     * Return the domain for a primary route name.
     *
     * @param string $name Primary route name.
     * @return string|null Domain string or null when not found.
     */
    public function nameDomain(string $name): ?string
    {
        $r = $this->findByName($name);

        return $r?->getDomain();
    }

    /**
     * Return the path for a primary route name.
     *
     * @param string $name Primary route name.
     * @return string|null Path string or null when not found.
     */
    public function namePath(string $name): ?string
    {
        $r = $this->findByName($name);

        return $r?->getPath();
    }

    /**
     * Remove a route from the collection.
     *
     * Behaviour:
     *  - Removes the route from the ordered list.
     *  - Rebuilds indices from remaining routes.
     *  - Purges aliases that pointed to the removed route.
     *
     * @param RouteInterface $route Route to remove.
     */
    public function remove(RouteInterface $route): void
    {
        $this->assertMutable();

        // remove from the ordered list
        $this->routes = array_values(
            array_filter($this->routes, static fn($r) => $r !== $route),
        );

        // rebuild primary indices from remaining routes
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

    /**
     * Resolve any key (primary or alias) into its [path, domain] tuple.
     *
     * @param string $name Primary name or alias.
     * @return array{0:string,1:?string}|null [path, domain] or null when unknown.
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();

        return $idx[$name] ?? null;
    }

    /**
     * Assert the collection is in a mutable (pre-compile) state.
     *
     * @throws LogicException When the collection has been frozen via compile().
     */
    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Route collection already compiled – further mutation prohibited.');
        }
    }

    /* ---------------------------------------------------------------------
     *  Internals
     * ------------------------------------------------------------------ */
    /**
     * Rebuild primary indices (name, handler, path) from the current routes list.
     *
     * Also prunes aliases that reference missing routes or conflict with primary names.
     *
     * @throws LogicException When duplicate primary names are discovered during rebuild.
     */
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
}
