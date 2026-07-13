<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledCollection;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

/**
 * Registrar
 *
 * Fluent, immutable builder used to declare routes and groups.
 *
 * @phpstan-type MiddlewareEntry string|object
 * @phpstan-type RawMiddlewareEntry string|object
 * @phpstan-type RawMiddlewareList list<RawMiddlewareEntry>
 * @phpstan-type MiddlewareList list<MiddlewareEntry>
 * @phpstan-type HandlerArray array{0:string|object,1:string}
 * @phpstan-type RouteHandler HandlerArray|string|callable
 * @phpstan-type RouteAttributes array{produces?:Produces,cors?:Cors}
 * @phpstan-type RouteOptions array{
 *   name?:mixed,
 *   as?:mixed,
 *   middleware?:mixed,
 *   aliases?:mixed,
 *   alias?:mixed,
 *   attributes?:mixed
 * }
 * @phpstan-type AliasSpec array{__alias:true,key:non-empty-string,params:list<string>}
 * @phpstan-type ResolvedMiddlewareEntry MiddlewareEntry|callable|string|AliasSpec
 * @phpstan-type ResourceSpecRow array{0:string,1:string,2:string,3:string,4:bool}
 * @phpstan-type GroupInput array{
 *   prefix?:mixed,
 *   domain?:mixed,
 *   middleware?:mixed,
 *   name?:mixed,
 *   as?:mixed
 * }
 * @method RouteInterface delete(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
 * @method RouteInterface get(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
 * @method RouteInterface head(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
 * @method RouteInterface options(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
 * @method RouteInterface patch(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
 * @method RouteInterface post(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
 * @method RouteInterface put(string $path, RouteHandler $handler, string|RouteOptions|null $nameOrOpts = null)
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
        private ?SignedUrlConfig $signedUrlConfig = null,
        private string $urlBaseUri = '',
    ) {
        if ($this->exposeUrlServices) {
            Router::bindUrlServices(
                $this->routes,
                $this->signKey,
                $this->signedDefaultTtl,
                $this->signedUrlConfig,
                $this->urlBaseUri,
            );
        }
    }

    /* -----------------------------------------------------------------
     *  HTTP verb helpers
     * ----------------------------------------------------------------*/

    /**
     * @param array{0:mixed,1:mixed,2?:mixed} $args
     */
    public function __call(string $method, array $args): mixed
    {
        $verb = HttpMethodEnum::tryFrom(strtoupper($method));
        if ($verb === null) {
            throw new \BadMethodCallException("Method {$method} does not exist on " . self::class . '.');
        }

        [$path, $handler, $nameOrOpts] = $this->normalizeVerbCallArgs($method, $args);

        return $this->verb($verb, $path, $handler, $nameOrOpts);
    }

    /* -----------------------------------------------------------------
     *  Compile
     * ----------------------------------------------------------------*/

    public function compile(): CompiledCollection
    {
        return $this->routes->compile();
    }

    /* -----------------------------------------------------------------
     *  Grouping
     * ----------------------------------------------------------------*/
    /**
     * @param string|GroupInput|null $prefix
     * @param string|GroupInput|Closure|null $domain
     * @param list<mixed>|Closure $middleware
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

        $scopeMiddleware = $this->resolveAliasMiddleware(
            $this->mergeMiddlewareWithAliasOverrides([], $middleware),
        );

        $childScope = $this->scope
            ->withPrefix($prefix ?? '')
            ->withDomain($domain)
            ->withMiddleware($scopeMiddleware)
            ->withNamePrefix($namePrefix ?? '');

        $child = new self(
            $this->routes,
            $childScope,
            $this->autoSlashRedirect,
            false,
            $this->signKey,
            $this->signedDefaultTtl,
            $this->signedUrlConfig,
            $this->urlBaseUri,
        );

        Router::withScopedInstance($child, $callback);
    }

    /* -----------------------------------------------------------------
     *  Resource helper (Laravel-ish)
     * ----------------------------------------------------------------*/

    /** @param array<string,mixed> $opts */
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

    /* -----------------------------------------------------------------
     *  Core registration (refactored)
     * ----------------------------------------------------------------*/

    /**
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
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

    /** @param RouteAttributes $attributes */
    private function applyPerCallOptions(Route $route, ?string $name, array $attributes): Route
    {
        if ($name !== null && $name !== '') {
            $route = $route->withName($name);
        }

        $cors = $attributes['cors'] ?? null;
        if ($cors instanceof Cors) {
            $route = $route->withCorsPolicy($cors);
        }

        return $route;
    }

    /* -----------------------------------------------------------------
     *  Resource internals
     * ----------------------------------------------------------------*/

    /** @return list<ResourceSpecRow> */
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
    /**
     * @param RawMiddlewareList $extraMw
     * @param list<string> $aliases
     */
    private function decorateWithScope(Route $route, array $extraMw, array &$aliases): Route
    {
        // Domain
        if ($domain = $this->scope->getDomain()) {
            $route = $route->withDomain($domain);
        }

        // Middleware (group + route, with alias overrides → resolved)
        $groupMw = $this->scope->getMiddleware();
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
                $aliases = array_map(static fn(string $a): string => $namePrefix . $a, $aliases);
            }
        }

        return $route;
    }

    /**
     * @param list<string>|null $only
     * @param list<string>|null $except
     */
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

    /** @param RouteHandler $handler */
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
    /**
     * @param RawMiddlewareList $group
     * @param RawMiddlewareList $route
     * @return list<RawMiddlewareEntry|AliasSpec>
     */
    private function mergeMiddlewareWithAliasOverrides(array $group, array $route): array
    {
        return registrar_merge_middleware_with_alias_overrides($group, $route);
    }

    /* -----------------------------------------------------------------
     *  Group helpers
     * ----------------------------------------------------------------*/
    /**
     * @param string|GroupInput|null $prefix
     * @param string|GroupInput|Closure|null $domain
     * @param list<mixed>|Closure $middleware
     * @return array{
     *   0:?string,
     *   1:?string,
     *   2:RawMiddlewareList,
     *   3:?string,
     *   4:Closure
     * }
     */
    private function normalizeGroupInputs(
        array|string|null $prefix,
        string|array|Closure|null $domain,
        array|Closure $middleware,
        string|Closure|null $namePrefix,
        ?Closure $callback,
    ): array {
        return registrar_normalize_group_inputs($prefix, $domain, $middleware, $namePrefix, $callback);
    }

    /* -----------------------------------------------------------------
     *  Options / aliases utilities
     * ----------------------------------------------------------------*/

    /**
     * Normalize name/options array into canonical tuple.
     * Returned: [name|null, extraMiddleware:list, aliases:list, attributes:array]
     *
     * @param string|RouteOptions|null $nameOrOpts
     * @return array{0:?string,1:RawMiddlewareList,2:list<string>,3:RouteAttributes}
     */
    private function normalizeOptions(string|array|null $nameOrOpts): array
    {
        return registrar_normalize_options($nameOrOpts);
    }

    /**
     * @param array{0:mixed,1:mixed,2?:mixed} $args
     * @return array{0:string,1:RouteHandler,2:string|RouteOptions|null}
     */
    private function normalizeVerbCallArgs(string $method, array $args): array
    {
        $path = $args[0] ?? null;
        $handler = $args[1] ?? null;
        $nameOrOpts = $args[2] ?? null;

        if (!\is_string($path)) {
            throw new \InvalidArgumentException("Invalid arguments for {$method} route registration.");
        }

        if ($nameOrOpts !== null && !\is_string($nameOrOpts) && !\is_array($nameOrOpts)) {
            throw new \InvalidArgumentException("Invalid name/options argument for {$method} route registration.");
        }

        if (\is_string($handler) || \is_callable($handler)) {
            return [$path, $handler, $nameOrOpts];
        }

        if (
            \is_array($handler)
            && isset($handler[0], $handler[1])
            && (\is_string($handler[0]) || \is_object($handler[0]))
            && \is_string($handler[1])
        ) {
            return [$path, [$handler[0], $handler[1]], $nameOrOpts];
        }

        throw new \InvalidArgumentException("Invalid arguments for {$method} route registration.");
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{
     *   0:string,
     *   1:list<string>|null,
     *   2:list<string>|null,
     *   3:array<string,string>,
     *   4:RawMiddlewareList,
     *   5:string
     * }
     */
    private function parseResourceOptions(array $opts): array
    {
        return registrar_parse_resource_options($opts);
    }

    /** @param list<string> $aliases */
    private function registerRouteAndAliases(Route $route, array $aliases): void
    {
        $this->routes->add($route);
        foreach ($aliases as $alias) {
            $this->routes->addAlias($route, $alias);
        }
    }

    /**
     * @phpstan-param list<RawMiddlewareEntry|AliasSpec> $list
     * @phpstan-return MiddlewareList
     */
    private function resolveAliasMiddleware(array $list): array
    {
        return registrar_resolve_alias_middleware($list);
    }

    /**
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    private function verb(
        HttpMethodEnum $method,
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add($method->value, $path, $handler, $nameOrOpts);
    }
}
