<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use LogicException;
use UnexpectedValueException;

/**
 * Process-level middleware alias registry. Mutable only during build/bootstrap;
 * production freezes it before traffic starts.
 */
final class MiddlewareAliases
{
    /** @var array<string,callable> */
    private static array $map = [];

    /** @var array<int|string,array{supports:callable(string):bool,resolve:callable(string,string...):(callable|object|string)}> */
    private static array $resolvers = [];

    private static bool $frozen = false;

    private function __construct() {}

    public static function freeze(): void
    {
        self::$frozen = true;
    }

    public static function frozen(): bool
    {
        return self::$frozen;
    }

    public static function has(string $alias): bool
    {
        $alias = strtolower($alias);
        if (isset(self::$map[$alias])) {
            return true;
        }

        return array_any(self::$resolvers, static fn(array $resolver): bool => ($resolver['supports'])($alias));
    }

    public static function register(string $alias, callable|string $factoryOrClass): void
    {
        self::assertMutable();
        $alias = strtolower(trim($alias));
        if ($alias === '') {
            throw new \InvalidArgumentException('Middleware alias must not be empty.');
        }

        if (is_string($factoryOrClass)) {
            $class = $factoryOrClass;
            $factoryOrClass = static fn(string ...$params): object|string => $params !== []
                ? new $class(...$params)
                : $class;
        }

        self::$map[$alias] = $factoryOrClass;
    }

    /**
     * @param callable(string):bool $supports
     * @param callable(string,string...):(callable|object|string) $resolve
     */
    public static function registerResolver(callable $supports, callable $resolve, ?string $name = null): void
    {
        self::assertMutable();
        $resolver = ['supports' => $supports, 'resolve' => $resolve];
        if ($name !== null && $name !== '') {
            self::$resolvers[$name] = $resolver;

            return;
        }

        self::$resolvers[] = $resolver;
    }

    public static function reset(): void
    {
        self::assertMutable();
        self::$map = [];
        self::$resolvers = [];
    }

    public static function resolveString(string $maybeAlias): callable|object|string
    {
        [$name, $paramStr] = explode(':', $maybeAlias, 2) + [1 => null];
        $key = strtolower((string) $name);
        $params = $paramStr !== null && $paramStr !== ''
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

        if (is_string($resolved) || is_object($resolved) || is_callable($resolved)) {
            return $resolved;
        }

        throw new UnexpectedValueException(sprintf(
            'Middleware alias "%s" resolved to unsupported type %s',
            $key,
            get_debug_type($resolved),
        ));
    }

    private static function assertMutable(): void
    {
        if (self::$frozen) {
            throw new LogicException('Middleware alias registry is frozen for production runtime.');
        }
    }
}
