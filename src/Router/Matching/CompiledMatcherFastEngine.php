<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;

/**
 * Production compact executor for the compiled matcher IR.
 *
 * This lane deals only in deterministic scalar route IDs plus parameter maps.
 * It never materializes CompiledRoute objects, interprets executable payloads,
 * or branches between rich/compact result representations after a successful
 * match.
 *
 * @phpstan-type FastRouteEntry array{route:mixed,id:int,params:array<string,string>,fast_params:list<string>}
 * @phpstan-type PcreStep array{type:'pcre',regex:string,fast_regex:string,routes:array<string,FastRouteEntry>}
 * @phpstan-type FastDispatchEntry array{id:int,params:list<string>}
 * @phpstan-type FastDispatchStep array{regex:string,routes:array<string,FastDispatchEntry>}
 * @phpstan-type FastDispatch array{segment:int,groups:array<string,list<FastDispatchStep>>}
 * @phpstan-type FallbackStep array{type:'fallback',segments:list<array<string,mixed>>,route:mixed,id:int}
 * @phpstan-type DynamicBucket array{steps:list<PcreStep|FallbackStep>,fast_dispatch?:FastDispatch}
 * @phpstan-type MatcherGroup array{static:array<string,array<string,mixed>>,static_ids:array<string,array<string,int>>,dynamic:array<string,array<int,array<string,DynamicBucket>>>}
 * @phpstan-type CompiledMatch int|array{0:int,1:array<string,string>}|MatchOutcome
 */
final class CompiledMatcherFastEngine
{
    /**
     * @param list<MatcherGroup> $hostGroups
     * @param list<MatcherGroup> $wildcardGroups
     * @return CompiledMatch
     */
    public function match(array $hostGroups, array $wildcardGroups, string $method, string $path): int|array|MatchOutcome
    {
        $hit = self::matchStaticGroups($hostGroups, $method, $path);
        if ($hit !== null) {
            return $hit;
        }
        $hit = self::matchStaticGroups($wildcardGroups, $method, $path);
        if ($hit !== null) {
            return $hit;
        }

        [$count, $prefix] = self::pathShape($path);
        $hit = $this->matchDynamicGroups($hostGroups, $method, $path, $count, $prefix);
        if ($hit !== null) {
            return $hit;
        }
        $hit = $this->matchDynamicGroups($wildcardGroups, $method, $path, $count, $prefix);
        if ($hit !== null) {
            return $hit;
        }

        $allowed = [];
        $skip = self::alreadyTestedMethods($method);
        self::collectStaticAllowed($hostGroups, $path, $skip, $allowed);
        self::collectStaticAllowed($wildcardGroups, $path, $skip, $allowed);
        $segments = null;
        $this->collectDynamicAllowed($hostGroups, $path, $count, $prefix, $skip, $allowed, $segments);
        $this->collectDynamicAllowed($wildcardGroups, $path, $count, $prefix, $skip, $allowed, $segments);

        return self::missOutcome($method, $allowed);
    }

