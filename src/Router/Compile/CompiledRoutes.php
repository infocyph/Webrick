<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compile;

use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;

/**
 * Iterable, serialisable snapshot of all compiled routes.
 *
 * @implements IteratorAggregate<int,CompiledRoute>
 */
final class CompiledRoutes implements IteratorAggregate, JsonSerializable
{
    /** @param list<CompiledRoute> $routes */
    public function __construct(private array $routes) {}

    /** @return list<CompiledRoute> */
    public function all(): array              { return $this->routes; }

    /* IteratorAggregate */
    public function getIterator(): \Traversable
    {
        return new ArrayIterator($this->routes);
    }

    /* JsonSerializable */
    public function jsonSerialize(): array    { return $this->routes; }
}
