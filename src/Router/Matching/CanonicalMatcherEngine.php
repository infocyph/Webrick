<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Shared runtime semantic engine for canonical matcher indexes.
 *
 * @phpstan-type CompiledMatch int|array{0:int,1:array<string,string>}|MatchOutcome
 * @phpstan-type RouteValue CompiledRoute|array<array-key,mixed>|string
 * @phpstan-type MatcherGroup array{static:array<string,array<string,RouteValue>>,dynamic:array<mixed>}
 */
final class CanonicalMatcherEngine
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

    /**
     * @return array{segments:list<array<string,mixed>>,verbs:array<string,RouteValue>}|null
     */
    private static function dynamicEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $segments = self::segmentList($entry['segments'] ?? null);
        $verbs = self::verbMap($entry['verbs'] ?? null);

        return $segments === null || $verbs === null ? null : ['segments' => $segments, 'verbs' => $verbs];
    }

    /**
     * @param array<string,bool> $allowed
     */
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

    /** @return list<array<string,mixed>>|null */
    private static function segmentList(mixed $segments): ?array
    {
        if (!is_array($segments) || !array_is_list($segments)) {
            return null;
        }

        $normalized = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                return null;
            }
            $spec = [];
            foreach ($segment as $key => $value) {
                if (!is_string($key)) {
                    return null;
                }
                $spec[$key] = $value;
            }
            $normalized[] = $spec;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        if ($path === '/' || $path === '') {
            return [];
        }

        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /** @return array<string,RouteValue>|null */
    private static function verbMap(mixed $verbs): ?array
    {
        if (!is_array($verbs)) {
            return null;
        }

        $map = [];
        foreach ($verbs as $verb => $route) {
            if (!is_string($verb) || (!is_string($route) && !is_array($route) && !$route instanceof CompiledRoute)) {
                return null;
            }
            $map[$verb] = $route;
        }

        return $map;
    }

    /**
     * @param array<string,bool> $allowed
     */
    /**
     * @param array<string,RouteValue> $verbs
     * @param array<string,bool> $allowed
     */
    private function addAllowed(array $verbs, array &$allowed): void
    {
        foreach ($verbs as $verb => $_route) {
            if ($verb !== '') {
                $allowed[$verb] = true;
            }
        }
        if (isset($verbs[HttpMethodEnum::GET->value])) {
            $allowed[HttpMethodEnum::HEAD->value] = true;
        }
    }

    /**
     * @param array<string,string> $params
     * @return int|array{0:int,1:array<string,string>}|MatchOutcome
     */
    private function found(mixed $value, array $params, bool $headFallback, bool $compact): int|array|MatchOutcome
    {
        if ($compact) {
            $index = self::routeIndex($value);

            return $params === [] ? $index : [$index, $params];
        }

        return MatchOutcome::found($this->materialize($value), $params, $headFallback);
    }

    /**
     * @param array<mixed> $entries
     * @param list<string> $segments
     * @param array<string,bool> $allowed
     * @return CompiledMatch|null
     */
    private function matchDynamicEntries(
        array $entries,
        string $method,
        array $segments,
        array &$allowed,
        bool $compact,
    ): int|array|MatchOutcome|null {
        foreach ($entries as $entry) {
            $entry = self::dynamicEntry($entry);
            if ($entry === null) {
                continue;
            }
            $params = $this->matchSegments($entry['segments'], $segments);
            if ($params === null) {
                continue;
            }
            $outcome = $this->selectVerb($entry['verbs'], $method, $params, $compact);
            if ($outcome !== null) {
                return $outcome;
            }
            $this->addAllowed($entry['verbs'], $allowed);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $group
     * @param list<string> $segments
     * @param array<string,bool> $allowed
     * @return CompiledMatch|null
     */
    private function matchDynamicGroup(
        array $group,
        string $method,
        array $segments,
        string $prefix,
        array &$allowed,
        bool $compact,
    ): int|array|MatchOutcome|null {
        $dynamic = $group['dynamic'] ?? null;
        if (!is_array($dynamic)) {
            return null;
        }
        $bucket = $dynamic[count($segments)] ?? null;
        if (!is_array($bucket)) {
            return null;
        }

        $entries = $bucket[$prefix] ?? null;
        if (is_array($entries)) {
            $hit = $this->matchDynamicEntries($entries, $method, $segments, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        if ($prefix === '*') {
            return null;
        }
        $entries = $bucket['*'] ?? null;

        return is_array($entries)
            ? $this->matchDynamicEntries($entries, $method, $segments, $allowed, $compact)
            : null;
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
        $allowed = [];

        foreach ($hostGroups as $group) {
            $hit = $this->matchStaticGroup($group, $method, $path, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }
        foreach ($wildcardGroups as $group) {
            $hit = $this->matchStaticGroup($group, $method, $path, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        $segments = self::segments($path);
        $prefix = $segments[0] ?? '';

        foreach ($hostGroups as $group) {
            $hit = $this->matchDynamicGroup($group, $method, $segments, $prefix, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }
        foreach ($wildcardGroups as $group) {
            $hit = $this->matchDynamicGroup($group, $method, $segments, $prefix, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        return self::missOutcome($method, $allowed);
    }

    /**
     * @param list<array<string,mixed>> $specs
     * @param list<string> $segments
     * @return array<string,string>|null
     */
    private function matchSegments(array $specs, array $segments): ?array
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
        $allowed = [];

        if ($hostGroup !== null) {
            $hit = $this->matchStaticGroup($hostGroup, $method, $path, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = $this->matchStaticGroup($wildcardGroup, $method, $path, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        $segments = self::segments($path);
        $prefix = $segments[0] ?? '';

        if ($hostGroup !== null) {
            $hit = $this->matchDynamicGroup($hostGroup, $method, $segments, $prefix, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = $this->matchDynamicGroup($wildcardGroup, $method, $segments, $prefix, $allowed, $compact);
            if ($hit !== null) {
                return $hit;
            }
        }

        return self::missOutcome($method, $allowed);
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,bool> $allowed
     * @return CompiledMatch|null
     */
    private function matchStaticGroup(
        array $group,
        string $method,
        string $path,
        array &$allowed,
        bool $compact,
    ): int|array|MatchOutcome|null {
        $static = $group['static'] ?? null;
        if (!is_array($static)) {
            return null;
        }
        $verbs = $static[$path] ?? null;
        $verbs = self::verbMap($verbs);
        if ($verbs === null) {
            return null;
        }

        $outcome = $this->selectVerb($verbs, $method, [], $compact);
        if ($outcome !== null) {
            return $outcome;
        }
        $this->addAllowed($verbs, $allowed);

        return null;
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

    /**
     * @param array<string,RouteValue> $verbs
     * @param array<string,string> $params
     * @return CompiledMatch|null
     */
    private function selectVerb(array $verbs, string $method, array $params, bool $compact): int|array|MatchOutcome|null
    {
        if (array_key_exists($method, $verbs)) {
            return $this->found($verbs[$method], $params, false, $compact);
        }
        if ($method === HttpMethodEnum::HEAD->value && array_key_exists(HttpMethodEnum::GET->value, $verbs)) {
            return $this->found($verbs[HttpMethodEnum::GET->value], $params, true, $compact);
        }

        return null;
    }
}
