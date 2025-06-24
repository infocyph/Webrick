<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Interfaces\{RouterInterface, RouteInterface};
use Infocyph\Webrick\Router\Compiler\RouteDumper;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Main entry-point: register routes fluently,
 * handle requests (PSR-15), generate URLs.
 */
final class Router implements RouterInterface
{
    /* -----------------------------------------------------------------
       Bootstrapping helpers
       ----------------------------------------------------------------- */
    public static function boot(bool $useCache = true): self
    {
        $collection = new RouteCollection();
        $router     = new self($collection);

        /* load from cache or let RouteDumper warm it later */
        if ($useCache) {
            (new RouteDumper())->load($collection, static function () {
                /* When cache miss, user route files should be included here.
                   For example:  require base_path('routes/web.php'); */
            });
        }
        return $router;
    }

    /* ----------------------------------------------------------------- */

    public function __construct(private RouteCollection $routes)
    {
    }

    /* ======== request handling (PSR-15) ============================ */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$route, $params] = $this->routes->match($request);
        foreach ($params as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }

        /* Build mini pipeline of route-specific middleware */
        $core = new class ($route) implements RequestHandlerInterface {
            public function __construct(private RouteInterface $route)
            {
            }
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $handler = $this->route->getHandler();
                return ($handler)($r);
            }
        };

        $handler = array_reduce(
            array_reverse($route->getMiddlewares()),
            /** @return RequestHandlerInterface */
            fn (RequestHandlerInterface $next, $mw) => new class ($mw, $next) implements RequestHandlerInterface {
                public function __construct(private $mw, private RequestHandlerInterface $next)
                {
                }
                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    $m = is_string($this->mw) ? new $this->mw() : $this->mw;
                    return $m->process($r, $this->next);
                }
            },
            $core
        );

        return $handler->handle($request);
    }

    /* ======== route registration ================================ */
    public function addRoute(string $method, string $path, callable $handler): RouteInterface
    {
        $route = new Route(strtoupper($method), $path, $handler);
        $this->routes->addRoute($route);
        return $route;
    }

    /* verb shortcuts */
    public function get(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('GET', $p, $h);
    }
    public function post(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('POST', $p, $h);
    }
    public function put(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('PUT', $p, $h);
    }
    public function patch(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('PATCH', $p, $h);
    }
    public function delete(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('DELETE', $p, $h);
    }
    public function head(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('HEAD', $p, $h);
    }
    public function options(string $p, callable $h): RouteInterface
    {
        return $this->addRoute('OPTIONS', $p, $h);
    }

    /* ======== helpers =========================================== */
    public function urlFor(string $name, array $params = [], bool $absolute = false): string
    {
        $url = $this->routes->urlFor($name, $params);
        if ($absolute) {
            // naive absolute generator – replace with full host detection if needed
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url = "{$scheme}://{$host}{$url}";
        }
        return $url;
    }
}
