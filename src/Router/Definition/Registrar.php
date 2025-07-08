<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use Infocyph\Webrick\Router\Contracts\RouteInterface;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;
use InvalidArgumentException;

/**
 * Fluent, immutable builder that declares routes and groups.
 *
 * All mutators return a new GroupScope; the original is never modified.
 * Routes are instantiated as Route DTOs and pushed into the provided Collection.
 */
final readonly class Registrar
{
    public function __construct(
        private Collection $routes,
        private GroupScope $scope = new GroupScope(),
    ) {
    }

    /* -----------------------------------------------------------------
     *  HTTP verb helpers
     * ----------------------------------------------------------------*/

    public function get(string $path, callable $handler): RouteInterface
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): RouteInterface
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): RouteInterface
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): RouteInterface
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): RouteInterface
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function head(string $path, callable $handler): RouteInterface
    {
        return $this->add('HEAD', $path, $handler);
    }

    public function options(string $path, callable $handler): RouteInterface
    {
        return $this->add('OPTIONS', $path, $handler);
    }

    /* -----------------------------------------------------------------
     *  Grouping
     * ----------------------------------------------------------------*/

    /**
     * Declare a nested group of routes.
     *
     * Supports both “Laravel-style” options array:
     *   ->group(['prefix'=>'v1','middleware'=>[...]], fn($r)=>{})
     *
     * and positional arguments:
     *   ->group('v1', 'api.example.com', [...], 'api.', fn($r)=>{})
     *
     * @param array|string|null $prefix URI prefix or options array
     * @param string|array|Closure|null $domain Domain, middleware list, or callback
     * @param array|Closure $middleware Middleware list or callback
     * @param string|Closure|null $namePrefix Name prefix or callback
     * @param Closure|null $callback Group callback
     */
    public function group(
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        array|Closure $middleware = [],
        string|Closure|null $namePrefix = null,
        ?Closure $callback = null,
    ): void {
        // 1) Laravel-style options array?
        if (is_array($prefix)) {
            $opts = $prefix;
            $callback = $domain instanceof Closure ? $domain : $callback;
            $prefix = $opts['prefix'] ?? null;
            $domain = $opts['domain'] ?? null;
            $middleware = $opts['middleware'] ?? [];
            $namePrefix = $opts['name'] ?? $opts['as'] ?? null;
        }

        // 2) Positional shifting when callback is passed early
        if ($domain instanceof Closure && $callback === null) {
            $callback = $domain;
            $domain = null;
        }
        if ($middleware instanceof Closure && $callback === null) {
            $callback = $middleware;
            $middleware = [];
        }
        if ($namePrefix instanceof Closure && $callback === null) {
            $callback = $namePrefix;
            $namePrefix = null;
        }

        if (!$callback instanceof Closure) {
            throw new InvalidArgumentException('A group callback Closure is required.');
        }

        // 3) Build child scope
        $childScope = $this->scope
            ->withPrefix((string)$prefix)
            ->withDomain(is_string($domain) ? $domain : null)
            ->withMiddleware(is_array($middleware) ? $middleware : [])
            ->withNamePrefix(is_string($namePrefix) ? $namePrefix : '');

        // 4) Delegate into nested Registrar
        $child = new self($this->routes, $childScope);
        $callback($child);
    }

    /* -----------------------------------------------------------------
     *  Internal helper
     * ----------------------------------------------------------------*/

    private function add(string $verb, string $path, callable $handler): RouteInterface
    {
        // 1) Compute full path with scope prefix
        $fullPrefix = ltrim($this->scope->getPrefix(), '/');
        $fullPath = '/' . ltrim($fullPrefix . '/' . ltrim($path, '/'), '/');

        // 2) Instantiate the Route DTO
        $route = new Route($verb, $fullPath, $handler);

        // 3) Apply scope decorations
        if ($domain = $this->scope->getDomain()) {
            $route = $route->withDomain($domain);
        }

        if ($middlewares = $this->scope->getMiddleware()) {
            $route = $route->withMiddleware($middlewares);
        }

        if ($namePrefix = $this->scope->getNamePrefix()) {
            $baseName = $route->getName() ?? '';
            $route = $route->withName($namePrefix . $baseName);
        }

        // 4) Register in collection
        $this->routes->add($route);

        return $route;
    }
}
