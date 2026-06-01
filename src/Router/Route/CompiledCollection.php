<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use IteratorAggregate;
use Traversable;

/**
 * CompiledCollection
 *
 * Immutable, readonly collection of CompiledRoute instances produced by the
 * route Collection during the compile phase. This lightweight container is
 * intended for consumption by matcher implementations and preserves insertion
 * order.
 *
 * Responsibilities:
 *  - Hold an ordered, immutable list of CompiledRoute objects.
 *  - Provide iteration and array-like access via all() and IteratorAggregate.
 *
 *
 * @implements IteratorAggregate<int, CompiledRoute>
 */
final readonly class CompiledCollection implements IteratorAggregate
{
    /**
     * @param list<CompiledRoute> $routes Ordered CompiledRoute list.
     */
    public function __construct(private array $routes) {}

    /**
     * Return the underlying ordered list of CompiledRoute objects.
     *
     * The returned array is the internal representation; callers should treat
     * it as immutable.
     *
     * @return list<CompiledRoute> Ordered list of compiled routes.
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * Retrieve an iterator for the compiled routes.
     *
     * Provides support for foreach iteration over the collection in insertion
     * order.
     *
     * @return Traversable<int, CompiledRoute> Iterator over CompiledRoute items.
     */
    public function getIterator(): Traversable
    {
        /** @var \ArrayIterator<int, CompiledRoute> $iterator */
        $iterator = new \ArrayIterator($this->routes);

        return $iterator;
    }
}
