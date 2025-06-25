<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Executes route-specific middleware chain then the callable handler.
 *
 * If the route has no middleware this wrapper should be skipped to
 * avoid overhead (Router already does that optimisation).
 */
final class RouteRunner implements RequestHandlerInterface
{
    public function __construct(
        private readonly RouteInterface $route,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class ($this->route) implements RequestHandlerInterface {
            public function __construct(private RouteInterface $route) {}

            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $resp = ($this->route->getHandler())($r);
                if (!$resp instanceof ResponseInterface) {
                    throw new \RuntimeException('Route handler must return ResponseInterface');
                }
                return $resp;
            }
        };

        foreach (array_reverse($this->route->getMiddlewares()) as $mwDef) {
            $mw = $mwDef instanceof MiddlewareInterface ? $mwDef : new $mwDef();
            $handler = new class ($mw, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private MiddlewareInterface $mw,
                    private RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    return $this->mw->process($r, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }
}
