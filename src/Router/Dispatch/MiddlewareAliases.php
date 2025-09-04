<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

final class MiddlewareAliases
{
    /**
     * alias => factory(...$params): callable|object|string(class-string)
     * @var array<string, callable>
     */
    private static array $map = [];

    /**
     * Register an alias.
     *  • Closure factory gets **variadic** params.
     *  • Or pass a class-string; we’ll `new $class(...$params)` when params exist,
     *    otherwise we return the class-string (so the pipeline can memoize a single instance).
     */
    public static function register(string $alias, callable|string $factoryOrClass): void
    {
        $alias = strtolower($alias);

        if (is_string($factoryOrClass)) {
            $class = $factoryOrClass;
            $factoryOrClass = static function (...$params) use ($class) {
                // If params were provided, construct now (route-specific instance).
                // If no params, return class-string so the pipeline can memoize it.
                return ($params !== [])
                    ? new $class(...$params)
                    : $class;
            };
        }

        self::$map[$alias] = $factoryOrClass;
    }

    public static function has(string $alias): bool
    {
        return isset(self::$map[strtolower($alias)]);
    }

    /**
     * Resolve "alias:arg1,arg2" → callable|object|string(class-string).
     * If not a known alias, returns the original string untouched.
     */
    public static function resolveString(string $maybeAlias): callable|object|string
    {
        // If it’s a class-string already, let the pipeline handle it.
        if (class_exists($maybeAlias)) {
            return $maybeAlias;
        }

        [$name, $paramStr] = explode(':', $maybeAlias, 2) + [1 => null];
        $key = strtolower($name);

        if (!isset(self::$map[$key])) {
            return $maybeAlias; // not our alias
        }

        $params = ($paramStr !== null && $paramStr !== '')
            ? array_map('trim', explode(',', $paramStr))
            : [];

        // Call variadic factory
        return (self::$map[$key])(...$params);
    }
}
