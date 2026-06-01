<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Facade;

use Closure;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\RouteGenerator;
use Infocyph\Webrick\Router\Url\SignedUrlGenerator;
use Infocyph\Webrick\Router\Url\TemporaryUrlGenerator;
use RuntimeException;

/**
 * Router façade
 *
 * Lightweight static façade that exposes a convenient, Laravel-like API for
 * route registration while delegating actual work to a concrete Registrar
 * instance. Bind a Registrar at bootstrap with setInstance() and thereafter
 * call the static helpers (get/post/resource/group...) from application code.
 *
 * Responsibilities:
 *  - Hold a single, replaceable Registrar instance used by static helpers.
 *  - Provide typed convenience methods for common HTTP verbs and resource/group
 *    registration, forwarding calls to the bound Registrar.
 *  - Temporarily swap the bound Registrar for a scoped callback via
 *    withScopedInstance() (useful for nested registration contexts).
 *
 * Usage:
 *   Router::setInstance($registrar);
 *   Router::get('/ping', fn () => 'pong', 'ping');
 *
 * @phpstan-type HandlerArray array{0:string|object,1:string}
 * @phpstan-type RouteHandler HandlerArray|string|callable
 * @phpstan-type RouteOptions array{
 *   name?:mixed,
 *   as?:mixed,
 *   middleware?:mixed,
 *   aliases?:mixed,
 *   alias?:mixed,
 *   attributes?:mixed
 * }
 * @phpstan-type GroupOptions array{
 *   prefix?:mixed,
 *   domain?:mixed,
 *   middleware?:mixed,
 *   name?:mixed,
 *   as?:mixed
 * }
 */
final class Router
{
    /**
     * Concrete Registrar instance used by the façade.
     *
     * When null the façade has not been initialised and calls will throw.
     */
    private static ?Registrar $instance = null;

    private static ?RouteGenerator $routeGen = null;

    private static ?Collection $routesRef = null;

    private static ?SignedUrlGenerator $signedGen = null;

    private static ?TemporaryUrlGenerator $tempGen = null;

    /**
     * Private constructor to prevent instantiation — façade is static-only.
     */
    private function __construct() {}

    /* ──────────── fallback ──────────── */

    /**
     * Dynamic fallback to forward any undeclared static calls to the Registrar.
     *
     * This keeps the façade resilient to new Registrar APIs without requiring
     * immediate updates to the façade. If the method does not exist on the
     * concrete Registrar a RuntimeException is thrown.
     *
     * @param string $method Method name being called statically
     * @param list<mixed> $args Positional arguments passed to the method
     * @return mixed The underlying Registrar method return value
     *
     * @throws RuntimeException When the concrete Registrar does not implement the method
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        $router = self::getInstance();
        if (!method_exists($router, $method)) {
            throw new RuntimeException(
                sprintf(
                    'Method %s::%s() does not exist on concrete router.',
                    $router::class,
                    $method,
                ),
            );
        }

        return $router->$method(...$args);
    }

    /**
     * Bind URL generation services to the Route facade.
     */
    public static function bindUrlServices(
        Collection $routes,
        ?string $signKey = null,
        ?int $defaultTtl = null,
    ): void {
        self::$routesRef = $routes;
        self::$routeGen = new RouteGenerator('', $routes);
        self::$signedGen = null;
        self::$tempGen = null;

        if ($signKey !== null && $signKey !== '') {
            self::$signedGen = new SignedUrlGenerator('', $routes, $signKey);
            if ($defaultTtl !== null) {
                self::$tempGen = new TemporaryUrlGenerator('', $routes, $signKey, $defaultTtl);
            }
        }
    }

    /**
     * Register a DELETE route via the bound Registrar.
     *
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    public static function delete(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('delete', $path, $handler, $nameOrOpts);
    }

    /* ──────────── explicit accessors (typed, IDE-friendly) ──────────── */

    /**
     * Register a GET route via the bound Registrar.
     *
     * @param string $path Route path template
     * @param RouteHandler $handler Handler (callable or controller descriptor)
     * @param string|RouteOptions|null $nameOrOpts Optional route name or options array
     * @return RouteInterface Registered route DTO
     */
    public static function get(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('get', $path, $handler, $nameOrOpts);
    }

