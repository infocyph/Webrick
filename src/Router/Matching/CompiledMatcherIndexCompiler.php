<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/**
 * Converts the canonical route index into the request-time matcher IR.
 *
 * Static routes become method-first exact-path maps. Dynamic regex routes are
 * compiled into bounded MARK-based PCRE chunks. Routes containing callable
 * constraints stay in a narrow method-specific fallback lane.
 *
 * @phpstan-type RouteValue mixed
 * @phpstan-type SegmentSpec array<string,mixed>
 * @phpstan-type CanonicalDynamicEntry array{segments:list<SegmentSpec>,verbs:array<string,RouteValue>}
 * @phpstan-type FastRouteEntry array{route:RouteValue,params:array<int,string>}
 * @phpstan-type PcreChunk array{regex:string,routes:array<string,FastRouteEntry>}
 * @phpstan-type FallbackEntry array{segments:list<SegmentSpec>,route:RouteValue}
 * @phpstan-type DynamicBucket array{pcre:list<PcreChunk>,fallback:list<FallbackEntry>}
 */
final class CompiledMatcherIndexCompiler
{
    public const int DEFAULT_CHUNK_SIZE = 32;

    public function __construct(private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if ($this->chunkSize < 1) {
            throw new \InvalidArgumentException('Matcher PCRE chunk size must be at least 1.');
        }
    }

    /**
     * @param array<string,array{static:array<string,array<string,mixed>>,dynamic:array<int,array<string,array<string,CanonicalDynamicEntry>>>}> $indexes
     * @return array<string,array{static:array<string,array<string,RouteValue>>,dynamic:array<string,array<int,array<string,DynamicBucket>>>}>
     */
    public function compile(array $indexes): array
    {
        $compiled = [];
        foreach ($indexes as $host => $index) {
            $compiled[$host] = [
                'static' => $this->compileStatic($index['static']),
                'dynamic' => $this->compileDynamic($index['dynamic']),
            ];
        }

        return $compiled;
    }

    /**
     * @param array<int,array<string,array<string,CanonicalDynamicEntry>>> $dynamic
     * @return array<string,array<int,array<string,DynamicBucket>>>
     */
    private function compileDynamic(array $dynamic): array
    {
        $methods = [];

        foreach ($dynamic as $count => $prefixes) {
            foreach ($prefixes as $prefix => $entries) {
                /** @var array<string,list<array{segments:list<SegmentSpec>,route:RouteValue}>> $pcre */
                $pcre = [];
                /** @var array<string,list<FallbackEntry>> $fallback */
                $fallback = [];

                foreach ($entries as $entry) {
                    $segments = $entry['segments'];
                    $canCompile = $this->isPcreCompilable($segments);
                    foreach ($entry['verbs'] as $method => $route) {
                        if ($canCompile) {
                            $pcre[$method][] = ['segments' => $segments, 'route' => $route];
                        } else {
                            $fallback[$method][] = ['segments' => $segments, 'route' => $route];
                        }
                    }
                }

                foreach ($pcre as $method => $routes) {
                    $methods[$method][$count][$prefix] ??= ['pcre' => [], 'fallback' => []];
                    $methods[$method][$count][$prefix]['pcre'] = $this->compileChunks($routes);
                }
                foreach ($fallback as $method => $routes) {
                    $methods[$method][$count][$prefix] ??= ['pcre' => [], 'fallback' => []];
                    $methods[$method][$count][$prefix]['fallback'] = $routes;
                }
            }
        }

        return $methods;
    }

    /**
     * @param list<array{segments:list<SegmentSpec>,route:RouteValue}> $routes
     * @return list<PcreChunk>
     */
    private function compileChunks(array $routes): array
    {
        $chunks = [];
        foreach (array_chunk($routes, $this->chunkSize) as $routesChunk) {
            $alternatives = [];
            $routeMap = [];

            foreach ($routesChunk as $index => $entry) {
                $mark = 'r' . $index;
                $alternatives[] = '(?:' . $this->routePattern($entry['segments']) . ')(*MARK:' . $mark . ')';
                $routeMap[$mark] = [
                    'route' => $entry['route'],
                    'params' => $this->parameterPositions($entry['segments']),
                ];
            }

            $regex = '~\\A(?:' . implode('|', $alternatives) . ')\\z~D';
            if (@preg_match($regex, '') === false) {
                throw new \UnexpectedValueException('Failed to compile combined matcher PCRE chunk.');
            }

            $chunks[] = ['regex' => $regex, 'routes' => $routeMap];
        }

        return $chunks;
    }

    /** @param list<SegmentSpec> $segments */
    private function isPcreCompilable(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (($segment['type'] ?? null) === 'var' && !isset($segment['regex'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<SegmentSpec> $segments
     * @return array<int,string>
     */
    private function parameterPositions(array $segments): array
    {
        $positions = [];
        foreach ($segments as $index => $segment) {
            if (($segment['type'] ?? null) !== 'var') {
                continue;
            }
            $name = $segment['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Compiled matcher variable is missing its name.');
            }
            $positions[$index] = $name;
        }

        return $positions;
    }

    /** @param list<SegmentSpec> $segments */
    private function routePattern(array $segments): string
    {
        if ($segments === []) {
            return '/';
        }

        $parts = [];
        foreach ($segments as $segment) {
            $type = $segment['type'] ?? null;
            if ($type === 'lit') {
                $literal = $segment['val'] ?? null;
                if (!is_string($literal)) {
                    throw new \UnexpectedValueException('Compiled matcher literal is invalid.');
                }
                $parts[] = preg_quote($literal, '~');

                continue;
            }
            if ($type !== 'var') {
                throw new \UnexpectedValueException('Compiled matcher segment type is invalid.');
            }
            $regex = $segment['regex'] ?? null;
            if (!is_string($regex)) {
                throw new \LogicException('Callable matcher segment cannot enter the PCRE fast lane.');
            }
            $parts[] = '(?:' . self::segmentRegexInner($regex) . ')';
        }

        return '/' . implode('/', $parts);
    }

    private static function segmentRegexInner(string $regex): string
    {
        if (!str_starts_with($regex, '#\\A') || !str_ends_with($regex, '\\z#D')) {
            throw new \UnexpectedValueException('Compiled matcher segment regex has an unsupported form.');
        }

        $inner = substr($regex, 3, -4);
        if ($inner === '') {
            throw new \UnexpectedValueException('Compiled matcher segment regex cannot be empty.');
        }

        // The combined matcher uses ~ as its delimiter. Escaping an otherwise
        // literal ~ is safe both inside and outside character classes.
        return str_replace('~', '\\~', $inner);
    }

    /**
     * @param array<string,array<string,RouteValue>> $static
     * @return array<string,array<string,RouteValue>>
     */
    private function compileStatic(array $static): array
    {
        $compiled = [];
        foreach ($static as $path => $verbs) {
            foreach ($verbs as $method => $route) {
                $compiled[$method][$path] = $route;
            }
        }

        return $compiled;
    }
}
