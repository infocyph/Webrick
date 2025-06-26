<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteRunner implements RequestHandlerInterface
{
    private Invoker $invoker;

    public function __construct(
        private readonly RouteInterface $route,
        private readonly ContainerInterface $container,
    ) {
        $this->invoker = Invoker::with($this->container);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /* ------------------------------------------------------------
         * Core handler executed via Invoker (container auto-wires args)
         * ---------------------------------------------------------- */
        $core = new class ($this->invoker, $this->route) implements RequestHandlerInterface {
            public function __construct(
                private Invoker $invoker,
                private RouteInterface $route,
            ) {
            }

            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $out = $this->invoker->invoke($this->route->getHandler());
                if (!$out instanceof ResponseInterface) {
                    throw new \RuntimeException('Route handler must return ResponseInterface');
                }
                return $out;
            }
        };

        /* ------------------------------------------------------------
         * Wrap route-level middleware
         * ---------------------------------------------------------- */
        $stack = $core;

        foreach (array_reverse($this->route->getMiddlewares()) as $mwDef) {
            $mw = $this->resolveMiddleware($mwDef);

            $stack = new class ($mw, $stack) implements RequestHandlerInterface {
                public function __construct(
                    private MiddlewareInterface $mw,
                    private RequestHandlerInterface $next
                ) {
                }
                public function handle(ServerRequestInterface $req): ResponseInterface
                {
                    return $this->mw->process($req, $this->next);
                }
            };
        }

        return $stack->handle($request);
    }

    /* ------------------------------------------------------------
       Resolve middleware via container
       ---------------------------------------------------------- */
    private function resolveMiddleware(string|MiddlewareInterface $mw): MiddlewareInterface
    {
        if ($mw instanceof MiddlewareInterface) {
            return $mw;
        }

        if ($this->container->has($mw)) {
            $obj = $this->container->get($mw);
            if ($obj instanceof MiddlewareInterface) {
                return $obj;
            }
        }

        if (class_exists($mw)) {
            $obj = $this->container->make($mw);
            if ($obj instanceof MiddlewareInterface) {
                return $obj;
            }
        }

        throw new \RuntimeException("Cannot resolve middleware {$mw}");
    }
}
