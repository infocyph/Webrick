<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Rich-result adapter over the compact production matcher executor.
 *
 * Routing discrimination is performed exactly once by CompiledMatcherFastEngine.
 * This class only resolves the winning scalar route ID into its central payload
 * and materializes a CompiledRoute when callers explicitly request MatchOutcome.
 *
 * @phpstan-type MatcherGroup array{routes:array<int,mixed>,static:array<string,array<string,int>>,static_allowed:array<string,list<string>>,dynamic:array<string,mixed>,dynamic_allowed:array<int,array<string,array<string,mixed>>>}
 * @phpstan-type CompiledMatch int|array{0:int,1:array<string,string>}|MatchOutcome
 */
final class CompiledMatcherEngine
{
    private CompiledMatcherFastEngine $fastEngine;

    /** @var array<int,CompiledRoute> */
    private array $materialized = [];

    public function __construct()
    {
        $this->fastEngine = new CompiledMatcherFastEngine();
    }

    /**
     * @param list<MatcherGroup> $hostGroups
     * @param list<MatcherGroup> $wildcardGroups
     */
    public function match(array $hostGroups, array $wildcardGroups, string $method, string $path): MatchOutcome
    {
        $result = $this->fastEngine->match($hostGroups, $wildcardGroups, $method, $path);

        return $this->richOutcome($result, [...$hostGroups, ...$wildcardGroups], $method);
    }

    /**
     * @param list<MatcherGroup> $hostGroups
     * @param list<MatcherGroup> $wildcardGroups
     * @return CompiledMatch
     */
    public function matchCompiled(array $hostGroups, array $wildcardGroups, string $method, string $path): int|array|MatchOutcome
    {
        return $this->fastEngine->match($hostGroups, $wildcardGroups, $method, $path);
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
        $result = $this->fastEngine->matchSingle($hostGroup, $wildcardGroup, $method, $path);
        $groups = [];
        if ($hostGroup !== null) {
            $groups[] = $hostGroup;
        }
        if ($wildcardGroup !== null) {
            $groups[] = $wildcardGroup;
        }

        return $this->richOutcome($result, $groups, $method);
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
        return $this->fastEngine->matchSingle($hostGroup, $wildcardGroup, $method, $path);
    }

    /**
     * @param CompiledMatch $result
     * @param list<MatcherGroup> $groups
     */
    private function richOutcome(int|array|MatchOutcome $result, array $groups, string $method): MatchOutcome
    {
        if ($result instanceof MatchOutcome) {
            return $result;
        }

        $id = is_int($result) ? $result : $result[0];
        $params = is_int($result) ? [] : $result[1];
        $route = $this->materialize($this->routePayload($groups, $id), $id);
        $headFallback = $method === HttpMethodEnum::HEAD->value
            && HttpMethodEnum::normalize($route->getMethod()) === HttpMethodEnum::GET->value;

        return MatchOutcome::found($route, $params, $headFallback);
    }

    /**
     * @param list<MatcherGroup> $groups
     */
    private static function routePayload(array $groups, int $id): mixed
    {
        foreach ($groups as $group) {
            if (array_key_exists($id, $group['routes'])) {
                return $group['routes'][$id];
            }
        }

        throw new \UnexpectedValueException('Compact matcher route ID is missing from the central payload table.');
    }

    private function materialize(mixed $value, int $id): CompiledRoute
    {
        if ($value instanceof CompiledRoute) {
            return $value;
        }
        if (!is_array($value) || ExecutableRoutePayload::routeIndex($value) !== $id) {
            throw new \UnexpectedValueException('Invalid compact matcher route payload.');
        }

        return $this->materialized[$id] ??= matcher_materialize_cached_route($value);
    }
}
