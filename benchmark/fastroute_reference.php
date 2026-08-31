<?php

declare(strict_types=1);

use FastRoute\DataGenerator\MarkBased as FastRouteMarkDataGenerator;
use FastRoute\Dispatcher\MarkBased as FastRouteMarkDispatcher;
use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\RouteCollector as FastRouteCollector;
use FastRoute\RouteParser\Std as FastRouteParser;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * FastRoute MarkBased versus Webrick matcher hot-path reference.
 *
 * This intentionally benchmarks only semantics shared by both routers: exact
 * static paths, unconstrained dynamic variables, 404 and 405. Host routing,
 * callable constraints and other Webrick-only features belong in separate
 * Webrick-native scenarios.
 *
 * Examples:
 *   php benchmark/fastroute_reference.php
 *   php benchmark/fastroute_reference.php --routes=1000 --iters=500000 --rounds=7 --warmup=20000
 */
$opts = getopt('', ['routes::', 'iters::', 'rounds::', 'warmup::', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php benchmark/fastroute_reference.php [--routes=1000] [--iters=250000] [--rounds=7] [--warmup=20000]\n");

    return;
}

$routeCount = max(10, (int) ($opts['routes'] ?? 1000));
$iterations = max(1, (int) ($opts['iters'] ?? 250000));
$rounds = max(1, (int) ($opts['rounds'] ?? 7));
$warmup = max(0, (int) ($opts['warmup'] ?? 20000));

$staticCount = intdiv($routeCount, 2);
$dynamicCount = $routeCount - $staticCount;

/** @var list<array{method:string,path:string,handler:int}> $definitions */
$definitions = [];
for ($i = 0; $i < $staticCount; $i++) {
    $definitions[] = [
        'method' => 'GET',
        'path' => '/bench/static/route-' . $i,
        'handler' => count($definitions),
    ];
}
for ($i = 0; $i < $dynamicCount; $i++) {
    $definitions[] = [
        'method' => 'GET',
        'path' => '/bench/dynamic/item-' . $i . '/{id}',
        'handler' => count($definitions),
    ];
}

$lastStatic = max(0, $staticCount - 1);
$lastDynamic = max(0, $dynamicCount - 1);
$middleDynamic = intdiv($lastDynamic, 2);

$scenarios = [
    'static-last' => ['GET', '/bench/static/route-' . $lastStatic, true],
    'dynamic-middle' => ['GET', '/bench/dynamic/item-' . $middleDynamic . '/42', true],
    'dynamic-last' => ['GET', '/bench/dynamic/item-' . $lastDynamic . '/42', true],
    'not-found' => ['GET', '/bench/dynamic/missing/42', false],
    'method-not-allowed' => ['POST', '/bench/dynamic/item-' . $lastDynamic . '/42', false],
];

$fastCollector = new FastRouteCollector(new FastRouteParser(), new FastRouteMarkDataGenerator());
foreach ($definitions as $definition) {
    $fastCollector->addRoute($definition['method'], $definition['path'], $definition['handler']);
}
$fastRoute = new FastRouteMarkDispatcher($fastCollector->getData());

$webrickFactories = [
    'Webrick Fused' => static fn(): MatcherInterface => FusedMatcher::make(),
    'Webrick Generated' => static fn(): MatcherInterface => GeneratedMatcher::make(),
    'Webrick Sharded' => static fn(): MatcherInterface => ShardedMatcher::make(),
];

/** @var array<string,MatcherInterface> $webrick */
$webrick = [];
foreach ($webrickFactories as $name => $factory) {
    $matcher = $factory();
    foreach ($definitions as $definition) {
        $matcher->add(CompiledRoute::fromRoute(new Route(
            $definition['method'],
            $definition['path'],
            'benchmark-handler-' . $definition['handler'],
        )));
    }
    $matcher->finalize();
    $webrick[$name] = $matcher;
}

/**
 * @return array{median_ns:float,best_ns:float,median_ops:float,best_ops:float,checksum:int}
 */
function runReferenceBench(Closure $operation, int $iterations, int $rounds, int $warmup): array
{
    $checksum = 0;
    for ($i = 0; $i < $warmup; $i++) {
        $checksum ^= $operation();
    }

    $samples = [];
    for ($round = 0; $round < $rounds; $round++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $checksum ^= $operation();
        }
        $elapsed = hrtime(true) - $start;
        $samples[] = $elapsed / $iterations;
    }

    sort($samples, SORT_NUMERIC);
    $middle = intdiv(count($samples), 2);
    $median = count($samples) % 2 === 1
        ? $samples[$middle]
        : ($samples[$middle - 1] + $samples[$middle]) / 2;
    $best = $samples[0];

    return [
        'median_ns' => $median,
        'best_ns' => $best,
        'median_ops' => 1_000_000_000 / $median,
        'best_ops' => 1_000_000_000 / $best,
        'checksum' => $checksum,
    ];
}

/** @return int */
function fastRouteChecksum(FastRouteMarkDispatcher $dispatcher, string $method, string $path): int
{
    $result = $dispatcher->dispatch($method, $path);
    $status = $result[0] ?? -1;
    if ($status === FastRouteDispatcher::FOUND) {
        return (int) ($result[1] ?? 0) + 17;
    }

    return (int) $status;
}

/** @return int */
function webrickChecksum(MatcherInterface $matcher, string $method, string $path): int
{
    $result = $matcher->matchCompiled($method, '*', $path);
    if (is_int($result)) {
        return $result + 17;
    }
    if (is_array($result)) {
        return $result[0] + 17;
    }
    if ($result instanceof MatchOutcome) {
        return $result->type->value + 31;
    }

    throw new LogicException('Unexpected matcher benchmark result.');
}

fwrite(STDOUT, "FastRoute MarkBased reference versus Webrick\n");
fwrite(STDOUT, "Routes: {$routeCount} ({$staticCount} static / {$dynamicCount} dynamic)\n");
fwrite(STDOUT, "Iterations/round: {$iterations}, rounds: {$rounds}, warmup: {$warmup}\n");
fwrite(STDOUT, 'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n\n");
fwrite(STDOUT, sprintf("%-22s %-20s %14s %14s\n", 'Scenario', 'Matcher', 'median ns/op', 'median ops/s'));
fwrite(STDOUT, str_repeat('-', 74) . "\n");

$finalChecksum = 0;
foreach ($scenarios as $scenario => [$method, $path]) {
    $fastResult = runReferenceBench(
        static fn(): int => fastRouteChecksum($fastRoute, $method, $path),
        $iterations,
        $rounds,
        $warmup,
    );
    $finalChecksum ^= $fastResult['checksum'];
    fwrite(STDOUT, sprintf(
        "%-22s %-20s %14.1f %14.0f\n",
        $scenario,
        'FastRoute MarkBased',
        $fastResult['median_ns'],
        $fastResult['median_ops'],
    ));

    foreach ($webrick as $name => $matcher) {
        $result = runReferenceBench(
            static fn(): int => webrickChecksum($matcher, $method, $path),
            $iterations,
            $rounds,
            $warmup,
        );
        $finalChecksum ^= $result['checksum'];
        fwrite(STDOUT, sprintf(
            "%-22s %-20s %14.1f %14.0f\n",
            '',
            $name,
            $result['median_ns'],
            $result['median_ops'],
        ));
    }
    fwrite(STDOUT, "\n");
}

fwrite(STDOUT, "Checksum: {$finalChecksum}\n");
fwrite(STDOUT, "Interpretation: compare repeated medians; this is a matcher hot-path reference, not an end-to-end HTTP benchmark.\n");