    /**
     * Mirrors Registrar::group signature and forwards to the bound Registrar.
     *
     * Accepts both positional and associative (Laravel-style) arguments and
     * supports the callback styles that Registrar::group accepts.
     *
     * @param string|GroupOptions|null $prefix Path prefix or options array
     * @param string|GroupOptions|Closure|null $domain Domain constraint or closure
     * @param list<mixed>|Closure $middleware Middleware list or closure
     * @param string|Closure|null $namePrefix Name prefix or closure
     * @param Closure|null $callback Callback used to declare nested routes
     */
    public static function group(
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        array|Closure $middleware = [],
        string|Closure|null $namePrefix = null,
        ?Closure $callback = null,
    ): void {
        self::getInstance()->group($prefix, $domain, $middleware, $namePrefix, $callback);
    }

    /**
     * Register a HEAD route via the bound Registrar.
     *
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    public static function head(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('head', $path, $handler, $nameOrOpts);
    }

    /**
     * Register an OPTIONS route via the bound Registrar.
     *
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    public static function options(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('options', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a PATCH route via the bound Registrar.
     *
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    public static function patch(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('patch', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a POST route via the bound Registrar.
     *
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    public static function post(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('post', $path, $handler, $nameOrOpts);
    }

    /**
     * Register a PUT route via the bound Registrar.
     *
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    public static function put(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::verb('put', $path, $handler, $nameOrOpts);
    }

    /**
     * Reset the façade by clearing the bound Registrar instance.
     *
     * Intended for long-running worker environments (e.g., RoadRunner/Swoole)
     * where process state persists across requests. Call this at the start of
     * a new request or during a worker reset to ensure no stale Registrar is
     * leaked between lifecycles.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::resetUrlServices();
    }

    /**
     * Reset URL generation services (useful for tests/workers).
     */
    public static function resetUrlServices(): void
    {
        self::$routesRef = null;
        self::$routeGen = null;
        self::$signedGen = null;
        self::$tempGen = null;
    }

    /**
     * Register a set of resourceful routes for a controller.
     *
     * Forwards to Registrar::resource and mirrors its options.
     *
     * @param string $name Resource base name used for route naming
     * @param string $prefix URL prefix for the resource (e.g. "/users")
     * @param string $controller Controller class name or callable representation
     * @param array<string,mixed> $opts Optional configuration forwarded to Registrar::resource
     */
    public static function resource(string $name, string $prefix, string $controller, array $opts = []): void
    {
        self::getInstance()->resource($name, $prefix, $controller, $opts);
    }

    /* ──────────── lifecycle ──────────── */
    /**
     * Bind a concrete Registrar instance to the façade.
     *
     * Subsequent static calls are forwarded to this instance.
     *
     * @param Registrar $router Registrar to bind
     */
    public static function setInstance(Registrar $router): void
    {
        self::$instance = $router;
    }

