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
    /** @var array<string, RouteInterface> */
    private array $aliases = [];

    /** @var array<string, array{0:string,1:?string}>|null */
    private ?array $aliasIndex = null;

    /** @var array<string, list<RouteInterface>> */
    private array $byHandler = [];

    /** @var array<string, RouteInterface> */
    private array $byName = [];

    /** @var array<string, RouteInterface> */
    private array $byPath = [];

    private ?CompiledCollection $compiled = null;

    private bool $dirty = false;

    private bool $frozen = false;

    /** @var list<RouteInterface> */
    private array $routes = [];

    public function add(RouteInterface $route): void
    {
        $this->assertMutable();

        $this->routes[] = $route;
        $this->dirty = true;
        $this->aliasIndex = null;

        if (($name = $route->getName()) !== null && $name !== '') {
            if ((isset($this->byName[$name]) && $this->byName[$name] !== $route)
                || isset($this->aliases[$name])) {
                throw new LogicException("Duplicate route name: {$name}");
            }
            $this->byName[$name] = $route;
        }

        $this->byPath[$route->getPath()] = $route;
        $this->byHandler[$route->getHandlerId()][] = $route;
    }

    public function addAlias(RouteInterface $route, string $alias): void
    {
        $this->assertMutable();

        $alias = trim($alias);
        if ($alias === '') {
            throw new LogicException('Alias cannot be empty.');
        }
        if (isset($this->byName[$alias])) {
            throw new LogicException("Alias '{$alias}' conflicts with an existing route name.");
        }
        if (isset($this->aliases[$alias]) && $this->aliases[$alias] !== $route) {
            throw new LogicException("Alias '{$alias}' is already in use.");
        }
        if (($route->getName() ?? '') === $alias) {
            throw new LogicException("Alias '{$alias}' duplicates the route's primary name.");
        }

        $this->aliases[$alias] = $route;
        $this->aliasIndex = null;
    }

    /**
     * @param string[] $aliases
     * @param RouteInterface $route
     */
    public function addAliases(RouteInterface $route, array $aliases): void
    {
        foreach ($aliases as $alias) {
            $this->addAlias($route, (string) $alias);
        }
    }

    /** @return array<string, array{0:string,1:?string}> */
    public function aliasIndex(): array
    {
        if ($this->aliasIndex !== null) {
            return $this->aliasIndex;
        }

        $out = [];
        foreach ($this->byName as $name => $route) {
            $out[$name] = [$route->getPath(), $route->getDomain()];
        }
        foreach ($this->aliases as $alias => $route) {
            $out[$alias] ??= [$route->getPath(), $route->getDomain()];
        }

        return $this->aliasIndex = $out;
    }

    /** @return list<RouteInterface> */
    public function all(): array
    {
        return $this->routes;
    }

    public function clear(): void
    {
        $this->assertMutable();

        $this->routes = [];
        $this->resetIndexes();
    }

    public function compile(): CompiledCollection
    {
        if ($this->compiled !== null && !$this->dirty) {
            return $this->compiled;
        }

        $compiledRoutes = [];
        foreach ($this->routes as $index => $route) {
            $compiledRoutes[] = CompiledRoute::fromRoute($route, $index);
        }

        $this->compiled = new CompiledCollection($compiledRoutes);
        $this->dirty = false;
        $this->frozen = true;

        return $this->compiled;
    }

    public function dirty(): bool
    {
        return $this->dirty;
    }

    /**
     * @return list<RouteInterface>
     * @param callable|string $handler
     */
    public function findAllByHandler(callable|string $handler): array
    {
        $id = Route::fingerprint($handler);

        return $this->byHandler[$id] ?? [];
    }

    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        $id = Route::fingerprint($handler);

        return $this->byHandler[$id][0] ?? null;
    }

    public function findByName(string $name): ?RouteInterface
    {
        return $this->byName[$name] ?? $this->aliases[$name] ?? null;
    }

    public function frozen(): bool
    {
        return $this->frozen;
    }

    /** @return Traversable<int, RouteInterface> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->routes);
    }

    public function hasPath(string $path): bool
    {
        return isset($this->byPath[$path]);
    }

    public function nameDomain(string $name): ?string
    {
        $route = $this->findByName($name);

        return $route?->getDomain();
    }

    public function namePath(string $name): ?string
    {
        $route = $this->findByName($name);

        return $route?->getPath();
    }

    public function remove(RouteInterface $route): void
    {
        $this->assertMutable();

        $this->routes = array_values(
            array_filter($this->routes, static fn($entry) => $entry !== $route),
        );
        $this->rebuildIndices();

        foreach ($this->aliases as $name => $entry) {
            if ($entry === $route) {
                unset($this->aliases[$name]);
            }
        }

        $this->dirty = true;
        $this->aliasIndex = null;
    }

    /**
     * @return array{0:string,1:?string}|null
     * @param string $name
     */
    public function resolveAlias(string $name): ?array
    {
        $index = $this->aliasIndex();

        return $index[$name] ?? null;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Route collection already compiled – further mutation prohibited.');
        }
    }

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

        foreach (array_keys($this->aliases) as $alias) {
            $route = $this->aliases[$alias];
            if (!in_array($route, $this->routes, true) || isset($this->byName[$alias])) {
                unset($this->aliases[$alias]);
            }
        }

        $this->aliasIndex = null;
    }

    private function resetIndexes(): void
    {
        [$this->byName, $this->byHandler, $this->byPath, $this->aliases] = [[], [], [], []];
        $this->dirty = true;
        $this->aliasIndex = null;
    }
}
