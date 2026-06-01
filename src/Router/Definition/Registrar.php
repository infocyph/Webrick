<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledCollection;
use Infocyph\Webrick\Router\Route\Route;
use InvalidArgumentException;

/**
 * Registrar
 *
 * Fluent, immutable builder used to declare routes and groups.
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
            Router::bindUrlServices($this->routes, $this->signKey, $this->signedDefaultTtl);
        }
    }

    /* -----------------------------------------------------------------
     *  Compile
     * ----------------------------------------------------------------*/

    public function compile(): CompiledCollection
    {
        return $this->routes->compile();
    }

    /* -----------------------------------------------------------------
     *  HTTP verb helpers
     * ----------------------------------------------------------------*/

    public function delete(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::DELETE->value, $path, $handler, $nameOrOpts);
    }

    public function get(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::GET->value, $path, $handler, $nameOrOpts);
    }

    /* -----------------------------------------------------------------
     *  Grouping
     * ----------------------------------------------------------------*/

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

        $childScope = $this->scope
            ->withPrefix((string) $prefix)
            ->withDomain(is_string($domain) ? $domain : null)
            ->withMiddleware(is_array($middleware) ? $middleware : [])
            ->withNamePrefix(is_string($namePrefix) ? $namePrefix : '');

        $child = new self(
            $this->routes,
            $childScope,
            $this->autoSlashRedirect,
            false,
            $this->signKey,
            $this->signedDefaultTtl,
        );

        Router::withScopedInstance($child, $callback);
    }

    public function head(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::HEAD->value, $path, $handler, $nameOrOpts);
    }

    public function options(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::OPTIONS->value, $path, $handler, $nameOrOpts);
    }

    public function patch(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::PATCH->value, $path, $handler, $nameOrOpts);
    }

    public function post(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::POST->value, $path, $handler, $nameOrOpts);
    }

    public function put(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add(HttpMethodEnum::PUT->value, $path, $handler, $nameOrOpts);
    }

    /* -----------------------------------------------------------------
     *  Resource helper (Laravel-ish)
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

            $method = strtolower((string) $http);
            $this->{$method}($path, [$ctrl, $action], $nameOrOpt);
        }
    }

    /* -----------------------------------------------------------------
     *  Core registration (refactored)
     * ----------------------------------------------------------------*/

    private function add(
        string $verb,
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        $fullPath = $this->computeFullPath($path);
        $route = $this->instantiateRoute($verb, $fullPath, $handler);

        // [name, extraMw, aliases, attributes]
        [$name, $extraMw, $aliases, $attributes] = $this->normalizeOptions($nameOrOpts);

        $route = $this->applyPerCallOptions($route, $name, $attributes);
        $route = $this->decorateWithScope($route, $extraMw, $aliases);

        $this->registerRouteAndAliases($route, $aliases);
        $this->maybeRegisterSlashVariant($verb, $fullPath);

        return $route;
    }

    private function applyPerCallOptions(Route $route, ?string $name, array $attributes): Route
    {
        if ($name !== null && $name !== '') {
            $route = $route->withName($name);
        }

        if (!empty($attributes)) {
            if (method_exists($route, 'withAttributes')) {
                $route = $route->withAttributes($attributes);
            } elseif (method_exists($route, 'withMeta')) {
                $route = $route->withMeta($attributes);
            }
        }

        return $route;
    }

    /* -----------------------------------------------------------------
     *  Resource internals
     * ----------------------------------------------------------------*/

    private function buildResourceSpec(string $param, string $patchAction): array
    {
        return [
            [HttpMethodEnum::GET->value, '', 'index', 'index', true],
            [HttpMethodEnum::GET->value, '/create', 'create', 'create', true],
            [HttpMethodEnum::POST->value, '', 'store', 'store', true],
            [HttpMethodEnum::GET->value, '/{' . $param . '}', 'show', 'show', true],
            [HttpMethodEnum::GET->value, '/{' . $param . '}/edit', 'edit', 'edit', true],
            [HttpMethodEnum::PUT->value, '/{' . $param . '}', 'update', 'update', true],
            [HttpMethodEnum::PATCH->value, '/{' . $param . '}', $patchAction, $patchAction, $patchAction !== 'update'],
            [HttpMethodEnum::DELETE->value, '/{' . $param . '}', 'destroy', 'destroy', true],
        ];
    }

    /* ===== Helpers for add() ===== */

    private function computeFullPath(string $path): string
    {
        $fullPrefix = ltrim($this->scope->getPrefix(), '/');

        return '/' . ltrim($fullPrefix . '/' . ltrim($path, '/'), '/');
    }

    /**
     * Apply scope domain, merge+resolve middleware, and prepend name/aliases with group prefix.
     * NOTE: $aliases is passed by reference so we can mutate the list in-place.
     */
    private function decorateWithScope(Route $route, array $extraMw, array &$aliases): Route
    {
        // Domain
        if ($domain = $this->scope->getDomain()) {
            $route = $route->withDomain($domain);
        }

        // Middleware (group + route, with alias overrides → resolved)
        $groupMw = $this->scope->getMiddleware() ?? [];
        $merged = $this->mergeMiddlewareWithAliasOverrides($groupMw, $extraMw);
        $resolved = $this->resolveAliasMiddleware($merged);
        if ($resolved !== []) {
            $route = $route->withMiddleware($resolved);
        }

        // Name prefix (+ alias prefixing)
        if ($namePrefix = $this->scope->getNamePrefix()) {
            $baseName = $route->getName() ?? '';
            $route = $route->withName($namePrefix . $baseName);

            if ($aliases !== []) {
                $aliases = array_map(
                    static fn(string $a) => $namePrefix . $a,
                    $aliases,
                );
            }
        }

        return $route;
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

    private function instantiateRoute(string $verb, string $fullPath, array|string|callable $handler): Route
    {
        return new Route($verb, $fullPath, $handler);
    }

    private function maybeRegisterSlashVariant(string $verb, string $fullPath): void
    {
        if (!$this->autoSlashRedirect || $verb !== HttpMethodEnum::GET->value || str_contains($fullPath, '{')) {
            return;
        }
        $alt = str_ends_with($fullPath, '/') ? rtrim($fullPath, '/') : $fullPath . '/';
        $this->routes->add(
            new Route(
                HttpMethodEnum::GET->value,
                $alt,
                static fn() => Response::redirect($fullPath, StatusEnum::PERMANENT_REDIRECT->value),
            ),
        );
    }

    /**
     * Merge group + route middleware arrays with alias override semantics.
     */
    private function mergeMiddlewareWithAliasOverrides(array $group, array $route): array
    {
        $result = [];
        $aliasPos = []; // aliasKey => index in $result

        $push = function (mixed $mw) use (&$result, &$aliasPos): void {
            if (is_string($mw) && ($spec = $this->parseAliasSpec($mw))) {
                if (isset($aliasPos[$spec['key']])) {
                    unset($result[$aliasPos[$spec['key']]]);
                }
                $result[] = $spec;
                $aliasPos[$spec['key']] = array_key_last($result);
            } else {
                $result[] = $mw;
            }
        };

        foreach ($group as $mw) {
            $push($mw);
        }
        foreach ($route as $mw) {
            $push($mw);
        }

        return array_values($result);
    }

    /* -----------------------------------------------------------------
     *  Group helpers
     * ----------------------------------------------------------------*/

    private function normalizeGroupInputs(
        array|string|null $prefix,
        string|array|Closure|null $domain,
        array|Closure $middleware,
        string|Closure|null $namePrefix,
        ?Closure $callback,
    ): array {
        if (is_array($prefix)) {
            $opts = $prefix;
            $callback = $domain instanceof Closure ? $domain : $callback;
            $prefix = $opts['prefix'] ?? null;
            $domain = $opts['domain'] ?? null;
            $middleware = $opts['middleware'] ?? [];
            $namePrefix = $opts['name'] ?? $opts['as'] ?? null;
        }

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
     *  Options / aliases utilities
     * ----------------------------------------------------------------*/

    /**
     * Normalize name/options array into canonical tuple.
     * Returned: [name|null, extraMiddleware:list, aliases:list, attributes:array]
     */
    private function normalizeOptions(string|array|null $nameOrOpts): array
    {
        if ($nameOrOpts === null) {
            return [null, [], [], []];
        }

        if (is_string($nameOrOpts)) {
            return [$nameOrOpts, [], [], []];
        }

        $name = $nameOrOpts['name'] ?? $nameOrOpts['as'] ?? null;

        $mw = $nameOrOpts['middleware'] ?? [];
        if (!is_array($mw)) {
            $mw = [];
        }

        $aliasesRaw = $nameOrOpts['aliases'] ?? ($nameOrOpts['alias'] ?? []);
        $aliases = is_array($aliasesRaw) ? $aliasesRaw : [$aliasesRaw];
        $aliases = array_values(array_filter(array_map(strval(...), $aliases), static fn($s) => $s !== ''));

        $attrs = $nameOrOpts['attributes'] ?? [];
        if (!is_array($attrs)) {
            $attrs = [];
        }

        return [$name, $mw, $aliases, $attrs];
    }

    private function parseAliasSpec(string $s): ?array
    {
        if (class_exists($s)) {
            return null;
        }

        [$name, $paramStr] = explode(':', $s, 2) + [1 => null];
        $key = strtolower(trim((string) $name));

        if ($key === '' || !MiddlewareAliases::has($key)) {
            return null;
        }

        $params = ($paramStr !== null && $paramStr !== '')
            ? array_map(trim(...), explode(',', $paramStr))
            : [];

        return ['__alias' => true, 'key' => $key, 'params' => $params];
    }

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

    private function registerRouteAndAliases(Route $route, array $aliases): void
    {
        $this->routes->add($route);
        foreach ($aliases as $alias) {
            $this->routes->addAlias($route, $alias);
        }
    }

    private function resolveAliasMiddleware(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (is_array($item) && ($item['__alias'] ?? false) === true) {
                $key = (string) $item['key'];
                $params = $item['params'] ?? [];
                $s = $key;
                if ($params !== []) {
                    $s .= ':' . implode(',', array_map(static fn($v) => (string) $v, $params));
                }
                $out[] = MiddlewareAliases::resolveString($s);
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }
}
