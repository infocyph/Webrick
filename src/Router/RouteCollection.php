<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Psr\Http\Message\ServerRequestInterface;

/**
 * Holds the compiled static & dynamic route tables.
 * Provides export/hydrate for the compiler cache.
 */
final class RouteCollection
{
    /** @var array<string,array<string,array<string,RouteInterface>>> */
    private array $staticRoutes = [];        // [domain][method][path]
    /** @var list<array{domain:string,method:string,pattern:string,paramNames:array<int,string>,route:RouteInterface}> */
    private array $dynamicRoutes = [];
    /** @var array<string,RouteInterface> */
    private array $named = [];

    /* -----------------------------------------------------------------
       Public API
       ----------------------------------------------------------------- */
    public function addRoute(RouteInterface $route): void
    {
        $method = strtoupper($route->getMethod());
        $domain = $route->getDomain() ?? '';
        $path   = $route->getPath();

        if ($route->getName()) {
            $this->named[$route->getName()] = $route;
        }

        if (str_contains($path, '{')) {
            $parser  = new RouteParser();
            $parsed  = $parser->parse($path);
            $this->dynamicRoutes[] = [
                'domain'     => $domain,
                'method'     => $method,
                'pattern'    => $parsed['pattern'],
                'paramNames' => $parsed['paramNames'],
                'route'      => $route,
            ];
        } else {
            $this->staticRoutes[$domain][$method][$path] = $route;
        }
    }

    /**
     * @return array{RouteInterface,array<string,string>}
     */
    public function match(ServerRequestInterface $req): array
    {
        $verb   = strtoupper($req->getMethod());
        $domain = $req->getUri()->getHost() ?? '';
        $path   = $req->getUri()->getPath() ?: '/';

        /* 1) static lookup -------------------------------------- */
        $staticDomain = $this->staticRoutes[$domain] ?? $this->staticRoutes[''] ?? [];
        if (isset($staticDomain[$verb][$path])) {
            return [$staticDomain[$verb][$path], []];
        }

        /* 2) dynamic -------------------------------------------- */
        $allowed = [];
        foreach ($this->dynamicRoutes as $d) {
            if ($d['domain'] !== '' && $d['domain'] !== $domain) {
                continue;
            }
            if (!preg_match($d['pattern'], $path, $m)) {
                continue;
            }
            if ($d['method'] !== $verb) {
                $allowed[$d['method']] = true;
                continue;
            }
            $params = [];
            foreach ($d['paramNames'] as $n) { $params[$n] = $m[$n]; }
            return [$d['route'], $params];
        }

        /* 3) errors --------------------------------------------- */
        if ($allowed !== []) {
            throw new MethodNotAllowedException('Allowed: ' . implode(', ', array_keys($allowed)));
        }
        throw new RouteNotFoundException("No route for {$verb} {$path}");
    }

    /* -----------------------------------------------------------------
       URL generation helper
       ----------------------------------------------------------------- */
    public function urlFor(string $name, array $params): string
    {
        if (!isset($this->named[$name])) {
            throw new \RuntimeException("Named route '{$name}' not found");
        }
        $path = $this->named[$name]->getPath();

        $out = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)[^}]*\}/', function ($m) use (&$params) {
            $key = $m[1];
            if (!array_key_exists($key, $params)) {
                throw new \RuntimeException("Missing parameter '{$key}' for URL generation");
            }
            $val = $params[$key];
            unset($params[$key]);
            return $val;
        }, $path);

        if ($params) {
            $out .= '?' . http_build_query($params);
        }
        return $out;
    }

    /* -----------------------------------------------------------------
       Compiler support
       ----------------------------------------------------------------- */
    public function exportStatic(): array   { return $this->staticRoutes;  }
    public function exportDynamic(): array  { return $this->dynamicRoutes; }
    public function exportNamed(): array    { return $this->named;         }

    public function hydrate(array $static, array $dynamic, array $named): void
    {
        $this->staticRoutes  = $static;
        $this->dynamicRoutes = $dynamic;
        $this->named         = $named;
    }

    /* For CLI diagnostics */
    public function named(): array { return $this->named; }
}