    /**
     * @param MatcherGroup|null $hostGroup
     * @param MatcherGroup|null $wildcardGroup
     * @return CompiledMatch
     */
    public function matchSingle(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
    ): int|array|MatchOutcome {
        if ($hostGroup !== null) {
            $hit = self::matchStaticRequested($hostGroup, $method, $path);
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = self::matchStaticRequested($wildcardGroup, $method, $path);
            if ($hit !== null) {
                return $hit;
            }
        }

        [$count, $prefix] = self::pathShape($path);
        if ($hostGroup !== null) {
            $hit = $this->matchDynamicRequested($hostGroup, $method, $path, $count, $prefix);
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = $this->matchDynamicRequested($wildcardGroup, $method, $path, $count, $prefix);
            if ($hit !== null) {
                return $hit;
            }
        }

        $allowed = [];
        $skip = self::alreadyTestedMethods($method);
        if ($hostGroup !== null) {
            self::collectStaticAllowedGroup($hostGroup, $path, $skip, $allowed);
        }
        if ($wildcardGroup !== null) {
            self::collectStaticAllowedGroup($wildcardGroup, $path, $skip, $allowed);
        }
        $segments = null;
        if ($hostGroup !== null) {
            $this->collectDynamicAllowedGroup($hostGroup, $path, $count, $prefix, $skip, $allowed, $segments);
        }
        if ($wildcardGroup !== null) {
            $this->collectDynamicAllowedGroup($wildcardGroup, $path, $count, $prefix, $skip, $allowed, $segments);
        }

        return self::missOutcome($method, $allowed);
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

    /** @return array<string,true> */
    private static function alreadyTestedMethods(string $method): array
    {
        $tested = [$method => true];
        if ($method === HttpMethodEnum::HEAD->value) {
            $tested[HttpMethodEnum::GET->value] = true;
        }

        return $tested;
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

    /** @param MatcherGroup $group */
    private static function matchStaticMethod(array $group, string $method, string $path): ?int
    {
        return $group['static_ids'][$method][$path] ?? null;
    }

    /** @param MatcherGroup $group */
    private static function matchStaticRequested(array $group, string $method, string $path): ?int
    {
        $hit = self::matchStaticMethod($group, $method, $path);
        if ($hit !== null || $method !== HttpMethodEnum::HEAD->value) {
            return $hit;
        }

        return self::matchStaticMethod($group, HttpMethodEnum::GET->value, $path);
    }

    /** @param list<MatcherGroup> $groups */
    private static function matchStaticGroups(array $groups, string $method, string $path): ?int
    {
        foreach ($groups as $group) {
            $hit = self::matchStaticRequested($group, $method, $path);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param list<FastDispatchStep> $steps
     * @return array{0:int,1:array<string,string>}|null
     */
    private static function findFastDispatchSteps(array $steps, string $path): ?array
    {
        foreach ($steps as $step) {
            $matches = [];
            $status = preg_match($step['regex'], $path, $matches);
            if ($status === false) {
                throw new \RuntimeException('Adaptive matcher PCRE failed during dispatch.');
            }
            if ($status !== 1) {
                continue;
            }

            $mark = $matches['MARK'] ?? null;
            if (!is_string($mark) || !isset($step['routes'][$mark])) {
                throw new \UnexpectedValueException('Adaptive matcher PCRE returned an unknown route mark.');
            }
            $entry = $step['routes'][$mark];
            $params = [];
            foreach ($entry['params'] as $offset => $name) {
                $piece = $matches[$offset + 1] ?? null;
                if (!is_string($piece)) {
                    throw new \UnexpectedValueException('Adaptive matcher positional parameter capture is unavailable.');
                }
                $params[$name] = $piece;
            }

            return [$entry['id'], $params];
        }

        return null;
    }

    /**
     * @param DynamicBucket $bucket
     * @param list<string>|null $segments
     * @return array{0:int,1:array<string,string>}|null
     */
    private function findDynamicBucket(array $bucket, string $path, ?array &$segments): ?array
    {
        $dispatch = $bucket['fast_dispatch'] ?? null;
        if (is_array($dispatch)) {
            $segments ??= self::pathSegments($path);
            $value = $segments[$dispatch['segment']] ?? null;
            if (!is_string($value)) {
                return null;
            }
            $steps = $dispatch['groups'][$value] ?? null;

            return is_array($steps) ? self::findFastDispatchSteps($steps, $path) : null;
        }

        foreach ($bucket['steps'] as $step) {
            if ($step['type'] === 'pcre') {
                $matches = [];
                $status = preg_match($step['fast_regex'], $path, $matches);
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
                foreach ($entry['fast_params'] as $offset => $name) {
                    $piece = $matches[$offset + 1] ?? null;
                    if (!is_string($piece)) {
                        throw new \UnexpectedValueException('Compiled matcher positional parameter capture is unavailable.');
                    }
                    $params[$name] = $piece;
                }

                return [$entry['id'], $params];
            }

            $segments ??= self::pathSegments($path);
            $params = self::matchFallbackSegments($step['segments'], $segments);
            if ($params !== null) {
                return [$step['id'], $params];
            }
        }

        return null;
    }

    /**
     * @param MatcherGroup $group
     * @param list<string>|null $segments
     * @return array{0:int,1:array<string,string>}|null
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

    /** @param MatcherGroup $group */
    private function matchDynamicRequested(
        array $group,
        string $method,
        string $path,
        int $count,
        string $prefix,
    ): int|array|null {
        $segments = null;
        $entry = $this->findDynamicMethod($group, $method, $path, $count, $prefix, $segments);
        if ($entry !== null) {
            return $entry[1] === [] ? $entry[0] : $entry;
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

        return $entry === null ? null : ($entry[1] === [] ? $entry[0] : $entry);
    }

    /** @param list<MatcherGroup> $groups */
    private function matchDynamicGroups(
        array $groups,
        string $method,
        string $path,
        int $count,
        string $prefix,
    ): int|array|null {
        foreach ($groups as $group) {
            $hit = $this->matchDynamicRequested($group, $method, $path, $count, $prefix);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param MatcherGroup $group
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     */
    private static function collectStaticAllowedGroup(array $group, string $path, array $skip, array &$allowed): void
    {
        foreach ($group['static_ids'] as $method => $routes) {
            if (!isset($skip[$method]) && isset($routes[$path])) {
                self::addAllowedMethod($allowed, $method);
            }
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     */
    private static function collectStaticAllowed(array $groups, string $path, array $skip, array &$allowed): void
    {
        foreach ($groups as $group) {
            self::collectStaticAllowedGroup($group, $path, $skip, $allowed);
        }
    }

    /**
     * @param MatcherGroup $group
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string>|null $segments
     */
    private function collectDynamicAllowedGroup(
        array $group,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        foreach ($group['dynamic'] as $method => $_buckets) {
            if (isset($skip[$method]) || isset($allowed[$method])) {
                continue;
            }
            if ($this->findDynamicMethod($group, $method, $path, $count, $prefix, $segments) !== null) {
                self::addAllowedMethod($allowed, $method);
            }
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string>|null $segments
     */
    private function collectDynamicAllowed(
        array $groups,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        foreach ($groups as $group) {
            $this->collectDynamicAllowedGroup($group, $path, $count, $prefix, $skip, $allowed, $segments);
        }
    }

    /**
     * @param list<array<string,mixed>> $specs
     * @param list<string> $segments
     * @return array<string,string>|null
     */
    private static function matchFallbackSegments(array $specs, array $segments): ?array
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
}
