<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Facade;

use Closure;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Registrar;
use RuntimeException;

/**
 * 📌 Public static entry-point — “Laravel-style” router façade.
 *
 * Bind a concrete {@see Registrar} once at bootstrap via {@see Router::setInstance()},
 * then call router APIs statically:
 *
 *   Router::get('/ping', fn () => 'pong', 'ping');
 *
 * Group usage with scoped instance injection:
 *
 *   Router::group(prefix: '/v1', namePrefix: 'api.', callback: function (Registrar $r) {
 *       $r->post('/login', [AuthController::class, 'login'], 'login');
 *   });
 *
 * Or omit the parameter and use the façade inside:
 *
 *   Router::group(prefix: '/v1', namePrefix: 'api.', callback: function () {
 *       Router::get('/health', fn () => 'ok', 'health');
 *   });
 */
final class Router
{
    private static ?Registrar $instance = null;

    /*──────────── lifecycle ────────────*/

    public static function setInstance(Registrar $router): void
    {
        self::$instance = $router;
    }

    private static function getInstance(): Registrar
    {
        return self::$instance
            ?? throw new RuntimeException('Router façade used before a concrete instance was set.');
    }

    /**
     * Temporarily swap the façade instance to $router while running $callback,
     * restoring the previous instance afterwards. If $callback declares one
     * parameter, the scoped router is injected.
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
            self::$instance = $prev;
        }
    }

    /*──────────── explicit accessors (typed, IDE-friendly) ────────────*/

    public static function get(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->get($path, $handler, $nameOrOpts);
    }

    public static function post(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->post($path, $handler, $nameOrOpts);
    }

    public static function put(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->put($path, $handler, $nameOrOpts);
    }

    public static function patch(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->patch($path, $handler, $nameOrOpts);
    }

    public static function delete(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->delete($path, $handler, $nameOrOpts);
    }

    public static function head(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->head($path, $handler, $nameOrOpts);
    }

    public static function options(
        string $path,
        array|string|callable $handler,
        string|array|null $nameOrOpts = null,
    ): RouteInterface {
        return self::getInstance()->options($path, $handler, $nameOrOpts);
    }

    public static function resource(string $name, string $prefix, string $controller, array $opts = []): void
    {
        self::getInstance()->resource($name, $prefix, $controller, $opts);
    }

    /**
     * Mirrors Registrar::group signature and defers there.
     * Supports both positional and named arguments like Laravel.
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

    /*──────────── fallback ────────────*/

    /**
     * Keep a minimal dynamic fallback so newly added Registrar methods
     * don’t require immediate façade changes. This gets hit only if a
     * method isn’t explicitly declared above.
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

    private function __construct()
    {
    }
}
