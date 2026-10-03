<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;

/** Executes deferred alias descriptors inside the active InterMix request scope. */
final readonly class RuntimeAliasInvoker
{
    public function __construct(private RuntimeContainerInterface $invoker) {}

    public function invoke(
        RuntimeMiddlewareDescriptor $descriptor,
        Request $request,
        Closure $next,
        string $alias,
    ): Response {
        $resolved = $this->resolveDescriptor(
            $descriptor->resolverSpec(),
            $descriptor->parameters,
        );
        $result = $this->invokeResolved($resolved, $request, $next);
        if (!$result instanceof Response) {
            throw new InvalidArgumentException("Middleware {$alias} must return Response.");
        }

        return $result;
    }

    /**
     * @param array<array-key,mixed>|callable|string $descriptor
     * @param array<int|string,mixed> $arguments
     */
    private function resolveDescriptor(array|callable|string $descriptor, array $arguments = []): mixed
    {
        if (is_array($descriptor)) {
            if (
                count($descriptor) !== 2
                || !is_string($descriptor[0])
                || !is_string($descriptor[1])
                || !class_exists($descriptor[0])
            ) {
                throw new InvalidArgumentException('Runtime middleware resolver array must contain a class and method.');
            }

            if (is_callable($descriptor)) {
                return $this->invoker->invoke($descriptor, $arguments);
            }

            $instance = $this->invoker->make($descriptor[0]);
            $callable = [$instance, $descriptor[1]];
            if (!is_callable($callable)) {
                throw new InvalidArgumentException('Runtime middleware resolver method is not callable.');
            }

            return $this->invoker->invoke($callable, $arguments);
        }

        if (is_string($descriptor)) {
            if (function_exists($descriptor)) {
                return $this->invoker->invoke($descriptor, $arguments);
            }
            if ($this->invoker->has($descriptor)) {
                $resolved = $this->invoker->get($descriptor);
                if ($arguments === [] || !is_callable($resolved)) {
                    return $resolved;
                }

                return $this->invoker->invoke($resolved, $arguments);
            }
            if (class_exists($descriptor)) {
                $resolved = $this->invoker->make($descriptor);
                if ($arguments === [] || !is_callable($resolved)) {
                    return $resolved;
                }

                return $this->invoker->invoke($resolved, $arguments);
            }

            throw new InvalidArgumentException("Runtime middleware resolver '{$descriptor}' is not resolvable.");
        }

        return $this->invoker->invoke($descriptor, $arguments);
    }

    private function invokeResolved(mixed $resolved, Request $request, Closure $next): mixed
    {
        $parameters = ['request' => $request, 'next' => $next];
        if (is_string($resolved)) {
            $spec = class_exists($resolved) && method_exists($resolved, '__invoke')
                ? [$resolved, '__invoke']
                : $resolved;

            return $this->resolveDescriptor($spec, $parameters);
        }
        if (is_callable($resolved)) {
            return $this->invoker->invoke($resolved, $parameters);
        }

        throw new InvalidArgumentException(sprintf(
            'Runtime middleware alias resolved to unsupported type %s.',
            get_debug_type($resolved),
        ));
    }
}
