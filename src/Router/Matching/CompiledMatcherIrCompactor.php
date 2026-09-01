<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Collapses the compiler's rich intermediate representation into the immutable
 * production representation consumed by both matcher executors.
 *
 * Route payloads live once in a central ID table. Static maps and dynamic steps
 * reference only scalar IDs. Dynamic PCRE steps retain the positional regex and
 * ordered parameter names, so rich matching no longer needs a second named-
 * capture regex representation.
 *
 * @phpstan-import-type MatcherGroup from CompiledMatcherFastEngine
 */
final class CompiledMatcherIrCompactor
{
    private function __construct() {}

    /**
     * @param array<array-key,mixed> $group
     * @phpstan-return MatcherGroup
     */
    public static function compactGroup(array $group): array
    {
        $routes = [];

        $static = $group['static'] ?? null;
        $staticIds = $group['static_ids'] ?? null;
        if (!is_array($static) || !is_array($staticIds)) {
            throw new \UnexpectedValueException('Compiled matcher static IR is invalid.');
        }
        foreach ($static as $method => $paths) {
            if (!is_string($method) || !is_array($paths)) {
                throw new \UnexpectedValueException('Compiled matcher static method IR is invalid.');
            }
            foreach ($paths as $path => $route) {
                if (!is_string($path)) {
                    throw new \UnexpectedValueException('Compiled matcher static path IR is invalid.');
                }
                $idsByPath = $staticIds[$method] ?? null;
                if (!is_array($idsByPath)) {
                    throw new \UnexpectedValueException('Compiled matcher static ID map is invalid.');
                }
                $id = $idsByPath[$path] ?? null;
                if (!is_int($id) || $id < 0) {
                    throw new \UnexpectedValueException('Compiled matcher static route ID is invalid.');
                }
                self::captureRoute($routes, $id, $route);
            }
        }

        $dynamic = self::compactDynamic($group['dynamic'] ?? null, $routes);
        ksort($routes, SORT_NUMERIC);

        return CompactMatcherIndexValidator::validateGroup([
            'routes' => $routes,
            'static' => $staticIds,
            'static_allowed' => $group['static_allowed'] ?? [],
            'dynamic' => $dynamic,
            'dynamic_allowed' => $group['dynamic_allowed'] ?? [],
        ]);
    }

    /**
     * @param array<array-key,mixed> $hosts
     * @return array<string,MatcherGroup>
     */
    public static function compactHosts(array $hosts): array
    {
        $compact = [];
        foreach ($hosts as $host => $group) {
            if (!is_string($host) || !is_array($group)) {
                throw new \UnexpectedValueException('Compiled matcher host group is invalid.');
            }
            $compact[$host] = self::compactGroup($group);
        }

        return $compact;
    }

    /** @param array<int,mixed> $routes */
    private static function captureRoute(array &$routes, int $id, mixed $route): void
    {
        $actual = self::routeIndex($route);
        if ($actual !== $id) {
            throw new \UnexpectedValueException('Compiled matcher route payload does not match its deterministic ID.');
        }

        if (!array_key_exists($id, $routes)) {
            $routes[$id] = $route;
        }
    }

    /**
     * @param array<array-key,mixed> $bucket
     * @param array<int,mixed> $routes
     * @return array<string,mixed>
     */
    private static function compactBucket(array $bucket, array &$routes): array
    {
        $stepsRaw = $bucket['steps'] ?? null;
        if (!is_array($stepsRaw) || !array_is_list($stepsRaw)) {
            throw new \UnexpectedValueException('Compiled matcher dynamic steps are invalid.');
        }

        $steps = [];
        foreach ($stepsRaw as $step) {
            if (!is_array($step)) {
                throw new \UnexpectedValueException('Compiled matcher dynamic step is invalid.');
            }
            if (($step['type'] ?? null) === 'pcre') {
                $steps[] = self::compactPcreStep($step, $routes);

                continue;
            }
            if (($step['type'] ?? null) !== 'fallback') {
                throw new \UnexpectedValueException('Compiled matcher dynamic step type is invalid.');
            }

            $id = $step['id'] ?? null;
            if (!is_int($id) || $id < 0) {
                throw new \UnexpectedValueException('Compiled matcher fallback route ID is invalid.');
            }
            self::captureRoute($routes, $id, $step['route'] ?? null);
            $steps[] = [
                'type' => 'fallback',
                'segments' => $step['segments'] ?? [],
                'id' => $id,
            ];
        }

        $compact = ['steps' => $steps];
        if (isset($bucket['fast_dispatch'])) {
            $compact['fast_dispatch'] = $bucket['fast_dispatch'];
        }

        return $compact;
    }

