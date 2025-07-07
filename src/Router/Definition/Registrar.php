<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use Infocyph\Webrick\Router\Contracts\RouteInterface;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;

/**
 * Fluent, immutable builder that **declares** routes and groups.
 *
 * Nothing is dispatched here – we only create Route DTOs and push them
 * into the in-memory Collection.
 */
final class Registrar
{
    /** @internal only Router should create a Registrar */
    public function __construct(
        private readonly Collection $routes,
        private readonly GroupScope $scope = new GroupScope(),
    ) {
    }

    /* -----------------------------------------------------------------
     *  Public – HTTP verb helpers
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
     *  Public – grouping
     * ----------------------------------------------------------------*/
    /**
     * Declare a nested group.
     *
     * • **Laravel   style**:  $r->group(['prefix' => 'v1', 'middleware' => …], fn ($r) => …);
     * • **Named args style**: $r->group(prefix: 'v1', middleware: [...], callback: fn ($r) => …);
     *
     * @param array|string|null         $prefix     Either the options‐array *or* the URI prefix
     * @param string|array|Closure|null $domain     Domain *or* middleware *or* the callback
     * @param array|Closure             $middleware Middleware list *or* the callback
     * @param string|Closure|null       $namePrefix Name prefix *or* the callback
     * @param Closure|null              $callback   Group body
     */
    public function group(
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        array|Closure $middleware = [],
        string|Closure|null $namePrefix = null,
        ?Closure $callback = null,
    ): void {
        /* -----------------------------------------------------------------
         *  1) Laravel-style options array?
         * ----------------------------------------------------------------*/
        if (\is_array($prefix)) {
            $opts     = $prefix;
            $callback = $domain instanceof Closure ? $domain : $callback;

            $prefix     = $opts['prefix']     ?? null;
            $domain     = $opts['domain']     ?? null;
            $middleware = $opts['middleware'] ?? [];
            $namePrefix = $opts['name']       // Laravel key
                ?? $opts['as']         // some projects use 'as'
                ?? null;
        }

        /* -----------------------------------------------------------------
         *  2) Shift arguments when positional call omitted $domain
         *     e.g. group('v1', fn($r)=>…)  instead of  group('v1', null, [], null, fn)
         * ----------------------------------------------------------------*/
        if ($domain instanceof Closure && $callback === null) {
            $callback = $domain;
            $domain   = null;
        }
        if ($middleware instanceof Closure && $callback === null) {
            $callback   = $middleware;
            $middleware = [];
        }
        if ($namePrefix instanceof Closure && $callback === null) {
            $callback    = $namePrefix;
            $namePrefix  = null;
        }

        /* ---- runtime guard --------------------------------------------------- */
        if (!$callback instanceof Closure) {
            throw new \InvalidArgumentException('Missing group callback Closure.');
        }

        /* -----------------------------------------------------------------
         *  3) Build the derived scope & delegate
         * ----------------------------------------------------------------*/
        $child = new self(
            $this->routes,
            $this->scope
                ->withPrefix($prefix ?? '')
                ->withDomain($domain)
                ->withMiddleware($middleware)
                ->withNamePrefix($namePrefix ?? ''),
        );

        $callback($child);
    }


    /* -----------------------------------------------------------------
     *  Internal helper
     * ----------------------------------------------------------------*/

    private function add(string $verb, string $path, callable $handler): RouteInterface
    {
        $fullPath = '/' . ltrim($this->scope->prefix() . '/' . ltrim($path, '/'), '/');
        $route = new Route($verb, $fullPath, $handler);

        // apply group decorations
        if ($domain = $this->scope->domain()) {
            $route = $route->withDomain($domain);
        }
        if ($mw = $this->scope->middleware()) {
            $route = $route->withMiddleware($mw);
        }
        if ($this->scope->namePrefix() !== '') {
            $route = $route->withName($this->scope->namePrefix() . ($route->getName() ?? ''));
        }

        $this->routes->add($route);

        return $route;
    }
}