    /**
     * Generate signed URL for named route.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $query
     */
    public static function signedUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = false,
    ): string {
        self::assertRouteName($name);
        $signed = self::requireSignedGenerator();
        $normalizedParams = self::normalizeRouteParams($params);
        $normalizedQuery = self::normalizeRouteQuery($query);

        $path = $ttl === null
            ? $signed->signed($name, $normalizedParams, $normalizedQuery, null, false)
            : $signed->signed($name, $normalizedParams, $normalizedQuery, max(1, $ttl), false);

        return self::finalizeGeneratedPath($name, $path, $absolute);
    }

    /**
     * Generate temporary signed URL for named route.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $query
     */
    public static function temporaryUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = false,
    ): string {
        self::assertRouteName($name);
        $temp = self::requireTemporaryGenerator();
        $path = $temp->temporary($name, self::normalizeRouteParams($params), self::normalizeRouteQuery($query), $ttl, false);

        return self::finalizeGeneratedPath($name, $path, $absolute);
    }

    /**
     * Generate URL for named route.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $query
     */
    public static function urlFor(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        self::assertRouteName($name);
        $routeGen = self::requireRouteGenerator();
        $path = $routeGen->route($name, self::normalizeRouteParams($params), self::normalizeRouteQuery($query), false);

        return self::finalizeGeneratedPath($name, $path, $absolute);
    }

    /**
     * Temporarily swap the façade instance while executing a callback.
     *
     * The previous instance is restored after $callback completes (even on
     * exception). If $callback declares a single parameter it receives the
     * scoped Registrar instance.
     *
     * @param Registrar $router Registrar to use for the duration of the callback
     * @param Closure $callback Callback to execute while $router is scoped
     * @return mixed The callback return value (any type)
     */
    public static function withScopedInstance(Registrar $router, Closure $callback): mixed
    {
        $prev = self::$instance;
        self::$instance = $router;

        try {
            $ref = ReflectionResource::getFunctionReflection($callback);

            return $ref->getNumberOfParameters() > 0
                ? $callback($router)
                : $callback();
        } finally {
            // Always restore previous instance to avoid surprising global state.
            self::$instance = $prev;
        }
    }

    private static function assertRouteName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Route name must be non-empty.');
        }
    }

    private static function assertSignedBound(): void
    {
        if (!self::$signedGen || !self::$routesRef) {
            throw new \LogicException('Signed URL service not bound. Provide $signKey to Registrar.');
        }
    }

    private static function assertUrlBound(): void
    {
        if (!self::$routeGen || !self::$routesRef) {
            throw new \LogicException('URL services not bound. Enable via Registrar constructor.');
        }
    }

    private static function finalizeGeneratedPath(string $name, string $path, bool $absolute): string
    {
        return $absolute ? self::withRouteDomain($name, $path) : $path;
    }

    /**
     * Return the bound Registrar instance or throw if none is set.
     *
     * @throws RuntimeException When the façade is used before a Registrar is set
     */
    private static function getInstance(): Registrar
    {
        return self::$instance
            ?? throw new RuntimeException('Router façade used before a concrete instance was set.');
    }

    /**
     * @param array<int,mixed>|string|callable $handler
     * @return RouteHandler
     */
    private static function normalizeHandler(array|string|callable $handler): array|string|callable
    {
        if (is_array($handler)) {
            if (
                count($handler) !== 2
                || (!is_string($handler[0]) && !is_object($handler[0]))
                || !is_string($handler[1])
            ) {
                throw new \InvalidArgumentException('Array handler must be [class-or-object, method].');
            }

            return [$handler[0], $handler[1]];
        }

        return $handler;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,bool|float|int|string|null>
     */
    private static function normalizeRouteParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[$key] = self::normalizeScalarValue($value);
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,array<mixed>|bool|float|int|string|null>
     */
    private static function normalizeRouteQuery(array $query): array
    {
        $normalized = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $value;

                continue;
            }
            $normalized[$key] = self::normalizeScalarValue($value);
        }

        return $normalized;
    }

    private static function normalizeScalarValue(mixed $value): bool|float|int|string|null
    {
        if (is_bool($value) || is_float($value) || is_int($value) || is_string($value) || $value === null) {
            return $value;
        }

        return $value instanceof \Stringable ? (string) $value : null;
    }

    private static function requireRouteGenerator(): RouteGenerator
    {
        self::assertUrlBound();

        return self::$routeGen
            ?? throw new \LogicException('URL services not bound. Enable via Registrar constructor.');
    }

    private static function requireSignedGenerator(): SignedUrlGenerator
    {
        self::assertSignedBound();

        return self::$signedGen
            ?? throw new \LogicException('Signed URL service not bound. Provide $signKey to Registrar.');
    }

    private static function requireTemporaryGenerator(): TemporaryUrlGenerator
    {
        if (!self::$tempGen) {
            throw new \LogicException('TemporaryUrlGenerator not bound (no default TTL provided).');
        }

        return self::$tempGen;
    }

    /**
     * @param RouteHandler $handler
     * @param string|RouteOptions|null $nameOrOpts
     */
    private static function verb(
        string $verb,
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        $router = self::getInstance();
        $normalized = self::normalizeHandler($handler);

        return match ($verb) {
            'delete' => $router->delete($path, $normalized, $nameOrOpts),
            'get' => $router->get($path, $normalized, $nameOrOpts),
            'head' => $router->head($path, $normalized, $nameOrOpts),
            'options' => $router->options($path, $normalized, $nameOrOpts),
            'patch' => $router->patch($path, $normalized, $nameOrOpts),
            'post' => $router->post($path, $normalized, $nameOrOpts),
            'put' => $router->put($path, $normalized, $nameOrOpts),
            default => throw new RuntimeException("Unsupported HTTP verb '{$verb}' in Router facade."),
        };
    }

    /**
     * Prefix a generated path with the route domain when available.
     */
    private static function withRouteDomain(string $name, string $path): string
    {
        $domain = self::$routesRef?->findByName($name)?->getDomain();

        return ($domain && $domain !== '*') ? ('//' . $domain . $path) : $path;
    }
}
