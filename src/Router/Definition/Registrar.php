<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
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
 *
 * Responsibilities:
 *  - Provide convenient HTTP verb helpers (get/post/put/etc.) that delegate to add()
 *  - Compose GroupScope state (prefix, domain, middleware, name prefix) for nested groups
 *  - Register Route DTOs into a Route Collection and optionally register aliases
 *  - Support resource-style route registration (Laravel-like)
 *  - Handle middleware aliasing semantics and resolution
 *
 * Notes:
 *  - Instances are readonly and immutable; mutators return new GroupScope or
 *    new Registrar instances where appropriate.
 *  - This class does not dispatch requests; it only registers route metadata.
 *
 * @package Infocyph\Webrick\Router\Definition
 */
final readonly class Registrar
{
    /**
     * Construct a Registrar.
     *
     * The constructor uses promoted properties to hold registrar state. When
     * $exposeUrlServices is true the Response URL helpers are bound to the
     * provided route collection using the optional signing configuration.
     *
     * @param Collection $routes Route collection where generated Route objects are stored
     * @param GroupScope $scope Current group scope (prefix/domain/middleware/name prefix)
     * @param bool $autoSlashRedirect When true, auto-registers slash-variant redirects for GET routes
     * @param bool $exposeUrlServices When true, binds URL generation services into Response
     * @param string|null $signKey Optional signing key for signed URL generation
     * @param int|null $signedDefaultTtl Optional default TTL (seconds) for temporary signed URLs
     */
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

    /**
     * Register a GET route.
     *
     * @param string $path Route path template
     * @param array|string|callable $handler Controller or callable handler
     * @param string|array|null $nameOrOpts Optional route name or options array
     * @return RouteInterface Registered Route DTO
     */
    public function get(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('GET', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a POST route.
     *
     * @param string $path
     * @param array|string|callable $handler
     * @param string|array|null $nameOrOpts
     * @return RouteInterface
     */
    public function post(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('POST', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a PUT route.
     *
     * @param string $path
     * @param array|string|callable $handler
     * @param string|array|null $nameOrOpts
     * @return RouteInterface
     */
    public function put(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('PUT', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $path
     * @param array|string|callable $handler
     * @param string|array|null $nameOrOpts
     * @return RouteInterface
     */
    public function patch(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('PATCH', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $path
     * @param array|string|callable $handler
     * @param string|array|null $nameOrOpts
     * @return RouteInterface
     */
    public function delete(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('DELETE', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a HEAD route.
     *
     * @param string $path
     * @param array|string|callable $handler
     * @param string|array|null $nameOrOpts
     * @return RouteInterface
     */
    public function head(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return $this->add('HEAD', $path, $handler, $nameOrOpts);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param string $path
     * @param array|string|callable $handler
     * @param string|array|null $nameOrOpts
     * @return RouteInterface
     */
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

    /**
     * Register a set of resourceful routes for a controller (index, show, create, store, edit, update, patch, destroy).
     *
     * @param string $name Resource base name used for route naming
     * @param string $prefix URL prefix for the resource (e.g. "/users")
     * @param string $ctrl Controller class name or callable representation
     * @param array $opts Optional configuration keys:
     *   - 'param' => string name of route parameter (default 'id')
     *   - 'only' => array of keys to include
     *   - 'except' => array of keys to exclude
     *   - 'names' => array of custom names per key
     *   - 'middleware' => array middleware applied to all generated routes
     *   - 'patch_action' => string name for PATCH action (default 'update')
     *
     * @return void
     */
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

    /**
     * Parse and normalise resource options.
     *
     * @param array $opts Raw options passed to resource()
     * @return array{0:string,1:?array,2:?array,3:array,4:array,5:string} [
     *   0 => parameter name,
     *   1 => only list or null,
     *   2 => except list or null,
     *   3 => names map,
     *   4 => middleware list,
     *   5 => patch action name
     * ]
     */
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
     * Build the resource route specification used by resource().
     *
     * Each item is a tuple:
     *   [HTTP method, URI suffix, controller method, key, nameable?]
     *
     * @param string $param Route parameter name to inject into patterns
     * @param string $patchAction Controller method name for PATCH requests
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
                $patchAction,
                $patchAction !== 'update',
            ],
            ['DELETE', '/{' . $param . '}', 'destroy', 'destroy', true],
        ];
    }

    /**
     * Determine whether a resource key should be included based on only/except filters.
     *
     * @param string $key Resource route key (e.g., 'index', 'show')
     * @param array|null $only Optional include list
     * @param array|null $except Optional exclude list
     * @return bool True when key should be included
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

    /* -----------------------------------------------------------------
     *  Grouping (split normalization from registration)
     * ----------------------------------------------------------------*/

    /**
     * Register a group of routes sharing common configuration.
     *
     * Accepts either Laravel-style options array as first argument or positional
     * arguments. The final argument must be a Closure used to register routes.
     *
     * @param array|string|null $prefix Path prefix or array of options
     * @param string|array|Closure|null $domain Domain constraint or closure
     * @param array|Closure $middleware Middleware list or closure (if closure then it's the callback)
     * @param string|Closure|null $namePrefix Name prefix or closure
     * @param Closure|null $callback Callback used to declare nested routes
     * @return void
     * @throws InvalidArgumentException When a group callback Closure is not provided
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

        // allow both styles:
        //  • function (Registrar $r) { $r->get(...); }
        //  • function () { Router::get(...); }
        Router::withScopedInstance($child, $callback);
    }

    /**
     * Normalize group arguments (supports Laravel-style array or positional).
     *
     * Returns a 5-tuple:
     *  [prefix, domain, middlewareArray, namePrefixOrNull, callbackClosure]
     *
     * @param array|string|null $prefix
     * @param string|array|Closure|null $domain
     * @param array|Closure $middleware
     * @param string|Closure|null $namePrefix
     * @param Closure|null $callback
     * @return array{0:array|string|null,1:string|array|null,2:array,3:string|null,4:Closure}
     * @throws InvalidArgumentException When the final callback is not a Closure
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

    /**
     * Compile the registered routes into a compiled collection.
     *
     * @return CompiledCollection Compiled route collection for dispatch
     */
    public function compile(): CompiledCollection
    {
        return $this->routes->compile();
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------*/

    /**
     * Normalize name/options array into canonical tuple.
     *
     * Returned tuple: [name|null, extraMiddleware:list, aliases:list]
     *
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

    /**
     * Core route registration implementation.
     *
     * Steps:
     *  1) Compute path with group prefix
     *  2) Instantiate Route DTO
     *  3) Apply scope decorations (domain, middleware, name prefix)
     *  4) Register in collection and optional alias registrations
     *  5) Optionally register auto-slash redirect GET variant
     *
     * @param string $verb HTTP method verb (e.g., 'GET', 'POST')
     * @param string $path Route path template (may contain tokens)
     * @param array|string|callable $handler Handler description (callable or controller tuple)
     * @param string|array|null $nameOrOpts Name string or options array
     * @return RouteInterface Registered Route DTO
     */
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

        // 3.1) Merge middleware with **alias override** semantics, then resolve aliases
        $groupMw = $this->scope->getMiddleware() ?? [];
        $merged = $this->mergeMiddlewareWithAliasOverrides($groupMw, $extraMw);
        $resolved = $this->resolveAliasMiddleware($merged);

        if ($resolved !== []) {
            $route = $route->withMiddleware($resolved);
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
            $this->routes->addAlias($route, $alias);
        }

        // 5) Optional auto “slash variant” redirect
        if ($this->autoSlashRedirect && $verb === 'GET' && !str_contains($fullPath, '{')) {
            $alt = str_ends_with($fullPath, '/') ? rtrim($fullPath, '/') : $fullPath . '/';

            $this->routes->add(
                new Route('GET', $alt, static fn () => Response::redirect($fullPath, 308)),
            );
        }

        return $route;
    }

    /* ──────────────────────────── Alias helpers ─────────────────────────── */

    /**
     * Merge group + route middleware arrays with **alias override** semantics.
     *
     * Rules:
     *  - If both contain the same alias key (e.g. 'throttle'), the route's
     *    version replaces the group's.
     *  - Non-aliased entries keep order: group first, then route extras.
     *
     * @param array $group Group-level middleware raw specs (class-string|object|string alias)
     * @param array $route Route-level middleware raw specs
     * @return array Merged raw specs (strings/objects/callables/structured alias specs)
     */
    private function mergeMiddlewareWithAliasOverrides(array $group, array $route): array
    {
        // Build list of specs where alias entries are recognized and comparable
        $result = [];
        $aliasPos = []; // aliasKey => index in $result

        $push = function (mixed $mw) use (&$result, &$aliasPos): void {
            if (is_string($mw) && ($spec = $this->parseAliasSpec($mw))) {
                // if alias already present, drop older one (keep newest)
                if (isset($aliasPos[$spec['key']])) {
                    unset($result[$aliasPos[$spec['key']]]);
                }
                $result[] = $spec;                          // store structured spec
                $aliasPos[$spec['key']] = array_key_last($result);
            } else {
                $result[] = $mw;                             // non-aliased (class-string/object/callable)
            }
        };

        foreach ($group as $mw) {
            $push($mw);
        }
        foreach ($route as $mw) {
            $push($mw);
        }

        // Reindex (in case we unset something)
        return array_values($result);
    }

    /**
     * Resolve alias specifications into actual middleware entries using MiddlewareAliases.
     *
     * Alias specs have the form ['__alias'=>true,'key'=>string,'params'=>array].
     * This method leaves non-aliased entries untouched.
     *
     * @param array $list Mixed items; alias items are structured arrays as above
     * @return array List of callables|objects|class-string values ready for Route middleware
     */
    private function resolveAliasMiddleware(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (is_array($item) && ($item['__alias'] ?? false) === true) {
                $key = (string)$item['key'];
                $params = $item['params'] ?? [];
                $s = $key;
                if ($params !== []) {
                    $s .= ':' . implode(',', array_map(static fn ($v) => (string)$v, $params));
                }
                // Let MiddlewareAliases produce either object or class-string
                $out[] = MiddlewareAliases::resolveString($s);
            } else {
                // untouched: callable|object|string(class-string)
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Parse an alias specification string like "alias:arg1,arg2" into a structured spec.
     *
     * - If $s is a concrete class name (class_exists) it is NOT considered an alias.
     * - If the alias key is not registered in MiddlewareAliases the function returns null.
     *
     * @param string $s Alias specification string or class-string
     * @return array{__alias:true,key:string,params:array}|null Structured alias spec or null when not an alias
     */
    private function parseAliasSpec(string $s): ?array
    {
        // class-string? then it's NOT an alias
        if (class_exists($s)) {
            return null;
        }

        [$name, $paramStr] = explode(':', $s, 2) + [1 => null];
        $key = strtolower(trim($name));

        if ($key === '' || !MiddlewareAliases::has($key)) {
            return null;
        }

        $params = ($paramStr !== null && $paramStr !== '')
            ? array_map('trim', explode(',', $paramStr))
            : [];

        return ['__alias' => true, 'key' => $key, 'params' => $params];
    }
}
