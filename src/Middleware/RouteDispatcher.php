<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * RouteDispatcher is the final request handler that:
 * 1) Builds a mini pipeline of route-level middleware (if any).
 * 2) Invokes the route's callable (closure, array, or "Class@method").
 * 3) Optionally uses Intermix (PSR-11) for class resolution.
 */
class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(private ?RouteInterface $route = null, private readonly ?ContainerInterface $container = null)
    {
    }

    public function setRoute(RouteInterface $route): void
    {
        $this->route = $route;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->route) {
            throw new RuntimeException('No route set in RouteDispatcher.');
        }

        // Build route-level middleware pipeline
        $middlewares = $this->resolveMiddlewares($this->route->getMiddlewares());

        // The final "core" handler
        $coreHandler = new class ($this->route, $this->container) implements RequestHandlerInterface {
            public function __construct(private readonly RouteInterface $route, private readonly ?ContainerInterface $container)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $handler = $this->route->getHandler();

                // If it's a string like "SomeController@method"
                if (is_string($handler) && str_contains($handler, '@')) {
                    [$class, $method] = explode('@', $handler);
                    $instance = $this->resolveClass($class);
                    $handler  = [$instance, $method];
                }
                // If it's array-based [ClassName::class, 'method']
                elseif (is_array($handler) && isset($handler[0], $handler[1])) {
                    [$class, $method] = $handler;
                    if (is_string($class)) {
                        $instance = $this->resolveClass($class);
                        $handler  = [$instance, $method];
                    }
                }

                $response = \call_user_func($handler, $request);
                if (!$response instanceof ResponseInterface) {
                    throw new RuntimeException('Route handler must return a valid ResponseInterface.');
                }
                return $response;
            }

            private function resolveClass(string $class)
            {
                // If using Intermix container to create or retrieve the class instance
                if ($this->container && $this->container->has($class)) {
                    return $this->container->get($class);
                }
                // fallback: just new it
                return new $class();
            }
        };

        // Wrap them (reverse order) around coreHandler
        $handler = $coreHandler;
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $m = $middlewares[$i];
            $handler = new class ($m, $handler) implements RequestHandlerInterface {
                public function __construct(private readonly MiddlewareInterface $middleware, private readonly RequestHandlerInterface $next)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }

    /**
     * Convert route-level middleware definitions into objects.
     * e.g. [AuthMiddleware::class] => [new AuthMiddleware()]
     */
    private function resolveMiddlewares(array $defined): array
    {
        $resolved = [];
        foreach ($defined as $mw) {
            if ($mw instanceof MiddlewareInterface) {
                // already an object
                $resolved[] = $mw;
            } elseif (is_string($mw)) {
                if ($this->container && $this->container->has($mw)) {
                    // retrieve from container
                    $obj = $this->container->get($mw);
                    if (!$obj instanceof MiddlewareInterface) {
                        throw new RuntimeException("Resolved $mw but it's not a MiddlewareInterface.");
                    }
                    $resolved[] = $obj;
                } else {
                    // just new it
                    $obj = new $mw();
                    if (!$obj instanceof MiddlewareInterface) {
                        throw new RuntimeException("Class $mw is not a MiddlewareInterface.");
                    }
                    $resolved[] = $obj;
                }
            } else {
                throw new RuntimeException("Invalid route middleware entry: $mw");
            }
        }
        return $resolved;
    }
}
