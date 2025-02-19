<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Interfaces\RouterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The Router is itself a PSR-15 RequestHandlerInterface.
 * It determines which route matches, then delegates actual handling
 * to a RouteDispatcher (also a RequestHandlerInterface).
 */
class Router implements RouterInterface
{
    private ?RequestHandlerInterface $finalRouteDispatcher = null;

    public function __construct(private readonly RouteCollection $routes)
    {
    }

    /**
     * Handle the incoming request (PSR-15).
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1) Match the route
        [$matchedRoute, $params] = $this->routes->match($request);

        // 2) Inject route params into request attributes
        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        // 3) Defer actual invocation to the "final" dispatcher
        //    which calls the route's callable
        if ($this->finalRouteDispatcher === null) {
            // If not set, use the default RouteDispatcher
            $this->finalRouteDispatcher = new \Infocyph\Webrick\Middleware\RouteDispatcher($matchedRoute);
        } else {
            // If there's a custom final dispatcher, set the route there
            if (method_exists($this->finalRouteDispatcher, 'setRoute')) {
                $this->finalRouteDispatcher->setRoute($matchedRoute);
            }
        }

        return $this->finalRouteDispatcher->handle($request);
    }

    /**
     * Register a new route (method + path + handler).
     */
    public function addRoute(string $method, string $path, callable $handler): RouteInterface
    {
        $route = new Route($method, $path, $handler);
        $this->routes->addRoute($route);

        return $route;
    }

    // Common HTTP verbs

    public function get(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function options(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    public function head(string $path, callable $handler): RouteInterface
    {
        return $this->addRoute('HEAD', $path, $handler);
    }

    /**
     * (Optional) Provide a custom final dispatcher that is also a RequestHandlerInterface.
     *
     * For example, you might create a dispatcher that integrates
     * with a PSR-11 container or logs route invocations.
     */
    public function setFinalRouteDispatcher(RequestHandlerInterface $dispatcher): void
    {
        $this->finalRouteDispatcher = $dispatcher;
    }
}
