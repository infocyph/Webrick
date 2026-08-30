<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Shared runtime semantic engine for canonical matcher indexes.
 *
 * Exact static lookup is always attempted before any path splitting. Dynamic
 * candidates are limited by segment count and first-literal prefix. Callable
 * constraints have already been validated at route compilation/artifact load.
 */
final class CanonicalMatcherEngine
{
    /** @var array<string,CompiledRoute> */
    private array $materialized = [];

    /**
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>|string>>,dynamic:array<mixed>}> $hostGroups
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>|string>>,dynamic:array<mixed>}> $wildcardGroups
     */
    public function match(array $hostGroups, array $wildcardGroups, string $method, string $path): MatchOutcome
    {
        $allowed = [];

        $hit = $this->matchStaticGroups($hostGroups, $method, $path, $allowed);
        if ($hit instanceof MatchOutcome) {
            return $hit;
        }
        $hit = $this->matchStaticGroups($wildcardGroups, $method, $path, $allowed);
        if ($hit instanceof MatchOutcome) {
            return $hit;
        }

        $segments = self::segments($path);
        $prefix = $segments[0] ?? '';

        $hit = $this->matchDynamicGroups($hostGroups, $method, $segments, $prefix, $allowed);
        if ($hit instanceof MatchOutcome) {
            return $hit;
        }
        $hit = $this->matchDynamicGroups($wildcardGroups, $method, $segments, $prefix, $allowed);
        if ($hit instanceof MatchOutcome) {
            return $hit;
        }

        if ($allowed === []) {
            return MatchOutcome::notFound();
        }

        $methods = array_keys($allowed);

        return $method === HttpMethodEnum::OPTIONS->value
            ? MatchOutcome::autoOptions($methods)
            : MatchOutcome::methodNotAllowed($methods);
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
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>|string>>,dynamic:array<mixed>}> $groups
     * @param list<string> $segments
     * @param array<string,bool> $allowed
     */
    private function matchDynamicGroups(
        array $groups,
        string $method,
        array $segments,
        string $prefix,
        array &$allowed,
    ): ?MatchOutcome {
        $count = count($segments);
        foreach ($groups as $group) {
            $bucket = $group['dynamic'][$count] ?? null;
            if (!is_array($bucket)) {
                continue;
            }

            $candidateSets = [];
            if (isset($bucket[$prefix]) && is_array($bucket[$prefix])) {
                $candidateSets[] = $bucket[$prefix];
            }
            if ($prefix !== '*' && isset($bucket['*']) && is_array($bucket['*'])) {
                $candidateSets[] = $bucket['*'];
            }

            foreach ($candidateSets as $entries) {
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !is_array($entry['segments'] ?? null) || !is_array($entry['verbs'] ?? null)) {
                        continue;
                    }
                    $params = $this->matchSegments($entry['segments'], $segments);
                    if ($params === null) {
                        continue;
                    }
                    $outcome = $this->selectVerb($entry['verbs'], $method, $params);
                    if ($outcome instanceof MatchOutcome) {
                        return $outcome;
                    }
                    $this->addAllowed($entry['verbs'], $allowed);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{static:array<string,array<string,CompiledRoute|array<mixed>|string>>,dynamic:array<mixed>}> $groups
     * @param array<string,bool> $allowed
     */
    private function matchStaticGroups(array $groups, string $method, string $path, array &$allowed): ?MatchOutcome
    {
        foreach ($groups as $group) {
            $verbs = $group['static'][$path] ?? null;
            if (!is_array($verbs)) {
                continue;
            }
            $outcome = $this->selectVerb($verbs, $method, []);
            if ($outcome instanceof MatchOutcome) {
                return $outcome;
            }
            $this->addAllowed($verbs, $allowed);
        }

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

    private function materialize(mixed $value): CompiledRoute
    {
        if ($value instanceof CompiledRoute) {
            return $value;
        }
        if (is_array($value)) {
            $index = $value[10] ?? null;
            if (!is_int($index)) {
                throw new \UnexpectedValueException('Cached compiled route is missing its route index.');
            }
            $key = 'payload:' . $index;

            return $this->materialized[$key] ??= matcher_materialize_cached_route($value);
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Invalid compiled route value in matcher index.');
        }

        return $this->materialized[$value] ??= matcher_materialize_cached_route($value);
    }

    /**
     * @param array<string,CompiledRoute|array<mixed>|string> $verbs
     * @param array<string,string> $params
     */
    private function selectVerb(array $verbs, string $method, array $params): ?MatchOutcome
    {
        if (array_key_exists($method, $verbs)) {
            return MatchOutcome::found($this->materialize($verbs[$method]), $params);
        }
        if ($method === HttpMethodEnum::HEAD->value && array_key_exists(HttpMethodEnum::GET->value, $verbs)) {
            return MatchOutcome::found(
                $this->materialize($verbs[HttpMethodEnum::GET->value]),
                $params,
                true,
            );
        }

        return null;
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
