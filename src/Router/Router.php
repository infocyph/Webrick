<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Interfaces\RouterInterface;
use Infocyph\Webrick\Response\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The public-facing Router – PSR-15 RequestHandler **and**
 * fluent route registrar in one neat package.
 */
final class Router implements RouterInterface
{
    private RouteCollection $routes;
    private Registrar       $registrar;
    private Invoker         $invoker;

    public function __construct(?Container $container = null)
    {
        $this->routes    = new RouteCollection();
        $this->registrar = new Registrar($this);
        $this->invoker   = Invoker::with(
            $container ?? Container::instance('intermix')
        );
    }

    /* =========================================================================
       Registrar façade – users call $router->get(…); under the hood this
       delegates to our Registrar, which builds a Route and hands it back.
       =========================================================================*/

    public function addRoute(string $method, string $path, callable $handler): RouteInterface
    {
        return $this->registrar->{$method}($path, $handler);
    }

    public function get(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->get($path, $handler);
    }

    public function post(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->post($path, $handler);
    }

    public function put(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->put($path, $handler);
    }

    public function patch(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->patch($path, $handler);
    }

    public function delete(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->delete($path, $handler);
    }

    public function head(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->head($path, $handler);
    }

    public function options(string $path, callable $handler): RouteInterface
    {
        return $this->registrar->options($path, $handler);
    }

    /**
     * Internal hook: Registrar calls this to finalize a Route.
     */
    public function register(Route $route): Route
    {
        $compiled = RouteCompiler::compile($route->getPath());
        $this->routes->add($route, $compiled);
        return $route;
    }

    /* =========================================================================
       PSR-15 dispatch
       =========================================================================*/

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path   = $request->getUri()->getPath() ?: '/';
        $method = strtoupper($request->getMethod());

        // match or throw RouteNotFoundException / MethodNotAllowedException
        $route = $this->routes->routeFor($method, $path);

        // invoke the handler via DI-aware Invoker
        $result = $this->invoker->invoke(
            $route->getHandler(),
            [$request]
        );

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        // if handler returned string / other, wrap in a 200 response
        return Response::empty(200)
            ->withBody(new Stream((string) $result));
    }

    /* =========================================================================
       URL generator
       =========================================================================*/

    public function urlFor(string $name, array $params = [], bool $absolute = false): string
    {
        return new UrlGenerator($this->routes)
            ->urlFor($name, $params, $absolute);
    }

    /* =========================================================================
       Shortcut to the Registrar for groups, middleware, etc.
       =========================================================================*/

    public function scope(): Registrar
    {
        return $this->registrar;
    }

    public function routes(): array
    {
        return $this->routes->all();
    }
}
