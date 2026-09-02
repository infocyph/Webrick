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
    /** @var list<string> */
    public array $parameters;

    /**
     * @param array<array-key,mixed> $parameters
     */
    public function __construct(
        public mixed $resolver,
        array $parameters = [],
    ) {
        self::assertResolver($resolver);
        self::assertParameters($parameters);

        /** @var list<string> $parameters */
        $this->parameters = $parameters;
    }

    /** @return string|array<array-key,mixed>|Closure|callable */
    public function resolverSpec(): string|array|Closure|callable
    {
        $resolver = $this->resolver;
        if (is_string($resolver) || is_array($resolver) || $resolver instanceof Closure || is_callable($resolver)) {
            return $resolver;
        }

        throw new InvalidArgumentException('Runtime middleware resolver must be a resolver-compatible callable descriptor.');
    }

    /** @param array<array-key,mixed> $parameters */
    private static function assertParameters(array $parameters): void
    {
        if (!array_is_list($parameters)) {
            throw new InvalidArgumentException('Runtime middleware parameters must be a list of strings.');
        }
        foreach ($parameters as $parameter) {
            if (!is_string($parameter)) {
                throw new InvalidArgumentException('Runtime middleware parameters must be a list of strings.');
            }
        }
    }

    private static function assertResolver(mixed $resolver): void
    {
        if (!is_string($resolver) && !is_array($resolver) && !$resolver instanceof Closure && !is_callable($resolver)) {
            throw new InvalidArgumentException('Runtime middleware resolver must be a resolver-compatible callable descriptor.');
        }
    }
}
