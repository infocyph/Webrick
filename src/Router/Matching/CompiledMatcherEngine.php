<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Request-time executor for the compiled matcher IR.
 *
 * Successful static requests are direct method/path lookups. Successful
 * regex-dynamic requests execute bounded combined PCRE chunks and use MARK to
 * identify the selected route. Fallback steps remain in registration order so
 * optimization never changes route precedence. Cross-method probing is
 * reserved for miss, automatic OPTIONS and 405 handling.
 *
 * @phpstan-type RouteValue CompiledRoute|array<array-key,mixed>|string
 * @phpstan-type FastRouteEntry array{route:RouteValue,params:array<int,string>}
 * @phpstan-type PcreStep array{type:'pcre',regex:string,routes:array<string,FastRouteEntry>}
 * @phpstan-type FallbackStep array{type:'fallback',segments:list<array<string,mixed>>,route:RouteValue}
 * @phpstan-type DynamicBucket array{steps:list<PcreStep|FallbackStep>}
 * @phpstan-type MatcherGroup array{static:array<string,array<string,RouteValue>>,dynamic:array<string,array<int,array<string,DynamicBucket>>>}
 * @phpstan-type CompiledMatch int|array{0:int,1:array<string,string>}|MatchOutcome
 */
final class CompiledMatcherEngine
{
    /** @var array<string,CompiledRoute> */
    private array $materialized = [];

    /**
     * @param list<MatcherGroup> $hostGroups
     * @param list<MatcherGroup> $wildcardGroups
     */
    public function match(array $hostGroups, array $wildcardGroups, string $method, string $path): MatchOutcome
    {
        /** @var MatchOutcome $outcome */
        $outcome = $this->matchGroups($hostGroups, $wildcardGroups, $method, $path, false);

        return $outcome;
    }

    /**
     * @param list<MatcherGroup> $hostGroups
     * @param list<MatcherGroup> $wildcardGroups
     * @return CompiledMatch
     */
    public function matchCompiled(array $hostGroups, array $wildcardGroups, string $method, string $path): int|array|MatchOutcome
    {
        return $this->matchGroups($hostGroups, $wildcardGroups, $method, $path, true);
    }

    /**
     * @param MatcherGroup|null $hostGroup
     * @param MatcherGroup|null $wildcardGroup
     */
    public function matchSingle(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
    ): MatchOutcome {
        /** @var MatchOutcome $outcome */
        $outcome = $this->matchSingleGroup($hostGroup, $wildcardGroup, $method, $path, false);

        return $outcome;
    }

    /**
     * @param MatcherGroup|null $hostGroup
     * @param MatcherGroup|null $wildcardGroup
     * @return CompiledMatch
     */
    public function matchSingleCompiled(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
    ): int|array|MatchOutcome {
        return $this->matchSingleGroup($hostGroup, $wildcardGroup, $method, $path, true);
    }

    /** @param array<string,bool> $allowed */
    private static function addAllowedMethod(array &$allowed, string $method): void
    {
        if ($method === '') {
            return;
        }
        $allowed[$method] = true;
        if ($method === HttpMethodEnum::GET->value) {
            $allowed[HttpMethodEnum::HEAD->value] = true;
        }
    }

    /** @return list<string> */
    private static function pathSegments(string $path): array
    {
        if ($path === '/' || $path === '') {
            return [];
        }

        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /** @return array{0:int,1:string} */
    private static function pathShape(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [0, ''];
        }

        $slash = strpos($trimmed, '/');
        $prefix = $slash === false ? $trimmed : substr($trimmed, 0, $slash);

        return [substr_count($trimmed, '/') + 1, $prefix];
    }

    /** @param array<string,bool> $allowed */
    private static function missOutcome(string $method, array $allowed): MatchOutcome
    {
        if ($allowed === []) {
            return MatchOutcome::notFound();
        }

        $methods = array_keys($allowed);

        return $method === HttpMethodEnum::OPTIONS->value
            ? MatchOutcome::autoOptions($methods)
            : MatchOutcome::methodNotAllowed($methods);
    }

    private static function routeIndex(mixed $value): int
    {
        if ($value instanceof CompiledRoute) {
            return $value->getIndex();
        }

        $index = ExecutableRoutePayload::routeIndex($value);
        if ($index === null) {
            throw new \UnexpectedValueException('Cached compiled route is missing its route index.');
        }

        return $index;
    }

