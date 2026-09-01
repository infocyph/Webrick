<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Converts the canonical route index into the request-time matcher IR.
 *
 * Static routes become method-first exact-path maps. Dynamic routes retain
 * registration precedence as ordered steps: consecutive safe regex routes are
 * combined into MARK-based PCRE chunks, while callable/arbitrary-regex routes
 * remain individual fallback steps that act as precedence barriers.
 *
 * Rich route payload/named-capture data remains available for matchOutcome(),
 * while compact production dispatch receives deterministic scalar route IDs
 * plus a positional branch-reset PCRE that avoids named-capture bookkeeping.
 * Large all-PCRE families may additionally receive a build-time-selected
 * literal-segment discriminator when it sharply reduces the candidate set.
 *
 * Miss handling receives its own build-time metadata. Exact static paths carry
 * their complete allowed-method set. Dynamic buckets receive a method-
 * independent literal discriminator only when one literal segment uniquely
 * identifies every canonical route shape; ambiguous families retain the
 * general cross-method fallback so 405/OPTIONS semantics stay exact.
 *
 * @phpstan-type RouteValue mixed
 * @phpstan-type SegmentSpec array<string,mixed>
 * @phpstan-type CanonicalDynamicEntry array{segments:list<SegmentSpec>,verbs:array<string,RouteValue>}
 * @phpstan-type FastRouteEntry array{route:RouteValue,id:int,params:array<string,string>,fast_params:list<string>}
 * @phpstan-type PcreStep array{type:'pcre',regex:string,fast_regex:string,routes:array<string,FastRouteEntry>}
 * @phpstan-type FastDispatchEntry array{id:int,params:list<string>}
 * @phpstan-type FastDispatchStep array{regex:string,routes:array<string,FastDispatchEntry>}
 * @phpstan-type FastDispatch array{segment:int,groups:array<array-key,list<FastDispatchStep>>}
 * @phpstan-type AllowedLiteralEntry array{regex:string,methods:list<string>}
 * @phpstan-type AllowedBucket array{type:'literal',segment:int,groups:array<array-key,AllowedLiteralEntry>}|array{type:'fallback'}
 * @phpstan-type FallbackStep array{type:'fallback',segments:list<SegmentSpec>,route:RouteValue,id:int}
 * @phpstan-type DynamicStep PcreStep|FallbackStep
 * @phpstan-type DynamicBucket array{steps:list<DynamicStep>,fast_dispatch?:FastDispatch}
 */
