<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Router\Compiled\CompiledRoute;

/**
 * High-performance container for all compiled routes.
 *
 *  • **Static** map → O(1) array lookup.
 *  • **Dynamic**   → pre-compiled regex list ordered by specificity.
 *  • Keeps a *name ⇒ Route* index for UrlGenerator.
 */
final class RouteCollection
{
    /** @var array<string,array<string,Route>>  [METHOD][path] = Route */
    private array $static = [];

    /** @var array<string,array<int,array{regex:string,vars:list<string>,route:Route}>> */
    private array $dynamic = [];

    /** @var array<string,Route> */
    private array $names = [];

    /* --------------------------------------------------------- */

    public function add(Route $r, array $compiled): void
    {
        // Name index for UrlGenerator
        if ($r->getName()) {
            $this->names[$r->getName()] = $r;
        }

        // Static ?
        if ($compiled['kind'] === 'static') {
            $this->static[$r->getMethod()][$compiled['path']] = $r;
            return;
        }

        // Dynamic – keep list per verb
        $this->dynamic[$r->getMethod()][] = [
            'regex' => $compiled['regex'],
            'vars'  => $compiled['vars'],
            'route' => $r,
        ];
    }

    /* --------------------------------------------------------- */

    public function routeFor(string $method, string $host, string $path): Route
    {
        /* 1️⃣  Fast static lookup */
        foreach ($this->static[$method][$path] ?? [] as $cand) {
            if (!$cand->getDomain() || $cand->getDomain() === $host) {
                return $cand;
            }
        }

        /* 2️⃣ dynamic */
        foreach ($this->dynamic[$method] ?? [] as $e) {
            if (preg_match($e['regex'], $path) &&
                (!$e['route']->getDomain() || $e['route']->getDomain() === $host)) {
                return $e['route'];
            }
        }

        /* 3️⃣  FAILURE HANDLING —— collect allowed verbs for this path */
        $allowed = [];
        foreach ($this->dynamic + $this->static as $verb => $routes) {
            if ($verb === $method) { continue; }

            // static?
            if (isset($routes[$path])) {
                $allowed[] = $verb;
                continue;
            }
            // dynamic ?
            foreach ($routes as $e) {
                $rx = $e['regex'] ?? $e;    // static routes are raw path
                if (is_string($rx) ? preg_match($rx, $path) : ($path === $e)) {
                    $allowed[] = $verb;
                    break;
                }
            }
        }

        if ($allowed) {
            throw new MethodNotAllowedException($method, $path, $allowed);
        }
        throw new RouteNotFoundException($method, $path);
    }

    public function named(string $name): Route
    {
        return $this->names[$name]
            ?? throw new \RuntimeException("Unknown route name: {$name}");
    }

    public function all(): array
    {
        $out = [];

        // 1) Static routes
        foreach ($this->static as $method => $byPath) {
            foreach ($byPath as $path => $route) {
                $out[] = new CompiledRoute(
                    verbs:      [$method],
                    path:       $path,
                    regex:      '#^' . preg_quote($path, '#') . '$#',
                    vars:       [],
                    name:       $route->getName(),
                    middleware: $route->getMiddlewares()
                );
            }
        }

        // 2) Dynamic routes
        foreach ($this->dynamic as $method => $entries) {
            foreach ($entries as $e) {
                /** @var Route $r */
                $r = $e['route'];

                $out[] = new CompiledRoute(
                    verbs:      [$method],
                    path:       $r->getPath(),   // the original pattern, e.g. '/user/{id:int}'
                    regex:      $e['regex'],     // the compiled regex
                    vars:       $e['vars'],      // parameter names in order
                    name:       $r->getName(),
                    middleware: $r->getMiddlewares()
                );
            }
        }

        return $out;
    }
}