    /**
     * @param array<string,string> $params
     * @return CompiledMatch
     */
    private function found(mixed $route, array $params, bool $headFallback, bool $compact): int|array|MatchOutcome
    {
        if ($compact) {
            $index = self::routeIndex($route);

            return $params === [] ? $index : [$index, $params];
        }

        return MatchOutcome::found($this->materialize($route), $params, $headFallback);
    }

    /**
     * @param MatcherGroup $group
     * @return CompiledMatch|null
     */
    private function matchStaticMethod(
        array $group,
        string $method,
        string $path,
        bool $headFallback,
        bool $compact,
    ): int|array|MatchOutcome|null {
        $route = $group['static'][$method][$path] ?? null;
        if ($route === null) {
            return null;
        }

        return $this->found($route, [], $headFallback, $compact);
    }

    /**
     * @param MatcherGroup $group
     * @return CompiledMatch|null
     */
    private function matchStaticRequested(array $group, string $method, string $path, bool $compact): int|array|MatchOutcome|null
    {
        $hit = $this->matchStaticMethod($group, $method, $path, false, $compact);
        if ($hit !== null || $method !== HttpMethodEnum::HEAD->value) {
            return $hit;
        }

        return $this->matchStaticMethod($group, HttpMethodEnum::GET->value, $path, true, $compact);
    }

    /**
     * @param DynamicBucket $bucket
     * @param list<string>|null $segments
     * @return array{route:RouteValue,params:array<string,string>}|null
     */
    private function findDynamicBucket(array $bucket, string $path, ?array &$segments): ?array
    {
        foreach ($bucket['steps'] as $step) {
            if ($step['type'] === 'pcre') {
                $matches = [];
                $status = preg_match($step['regex'], $path, $matches);
                if ($status === false) {
                    throw new \RuntimeException('Compiled matcher PCRE failed during dispatch.');
                }
                if ($status !== 1) {
                    continue;
                }

                $mark = $matches['MARK'] ?? null;
                if (!is_string($mark) || !isset($step['routes'][$mark])) {
                    throw new \UnexpectedValueException('Compiled matcher PCRE returned an unknown route mark.');
                }
                $entry = $step['routes'][$mark];
                $params = [];
                if ($entry['params'] !== []) {
                    $segments ??= self::pathSegments($path);
                    foreach ($entry['params'] as $position => $name) {
                        $piece = $segments[$position] ?? null;
                        if (!is_string($piece)) {
                            throw new \UnexpectedValueException('Compiled matcher parameter position is unavailable.');
                        }
                        $params[$name] = $piece;
                    }
                }

                return ['route' => $entry['route'], 'params' => $params];
            }

            $segments ??= self::pathSegments($path);
            $params = $this->matchFallbackSegments($step['segments'], $segments);
            if ($params !== null) {
                return ['route' => $step['route'], 'params' => $params];
            }
        }

        return null;
    }

    /**
     * @param MatcherGroup $group
     * @param list<string>|null $segments
     * @return array{route:RouteValue,params:array<string,string>}|null
     */
    private function findDynamicMethod(
        array $group,
        string $method,
        string $path,
        int $count,
        string $prefix,
        ?array &$segments,
    ): ?array {
        $byCount = $group['dynamic'][$method][$count] ?? null;
        if (!is_array($byCount)) {
            return null;
        }

        $bucket = $byCount[$prefix] ?? null;
        if (is_array($bucket)) {
            $hit = $this->findDynamicBucket($bucket, $path, $segments);
            if ($hit !== null) {
                return $hit;
            }
        }

        if ($prefix === '*') {
            return null;
        }
        $bucket = $byCount['*'] ?? null;

        return is_array($bucket)
            ? $this->findDynamicBucket($bucket, $path, $segments)
            : null;
    }

    /**
     * @param MatcherGroup $group
     * @return CompiledMatch|null
     */
    private function matchDynamicRequested(
        array $group,
        string $method,
        string $path,
        int $count,
        string $prefix,
        bool $compact,
    ): int|array|MatchOutcome|null {
        $segments = null;
        $entry = $this->findDynamicMethod($group, $method, $path, $count, $prefix, $segments);
        if ($entry !== null) {
            return $this->found($entry['route'], $entry['params'], false, $compact);
        }
        if ($method !== HttpMethodEnum::HEAD->value) {
            return null;
        }

        $entry = $this->findDynamicMethod(
            $group,
            HttpMethodEnum::GET->value,
            $path,
            $count,
            $prefix,
            $segments,
        );

        return $entry === null
            ? null
            : $this->found($entry['route'], $entry['params'], true, $compact);
    }

