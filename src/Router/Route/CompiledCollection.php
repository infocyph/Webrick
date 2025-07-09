<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use IteratorAggregate;
use Traversable;

/**
 * Immutable set of CompiledRoute objects ready for the Matcher.
 *
 * @implements IteratorAggregate<int, CompiledRoute>
 */
final readonly class CompiledCollection implements IteratorAggregate
{
    /** @param list<CompiledRoute> $routes */
    public function __construct(
        private array $routes
    ) {
    }

    /** @return list<CompiledRoute> */
    public function all(): array
    {
        return $this->routes;
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->routes);
    }
}
