<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ProductionContainer;

/**
 * Boot-selected InterMix runtime. Webrick never infers or switches runtime mode
 * after construction.
 */
final readonly class InterMixRuntime
{
    public function __construct(private Container|ProductionContainer $container) {}

    public function container(): Container|ProductionContainer
    {
        return $this->container;
    }

    /**
     * @return array<string,mixed>
     * @param string $tag
     */
    public function findByTag(string $tag): array
    {
        return $this->container->findByTag($tag);
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function isProduction(): bool
    {
        return $this->container instanceof ProductionContainer;
    }

    /**
     * Resolve/invoke a compiled handler or a narrow InterMix dynamic island.
     *
     * @param string|array<array-key,mixed>|Closure|callable|null $spec
     * @param array<int|string,mixed> $parameters
     */
    public function resolveNow(string|array|Closure|callable|null $spec, array $parameters = []): mixed
    {
        return $this->container->resolveNow($spec, $parameters);
    }

    /**
     * @param array<string,mixed> $instances
     * @param string $scope
     * @param callable $callback
     */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        return $this->container->withinScope($scope, $callback, $instances);
    }
}