    /**
     * @param list<MatcherGroup> $groups
     * @return CompiledMatch|null
     */
    private function matchStaticGroups(array $groups, string $method, string $path, bool $compact): int|array|MatchOutcome|null
    {
        foreach ($groups as $group) {
            $hit = $this->matchStaticRequested($group, $method, $path, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param list<MatcherGroup> $groups
     * @return CompiledMatch|null
     */
    private function matchDynamicGroups(
        array $groups,
        string $method,
        string $path,
        int $count,
        string $prefix,
        bool $compact,
    ): int|array|MatchOutcome|null {
        foreach ($groups as $group) {
            $hit = $this->matchDynamicRequested($group, $method, $path, $count, $prefix, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,bool> $allowed
     */
    private function collectStaticAllowed(array $groups, string $path, array &$allowed): void
    {
        foreach ($groups as $group) {
            foreach ($group['static'] as $method => $routes) {
                if (isset($routes[$path])) {
                    self::addAllowedMethod($allowed, $method);
                }
            }
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,bool> $allowed
     */
    private function collectDynamicAllowed(
        array $groups,
        string $path,
        int $count,
        string $prefix,
        array &$allowed,
    ): void {
        foreach ($groups as $group) {
            $segments = null;
            foreach ($group['dynamic'] as $method => $_buckets) {
                if (isset($allowed[$method])) {
                    continue;
                }
                if ($this->findDynamicMethod($group, $method, $path, $count, $prefix, $segments) !== null) {
                    self::addAllowedMethod($allowed, $method);
                }
            }
        }
    }

    /**
     * @param list<MatcherGroup> $hostGroups
     * @param list<MatcherGroup> $wildcardGroups
     * @return CompiledMatch
     */
    private function matchGroups(
        array $hostGroups,
        array $wildcardGroups,
        string $method,
        string $path,
        bool $compact,
    ): int|array|MatchOutcome {
        $hit = $this->matchStaticGroups($hostGroups, $method, $path, $compact);
        if ($hit !== null) {
            return $hit;
        }
        $hit = $this->matchStaticGroups($wildcardGroups, $method, $path, $compact);
        if ($hit !== null) {
            return $hit;
        }

        [$count, $prefix] = self::pathShape($path);
        $hit = $this->matchDynamicGroups($hostGroups, $method, $path, $count, $prefix, $compact);
        if ($hit !== null) {
            return $hit;
        }
        $hit = $this->matchDynamicGroups($wildcardGroups, $method, $path, $count, $prefix, $compact);
        if ($hit !== null) {
            return $hit;
        }

        $allowed = [];
        $this->collectStaticAllowed($hostGroups, $path, $allowed);
        $this->collectStaticAllowed($wildcardGroups, $path, $allowed);
        $this->collectDynamicAllowed($hostGroups, $path, $count, $prefix, $allowed);
        $this->collectDynamicAllowed($wildcardGroups, $path, $count, $prefix, $allowed);

        return self::missOutcome($method, $allowed);
    }

    /**
     * @param MatcherGroup|null $hostGroup
     * @param MatcherGroup|null $wildcardGroup
     * @return CompiledMatch
     */
    private function matchSingleGroup(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
        bool $compact,
    ): int|array|MatchOutcome {
        $hostGroups = $hostGroup === null ? [] : [$hostGroup];
        $wildcardGroups = $wildcardGroup === null ? [] : [$wildcardGroup];

        return $this->matchGroups($hostGroups, $wildcardGroups, $method, $path, $compact);
    }

    /**
     * @param list<array<string,mixed>> $specs
     * @param list<string> $segments
     * @return array<string,string>|null
     */
    private function matchFallbackSegments(array $specs, array $segments): ?array
    {
        $params = [];
        foreach ($specs as $index => $spec) {
            $piece = $segments[$index] ?? null;
            if (!is_string($piece) || !CanonicalSegmentMatcher::matches($spec, $piece, $params)) {
                return null;
            }
        }

        return $params;
    }

    private function materialize(mixed $value): CompiledRoute
    {
        if ($value instanceof CompiledRoute) {
            return $value;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid compiled route value in matcher index.');
        }

        $index = self::routeIndex($value);
        $key = 'payload:' . $index;

        return $this->materialized[$key] ??= matcher_materialize_cached_route($value);
    }
}
