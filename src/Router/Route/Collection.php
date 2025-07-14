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
 * • **Build phase** – add(), remove(), clear() – mutate indices
 * • **Freeze phase** – compile() → CompiledCollection – locks further mutation
 * • Constant-time look-ups by name / handler
 * • IteratorAggregate for `foreach`
 */
final class Collection implements IteratorAggregate
{
    /** @var list<RouteInterface> */
    private array $routes = [];

    /** Indices for O(1) look-ups */
    private array $byName = [];   // name   ⇒ RouteInterface
    private array $byHandler = [];   // key    ⇒ list<RouteInterface>

    /** State flags */
    private bool $dirty = false;    // builder changed since last compile
    private bool $frozen = false;    // no further mutation allowed

    private ?CompiledCollection $compiled = null;

    /* ---------------------------------------------------------------------
     *  Core mutators  (disabled after ->compile())
     * ------------------------------------------------------------------ */

    public function add(RouteInterface $route): void
    {
        $this->assertMutable();

        $this->routes[] = $route;
        $this->dirty = true;

        // ---- indices ----------------------------------------------------
        if (($name = $route->getName()) !== null && $name !== '') {
            $this->byName[$name] = $route;
        }

        $this->byHandler[$route->getHandlerId()][] = $route;
    }

    public function remove(RouteInterface $route): void
    {
        $this->assertMutable();

        $this->routes = array_values(
            array_filter($this->routes, static fn ($r) => $r !== $route),
        );

        $this->rebuildIndices();
        $this->dirty = true;
    }

    public function clear(): void
    {
        $this->assertMutable();

        $this->routes = [];
        $this->byName = [];
        $this->byHandler = [];
        $this->dirty = true;
    }

    /* ---------------------------------------------------------------------
     *  Freeze & compile
     * ------------------------------------------------------------------ */

    /**
     * Transform every Route into its hot-path CompiledRoute representation
     * and lock the collection against further writes.
     */
    public function compile(): CompiledCollection
    {
        if ($this->compiled !== null && !$this->dirty) {
            return $this->compiled;          // idempotent, no rebuild needed
        }

        $compiledRoutes = array_map(
            static fn (RouteInterface $r): CompiledRoute => CompiledRoute::fromRoute($r),
            $this->routes,
        );

        $this->compiled = new CompiledCollection($compiledRoutes);
        $this->dirty = false;

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
        return $this->byName[$name] ?? null;
    }

    public function findByHandler(callable|string $handler): ?RouteInterface
    {
        $id = Route::fingerprint($handler);      // static helper lives in Route
        return $this->byHandler[$id][0] ?? null;
    }

    public function findAllByHandler(callable|string $handler): array
    {
        $id = Route::fingerprint($handler);
        return $this->byHandler[$id] ?? [];
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
        $this->byName    = [];
        $this->byHandler = [];

        foreach ($this->routes as $route) {
            if (($name = $route->getName()) !== null && $name !== '') {
                $this->byName[$name] = $route;
            }
            $this->byHandler[$route->getHandlerId()][] = $route;
        }
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Route collection already compiled – further mutation prohibited.');
        }
    }

    /**
     * Turn any callable spec into a stable scalar key.
     */
    private static function normaliseHandler(callable|string $h): string
    {
        if (is_string($h)) {
            return $h;
        }

        if (is_array($h)) {
            /** @var array{0:mixed,1:string} $h */
            $class = is_object($h[0]) ? $h[0]::class : (string)$h[0];
            return $class . '::' . $h[1];
        }

        if (is_object($h) && !($h instanceof \Closure)) {
            return $h::class . '::__invoke';
        }

        return 'closure@' . spl_object_id($h);
    }
}
