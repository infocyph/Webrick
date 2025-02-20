<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds an iterative chain of middleware, with a final request handler.
 */
class MiddlewareStack implements RequestHandlerInterface
{
    public function __construct(
        /** @var MiddlewareInterface[] */
        private array $middlewares,
        private readonly RequestHandlerInterface $finalHandler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // wrap in reverse order
        $handler = $this->finalHandler;
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        return $handler->handle($request);
    }
}
