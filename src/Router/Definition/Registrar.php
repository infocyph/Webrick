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
     *  HTTP verb helpers (3rd arg: string name OR
     *                     ['name'|'as'=>..,'aliases'=>[..],'middleware'=>[..]])
     * ----------------------------------------------------------------*/

    public function get(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('GET', $path, $handler, $nameOrOpts);
    }

    public function post(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('POST', $path, $handler, $nameOrOpts);
    }

    public function put(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('PUT', $path, $handler, $nameOrOpts);
    }

    public function patch(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('PATCH', $path, $handler, $nameOrOpts);
    }

    public function delete(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('DELETE', $path, $handler, $nameOrOpts);
    }

    public function head(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('HEAD', $path, $handler, $nameOrOpts);
    }

    public function options(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('OPTIONS', $path, $handler, $nameOrOpts);
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

            $nameOrOpt = $routeName === null
                ? ($mwAll !== [] ? ['middleware' => $mwAll] : null)
                : ['as' => $routeName, 'middleware' => $mwAll];

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
        $child = new self(
            $this->routes,
            $childScope,
            $this->autoSlashRedirect, // preserve flag inside groups
            false,                    // do NOT re-bind URL services in children
            $this->signKey,
            $this->signedDefaultTtl,
        );
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
     * @param string|array|null $nameOrOpts
     *   string => primary name
     *   array  => ['name'|'as'=>string, 'aliases'|'alias'=>string|string[], 'middleware'=>string[]]
     *
     * @return array{0:?string,1:array,2:array} [name, extraMiddleware, aliases]
     */
    private function normalizeOptions(string|array|null $nameOrOpts): array
    {
        if ($nameOrOpts === null) {
            return [null, [], []];
        }

        if (is_string($nameOrOpts)) {
            return [$nameOrOpts, [], []];
        }

        $name = $nameOrOpts['name'] ?? $nameOrOpts['as'] ?? null;

        $mw = $nameOrOpts['middleware'] ?? [];
        if (!is_array($mw)) {
            $mw = [];
        }

        $aliasesRaw = $nameOrOpts['aliases'] ?? ($nameOrOpts['alias'] ?? []);
        $aliases = is_array($aliasesRaw) ? $aliasesRaw : [$aliasesRaw];
        $aliases = array_values(array_filter(array_map('strval', $aliases), static fn ($s) => $s !== ''));

        return [$name, $mw, $aliases];
    }

    private function add(
        string $verb,
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        // 1) Compute full path with scope prefix
        $fullPrefix = ltrim($this->scope->getPrefix(), '/');
        $fullPath = '/' . ltrim($fullPrefix . '/' . ltrim($path, '/'), '/');

        // 2) Instantiate the Route DTO
        $route = new Route($verb, $fullPath, $handler);

        [$name, $extraMw, $aliases] = $this->normalizeOptions($nameOrOpts);

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

            // Also prefix any provided aliases
            if ($aliases !== []) {
                $aliases = array_map(
                    static fn (string $a) => $namePrefix . $a,
                    $aliases,
                );
            }
        }

        // 4) Register in collection
        $this->routes->add($route);

        // 4.1) Register aliases in the collection (optional fast lookups)
        foreach ($aliases as $alias) {
            // Assumes Collection::addAlias(RouteInterface $route, string $alias): void
            $this->routes->addAlias($route, $alias);
        }

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
