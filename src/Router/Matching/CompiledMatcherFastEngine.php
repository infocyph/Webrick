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
    private readonly CompiledMatcherDynamicEngine $dynamic;

    public function __construct()
    {
        $this->dynamic = new CompiledMatcherDynamicEngine();
    }

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
        $hit = $this->dynamic->matchGroups($hostGroups, $method, $path, $count, $prefix);
        if ($hit !== null) {
            return $hit;
        }
        $hit = $this->dynamic->matchGroups($wildcardGroups, $method, $path, $count, $prefix);
        if ($hit !== null) {
            return $hit;
        }

        $allowed = [];
        $skip = self::alreadyTestedMethods($method);
        self::collectCompiledStaticAllowed($hostGroups, $path, $skip, $allowed);
        self::collectCompiledStaticAllowed($wildcardGroups, $path, $skip, $allowed);
        $segments = null;
        $this->dynamic->collectAllowedGroups($hostGroups, $path, $count, $prefix, $skip, $allowed, $segments);
        $this->dynamic->collectAllowedGroups($wildcardGroups, $path, $count, $prefix, $skip, $allowed, $segments);

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
        $hit = $this->matchSingleDynamic($hostGroup, $wildcardGroup, $method, $path, $count, $prefix);
        if ($hit !== null) {
            return $hit;
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
            $this->dynamic->collectAllowedGroup($hostGroup, $path, $count, $prefix, $skip, $allowed, $segments);
        }
        if ($wildcardGroup !== null) {
            $this->dynamic->collectAllowedGroup($wildcardGroup, $path, $count, $prefix, $skip, $allowed, $segments);
        }

        return self::missOutcome($method, $allowed);
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
     */
    private static function collectCompiledStaticAllowedGroup(array $group, string $path, array $skip, array &$allowed): void
    {
        $methods = $group['static_allowed'][$path] ?? null;
        if (is_array($methods)) {
            self::addCompiledAllowedMethods($allowed, $methods, $skip);
        }
    }

    /**
     * @param MatcherGroup|null $hostGroup
     * @param MatcherGroup|null $wildcardGroup
     * @return int|array{0:int,1:array<string,string>}|null
     */
    private function matchSingleDynamic(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
        int $count,
        string $prefix,
    ): int|array|null {
        if ($hostGroup !== null) {
            $hit = $this->dynamic->matchRequested($hostGroup, $method, $path, $count, $prefix);
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = $this->dynamic->matchRequested($wildcardGroup, $method, $path, $count, $prefix);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
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
}
