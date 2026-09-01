<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;

/**
 * Production executor for the compact central-route-table matcher IR.
 *
 * The request path touches scalar route IDs, positional parameter-name lists and
 * compiled method metadata only. Rich route payloads are resolved separately by
 * CompiledMatcherEngine when callers explicitly request a MatchOutcome.
 *
 * @phpstan-type PcreEntry array{id:int,params:list<string>}
 * @phpstan-type PcreStep array{type:'pcre',regex:string,routes:array<string,PcreEntry>}
 * @phpstan-type FastDispatchStep array{regex:string,routes:array<string,PcreEntry>}
 * @phpstan-type FastDispatch array{segment:int,groups:array<string,list<FastDispatchStep>>}
 * @phpstan-type AllowedLiteralEntry array{regex:string,methods:list<string>}
 * @phpstan-type AllowedBucket array{type:'literal',segment:int,groups:array<string,AllowedLiteralEntry>}|array{type:'fallback'}
 * @phpstan-type FallbackStep array{type:'fallback',segments:list<array<string,mixed>>,id:int}
 * @phpstan-type DynamicBucket array{steps:list<PcreStep|FallbackStep>,fast_dispatch?:FastDispatch}
 * @phpstan-type MatcherGroup array{routes:array<int,mixed>,static:array<string,array<string,int>>,static_allowed:array<string,list<string>>,dynamic:array<string,array<int,array<string,DynamicBucket>>>,dynamic_allowed:array<int,array<string,AllowedBucket>>}
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
        self::collectCompiledStaticAllowed($hostGroups, $path, $skip, $allowed);
        self::collectCompiledStaticAllowed($wildcardGroups, $path, $skip, $allowed);
        $segments = null;
        $this->collectCompiledDynamicAllowed($hostGroups, $path, $count, $prefix, $skip, $allowed, $segments);
        $this->collectCompiledDynamicAllowed($wildcardGroups, $path, $count, $prefix, $skip, $allowed, $segments);

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
            self::collectCompiledStaticAllowedGroup($hostGroup, $path, $skip, $allowed);
        }
        if ($wildcardGroup !== null) {
            self::collectCompiledStaticAllowedGroup($wildcardGroup, $path, $skip, $allowed);
        }
        $segments = null;
        if ($hostGroup !== null) {
            $this->collectCompiledDynamicAllowedGroup($hostGroup, $path, $count, $prefix, $skip, $allowed, $segments);
        }
        if ($wildcardGroup !== null) {
            $this->collectCompiledDynamicAllowedGroup($wildcardGroup, $path, $count, $prefix, $skip, $allowed, $segments);
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

    /**
     * @param array<string,bool> $allowed
     * @param list<string> $methods
     * @param array<string,true> $skip
     */
    private static function addCompiledAllowedMethods(array &$allowed, array $methods, array $skip): void
    {
        foreach ($methods as $method) {
            if (!isset($skip[$method])) {
                $allowed[$method] = true;
            }
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
        return $group['static'][$method][$path] ?? null;
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
                foreach ($entry['params'] as $offset => $name) {
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
    private static function collectCompiledStaticAllowedGroup(array $group, string $path, array $skip, array &$allowed): void
    {
        $methods = $group['static_allowed'][$path] ?? null;
        if (is_array($methods)) {
            self::addCompiledAllowedMethods($allowed, $methods, $skip);
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     */
    private static function collectCompiledStaticAllowed(array $groups, string $path, array $skip, array &$allowed): void
    {
        foreach ($groups as $group) {
            self::collectCompiledStaticAllowedGroup($group, $path, $skip, $allowed);
        }
    }

    /**
     * @param MatcherGroup $group
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string>|null $segments
     */
    private function collectCompiledDynamicAllowedGroup(
        array $group,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        $byCount = $group['dynamic_allowed'][$count] ?? null;
        if (!is_array($byCount)) {
            return;
        }

        $needsFallback = false;
        $buckets = [];
        if (isset($byCount[$prefix]) && is_array($byCount[$prefix])) {
            $buckets[] = $byCount[$prefix];
        }
        if ($prefix !== '*' && isset($byCount['*']) && is_array($byCount['*'])) {
            $buckets[] = $byCount['*'];
        }

        foreach ($buckets as $bucket) {
            if (($bucket['type'] ?? null) === 'fallback') {
                $needsFallback = true;
                continue;
            }
            if (($bucket['type'] ?? null) !== 'literal') {
                throw new \UnexpectedValueException('Compiled matcher allowed-method bucket type is invalid.');
            }

            $segments ??= self::pathSegments($path);
            $segment = $bucket['segment'] ?? null;
            $value = is_int($segment) ? ($segments[$segment] ?? null) : null;
            if (!is_string($value)) {
                continue;
            }
            $entry = $bucket['groups'][$value] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $status = preg_match($entry['regex'], $path);
            if ($status === false) {
                throw new \RuntimeException('Compiled matcher allowed-method PCRE failed during dispatch.');
            }
            if ($status === 1) {
                self::addCompiledAllowedMethods($allowed, $entry['methods'], $skip);
            }
        }

        if ($needsFallback) {
            $this->collectDynamicAllowedGroup($group, $path, $count, $prefix, $skip, $allowed, $segments);
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string>|null $segments
     */
    private function collectCompiledDynamicAllowed(
        array $groups,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        foreach ($groups as $group) {
            $this->collectCompiledDynamicAllowedGroup($group, $path, $count, $prefix, $skip, $allowed, $segments);
        }
    }

    /**
     * General semantic fallback for dynamic allowed-method discovery. It is used
     * only for route families whose build-time topology cannot prove a unique
     * method-independent shape discriminator.
     *
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
