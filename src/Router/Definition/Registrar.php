<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledCollection;
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
        private bool $autoSlashRedirect = false,
        private bool $exposeUrlServices = false,
        private ?string $signKey = null,
        private ?int $signedDefaultTtl = null,
    ) {
        if ($this->exposeUrlServices) {
            Response::bindUrlServices($this->routes, $this->signKey, $this->signedDefaultTtl);
        }
    }

    /* -----------------------------------------------------------------
     *  HTTP verb helpers (3rd arg: string name OR ['name'|'as'=>..,'middleware'=>[..]])
     * ----------------------------------------------------------------*/

    public function get(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('GET', $path, $handler, $name, $extraMw);
    }

    public function post(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('POST', $path, $handler, $name, $extraMw);
    }

    public function put(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('PUT', $path, $handler, $name, $extraMw);
    }

    public function patch(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('PATCH', $path, $handler, $name, $extraMw);
    }

    public function delete(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('DELETE', $path, $handler, $name, $extraMw);
    }

    public function head(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('HEAD', $path, $handler, $name, $extraMw);
    }

    public function options(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        [$name, $extraMw] = $this->normalizeNameAndMiddleware($nameOrOpts);
        return $this->add('OPTIONS', $path, $handler, $name, $extraMw);
    }

    /* -----------------------------------------------------------------
     *  Resource helper (Laravel-ish) – split for clarity
     * ----------------------------------------------------------------*/

    public function resource(string $name, string $prefix, string $ctrl, array $opts = []): void
    {
        [$param, $only, $except, $names, $mwAll, $patchAction] = $this->parseResourceOptions($opts);
        $spec = $this->buildResourceSpec($param, $patchAction);

        foreach ($spec as [$http, $suffix, $action, $key, $nameable]) {
            if (!$this->includeResourceKey($key, $only, $except)) {
                continue;
            }

            $path = rtrim($prefix, '/') . $suffix;
            $routeName = $nameable ? ($names[$key] ?? "$name.$key") : null;
            $nameOrOpt = $this->routeOptionsArg($routeName, $mwAll);

            $method = strtolower($http);
            $this->{$method}($path, [$ctrl, $action], $nameOrOpt);
        }
    }

    /** @return array{0:string,1:?array,2:?array,3:array,4:array,5:string} */
    private function parseResourceOptions(array $opts): array
    {
        $param = is_string($opts['param'] ?? null) ? $opts['param'] : 'id';
        $only = (isset($opts['only']) && is_array($opts['only'])) ? $opts['only'] : null;
        $except = (isset($opts['except']) && is_array($opts['except'])) ? $opts['except'] : null;
        $names = (isset($opts['names']) && is_array($opts['names'])) ? $opts['names'] : [];
        $mwAll = (isset($opts['middleware']) && is_array($opts['middleware'])) ? $opts['middleware'] : [];
        $patchAction = is_string($opts['patch_action'] ?? null) ? $opts['patch_action'] : 'update';
        return [$param, $only, $except, $names, $mwAll, $patchAction];
    }

    /**
     * Build resource endpoints:
     *  [HTTP, suffix, controllerMethod, key, nameable?]
     *  Note: PATCH maps to update by default; we suppress its name to avoid PUT/PATCH alias collision.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:bool}>
     */
    private function buildResourceSpec(string $param, string $patchAction): array
    {
        return [
            ['GET', '', 'index', 'index', true],
            ['GET', '/create', 'create', 'create', true],
            ['POST', '', 'store', 'store', true],
            ['GET', '/{' . $param . '}', 'show', 'show', true],
            ['GET', '/{' . $param . '}/edit', 'edit', 'edit', true],
            ['PUT', '/{' . $param . '}', 'update', 'update', true],
            [
                'PATCH',
                '/{' . $param . '}',
                $patchAction,
                $patchAction === 'update' ? 'update' : $patchAction,
                $patchAction !== 'update',
            ],
            ['DELETE', '/{' . $param . '}', 'destroy', 'destroy', true],
        ];
    }

    private function includeResourceKey(string $key, ?array $only, ?array $except): bool
    {
        if ($only !== null && !in_array($key, $only, true)) {
            return false;
        }
        if ($except !== null && in_array($key, $except, true)) {
            return false;
        }
        return true;
    }

    /** Build the 3rd verb-arg: string name | ['as'=>..,'middleware'=>[..]] | null */
    private function routeOptionsArg(?string $name, array $mwAll): string|array|null
    {
        if ($name !== null && $mwAll !== []) {
            return ['as' => $name, 'middleware' => $mwAll];
        }
        if ($name !== null) {
            return $name;
        }
        if ($mwAll !== []) {
            return ['middleware' => $mwAll];
        }
        return null;
    }

    /* -----------------------------------------------------------------
     *  Grouping (split normalization from registration)
     * ----------------------------------------------------------------*/

    /**
     * @param array|string|null $prefix
     * @param string|array|Closure|null $domain
     * @param array|Closure $middleware
     * @param string|Closure|null $namePrefix
     * @param Closure|null $callback
     */
    public function group(
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        array|Closure $middleware = [],
        string|Closure|null $namePrefix = null,
        ?Closure $callback = null,
    ): void {
        [$prefix, $domain, $middleware, $namePrefix, $callback] = $this->normalizeGroupInputs(
            $prefix,
            $domain,
            $middleware,
            $namePrefix,
            $callback,
        );

        // Build child scope
        $childScope = $this->scope
            ->withPrefix((string)$prefix)
            ->withDomain(is_string($domain) ? $domain : null)
            ->withMiddleware(is_array($middleware) ? $middleware : [])
            ->withNamePrefix(is_string($namePrefix) ? $namePrefix : '');

        // Delegate into nested Registrar
        $child = new self($this->routes, $childScope);
        $callback($child);
    }

    /**
     * Normalize group arguments (supports Laravel-style array or positional).
     *
     * @return array{0:array|string|null,1:string|array|null,2:array,3:string|null,4:Closure}
     */
    private function normalizeGroupInputs(
        array|string|null $prefix,
        string|array|Closure|null $domain,
        array|Closure $middleware,
        string|Closure|null $namePrefix,
        ?Closure $callback,
    ): array {
        // Laravel-style options array
        if (is_array($prefix)) {
            $opts = $prefix;
            $callback = $domain instanceof Closure ? $domain : $callback;
            $prefix = $opts['prefix'] ?? null;
            $domain = $opts['domain'] ?? null;
            $middleware = $opts['middleware'] ?? [];
            $namePrefix = $opts['name'] ?? $opts['as'] ?? null;
        }

        // Positional shifting when callback is passed early
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

        return [
            $prefix,
            $domain,
            is_array($middleware) ? $middleware : [],
            is_string($namePrefix) ? $namePrefix : null,
            $callback,
        ];
    }

    /* -----------------------------------------------------------------
     *  Compile
     * ----------------------------------------------------------------*/

    public function compile(): CompiledCollection
    {
        return $this->routes->compile();
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------*/

    /**
     * @param string|array|null $nameOrOpts string=name, array=['name'|'as'=>..., 'middleware'=>[...]]
     * @return array{0:?string,1:array}      [name, extraMiddleware]
     */
    private function normalizeNameAndMiddleware(string|array|null $nameOrOpts): array
    {
        if ($nameOrOpts === null) {
            return [null, []];
        }

        if (is_string($nameOrOpts)) {
            return [$nameOrOpts, []];
        }

        $name = $nameOrOpts['name'] ?? $nameOrOpts['as'] ?? null;
        $mw = $nameOrOpts['middleware'] ?? [];
        if (!is_array($mw)) {
            $mw = [];
        }

        return [$name, $mw];
    }

    /**
     * @param string $verb
     * @param string $path
     * @param array|string|callable $handler
     * @param string|null $name
     * @param array $extraMw
     */
    private function add(
        string $verb,
        string $path,
        array|string|callable $handler,
        ?string $name = null,
        array $extraMw = [],
    ): RouteInterface {
        // 1) Compute full path with scope prefix
        $fullPrefix = ltrim($this->scope->getPrefix(), '/');
        $fullPath = '/' . ltrim($fullPrefix . '/' . ltrim($path, '/'), '/');

        // 2) Instantiate the Route DTO
        $route = new Route($verb, $fullPath, $handler);

        // 2.1) Per-call name first (group name prefix will prepend it later)
        if ($name !== null && $name !== '') {
            $route = $route->withName($name);
        }

        // 3) Apply scope decorations
        if ($domain = $this->scope->getDomain()) {
            $route = $route->withDomain($domain);
        }

        // 3.1) Group middleware, then per-call extra middleware
        if ($groupMw = $this->scope->getMiddleware()) {
            $route = $route->withMiddleware($groupMw);
        }
        if ($extraMw !== []) {
            $route = $route->withMiddleware($extraMw);
        }

        // 3.2) Name prefix (prepends whatever name we already set)
        if ($namePrefix = $this->scope->getNamePrefix()) {
            $baseName = $route->getName() ?? '';
            $route = $route->withName($namePrefix . $baseName);
        }

        // 4) Register in collection
        $this->routes->add($route);

        // 5) Optional auto “slash variant” redirect
        if ($this->autoSlashRedirect && $verb === 'GET' && !str_contains($fullPath, '{')) {
            $alt = str_ends_with($fullPath, '/') ? rtrim($fullPath, '/') : $fullPath . '/';

            $this->routes->add(
                new Route(
                    'GET',
                    $alt,
                    static fn () => Response::redirect($fullPath, 308),
                ),
            );
        }

        return $route;
    }
}
