<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15–compliant middleware pipeline.
 *
 * Usage:
 *   $finalHandler = new SomeFinalHandler();
 *   $stack = new MiddlewareStack([
 *       new AuthMiddleware(),
 *       new LoggingMiddleware(),
 *       // ...
 *   ], $finalHandler);
 *
 *   $response = $stack->handle($request);
 */
class MiddlewareStack implements RequestHandlerInterface
{
    /**
     * @param  MiddlewareInterface[]  $middlewares  Stack of PSR-15 middleware
     * @param  RequestHandlerInterface  $finalHandler  Fallback/final request handler
     */
    public function __construct(private array $middlewares, private readonly RequestHandlerInterface $finalHandler)
    {
    }

    /**
     * Handle the request by building an iterative chain of handlers
     * and then calling the first one.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Start with the final handler at the end of the chain.
        $handler = $this->finalHandler;

        // Wrap each middleware around the next handler, in reverse order.
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(private readonly MiddlewareInterface $middleware, private readonly RequestHandlerInterface $next)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    // Delegate to the current middleware,
                    // which will either produce a response or call $this->next->handle().
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        // Now $handler is the "outermost" middleware. Let it handle the request.
        return $handler->handle($request);
    }
}
