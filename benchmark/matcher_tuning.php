<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\CanonicalMatcherIndex;
use Infocyph\Webrick\Router\Matching\CompiledMatcherEngine;
use Infocyph\Webrick\Router\Matching\CompiledMatcherIndexCompiler;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['routes::', 'iters::', 'rounds::']);
$routeCount = max(64, (int) ($opts['routes'] ?? 1000));
$iterations = max(100, (int) ($opts['iters'] ?? 5000));
$rounds = max(3, (int) ($opts['rounds'] ?? 5));

/** @return array{median:float,best:float} */
function tuneBench(Closure $operation, int $iterations, int $rounds): array
{
    for ($i = 0; $i < min(1000, $iterations); $i++) {
        $operation();
    }

    $samples = [];
    for ($round = 0; $round < $rounds; $round++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $operation();
        }
        $samples[] = (hrtime(true) - $start) / $iterations;
    }
    sort($samples, SORT_NUMERIC);
    $middle = intdiv(count($samples), 2);
    $median = count($samples) % 2 === 1
        ? $samples[$middle]
        : ($samples[$middle - 1] + $samples[$middle]) / 2;

    return ['median' => $median, 'best' => $samples[0]];
}

$index = new CanonicalMatcherIndex();
for ($i = 0; $i < $routeCount; $i++) {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/bench/{group}/item-' . $i . '/{id}',
        'handler-' . $i,
    ));
    $index->add('*', $route);
}

$middle = intdiv($routeCount, 2);
$last = $routeCount - 1;
$scenarios = [
    'dynamic-middle' => '/bench/main/item-' . $middle . '/42',
    'dynamic-last' => '/bench/main/item-' . $last . '/42',
    'not-found' => '/bench/main/missing/42',
];

fwrite(STDOUT, "Matcher PCRE chunk-size tuning\n");
fwrite(STDOUT, "Routes: {$routeCount}; iterations: {$iterations}; rounds: {$rounds}\n");
fwrite(STDOUT, 'PHP: ' . PHP_VERSION . "\n\n");
fwrite(STDOUT, sprintf("%8s %-18s %14s %14s\n", 'chunk', 'scenario', 'median ns/op', 'best ns/op'));
fwrite(STDOUT, str_repeat('-', 60) . "\n");

foreach ([8, 16, 24, 32, 48, 64] as $chunkSize) {
    $hosts = (new CompiledMatcherIndexCompiler($chunkSize))->compile($index->hosts());
    $group = $hosts['*'];
    $engine = new CompiledMatcherEngine();

    foreach ($scenarios as $scenario => $path) {
        $result = tuneBench(
            static function () use ($engine, $group, $path): int {
                $hit = $engine->matchSingleCompiled($group, null, 'GET', $path);
                if (is_int($hit)) {
                    return $hit;
                }
                if (is_array($hit)) {
                    return $hit[0];
                }
                if ($hit instanceof MatchOutcome) {
                    return 0;
                }
                throw new LogicException('Unexpected matcher result.');
            },
            $iterations,
            $rounds,
        );

        fwrite(STDOUT, sprintf(
            "%8d %-18s %14.1f %14.1f\n",
            $chunkSize,
            $scenario,
            $result['median'],
            $result['best'],
        ));
    }
}

fwrite(STDOUT, "\nStatic map orientation microbenchmark\n");
$methodFirst = ['GET' => []];
$pathFirst = [];
for ($i = 0; $i < $routeCount; $i++) {
    $path = '/static/route-' . $i;
    $methodFirst['GET'][$path] = $i;
    $pathFirst[$path]['GET'] = $i;
}
$staticLast = '/static/route-' . ($routeCount - 1);
$missing = '/static/missing';

foreach ([
    'method-first-hit' => static fn(): mixed => $methodFirst['GET'][$staticLast] ?? null,
    'path-first-hit' => static fn(): mixed => $pathFirst[$staticLast]['GET'] ?? null,
    'method-first-miss' => static fn(): mixed => $methodFirst['GET'][$missing] ?? null,
    'path-first-miss' => static fn(): mixed => $pathFirst[$missing]['GET'] ?? null,
] as $label => $operation) {
    $result = tuneBench($operation, max(10000, $iterations * 4), $rounds);
    fwrite(STDOUT, sprintf("%-22s %14.1f ns/op\n", $label, $result['median']));
}
