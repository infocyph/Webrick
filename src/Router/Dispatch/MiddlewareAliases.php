<?php

declare(strict_types=1);

/**
 * MiddlewareAliases
 *
 * File-level helper that manages a registry of short alias names for middleware.
 * Each alias maps to a factory that, when invoked with variadic string parameters,
 * returns one of:
 *  - a callable (e.g. closure) to be used directly as middleware,
 *  - an instantiated object (middleware instance),
 *  - a class-string which the caller may choose to instantiate or memoize.
 *
 * Typical usage:
 *  • MiddlewareAliases::register('throttle', ThrottleMiddleware::class);
 *  • MiddlewareAliases::register('auth', fn (...$params) => new AuthMiddleware(...$params));
 *  • $resolved = MiddlewareAliases::resolveString('throttle:60,60');
 *
 * The registry is global and static; resolution functions are intentionally simple
 * and synchronous to support middleware pipeline construction in the router.
 */

namespace Infocyph\Webrick\Router\Dispatch;

final class MiddlewareAliases
{
    /**
     * Map of alias key => factory callable.
     *
     * The factory callable receives variadic string parameters and must return one of:
     *  - callable  : middleware callable/closure,
     *  - object    : instantiated middleware object,
     *  - string    : class-string (returned when no parameters are provided so callers
     *                       may instantiate/memoize instances themselves).
     *
     * @var array<string, callable>
     */
    private static array $map = [];

    /**
     * @var array<int|string, array{
     *     supports: callable(string):bool,
     *     resolve: callable(string,string...):(callable|object|string)
     * }>
     */
    private static array $resolvers = [];

    /**
     * Determine if an alias is registered.
     *
     * @param string $alias Alias name to check (case-insensitive)
     * @return bool True when the alias exists in the registry
     */
    public static function has(string $alias): bool
    {
        $alias = strtolower($alias);
        if (isset(self::$map[$alias])) {
            return true;
        }

        return array_any(self::$resolvers, fn(array $resolver): bool => ($resolver['supports'])($alias));
    }

    /**
     * Register an alias name with a factory or a class-string.
     *
     * Behaviour:
     *  - If $factoryOrClass is a callable it will be stored directly and will be
     *    invoked with variadic parameters when resolving the alias.
     *  - If $factoryOrClass is a class-string it will be wrapped into a factory
     *    that:
     *      • returns the raw class-string when invoked with no params (allowing
     *        callers to memoize or instantiate later), or
     *      • constructs and returns a new instance via `new $class(...$params)`
     *        when parameters are provided.
     *
     * The alias name is normalized to lower-case.
     *
     * @param string $alias Lower-case alias label (case-insensitive)
     * @param callable|string $factoryOrClass Callable factory or middleware class-string
     */
    public static function register(string $alias, callable|string $factoryOrClass): void
    {
        $alias = strtolower($alias);

        if (is_string($factoryOrClass)) {
            $class = $factoryOrClass;
            $factoryOrClass
                // If params were provided, construct now (route-specific instance).
                // If no params, return class-string so the pipeline can memoize it.
                = (static fn(...$params) => ($params !== [])
                ? new $class(...$params)
                : $class);
        }

        self::$map[$alias] = $factoryOrClass;
    }

    /**
     * Register one lazy resolver for a family of aliases.
     *
     * @param callable(string):bool $supports
     * @param callable(string,string...):(callable|object|string) $resolve
     * @param string|null $name Stable family name; registering it again replaces the previous resolver.
     */
    public static function registerResolver(callable $supports, callable $resolve, ?string $name = null): void
    {
        $resolver = [
            'supports' => $supports,
            'resolve' => $resolve,
        ];
        if ($name !== null && $name !== '') {
            self::$resolvers[$name] = $resolver;

            return;
        }

        self::$resolvers[] = $resolver;
    }

    /**
     * Reset the alias registry.
     *
     * Clears all registered middleware aliases. This is primarily intended for
     * long-running worker environments (e.g., RoadRunner/Swoole) where process
     * state persists across requests. Invoke this method during your worker's
     * bootstrap or per-request reset phase to ensure a clean slate before
     * (re)registering aliases for the next request/lifecycle.
     */
    public static function reset(): void
    {
        self::$map = [];
        self::$resolvers = [];
    }

    /**
     * Resolve a potential alias string into a middleware descriptor.
     *
     * Input:
     *  - $maybeAlias is split at the first ':' into name and comma-separated
     *    params. If the name corresponds to a registered alias, the associated factory
     *    is invoked with the trimmed params and its return value is returned.
     *  - If the name is not registered, the original string is returned unchanged;
     *    the pipeline remains responsible for class-string autoloading.
     *
     * Return value is one of:
     *  - callable : middleware callable/closure,
     *  - object   : instantiated middleware,
     *  - string   : class-string (caller may choose to instantiate or memoize).
     *
     * @param string $maybeAlias Alias-like string such as "throttle:60,60" or a class-string
     * @return callable|object|string Resolved middleware descriptor or original string when not an alias
     */
    public static function resolveString(string $maybeAlias): callable|object|string
    {
        [$name, $paramStr] = explode(':', $maybeAlias, 2) + [1 => null];
        $key = strtolower((string) $name);

        $params = ($paramStr !== null && $paramStr !== '')
            ? array_map(trim(...), explode(',', $paramStr))
            : [];

        if (isset(self::$map[$key])) {
            $resolved = (self::$map[$key])(...$params);
        } else {
            $resolved = null;
            foreach (self::$resolvers as $resolver) {
                if (($resolver['supports'])($key)) {
                    $resolved = ($resolver['resolve'])($key, ...$params);

                    break;
                }
            }
            if ($resolved === null) {
                return $maybeAlias;
            }
        }

        if (\is_string($resolved) || \is_object($resolved) || \is_callable($resolved)) {
            return $resolved;
        }

        throw new \UnexpectedValueException(
            \sprintf('Middleware alias "%s" resolved to unsupported type %s', $key, \get_debug_type($resolved)),
        );
    }
}
