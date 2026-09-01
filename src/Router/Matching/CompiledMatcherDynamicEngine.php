<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;

/**
 * Executes the dynamic branch of the compact matcher IR.
 *
 * Keeping this separate from the static fast path makes each execution mode
 * independently auditable while retaining the same immutable compiled table.
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
 */
final class CompiledMatcherDynamicEngine
{
    /**
     * @param MatcherGroup $group
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string> $segments
     */
    public function collectAllowedGroup(
        array $group,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        $needsFallback = false;
        foreach ($this->allowedBuckets($group, $count, $prefix) as $bucket) {
            if ($bucket['type'] === 'fallback') {
                $needsFallback = true;

                continue;
            }
            $segments ??= self::pathSegments($path);
            $this->collectLiteralAllowed($bucket, $path, $skip, $allowed, $segments);
        }

        if ($needsFallback) {
            $this->collectFallbackAllowed($group, $path, $count, $prefix, $skip, $allowed, $segments);
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string> $segments
     */
    public function collectAllowedGroups(
        array $groups,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        foreach ($groups as $group) {
            $this->collectAllowedGroup($group, $path, $count, $prefix, $skip, $allowed, $segments);
        }
    }

    /**
     * @param list<MatcherGroup> $groups
     * @return int|array{0:int,1:array<string,string>}|null
     */
    public function matchGroups(array $groups, string $method, string $path, int $count, string $prefix): int|array|null
    {
        foreach ($groups as $group) {
            $hit = $this->matchRequested($group, $method, $path, $count, $prefix);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param MatcherGroup $group
     * @return int|array{0:int,1:array<string,string>}|null
     */
    public function matchRequested(array $group, string $method, string $path, int $count, string $prefix): int|array|null
    {
        $segments = null;
        $entry = $this->findMethod($group, $method, $path, $count, $prefix, $segments);
        if ($entry !== null) {
            return $entry[1] === [] ? $entry[0] : $entry;
        }
        if ($method !== HttpMethodEnum::HEAD->value) {
            return null;
        }

        $entry = $this->findMethod($group, HttpMethodEnum::GET->value, $path, $count, $prefix, $segments);

        return $entry === null ? null : ($entry[1] === [] ? $entry[0] : $entry);
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
    private static function addAllowedMethods(array &$allowed, array $methods, array $skip): void
    {
        foreach ($methods as $method) {
            if (!isset($skip[$method])) {
                $allowed[$method] = true;
            }
        }
    }

    /**
     * @param array<string,DynamicBucket> $byPrefix
     * @return list<DynamicBucket>
     */
    private static function bucketsForPrefix(array $byPrefix, string $prefix): array
    {
        $buckets = [];
        if (isset($byPrefix[$prefix])) {
            $buckets[] = $byPrefix[$prefix];
        }
        if ($prefix !== '*' && isset($byPrefix['*'])) {
            $buckets[] = $byPrefix['*'];
        }

        return $buckets;
    }

    /**
     * @param PcreEntry $entry
     * @param array<array-key,mixed> $matches
     * @return array{0:int,1:array<string,string>}
     */
    private static function captureParams(array $entry, array $matches): array
    {
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

    /**
     * @param list<FastDispatchStep> $steps
     * @return array{0:int,1:array<string,string>}|null
     */
    private static function findFastDispatchSteps(array $steps, string $path): ?array
    {
        foreach ($steps as $step) {
            $hit = self::findPcreStep(['type' => 'pcre'] + $step, $path);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param PcreStep $step
     * @return array{0:int,1:array<string,string>}|null
     */
    private static function findPcreStep(array $step, string $path): ?array
    {
        $matches = [];
        $status = preg_match($step['regex'], $path, $matches);
        if ($status === false) {
            throw new \RuntimeException('Compiled matcher PCRE failed during dispatch.');
        }
        if ($status !== 1) {
            return null;
        }

        $mark = $matches['MARK'] ?? null;
        if (!is_string($mark) || !isset($step['routes'][$mark])) {
            throw new \UnexpectedValueException('Compiled matcher PCRE returned an unknown route mark.');
        }

        return self::captureParams($step['routes'][$mark], $matches);
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
            if (!is_string($piece)) {
                return null;
            }
            if (($spec['type'] ?? null) === 'lit') {
                if (($spec['val'] ?? null) !== $piece) {
                    return null;
                }

                continue;
            }

            $name = $spec['name'] ?? null;
            if (($spec['type'] ?? null) !== 'var' || !is_string($name)) {
                return null;
            }
            $matches = isset($spec['regex'])
                ? is_string($spec['regex']) && preg_match($spec['regex'], $piece) === 1
                : is_callable($spec['call'] ?? null) && ($spec['call'])($piece);
            if (!$matches) {
                return null;
            }

            $params[$name] = $piece;
        }

        return $params;
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

    /**
     * @param MatcherGroup $group
     * @return list<AllowedBucket>
     */
    private function allowedBuckets(array $group, int $count, string $prefix): array
    {
        $byCount = $group['dynamic_allowed'][$count] ?? null;
        if (!is_array($byCount)) {
            return [];
        }

        $buckets = [];
        if (isset($byCount[$prefix])) {
            $buckets[] = $byCount[$prefix];
        }
        if ($prefix !== '*' && isset($byCount['*'])) {
            $buckets[] = $byCount['*'];
        }

        return $buckets;
    }

    /**
     * @param MatcherGroup $group
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string> $segments
     */
    private function collectFallbackAllowed(
        array $group,
        string $path,
        int $count,
        string $prefix,
        array $skip,
        array &$allowed,
        ?array &$segments,
    ): void {
        foreach ($group['dynamic'] as $method => $_buckets) {
            if (!isset($skip[$method]) && !isset($allowed[$method])
                && $this->findMethod($group, $method, $path, $count, $prefix, $segments) !== null) {
                self::addAllowedMethod($allowed, $method);
            }
        }
    }

    /**
     * @param array{type:'literal',segment:int,groups:array<string,AllowedLiteralEntry>} $bucket
     * @param array<string,true> $skip
     * @param array<string,bool> $allowed
     * @param list<string> $segments
     */
    private function collectLiteralAllowed(array $bucket, string $path, array $skip, array &$allowed, array &$segments): void
    {
        $value = $segments[$bucket['segment']] ?? null;
        if (!is_string($value)) {
            return;
        }
        $entry = $bucket['groups'][$value] ?? null;
        if ($entry === null) {
            return;
        }

        $status = preg_match($entry['regex'], $path);
        if ($status === false) {
            throw new \RuntimeException('Compiled matcher allowed-method PCRE failed during dispatch.');
        }
        if ($status === 1) {
            self::addAllowedMethods($allowed, $entry['methods'], $skip);
        }
    }

    /**
     * @param DynamicBucket $bucket
     * @param list<string>|null $segments
     * @return array{0:int,1:array<string,string>}|null
     */
    private function findBucket(array $bucket, string $path, ?array &$segments): ?array
    {
        $dispatch = $bucket['fast_dispatch'] ?? null;
        if ($dispatch !== null) {
            $segments ??= self::pathSegments($path);

            return $this->findFastDispatch($dispatch, $path, $segments);
        }

        foreach ($bucket['steps'] as $step) {
            if ($step['type'] === 'pcre') {
                $hit = self::findPcreStep($step, $path);
            } else {
                $segments ??= self::pathSegments($path);
                $hit = $this->findFallbackStep($step, $segments);
            }
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param FallbackStep $step
     * @param list<string> $segments
     * @return array{0:int,1:array<string,string>}|null
     */
    private function findFallbackStep(array $step, array &$segments): ?array
    {
        $params = self::matchFallbackSegments($step['segments'], $segments);

        return $params === null ? null : [$step['id'], $params];
    }

    /**
     * @param FastDispatch $dispatch
     * @param list<string> $segments
     * @return array{0:int,1:array<string,string>}|null
     */
    private function findFastDispatch(array $dispatch, string $path, array &$segments): ?array
    {
        $value = $segments[$dispatch['segment']] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $steps = $dispatch['groups'][$value] ?? null;

        return $steps === null ? null : self::findFastDispatchSteps($steps, $path);
    }

    /**
     * @param MatcherGroup $group
     * @param list<string>|null $segments
     * @return array{0:int,1:array<string,string>}|null
     */
    private function findMethod(
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

        foreach (self::bucketsForPrefix($byCount, $prefix) as $bucket) {
            $hit = $this->findBucket($bucket, $path, $segments);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }
}
