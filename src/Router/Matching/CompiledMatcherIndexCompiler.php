<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Converts the canonical route index into the request-time matcher IR.
 *
 * Static routes become method-first exact-path maps. Dynamic routes retain
 * registration precedence as ordered steps: consecutive safe regex routes are
 * combined into MARK-based PCRE chunks, while callable/arbitrary-regex routes
 * remain individual fallback steps that act as precedence barriers.
 *
 * The rich route payload remains available for match()/matchOutcome(), while
 * the compact production lane receives deterministic scalar route IDs directly
 * and never has to rediscover an ID from a route object/payload after a hit.
 *
 * @phpstan-type RouteValue mixed
 * @phpstan-type SegmentSpec array<string,mixed>
 * @phpstan-type CanonicalDynamicEntry array{segments:list<SegmentSpec>,verbs:array<string,RouteValue>}
 * @phpstan-type FastRouteEntry array{route:RouteValue,id:int,params:array<string,string>}
 * @phpstan-type PcreStep array{type:'pcre',regex:string,routes:array<string,FastRouteEntry>}
 * @phpstan-type FallbackStep array{type:'fallback',segments:list<SegmentSpec>,route:RouteValue,id:int}
 * @phpstan-type DynamicStep PcreStep|FallbackStep
 * @phpstan-type DynamicBucket array{steps:list<DynamicStep>}
 */
final class CompiledMatcherIndexCompiler
{
    /**
     * Approximate PCRE routes per chunk. Contiguous PCRE runs are redistributed
     * evenly around this target so the final tail is not disproportionately
     * small. Benchmarking on PHP 8.4 selected 48 as the best balanced target.
     */
    public const int DEFAULT_CHUNK_SIZE = 48;

    public function __construct(private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if ($this->chunkSize < 1) {
            throw new \InvalidArgumentException('Matcher PCRE chunk size must be at least 1.');
        }
    }

    /**
     * @param array<string,array{static:array<string,array<string,mixed>>,dynamic:array<int,array<string,array<string,CanonicalDynamicEntry>>>}> $indexes
     * @return array<string,array{static:array<string,array<string,RouteValue>>,static_ids:array<string,array<string,int>>,dynamic:array<string,array<int,array<string,DynamicBucket>>>}>
     */
    public function compile(array $indexes): array
    {
        $compiled = [];
        foreach ($indexes as $host => $index) {
            [$static, $staticIds] = $this->compileStatic($index['static']);
            $compiled[$host] = [
                'static' => $static,
                'static_ids' => $staticIds,
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
                /** @var array<string,list<array{segments:list<SegmentSpec>,route:RouteValue,id:int,pcre:bool}>> $ordered */
                $ordered = [];

                foreach ($entries as $entry) {
                    $segments = $entry['segments'];
                    $canCompile = $this->isPcreCompilable($segments);
                    foreach ($entry['verbs'] as $method => $route) {
                        $ordered[$method][] = [
                            'segments' => $segments,
                            'route' => $route,
                            'id' => self::routeIndex($route),
                            'pcre' => $canCompile,
                        ];
                    }
                }

                foreach ($ordered as $method => $routes) {
                    $methods[$method][$count][$prefix] = [
                        'steps' => $this->compileSteps($routes),
                    ];
                }
            }
        }

        return $methods;
    }

    /**
     * @param list<array{segments:list<SegmentSpec>,route:RouteValue,id:int,pcre:bool}> $routes
     * @return list<DynamicStep>
     */
    private function compileSteps(array $routes): array
    {
        $steps = [];
        $pcreBuffer = [];

        foreach ($routes as $entry) {
            if ($entry['pcre']) {
                $pcreBuffer[] = [
                    'segments' => $entry['segments'],
                    'route' => $entry['route'],
                    'id' => $entry['id'],
                ];

                continue;
            }

            if ($pcreBuffer !== []) {
                array_push($steps, ...$this->compileBalancedPcreSteps($pcreBuffer));
                $pcreBuffer = [];
            }
            $steps[] = [
                'type' => 'fallback',
                'segments' => $entry['segments'],
                'route' => $entry['route'],
                'id' => $entry['id'],
            ];
        }

        if ($pcreBuffer !== []) {
            array_push($steps, ...$this->compileBalancedPcreSteps($pcreBuffer));
        }

        return $steps;
    }

