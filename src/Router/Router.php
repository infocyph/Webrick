<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Router\Compile\RouteCompiler;
use Infocyph\Webrick\Router\Compile\CompiledRoutes;
use Infocyph\Webrick\Router\Runtime\{Registrar, Route, RouteCollection, MiddlewareRunner};
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};

/**
 * Public-facing Router (PSR-15 RequestHandler *and* fluent registrar).
 */
final class Router
{
    /* ----------------------------------------------------- singleton façade */
    private static ?self $instance = null;
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
    public static function __callStatic(string $m, array $a): mixed
    {
        return self::instance()->$m(...$a);
    }

    /* ---------------------------------------------------------------------- */
    private RouteCollection $routes;
    private Registrar       $registrar;
    private Invoker         $invoker;

    /** @var array<string,string|object> alias ⇒ FQCN|object */
    private array           $mwAliases = [];
    /** @var list<string|object> */
    private array           $globalBefore = [];
    /** @var list<string|object> */
    private array           $globalAfter  = [];

    public function __construct(?Container $c = null)
    {
        $this->routes    = new RouteCollection();
        $this->registrar = new Registrar($this);
        $this->invoker   = Invoker::with($c ?? Container::instance('intermix'));
    }

    /* ------------------------------- fluent registration façade helpers --- */
    public function get(string $p, callable $h): Route   { return $this->registrar->get($p, $h); }
    public function post(string $p, callable $h): Route  { return $this->registrar->post($p, $h); }
    public function put(string $p, callable $h): Route   { return $this->registrar->put($p, $h); }
    public function patch(string $p, callable $h): Route { return $this->registrar->patch($p, $h); }
    public function delete(string $p, callable $h): Route{ return $this->registrar->delete($p, $h); }
    public function head(string $p, callable $h): Route  { return $this->registrar->head($p, $h); }
    public function options(string $p, callable $h): Route{ return $this->registrar->options($p, $h); }

    /** Manual “scope” helper (prefix/mw/domain) */
    public function group(array $opts, \Closure $fn): void
    {
        $this->registrar->group($opts, $fn);
    }

    /* ------------------------------- attribute discovery convenience ------ */
    public function scanAttributes(array $dirs): void
    {
        (new Discovery\AttributeScanner())->scan($this, $dirs);
    }

    /* ------------------------------- middleware helpers ------------------- */
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

    /* ------------------------------- PSR-15 RequestHandler ---------------- */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $host   = $request->getUri()->getHost() ?? '';
        $path   = $request->getUri()->getPath() ?: '/';
        $method = strtoupper($request->getMethod());

        $route  = $this->routes->routeFor($method, $host, $path);

        $stack  = [
            ...$this->resolveAliases($this->globalBefore),
            ...$this->resolveAliases($route->getMiddlewares()),
            ...$this->resolveAliases($this->globalAfter),
        ];

        $core = fn (ServerRequestInterface $r)
            => $this->invoker->invoke($route->getHandler(), [$r]);

        return MiddlewareRunner::run($request, $stack, $core);
    }

    /* ------------------------------- internal glue ------------------------ */
    public function register(Route $route): Route
    {
        $compiled = RouteCompiler::compile($route->getPath());
        $this->routes->add($route, $compiled);
        return $route;
    }

    /** @return list<string|object> */
    private function resolveAliases(array $list): array
    {
        return array_map(
            fn ($a) => $this->mwAliases[$a] ?? $a,
            $list
        );
    }

    /* ------------------------------- helpers ------------------------------ */
    public function urlFor(string $name, array $params = [], bool $abs = false): string
    {
        return (new UrlGenerator($this->routes))->urlFor($name, $params, $abs);
    }

    public function routes(): CompiledRoutes
    {
        return new CompiledRoutes($this->routes->all());
    }
}
