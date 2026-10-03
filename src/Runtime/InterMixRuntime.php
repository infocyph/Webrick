<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime;

use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\ScopeContext;

/**
 * Boot-selected InterMix runtime. Webrick never infers or switches runtime mode
 * after construction.
 */
final readonly class InterMixRuntime
{
    public function __construct(private RuntimeContainerInterface $container) {}

    public function captureScopeContext(): ScopeContext
    {
        return $this->container->captureScopeContext();
    }

    public function container(): RuntimeContainerInterface
    {
        return $this->container;
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /** @param array<int|string,mixed> $arguments */
    public function invoke(callable $callable, array $arguments = []): mixed
    {
        return $this->container->invoke($callable, $arguments);
    }

    /**
     * @param class-string $class
     * @param array<int|string,mixed> $arguments
     */
    public function make(string $class, array $arguments = []): object
    {
        return $this->container->make($class, $arguments);
    }

    public function isProduction(): bool
    {
        return $this->container instanceof ProductionContainer;
    }

    public function resetCurrentExecutionScope(): void
    {
        $this->container->resetCurrentExecutionScope();
    }

    /** @return iterable<string,mixed> */
    public function tagged(string $tag): iterable
    {
        return $this->container->tagged($tag);
    }

    /**
     * @param array<string,mixed> $instances
     */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        return $this->container->withinScope($scope, $callback, $instances);
    }

    public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed
    {
        return $this->container->withinScopeContext($scopeContext, $callback);
    }
}
