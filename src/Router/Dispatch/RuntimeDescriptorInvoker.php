<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use UnexpectedValueException;

/** Resolves Webrick runtime descriptors through the finalized InterMix runtime. */
final readonly class RuntimeDescriptorInvoker
{
    public function __construct(private RuntimeContainerInterface $runtime) {}

    /**
     * @param array<array-key,mixed>|callable|string $descriptor
     * @param array<int|string,mixed> $arguments
     */
    public function resolve(array|callable|string $descriptor, array $arguments = []): mixed
    {
        if (is_array($descriptor)) {
            return $this->resolveArray($descriptor, $arguments);
        }
        if (is_string($descriptor)) {
            return $this->resolveString($descriptor, $arguments);
        }

        return $this->runtime->invoke($descriptor, $arguments);
    }

    /**
     * @param array<array-key,mixed> $descriptor
     * @param array<int|string,mixed> $arguments
     */
    private function resolveArray(array $descriptor, array $arguments): mixed
    {
        if (
            count($descriptor) !== 2
            || !is_string($descriptor[0])
            || !is_string($descriptor[1])
            || !class_exists($descriptor[0])
        ) {
            throw new UnexpectedValueException('Runtime resolver array must contain a class and method.');
        }

        if (is_callable($descriptor)) {
            return $this->runtime->invoke(Closure::fromCallable($descriptor), $arguments);
        }

        $instance = $this->runtime->make($descriptor[0]);
        $callable = [$instance, $descriptor[1]];
        if (!is_callable($callable)) {
            throw new UnexpectedValueException('Runtime resolver method is not callable.');
        }

        return $this->runtime->invoke(Closure::fromCallable($callable), $arguments);
    }

    /**
     * @param array<int|string,mixed> $arguments
     */
    private function resolveString(string $descriptor, array $arguments): mixed
    {
        if (function_exists($descriptor)) {
            return $this->runtime->invoke(Closure::fromCallable($descriptor), $arguments);
        }
        if ($this->runtime->has($descriptor)) {
            return $this->resolveValue($this->runtime->get($descriptor), $arguments);
        }
        if (class_exists($descriptor)) {
            return $this->runtime->make($descriptor, $arguments);
        }

        throw new UnexpectedValueException("Runtime resolver '{$descriptor}' is not resolvable.");
    }

    /**
     * @param array<int|string,mixed> $arguments
     */
    private function resolveValue(mixed $resolved, array $arguments): mixed
    {
        if (!is_callable($resolved)) {
            return $resolved;
        }

        return $this->runtime->invoke(Closure::fromCallable($resolved), $arguments);
    }
}