    /**
     * @param array<int,mixed> $routes
     * @return array<string,array<int,array<string,array<string,mixed>>>>
     */
    private static function compactDynamic(mixed $raw, array &$routes): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher dynamic IR is invalid.');
        }

        $dynamic = [];
        foreach ($raw as $method => $counts) {
            if (!is_string($method) || !is_array($counts)) {
                throw new \UnexpectedValueException('Compiled matcher dynamic method IR is invalid.');
            }
            $dynamic[$method] = self::compactMethod($counts, $routes);
        }

        return $dynamic;
    }

    /**
     * @param array<array-key,mixed> $counts
     * @param array<int,mixed> $routes
     * @return array<int,array<string,array<string,mixed>>>
     */
    private static function compactMethod(array $counts, array &$routes): array
    {
        $method = [];
        foreach ($counts as $count => $prefixes) {
            if (!is_int($count) || !is_array($prefixes)) {
                throw new \UnexpectedValueException('Compiled matcher dynamic count IR is invalid.');
            }
            foreach ($prefixes as $prefix => $bucket) {
                if (!is_string($prefix) || !is_array($bucket)) {
                    throw new \UnexpectedValueException('Compiled matcher dynamic bucket IR is invalid.');
                }
                $method[$count][$prefix] = self::compactBucket($bucket, $routes);
            }
        }

        return $method;
    }

    /**
     * @param array<array-key,mixed> $step
     * @param array<int,mixed> $routes
     * @return array{type:'pcre',regex:string,routes:array<string,array{id:int,params:list<string>}>}
     */
    private static function compactPcreStep(array $step, array &$routes): array
    {
        $regex = $step['fast_regex'] ?? null;
        $routesRaw = $step['routes'] ?? null;
        if (!is_string($regex) || $regex === '' || !is_array($routesRaw)) {
            throw new \UnexpectedValueException('Compiled matcher positional PCRE IR is invalid.');
        }

        $map = [];
        foreach ($routesRaw as $mark => $entry) {
            if (!is_string($mark) || !is_array($entry)) {
                throw new \UnexpectedValueException('Compiled matcher PCRE route IR is invalid.');
            }
            $id = $entry['id'] ?? null;
            $params = $entry['fast_params'] ?? null;
            if (!is_int($id) || $id < 0 || !is_array($params) || !array_is_list($params)) {
                throw new \UnexpectedValueException('Compiled matcher compact PCRE entry is invalid.');
            }
            self::captureRoute($routes, $id, $entry['route'] ?? null);
            $map[$mark] = ['id' => $id, 'params' => self::stringList($params)];
        }

        return ['type' => 'pcre', 'regex' => $regex, 'routes' => $map];
    }

    private static function routeIndex(mixed $route): int
    {
        $index = $route instanceof CompiledRoute
            ? $route->getIndex()
            : ExecutableRoutePayload::routeIndex($route);

        if (!is_int($index) || $index < 0) {
            throw new \UnexpectedValueException('Compiled matcher route payload is invalid.');
        }

        return $index;
    }

    /**
     * @param array<array-key,mixed> $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException('Compiled matcher parameter list is invalid.');
            }
            $strings[] = $value;
        }

        return $strings;
    }
}