    /**
     * Evenly partitions one precedence-safe PCRE run around the configured
     * approximate target, following the same balancing principle used by
     * FastRoute rather than rigidly slicing at the target boundary.
     *
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return non-empty-list<PcreStep>
     */
    private function compileBalancedPcreSteps(array $routes): array
    {
        $count = count($routes);
        $parts = max(1, (int) round($count / $this->chunkSize));
        $size = (int) ceil($count / $parts);
        $steps = [];

        foreach (array_chunk($routes, $size) as $chunk) {
            $steps[] = $this->compilePcreStep($chunk);
        }

        return $steps;
    }

    /**
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return PcreStep
     */
    private function compilePcreStep(array $routes): array
    {
        $alternatives = [];
        $routeMap = [];

        foreach ($routes as $index => $entry) {
            $mark = 'r' . $index;
            [$pattern, $params] = $this->routePattern($entry['segments'], $index);
            $alternatives[] = '(?:' . $pattern . ')(*MARK:' . $mark . ')';
            $routeMap[$mark] = [
                'route' => $entry['route'],
                'id' => $entry['id'],
                'params' => $params,
            ];
        }

        // J allows different built-in route constraints to reuse named groups
        // inside separate alternatives without capture conflicts.
        $regex = '~(?J)\\A(?:' . implode('|', $alternatives) . ')\\z~D';
        if (@preg_match($regex, '') === false) {
            throw new \UnexpectedValueException('Failed to compile combined matcher PCRE chunk.');
        }

        return ['type' => 'pcre', 'regex' => $regex, 'routes' => $routeMap];
    }

    private static function escapeDelimiter(string $pattern, string $delimiter): string
    {
        $out = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === $delimiter) {
                $slashes = 0;
                for ($j = $i - 1; $j >= 0 && $pattern[$j] === '\\'; $j--) {
                    $slashes++;
                }
                if (($slashes % 2) === 0) {
                    $out .= '\\';
                }
            }
            $out .= $char;
        }

        return $out;
    }

    /** @param list<SegmentSpec> $segments */
    private function isPcreCompilable(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (($segment['type'] ?? null) !== 'var') {
                continue;
            }
            $regex = $segment['regex'] ?? null;
            if (!is_string($regex) || !ConstraintRegistry::isCombinedPcreSafeSegmentRegex($regex)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<SegmentSpec> $segments
     * @return array{0:string,1:array<string,string>}
     */
    private function routePattern(array $segments, int $routeOrdinal): array
    {
        if ($segments === []) {
            return ['/*', []];
        }

        $parts = [];
        $params = [];
        $parameterOrdinal = 0;

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
            $name = $segment['name'] ?? null;
            if (!is_string($regex) || !is_string($name) || $name === '') {
                throw new \LogicException('Non-PCRE matcher segment cannot enter the PCRE fast lane.');
            }

            $capture = 'w' . $routeOrdinal . 'p' . $parameterOrdinal++;
            $parts[] = '(?<' . $capture . '>' . self::segmentRegexInner($regex) . ')';
            $params[$capture] = $name;
        }

        return ['/*' . implode('/', $parts) . '/*', $params];
    }

    private static function routeIndex(mixed $route): int
    {
        $index = $route instanceof CompiledRoute
            ? $route->getIndex()
            : ExecutableRoutePayload::routeIndex($route);

        if (!is_int($index) || $index < 0) {
            throw new \UnexpectedValueException('Compiled matcher route is missing a valid deterministic route index.');
        }

        return $index;
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

        return self::escapeDelimiter($inner, '~');
    }

    /**
     * @param array<string,array<string,RouteValue>> $static
     * @return array{0:array<string,array<string,RouteValue>>,1:array<string,array<string,int>>}
     */
    private function compileStatic(array $static): array
    {
        $compiled = [];
        $ids = [];
        foreach ($static as $path => $verbs) {
            foreach ($verbs as $method => $route) {
                $compiled[$method][$path] = $route;
                $ids[$method][$path] = self::routeIndex($route);
            }
        }

        return [$compiled, $ids];
    }
}
