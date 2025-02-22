<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Interfaces\RouterInterface;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Middleware\RouteDispatcher;

class Router implements RouterInterface
{
    private ?RequestHandlerInterface $finalRouteDispatcher = null;

    // For grouping
    private string $groupPrefix = '';
    private ?string $groupDomain = null;
    private array $groupMiddlewares = [];

    public function __construct(private readonly RouteCollection $routes)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1) match route
        [$matchedRoute, $params] = $this->routes->match($request);

        // 2) put params in request attributes
        foreach ($params as $key => $val) {
            $request = $request->withAttribute($key, $val);
        }

        // 3) Build final dispatcher
        if ($this->finalRouteDispatcher === null) {
            // default
            $this->finalRouteDispatcher = new RouteDispatcher($matchedRoute);
        } else {
            if (method_exists($this->finalRouteDispatcher, 'setRoute')) {
                $this->finalRouteDispatcher->setRoute($matchedRoute);
            }
        }

        // 4) Let the dispatcher handle the request
        return $this->finalRouteDispatcher->handle($request);
    }

    public function setFinalRouteDispatcher(RequestHandlerInterface $dispatcher): void
    {
        $this->finalRouteDispatcher = $dispatcher;
    }

    /**
     * Group routes under a prefix, domain, and optional middleware list.
     *
     * Example:
     *  $router->group('/admin', function($r) {
     *      $r->get('/dashboard', ...);
     *  }, 'admin.example.com')->middleware([AuthMiddleware::class]);
     */
    public function group(string $prefix, callable $callback, ?string $domain = null): self
    {
        // Save old state
        $oldPrefix       = $this->groupPrefix;
        $oldDomain       = $this->groupDomain;
        $oldMiddlewares  = $this->groupMiddlewares;

        // Update group prefix/domain
        $this->groupPrefix     = rtrim($oldPrefix, '/') . '/' . ltrim($prefix, '/');
        $this->groupDomain     = $domain ?? $oldDomain;
        // Middlewares are appended by ->middleware()

        // Call user-defined route definitions
        $callback($this);

        // restore
        $this->groupPrefix     = $oldPrefix;
        $this->groupDomain     = $oldDomain;
        $this->groupMiddlewares = $oldMiddlewares;

        return $this; // for chaining
    }

    /**
     * Set or append group-level middlewares that apply to *all* routes in the group.
     */
    public function middleware(array $middlewares): self
    {
        $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middlewares);
        return $this;
    }

    public function addRoute(string $method, string $path, callable $handler): RouteInterface
    {
        $fullPath = '/' . ltrim(rtrim($this->groupPrefix, '/') . '/' . ltrim($path, '/'), '/');

        $route = new Route($method, $fullPath, $handler);
        // If a group domain is set, apply it
        if ($this->groupDomain !== null) {
            $route->setDomain($this->groupDomain);
        }
        // Also apply group-level middlewares
        $route->middleware($this->groupMiddlewares);

        $this->routes->addRoute($route);
        return $route;
    }

    // Common verbs
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
