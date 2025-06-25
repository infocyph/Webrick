<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

/**
 * Minimal PSR-15 dispatcher that chains an array of middleware
 * around a core RequestHandler.
 *
 * Usage:
 *     $handler = new Dispatcher($middleware, $core);
 *     $response = $handler->handle($request);
 *
 * • No external dependencies
 * • Immutable once constructed
 */
final class Dispatcher implements RequestHandlerInterface
{
    private RequestHandlerInterface $stack;

    /**
     * @param array<int,MiddlewareInterface> $middlewares
     */
    public function __construct(array $middlewares, RequestHandlerInterface $core)
    {
        $this->stack = array_reduce(
            array_reverse($middlewares),
            /** @return RequestHandlerInterface */
            static fn (RequestHandlerInterface $next, MiddlewareInterface $mw)
                => new class ($mw, $next) implements RequestHandlerInterface {
                    public function __construct(
                        private MiddlewareInterface   $mw,
                        private RequestHandlerInterface $next
                    ) {
                    }
                    public function handle(ServerRequestInterface $r): ResponseInterface
                    {
                        return $this->mw->process($r, $this->next);
                    }
                },
            $core
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stack->handle($request);
    }
}
