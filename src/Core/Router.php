<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Interfaces\RouterInterface;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Middleware\RouteDispatcher;

/**
 * PSR-15 Handler that uses a RouteCollection to match routes, then
 * delegates to a final dispatcher (RouteDispatcher by default).
 */
class Router implements RouterInterface
{
    private ?RequestHandlerInterface $finalRouteDispatcher = null;

    private string $groupPrefix = '';
    private ?string $groupDomain = null;

    public function __construct(private readonly RouteCollection $routes)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1) match route
        [$matchedRoute, $params] = $this->routes->match($request);

        // 2) put params in request attributes
        foreach ($params as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }

        // 3) use final dispatcher
        if ($this->finalRouteDispatcher === null) {
            // default
            $this->finalRouteDispatcher = new RouteDispatcher($matchedRoute);
        } else {
            // if custom dispatcher supports setRoute
            if (method_exists($this->finalRouteDispatcher, 'setRoute')) {
                $this->finalRouteDispatcher->setRoute($matchedRoute);
            }
        }

        return $this->finalRouteDispatcher->handle($request);
    }

    public function setFinalRouteDispatcher(RequestHandlerInterface $handler): void
    {
        $this->finalRouteDispatcher = $handler;
    }

    /**
     * Group routes under a common prefix/domain
     */
    public function group(string $prefix, callable $callback, ?string $domain = null): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousDomain = $this->groupDomain;

        $this->groupPrefix = rtrim($this->groupPrefix, '/') . '/' . ltrim($prefix, '/');
        if ($domain !== null) {
            $this->groupDomain = $domain;
        }

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupDomain = $previousDomain;
    }

    public function addRoute(string $method, string $path, callable $handler): RouteInterface
    {
        // merge with group prefix
        $fullPath = '/' . ltrim(rtrim($this->groupPrefix, '/') . '/' . ltrim($path, '/'), '/');

        $route = new Route($method, $fullPath, $handler);
        if ($this->groupDomain !== null) {
            $route->setDomain($this->groupDomain);
        }

        $this->routes->addRoute($route);
        return $route;
    }

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
}
