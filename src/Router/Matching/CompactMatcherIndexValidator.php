<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Validates the central-route-table production matcher IR.
 *
 * @phpstan-import-type MatcherGroup from CompiledMatcherFastEngine
 */
final class CompactMatcherIndexValidator
{
    private function __construct() {}

    /**
     * @phpstan-return MatcherGroup
     */
    public static function validateGroup(mixed $raw, bool $validateRegex = false): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher group must be an array.');
        }

        $routes = self::validateRoutes($raw['routes'] ?? null);

        return [
            'routes' => $routes,
            'static' => self::validateStatic($raw['static'] ?? null, $routes),
            'static_allowed' => self::validateStaticAllowed($raw['static_allowed'] ?? null),
            'dynamic' => CompactMatcherDynamicValidator::validateDynamic($raw['dynamic'] ?? null, $routes, $validateRegex),
            'dynamic_allowed' => CompactMatcherDynamicValidator::validateDynamicAllowed($raw['dynamic_allowed'] ?? null, $validateRegex),
        ];
    }

    /** @return array<string,MatcherGroup> */
    public static function validateHosts(mixed $raw, bool $validateRegex = false): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher hosts must be an array.');
        }

        $hosts = [];
        foreach ($raw as $host => $group) {
            if (!is_string($host) || $host === '') {
                throw new \UnexpectedValueException('Compact matcher host key is invalid.');
            }
            $hosts[$host] = self::validateGroup($group, $validateRegex);
        }

        return $hosts;
    }

    private static function routeIndex(mixed $route): int
    {
        $index = $route instanceof CompiledRoute
            ? $route->getIndex()
            : ExecutableRoutePayload::routeIndex($route);
        if (!is_int($index) || $index < 0) {
            throw new \UnexpectedValueException('Compact matcher route payload is invalid.');
        }

        return $index;
    }

    /** @return list<string> */
    private static function validateMethodList(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new \UnexpectedValueException('Compact matcher method list is invalid.');
        }

        $seen = [];
        foreach ($raw as $method) {
            if (!is_string($method) || $method === '' || isset($seen[$method])) {
                throw new \UnexpectedValueException('Compact matcher method token is invalid or duplicated.');
            }
            $seen[$method] = true;
        }

        return array_keys($seen);
    }

    /** @return array<int,mixed> */
    private static function validateRoutes(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher route table must be an array.');
        }

        $routes = [];
        foreach ($raw as $id => $route) {
            if (!is_int($id) || $id < 0 || self::routeIndex($route) !== $id) {
                throw new \UnexpectedValueException('Compact matcher route table entry is invalid.');
            }
            $routes[$id] = $route;
        }

        return $routes;
    }

    /**
     * @param array<int,mixed> $routes
     * @return array<string,array<string,int>>
     */
    private static function validateStatic(mixed $raw, array $routes): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher static map must be an array.');
        }

        $static = [];
        foreach ($raw as $method => $paths) {
            if (!is_string($method) || $method === '' || !is_array($paths)) {
                throw new \UnexpectedValueException('Compact matcher static method map is invalid.');
            }
            $static[$method] = self::validateStaticPaths($paths, $routes);
        }

        return $static;
    }

    /** @return array<string,list<string>> */
    private static function validateStaticAllowed(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher static allowed-method map must be an array.');
        }

        $allowed = [];
        foreach ($raw as $path => $methods) {
            if (!is_string($path)) {
                throw new \UnexpectedValueException('Compact matcher static allowed-method path is invalid.');
            }
            $allowed[$path] = self::validateMethodList($methods);
        }

        return $allowed;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<string,int>
     */
    private static function validateStaticPaths(array $raw, array $routes): array
    {
        $paths = [];
        foreach ($raw as $path => $id) {
            if (!is_string($path) || !is_int($id) || !array_key_exists($id, $routes)) {
                throw new \UnexpectedValueException('Compact matcher static route ID is invalid.');
            }
            $paths[$path] = $id;
        }

        return $paths;
    }
}