final readonly class CompiledMatcherIndexCompiler
{
    /**
     * Approximate PCRE routes per chunk. Contiguous PCRE runs are redistributed
     * evenly around this target so the final tail is not disproportionately
     * small. Benchmarking on PHP 8.4 selected 48 as the best balanced target.
     */
    public const int DEFAULT_CHUNK_SIZE = 48;

    public function __construct(private int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if ($this->chunkSize < 1) {
            throw new \InvalidArgumentException('Matcher PCRE chunk size must be at least 1.');
        }
    }

    /**
     * @param array<string,array{static:array<string,array<string,mixed>>,dynamic:array<int,array<string,array<string,CanonicalDynamicEntry>>>}> $indexes
     * @return array<string,array{static:array<string,array<string,RouteValue>>,static_ids:array<string,array<string,int>>,static_allowed:array<string,list<string>>,dynamic:array<string,array<int,array<string,DynamicBucket>>>,dynamic_allowed:array<int,array<string,AllowedBucket>>}>
     */
    public function compile(array $indexes): array
    {
        $compiled = [];
        foreach ($indexes as $host => $index) {
            [$static, $staticIds, $staticAllowed] = $this->compileStatic($index['static']);
            [$dynamic, $dynamicAllowed] = $this->compileDynamic($index['dynamic']);
            $compiled[$host] = [
                'static' => $static,
                'static_ids' => $staticIds,
                'static_allowed' => $staticAllowed,
                'dynamic' => $dynamic,
                'dynamic_allowed' => $dynamicAllowed,
            ];
        }

        return $compiled;
    }

    /**
     * @param array<string,RouteValue> $verbs
     * @return list<string>
     */
    private static function allowedMethods(array $verbs): array
    {
        $allowed = [];
        foreach ($verbs as $method => $_route) {
            if ($method === '') {
                continue;
            }

            $allowed[$method] = true;
            if ($method === 'GET') {
                $allowed['HEAD'] = true;
            }
        }

        return array_keys($allowed);
    }

    /** @return int<1,max> */
    private static function balancedChunkSize(int $count, int $parts): int
    {
        return max(1, (int) ceil($count / $parts));
    }

    /**
     * @param list<array{segments:list<SegmentSpec>}> $routes
     * @return array{segment:int,distinct:int}|null
     */
    private static function bestLiteralSegment(array $routes): ?array
    {
        $best = null;
        foreach ($routes[0]['segments'] as $segment => $_spec) {
            $values = [];
            foreach ($routes as $route) {
                $literal = self::literalSegment($route['segments'], $segment);
                if ($literal === null) {
                    continue 2;
                }
                $values[$literal] = true;
            }

            $distinct = count($values);
            if ($best === null || $distinct > $best['distinct']) {
                $best = ['segment' => $segment, 'distinct' => $distinct];
            }
        }

        return $best;
    }

    /**
     * @param list<SegmentSpec> $segments
     * @return list<SegmentSpec>
     */
    private static function compileFallbackSegments(array $segments): array
    {
        $compiled = [];
        foreach ($segments as $segment) {
            $call = $segment['call'] ?? null;
            if (($segment['type'] ?? null) !== 'var' || !is_string($call)) {
                $compiled[] = $segment;

                continue;
            }

            $name = $segment['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Compiled matcher callable segment is invalid.');
            }

            $opcode = CompiledMatcherConstraintOpcode::fromCallable($call);
            $compiled[] = $opcode === null
                ? $segment
                : ['type' => 'op', 'name' => $name, 'code' => $opcode];
        }

        return $compiled;
    }

    /** @param list<SegmentSpec> $segments */
    private static function literalSegment(array $segments, int $segment): ?string
    {
        $spec = $segments[$segment] ?? null;

        return is_array($spec) && ($spec['type'] ?? null) === 'lit' && is_string($spec['val'] ?? null)
            ? $spec['val']
            : null;
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

    /**
     * A method-independent dynamic miss accelerator is emitted only when every
     * canonical route in the bucket is PCRE-safe and one literal segment has a
     * unique value per route shape. A selected literal therefore identifies at
     * most one canonical shape, so its complete method set can be returned
     * without losing overlapping-route Allow semantics.
     *
     * @param array<string,CanonicalDynamicEntry> $entries
     * @return AllowedBucket
     */
    private function compileAllowedBucket(array $entries): array
    {
        $routeCount = count($entries);
        if ($routeCount < 4) {
            return ['type' => 'fallback'];
        }

        $routes = array_values($entries);
        foreach ($routes as $entry) {
            if (!CompiledMatcherPatternCompiler::isCompilable($entry['segments'])) {
                return ['type' => 'fallback'];
            }
        }

        $selection = self::bestLiteralSegment($routes);
        if ($selection === null || $selection['distinct'] !== $routeCount) {
            return ['type' => 'fallback'];
        }

        $groups = [];
        foreach ($routes as $entry) {
            $literal = self::literalSegment($entry['segments'], $selection['segment'])
                ?? throw new \UnexpectedValueException('Compiled matcher literal segment is invalid.');
            $groups[$literal] = [
                'regex' => CompiledMatcherPatternCompiler::predicate($entry['segments']),
                'methods' => self::allowedMethods($entry['verbs']),
            ];
        }

        return ['type' => 'literal', 'segment' => $selection['segment'], 'groups' => $groups];
    }

    /**
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return non-empty-list<FastDispatchStep>
     */
    private function compileBalancedFastDispatchSteps(array $routes): array
    {
        $count = count($routes);
        $parts = max(1, (int) round($count / $this->chunkSize));
        $size = self::balancedChunkSize($count, $parts);
        $steps = [];

        foreach (array_chunk($routes, $size) as $chunk) {
            $steps[] = $this->compileFastDispatchStep($chunk);
        }

        return $steps;
    }

    /**
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return non-empty-list<PcreStep>
     */
    private function compileBalancedPcreSteps(array $routes): array
    {
        $count = count($routes);
        $parts = max(1, (int) round($count / $this->chunkSize));
        $size = self::balancedChunkSize($count, $parts);
        $steps = [];

        foreach (array_chunk($routes, $size) as $chunk) {
            $steps[] = $this->compilePcreStep($chunk);
        }

        return $steps;
    }

    /**
     * @param list<array{segments:list<SegmentSpec>,route:RouteValue,id:int,pcre:bool}> $routes
     * @return DynamicBucket
     */
    private function compileBucket(array $routes): array
    {
        $bucket = ['steps' => $this->compileSteps($routes)];
        $dispatch = $this->compileFastLiteralDispatch($routes);
        if ($dispatch !== null) {
            $bucket['fast_dispatch'] = $dispatch;
        }

        return $bucket;
    }

    /**
     * @param array<int,array<string,array<string,CanonicalDynamicEntry>>> $dynamic
     * @return array{0:array<string,array<int,array<string,DynamicBucket>>>,1:array<int,array<string,AllowedBucket>>}
     */
    private function compileDynamic(array $dynamic): array
    {
        $methods = [];
        $allowed = [];

        foreach ($dynamic as $count => $prefixes) {
            foreach ($prefixes as $prefix => $entries) {
                $allowed[$count][$prefix] = $this->compileAllowedBucket($entries);

                /** @var array<string,list<array{segments:list<SegmentSpec>,route:RouteValue,id:int,pcre:bool}>> $ordered */
                $ordered = [];
                foreach ($entries as $entry) {
                    $segments = $entry['segments'];
                    $canCompile = CompiledMatcherPatternCompiler::isCompilable($segments);
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
                    $methods[$method][$count][$prefix] = $this->compileBucket($routes);
                }
            }
        }

        return [$methods, $allowed];
    }

    /**
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return FastDispatchStep
     */
    private function compileFastDispatchStep(array $routes): array
    {
        $alternatives = [];
        $routeMap = [];
        foreach ($routes as $index => $entry) {
            $mark = 'r' . $index;
            [$pattern, $params] = CompiledMatcherPatternCompiler::positionalPattern($entry['segments']);
            $alternatives[] = '(?:' . $pattern . ')(*MARK:' . $mark . ')';
            $routeMap[$mark] = ['id' => $entry['id'], 'params' => $params];
        }

        $regex = '~\\A(?|' . implode('|', $alternatives) . ')\\z~D';
        if (preg_match($regex, '') === false) {
            throw new \UnexpectedValueException('Failed to compile adaptive matcher PCRE chunk.');
        }

        return ['regex' => $regex, 'routes' => $routeMap];
    }

    /**
     * Builds a compact-only literal discriminator when one segment is literal
     * in every route, has enough distinct values to materially shrink the
     * candidate set, and the entire family is safe for combined PCRE matching.
     * Distinct literal values are disjoint, so grouping cannot alter route
     * precedence; order remains intact inside each selected group.
     *
     * @param list<array{segments:list<SegmentSpec>,route:RouteValue,id:int,pcre:bool}> $routes
     * @return FastDispatch|null
     */
    private function compileFastLiteralDispatch(array $routes): ?array
    {
        $routeCount = count($routes);
        if ($routeCount <= $this->chunkSize) {
            return null;
        }
        foreach ($routes as $entry) {
            if (!$entry['pcre']) {
                return null;
            }
        }

        $selection = self::bestLiteralSegment($routes);
        if ($selection === null || $selection['distinct'] < 4) {
            return null;
        }
        if ((int) ceil($routeCount / $selection['distinct']) > max(1, intdiv($this->chunkSize, 2))) {
            return null;
        }

        /** @var array<string,list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}>> $groups */
        $groups = [];
        foreach ($routes as $entry) {
            $key = self::literalSegment($entry['segments'], $selection['segment'])
                ?? throw new \UnexpectedValueException('Compiled matcher literal segment is invalid.');
            $groups[$key][] = [
                'segments' => $entry['segments'],
                'route' => $entry['route'],
                'id' => $entry['id'],
            ];
        }

        $compiled = [];
        foreach ($groups as $key => $group) {
            $compiled[$key] = $this->compileBalancedFastDispatchSteps($group);
        }

        return ['segment' => $selection['segment'], 'groups' => $compiled];
    }

    /**
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return PcreStep
     */
    private function compilePcreStep(array $routes): array
    {
        $alternatives = [];
        $fastAlternatives = [];
        $routeMap = [];

        foreach ($routes as $index => $entry) {
            $mark = 'r' . $index;
            [$pattern, $params] = CompiledMatcherPatternCompiler::namedPattern($entry['segments'], $index);
            [$fastPattern, $fastParams] = CompiledMatcherPatternCompiler::positionalPattern($entry['segments']);
            $alternatives[] = '(?:' . $pattern . ')(*MARK:' . $mark . ')';
            $fastAlternatives[] = '(?:' . $fastPattern . ')(*MARK:' . $mark . ')';
            $routeMap[$mark] = [
                'route' => $entry['route'],
                'id' => $entry['id'],
                'params' => $params,
                'fast_params' => $fastParams,
            ];
        }

        $regex = '~(?J)\\A(?:' . implode('|', $alternatives) . ')\\z~D';
        $fastRegex = '~\\A(?|' . implode('|', $fastAlternatives) . ')\\z~D';
        if (preg_match($regex, '') === false || preg_match($fastRegex, '') === false) {
            throw new \UnexpectedValueException('Failed to compile combined matcher PCRE chunk.');
        }

        return [
            'type' => 'pcre',
            'regex' => $regex,
            'fast_regex' => $fastRegex,
            'routes' => $routeMap,
        ];
    }

    /**
     * @param array<string,array<string,RouteValue>> $static
     * @return array{0:array<string,array<string,RouteValue>>,1:array<string,array<string,int>>,2:array<string,list<string>>}
     */
    private function compileStatic(array $static): array
    {
        $compiled = [];
        $ids = [];
        $allowed = [];
        foreach ($static as $path => $verbs) {
            $allowed[$path] = self::allowedMethods($verbs);
            foreach ($verbs as $method => $route) {
                $compiled[$method][$path] = $route;
                $ids[$method][$path] = self::routeIndex($route);
            }
        }

        return [$compiled, $ids, $allowed];
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
                'segments' => self::compileFallbackSegments($entry['segments']),
                'route' => $entry['route'],
                'id' => $entry['id'],
            ];
        }

        if ($pcreBuffer !== []) {
            array_push($steps, ...$this->compileBalancedPcreSteps($pcreBuffer));
        }

        return $steps;
    }
}
