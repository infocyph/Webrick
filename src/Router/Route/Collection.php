<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use ArrayIterator;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Build\RouteIdentity;
use IteratorAggregate;
use LogicException;
use Traversable;

/** @implements IteratorAggregate<int, RouteInterface> */
final class Collection implements IteratorAggregate
{
    /** @var array<string,RouteInterface> */
    private array $aliases = [];

    /** @var array<string,array{0:string,1:?string}>|null */
    private ?array $aliasIndex = null;

    /** @var array<string,list<RouteInterface>> */
    private array $byHandler = [];

    /** @var array<string,RouteInterface> */
    private array $byIdentity = [];

    /** @var array<string,RouteInterface> */
    private array $byName = [];

    /** @var array<string,RouteInterface> */
    private array $byPath = [];

    private ?CompiledCollection $compiled = null;

    private bool $dirty = false;

    private bool $frozen = false;

    /** @var list<RouteInterface> */
    private array $routes = [];

    public function add(RouteInterface $route): void
    {
        $this->assertMutable();
        $this->validateRouteRegistration($route);
        $this->commitRoute($route);
    }

    public function addAlias(RouteInterface $route, string $alias): void
    {
        $this->assertMutable();
        $alias = $this->normalizeAlias($route, $alias);
        $this->validateAliasRegistration($route, $alias);
        $this->aliases[$alias] = $route;
        $this->aliasIndex = null;
    }

    /** @param string[] $aliases */
    public function addAliases(RouteInterface $route, array $aliases): void
    {
        $this->assertMutable();
        $normalized = $this->validateAliasBatch($route, $aliases);
        foreach ($normalized as $alias) {
            $this->aliases[$alias] = $route;
        }
        if ($normalized !== []) {
            $this->aliasIndex = null;
        }
    }

    /** @param list<string> $aliases */
    public function addWithAliases(RouteInterface $route, array $aliases): void
    {
        $this->assertMutable();
        $this->validateRouteRegistration($route);
        $normalized = $this->validateAliasBatch($route, $aliases);

        $this->commitRoute($route);
        foreach ($normalized as $alias) {
            $this->aliases[$alias] = $route;
        }
        if ($normalized !== []) {
            $this->aliasIndex = null;
        }
    }

    /** @return array<string,array{0:string,1:?string}> */
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

    /** @return list<RouteInterface> */
    public function findAllByHandler(callable|string $handler): array
    {
        return $this->byHandler[Route::fingerprint($handler)] ?? [];
    }

    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        return $this->byHandler[Route::fingerprint($handler)][0] ?? null;
    }

    public function findByName(string $name): ?RouteInterface
    {
        return $this->byName[$name] ?? $this->aliases[$name] ?? null;
    }

    public function frozen(): bool
    {
        return $this->frozen;
    }

    /** @return Traversable<int,RouteInterface> */
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
        return $this->findByName($name)?->getDomain();
    }

    public function namePath(string $name): ?string
    {
        return $this->findByName($name)?->getPath();
    }

    public function remove(RouteInterface $route): void
    {
        $this->assertMutable();
        $this->routes = array_values(array_filter($this->routes, static fn($entry) => $entry !== $route));
        foreach ($this->aliases as $name => $entry) {
            if ($entry === $route) {
                unset($this->aliases[$name]);
            }
        }
        $this->rebuildIndices();
        $this->dirty = true;
        $this->aliasIndex = null;
    }

    /** @return array{0:string,1:?string}|null */
    public function resolveAlias(string $name): ?array
    {
        return $this->aliasIndex()[$name] ?? null;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Route collection already compiled – further mutation prohibited.');
        }
    }

    private function commitRoute(RouteInterface $route): void
    {
        $this->routes[] = $route;
        $this->dirty = true;
        $this->compiled = null;
        $this->aliasIndex = null;
        if (($name = $route->getName()) !== null && $name !== '') {
            $this->byName[$name] = $route;
        }
        $this->byIdentity[$this->routeIdentity($route)] = $route;
        $this->byPath[$route->getPath()] = $route;
        $this->byHandler[$route->getHandlerId()][] = $route;
    }

    private function duplicateRouteMessage(RouteInterface $route): string
    {
        $domain = $route->getDomain();
        $target = ($domain === null || $domain === '' || $domain === '*')
            ? $route->getPath()
            : $domain . $route->getPath();

        return "Duplicate canonical route: {$route->getMethod()} {$target}";
    }

    private function normalizeAlias(RouteInterface $route, string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new LogicException('Alias cannot be empty.');
        }
        if (($route->getName() ?? '') === $alias) {
            throw new LogicException("Alias '{$alias}' duplicates the route's primary name.");
        }

        return $alias;
    }

    private function rebuildIndices(): void
    {
        $this->byName = [];
        $this->byHandler = [];
        $this->byIdentity = [];
        $this->byPath = [];

        foreach ($this->routes as $route) {
            if (($name = $route->getName()) !== null && $name !== '') {
                if ((isset($this->byName[$name]) && $this->byName[$name] !== $route) || isset($this->aliases[$name])) {
                    throw new LogicException("Duplicate route name during rebuild: {$name}");
                }
                $this->byName[$name] = $route;
            }

            $identity = $this->routeIdentity($route);
            if (isset($this->byIdentity[$identity]) && $this->byIdentity[$identity] !== $route) {
                throw new LogicException($this->duplicateRouteMessage($route));
            }
            $this->byIdentity[$identity] = $route;
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
        [$this->byName, $this->byHandler, $this->byIdentity, $this->byPath, $this->aliases] = [[], [], [], [], []];
        $this->dirty = true;
        $this->compiled = null;
        $this->aliasIndex = null;
    }

    private function routeIdentity(RouteInterface $route): string
    {
        return RouteIdentity::canonicalKey($route->getMethod(), $route->getDomain(), $route->getPath());
    }

    /** @param list<string> $aliases @return list<string> */
    private function validateAliasBatch(RouteInterface $route, array $aliases): array
    {
        $normalized = [];
        $seen = [];
        foreach ($aliases as $alias) {
            $alias = $this->normalizeAlias($route, (string) $alias);
            if (isset($seen[$alias])) {
                throw new LogicException("Alias '{$alias}' is duplicated in the registration batch.");
            }
            $this->validateAliasRegistration($route, $alias);
            $seen[$alias] = true;
            $normalized[] = $alias;
        }

        return $normalized;
    }

    private function validateAliasRegistration(RouteInterface $route, string $alias): void
    {
        if (isset($this->byName[$alias])) {
            throw new LogicException("Alias '{$alias}' conflicts with an existing route name.");
        }
        if (isset($this->aliases[$alias]) && $this->aliases[$alias] !== $route) {
            throw new LogicException("Alias '{$alias}' is already in use.");
        }
    }

    private function validateRouteRegistration(RouteInterface $route): void
    {
        $identity = $this->routeIdentity($route);
        if (isset($this->byIdentity[$identity]) && $this->byIdentity[$identity] !== $route) {
            throw new LogicException($this->duplicateRouteMessage($route));
        }

        $name = $route->getName();
        if ($name === null || $name === '') {
            return;
        }
        if ((isset($this->byName[$name]) && $this->byName[$name] !== $route) || isset($this->aliases[$name])) {
            throw new LogicException("Duplicate route name: {$name}");
        }
    }
}
