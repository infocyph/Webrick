<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Interfaces\RouterInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Router\Compiled\CompiledRoutes;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The public-facing Router – PSR-15 RequestHandler **and**
 * fluent route registrar in one neat package.
 */
final class Router
{
    private static ?self $instance = null;                 // facade ☝️
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
    public static function __callStatic(string $m, array $a): mixed
    {
        return self::instance()->$m(...$a);
    }
    private RouteCollection $routes;
    private Registrar       $registrar;
    private Invoker         $invoker;

    /** @var array<string,string|object>  alias ⇒ FQCN|obj */
    private array           $mwAliases = [];
    /** @var string[] */
    private array           $globalBefore = [];
    /** @var string[] */
    private array           $globalAfter  = [];

    public function __construct(?Container $c = null)
    {
        $this->routes    = new RouteCollection();
        $this->registrar = new Registrar($this);
        $this->invoker   = Invoker::with($c ?? Container::instance('intermix'));
    }

    /* -------- attribute auto-scan helper -------- */
    public function scanAttributes(array $dirs): void
    {
        AttributeScanner::scan($this, $dirs);
    }

    /* -------- middleware registration -------- */
    public function middlewareAlias(string $name, string|object $mw): self
    {
        $this->mwAliases[$name] = $mw;
        return $this;
    }
    public function globalMiddleware(array $before = [], array $after = []): self
    {
        $this->globalBefore = $before;
        $this->globalAfter  = $after;
        return $this;
    }

    /* =========================================================================
       PSR-15 dispatch (using our Request class)
       =========================================================================*/
    public function handle(Request $request): ResponseInterface
    {
        $host   = $request->getUri()->getHost();
        $path   = $request->getUri()->getPath() ?: '/';
        $method = strtoupper($request->getMethod());

        $route  = $this->routes->routeFor($method, $host, $path);

        /* build middleware stack */
        $stack = [
            ...$this->resolveAliases($this->globalBefore),
            ...$this->resolveAliases($route->getMiddlewares()),
            ...$this->resolveAliases($this->globalAfter),
        ];

        $core = fn (Request $req) => $this->executeHandler($route, $req);

        $resp = MiddlewareRunner::run($request, $stack, $core);

        return $resp;
    }

    private function executeHandler(Route $route, Request $req): ResponseInterface
    {
        $result = $this->invoker->invoke($route->getHandler(), [$req]);

        return $result instanceof ResponseInterface
            ? $result
            : Response::empty(200)->withBody(new Stream((string) $result));
    }

    /** @return list<object|class-string> */
    private function resolveAliases(array $aliases): array
    {
        return array_map(
            fn ($a) => $this->mwAliases[$a] ?? $a,
            $aliases
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

//    public function handle(ServerRequestInterface $request): ResponseInterface
//    {
//        $path   = $request->getUri()->getPath() ?: '/';
//        $method = strtoupper($request->getMethod());
//
//        // match or throw RouteNotFoundException / MethodNotAllowedException
//        $route = $this->routes->routeFor($method, $path);
//
//        // invoke the handler via DI-aware Invoker
//        $result = $this->invoker->invoke(
//            $route->getHandler(),
//            [$request]
//        );
//
//        if ($result instanceof ResponseInterface) {
//            return $result;
//        }
//
//        // if handler returned string / other, wrap in a 200 response
//        return Response::empty(200)
//            ->withBody(new Stream((string) $result));
//    }

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

    public function routes(): CompiledRoutes
    {
        return new CompiledRoutes($this->routes->all());
    }
}
