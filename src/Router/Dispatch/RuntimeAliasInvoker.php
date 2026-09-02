<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;

/** Executes deferred alias descriptors inside the active InterMix request scope. */
final readonly class RuntimeAliasInvoker
{
    public function __construct(private Invoker $invoker) {}

    public function invoke(
        RuntimeMiddlewareDescriptor $descriptor,
        Request $request,
        Closure $next,
        string $alias,
    ): Response {
        $resolved = $this->invoker->getContainer()->resolveNow(
            $descriptor->resolverSpec(),
            $descriptor->parameters,
        );
        $result = $this->invokeResolved($resolved, $request, $next);
        if (!$result instanceof Response) {
            throw new InvalidArgumentException("Middleware {$alias} must return Response.");
        }

        return $result;
    }

    private function invokeResolved(mixed $resolved, Request $request, Closure $next): mixed
    {
        $parameters = ['request' => $request, 'next' => $next];
        if (is_string($resolved)) {
            $spec = class_exists($resolved) && method_exists($resolved, '__invoke')
                ? [$resolved, '__invoke']
                : $resolved;

            return $this->invoker->getContainer()->resolveNow($spec, $parameters);
        }
        if (is_callable($resolved)) {
            return $this->invoker->getContainer()->resolveNow($resolved, $parameters);
        }

        throw new InvalidArgumentException(sprintf(
            'Runtime middleware alias resolved to unsupported type %s.',
            get_debug_type($resolved),
        ));
    }
}
