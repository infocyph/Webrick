<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use InvalidArgumentException;

/**
 * Artifact-safe middleware alias descriptor.
 *
 * The resolver is evaluated inside the active request scope. Parameters are
 * the original positional strings parsed from the route alias and are kept
 * separate from Request/next invocation parameters.
 */
final readonly class RuntimeMiddlewareDescriptor
{
    /**
     * @param list<string> $parameters
     */
    public function __construct(
        public mixed $resolver,
        public array $parameters = [],
    ) {
        if (!is_string($resolver) && !is_array($resolver) && !$resolver instanceof Closure && !is_callable($resolver)) {
            throw new InvalidArgumentException('Runtime middleware resolver must be a resolver-compatible callable descriptor.');
        }
        if (!array_is_list($parameters) || !array_all($parameters, is_string(...))) {
            throw new InvalidArgumentException('Runtime middleware parameters must be a list of strings.');
        }
    }

    /**
     * @return string|array<array-key,mixed>|Closure|callable
     */
    public function resolverSpec(): string|array|Closure|callable
    {
        /** @var string|array<array-key,mixed>|Closure|callable $resolver */
        $resolver = $this->resolver;

        return $resolver;
    }
}
