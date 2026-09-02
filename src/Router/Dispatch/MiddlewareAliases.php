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
    private static bool $frozen = false;

    /** @var array<string,callable|string> */
    private static array $map = [];

    /** @var array<int|string,array{supports:callable(string):bool,resolve:callable(string,string...):(callable|object|string)}> */
    private static array $resolvers = [];

    private function __construct() {}

    /**
     * Build-plane alias parsing. Runtime-backed aliases are represented without
     * executing their resolver so construction stays inside the request scope.
     */
    public static function compileString(string $maybeAlias): RuntimeMiddlewareDescriptor|string
    {
        [$key, $params] = self::parse($maybeAlias);

        if (isset(self::$map[$key])) {
            return self::compileRegistered(self::$map[$key], $params);
        }

        foreach (self::$resolvers as $resolver) {
            if (($resolver['supports'])($key)) {
                return new RuntimeMiddlewareDescriptor($resolver['resolve'], [$key, ...$params]);
            }
        }

        return $maybeAlias;
    }

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
        [$key, $params] = self::parse($maybeAlias);

        if (isset(self::$map[$key])) {
            return self::resolveRegistered(self::$map[$key], $params);
        }

        foreach (self::$resolvers as $resolver) {
            if (($resolver['supports'])($key)) {
                return self::assertResolved(($resolver['resolve'])($key, ...$params), $key);
            }
        }

        return $maybeAlias;
    }

    private static function assertMutable(): void
    {
        if (self::$frozen) {
            throw new LogicException('Middleware alias registry is frozen for production runtime.');
        }
    }

    private static function assertResolved(mixed $resolved, string $alias): callable|object|string
    {
        if (is_string($resolved) || is_object($resolved) || is_callable($resolved)) {
            return $resolved;
        }

        throw new UnexpectedValueException(sprintf(
            'Middleware alias "%s" resolved to unsupported type %s',
            $alias,
            get_debug_type($resolved),
        ));
    }

    /**
     * @param list<string> $params
     */
    private static function compileRegistered(callable|string $registered, array $params): RuntimeMiddlewareDescriptor|string
    {
        if (is_string($registered) && $params === []) {
            return $registered;
        }

        return new RuntimeMiddlewareDescriptor($registered, $params);
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private static function parse(string $maybeAlias): array
    {
        [$name, $paramStr] = explode(':', $maybeAlias, 2) + [1 => null];
        $key = strtolower(trim((string) $name));
        $params = $paramStr !== null && $paramStr !== ''
            ? array_map(trim(...), explode(',', $paramStr))
            : [];

        return [$key, $params];
    }

    /**
     * @param list<string> $params
     */
    private static function resolveRegistered(callable|string $registered, array $params): callable|object|string
    {
        if (is_string($registered)) {
            $resolved = $params !== [] ? new $registered(...$params) : $registered;

            return self::assertResolved($resolved, $registered);
        }

        return self::assertResolved($registered(...$params), 'registered');
    }
}
