<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Shared runtime semantic engine for canonical matcher indexes. */
final class CanonicalMatcherEngine
{
    /** @var array<string,CompiledRoute> */
    private array $materialized = [];

    /**
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}> $hostGroups
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}> $wildcardGroups
     */
    public function match(array $hostGroups, array $wildcardGroups, string $method, string $path): MatchOutcome
    {
        return $this->matchGroups($hostGroups, $wildcardGroups, $method, $path, false);
    }

    /**
     * Strict compiled-runtime variant. FOUND outcomes carry only routeIndex.
     *
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}> $hostGroups
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}> $wildcardGroups
     */
    public function matchCompiled(array $hostGroups, array $wildcardGroups, string $method, string $path): MatchOutcome
    {
        return $this->matchGroups($hostGroups, $wildcardGroups, $method, $path, true);
    }

    /**
     * Fast path for one concrete host group plus one wildcard host group.
     *
     * @param array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}|null $hostGroup
     * @param array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}|null $wildcardGroup
     */
    public function matchSingle(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
    ): MatchOutcome {
        return $this->matchSingleGroup($hostGroup, $wildcardGroup, $method, $path, false);
    }

    /**
     * Strict compiled-runtime single-host fast path.
     *
     * @param array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}|null $hostGroup
     * @param array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}|null $wildcardGroup
     */
    public function matchSingleCompiled(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
    ): MatchOutcome {
        return $this->matchSingleGroup($hostGroup, $wildcardGroup, $method, $path, true);
    }

    /** @param array<string,bool> $allowed */
    private function addAllowed(array $verbs, array &$allowed): void
    {
        foreach ($verbs as $verb => $_route) {
            if (is_string($verb) && $verb !== '') {
                $allowed[$verb] = true;
            }
        }
        if (isset($verbs[HttpMethodEnum::GET->value])) {
            $allowed[HttpMethodEnum::HEAD->value] = true;
        }
    }

    /**
     * @param array<string,mixed> $group
     * @param list<string> $segments
     * @param array<string,bool> $allowed
     */
    private function matchDynamicGroup(
        array $group,
        string $method,
        array $segments,
        string $prefix,
        array &$allowed,
        bool $indexOnly,
    ): ?MatchOutcome {
        $bucket = $group['dynamic'][count($segments)] ?? null;
        if (!is_array($bucket)) {
            return null;
        }

        $entries = $bucket[$prefix] ?? null;
        if (is_array($entries)) {
            $hit = $this->matchDynamicEntries($entries, $method, $segments, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }

        if ($prefix === '*') {
            return null;
        }
        $entries = $bucket['*'] ?? null;

        return is_array($entries)
            ? $this->matchDynamicEntries($entries, $method, $segments, $allowed, $indexOnly)
            : null;
    }

    /**
     * @param array<mixed> $entries
     * @param list<string> $segments
     * @param array<string,bool> $allowed
     */
    private function matchDynamicEntries(
        array $entries,
        string $method,
        array $segments,
        array &$allowed,
        bool $indexOnly,
    ): ?MatchOutcome {
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_array($entry['segments'] ?? null) || !is_array($entry['verbs'] ?? null)) {
                continue;
            }
            $params = $this->matchSegments($entry['segments'], $segments);
            if ($params === null) {
                continue;
            }
            $outcome = $this->selectVerb($entry['verbs'], $method, $params, $indexOnly);
            if ($outcome instanceof MatchOutcome) {
                return $outcome;
            }
            $this->addAllowed($entry['verbs'], $allowed);
        }

        return null;
    }

    /**
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}> $hostGroups
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}> $wildcardGroups
     */
    private function matchGroups(
        array $hostGroups,
        array $wildcardGroups,
        string $method,
        string $path,
        bool $indexOnly,
    ): MatchOutcome {
        $allowed = [];

        foreach ($hostGroups as $group) {
            $hit = $this->matchStaticGroup($group, $method, $path, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }
        foreach ($wildcardGroups as $group) {
            $hit = $this->matchStaticGroup($group, $method, $path, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }

        $segments = self::segments($path);
        $prefix = $segments[0] ?? '';

        foreach ($hostGroups as $group) {
            $hit = $this->matchDynamicGroup($group, $method, $segments, $prefix, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }
        foreach ($wildcardGroups as $group) {
            $hit = $this->matchDynamicGroup($group, $method, $segments, $prefix, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }

        return self::missOutcome($method, $allowed);
    }

    /**
     * @param array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}|null $hostGroup
     * @param array{static:array<string,array<string,CompiledRoute|array<mixed>>>,dynamic:array<mixed>}|null $wildcardGroup
     */
    private function matchSingleGroup(
        ?array $hostGroup,
        ?array $wildcardGroup,
        string $method,
        string $path,
        bool $indexOnly,
    ): MatchOutcome {
        $allowed = [];

        if ($hostGroup !== null) {
            $hit = $this->matchStaticGroup($hostGroup, $method, $path, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = $this->matchStaticGroup($wildcardGroup, $method, $path, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }

        $segments = self::segments($path);
        $prefix = $segments[0] ?? '';

        if ($hostGroup !== null) {
            $hit = $this->matchDynamicGroup($hostGroup, $method, $segments, $prefix, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }
        if ($wildcardGroup !== null) {
            $hit = $this->matchDynamicGroup($wildcardGroup, $method, $segments, $prefix, $allowed, $indexOnly);
            if ($hit instanceof MatchOutcome) {
                return $hit;
            }
        }

        return self::missOutcome($method, $allowed);
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,bool> $allowed
     */
    private function matchStaticGroup(
        array $group,
        string $method,
        string $path,
        array &$allowed,
        bool $indexOnly,
    ): ?MatchOutcome {
        $verbs = $group['static'][$path] ?? null;
        if (!is_array($verbs)) {
            return null;
        }

        $outcome = $this->selectVerb($verbs, $method, [], $indexOnly);
        if ($outcome instanceof MatchOutcome) {
            return $outcome;
        }
        $this->addAllowed($verbs, $allowed);

        return null;
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
            if (!is_string($piece) || !is_array($spec)) {
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
            if (isset($spec['regex'])) {
                if (!is_string($spec['regex']) || preg_match($spec['regex'], $piece) !== 1) {
                    return null;
                }
            } else {
                /** @var callable-string $call */
                $call = $spec['call'];
                if (!$call($piece)) {
                    return null;
                }
            }
            $params[$name] = $piece;
        }

        return $params;
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
     * @param array<string,CompiledRoute|array<mixed>> $verbs
     * @param array<string,string> $params
     */
    private function selectVerb(array $verbs, string $method, array $params, bool $indexOnly): ?MatchOutcome
    {
        if (array_key_exists($method, $verbs)) {
            return $this->found($verbs[$method], $params, false, $indexOnly);
        }
        if ($method === HttpMethodEnum::HEAD->value && array_key_exists(HttpMethodEnum::GET->value, $verbs)) {
            return $this->found($verbs[HttpMethodEnum::GET->value], $params, true, $indexOnly);
        }

        return null;
    }

    /** @param array<string,string> $params */
    private function found(mixed $value, array $params, bool $headFallback, bool $indexOnly): MatchOutcome
    {
        return $indexOnly
            ? MatchOutcome::foundIndex(self::routeIndex($value), $params, $headFallback)
            : MatchOutcome::found($this->materialize($value), $params, $headFallback);
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        if ($path === '/' || $path === '') {
            return [];
        }

        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }
}
