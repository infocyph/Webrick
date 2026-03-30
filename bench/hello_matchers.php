<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Small routing hot-path benchmark for hello-world style routes.
 *
 * Usage examples:
 *   php bench/hello_matchers.php
 *   php bench/hello_matchers.php --iters=1000000 --rounds=8 --warmup=50000
 *   php bench/hello_matchers.php --cache   (cache-only)
 *   php bench/hello_matchers.php --memory  (memory-only)
 */

$opts = getopt('', ['iters::', 'rounds::', 'warmup::', 'cache', 'memory', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php bench/hello_matchers.php [--iters=500000] [--rounds=5] [--warmup=20000] [--cache] [--memory]\n";
    exit(0);
}

$iterations = max(1, (int)($opts['iters'] ?? 500000));
$rounds = max(1, (int)($opts['rounds'] ?? 5));
$warmup = max(0, (int)($opts['warmup'] ?? 20000));
$cacheOnly = isset($opts['cache']);
$memoryOnly = isset($opts['memory']);

if ($cacheOnly && $memoryOnly) {
    fwrite(STDERR, "Choose either --cache or --memory, not both.\n");
    exit(2);
}

/** @var array<string,bool> $modes label => useCache */
$modes = match (true) {
    $cacheOnly => ['cache-hot' => true],
    $memoryOnly => ['in-memory' => false],
    default => ['in-memory' => false, 'cache-hot' => true],
};

/**
 * @var array<int, array{
 *   key:string,
 *   title:string,
 *   method:string,
 *   host:string,
 *   path:string,
 *   routes:list<CompiledRoute>,
 *   assertHit:callable(array):bool
 * }>
 */
$scenarios = [
    [
        'key' => 'static',
        'title' => 'Static Route',
        'method' => 'GET',
        'host' => 'example.com',
        'path' => '/hello',
        'routes' => [
            CompiledRoute::fromRoute(
                (new Route('GET', '/hello', static fn (): string => 'hello'))
                    ->withDomain('example.com'),
            ),
        ],
        'assertHit' => static function (array $hit): bool {
            return isset($hit[0]) && $hit[0]->getPath() === '/hello' && (($hit[1] ?? []) === []);
        },
    ],
    [
        'key' => 'dynamic',
        'title' => 'Dynamic Route',
        'method' => 'GET',
        'host' => 'example.com',
        'path' => '/hello/benchmark',
        'routes' => [
            CompiledRoute::fromRoute(
                (new Route('GET', '/hello/{name}', static fn (): string => 'hello'))
                    ->withDomain('example.com'),
            ),
        ],
        'assertHit' => static function (array $hit): bool {
            return isset($hit[0], $hit[1]['name'])
                && $hit[0]->getPath() === '/hello/{name}'
                && $hit[1]['name'] === 'benchmark';
        },
    ],
];

echo "Webrick Matcher Benchmark\n";
echo "Modes: " . implode(', ', array_keys($modes)) . "\n";
echo "Iterations/round: {$iterations}, rounds: {$rounds}, warmup: {$warmup}\n";
echo "PHP: " . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
if (extension_loaded('xdebug')) {
    echo "Warning: xdebug loaded; scores will be slower.\n";
}
echo "\n";
echo "Metric notes:\n";
echo "  ops/s  = successful match operations per second (higher is better).\n";
echo "  ns/op  = nanoseconds spent per single match operation (lower is better).\n";
echo "  best   = fastest round.\n";
echo "  avg    = mean across all rounds.\n";

foreach ($modes as $modeLabel => $useCache) {
    $cacheRoot = null;
    if ($useCache) {
        $cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-bench-' . getmypid() . '-' . uniqid('', true);
        if (!is_dir($cacheRoot)) {
            @mkdir($cacheRoot, 0775, true);
        }
    }

    echo "\n=== Mode: {$modeLabel} ===\n";

    foreach ($scenarios as $scenario) {
        $results = [];
        $results[] = benchMatcher(
            'Fused',
            static function () use ($scenario, $useCache, $cacheRoot): MatcherInterface {
                $m = FusedMatcher::make();
                if ($useCache) {
                    $m->enableCache($cacheRoot . DIRECTORY_SEPARATOR . $scenario['key'] . '.fused.php');
                }
                foreach ($scenario['routes'] as $route) {
                    $m->add($route);
                }
                $m->finalize();
                return $m;
            },
            $iterations,
            $rounds,
            $warmup,
            method: $scenario['method'],
            host: $scenario['host'],
            path: $scenario['path'],
            assertHit: $scenario['assertHit'],
        );

        $results[] = benchMatcher(
            'Sharded',
            static function () use ($scenario, $useCache, $cacheRoot): MatcherInterface {
                $m = ShardedMatcher::make();
                if ($useCache) {
                    $m->enableCache($cacheRoot . DIRECTORY_SEPARATOR . $scenario['key'] . '-sharded');
                }
                foreach ($scenario['routes'] as $route) {
                    $m->add($route);
                }
                $m->finalize();
                return $m;
            },
            $iterations,
            $rounds,
            $warmup,
            method: $scenario['method'],
            host: $scenario['host'],
            path: $scenario['path'],
            assertHit: $scenario['assertHit'],
        );

        echo "\nScenario: {$scenario['title']} ({$scenario['method']} {$scenario['host']} {$scenario['path']})\n";
        echo str_pad('Matcher', 12)
            . str_pad('Best ops/s', 18)
            . str_pad('Avg ops/s', 18)
            . str_pad('Best ns/op', 16)
            . "Rounds(ms)\n";

        foreach ($results as $row) {
            $roundsMs = implode(', ', array_map(
                static fn (float $ns): string => number_format($ns / 1_000_000, 2),
                $row['rounds_ns'],
            ));
            echo str_pad($row['name'], 12)
                . str_pad(number_format($row['best_ops_s'], 2), 18)
                . str_pad(number_format($row['avg_ops_s'], 2), 18)
                . str_pad(number_format($row['best_ns_op'], 2), 16)
                . $roundsMs . "\n";
        }

        usort($results, static fn (array $a, array $b): int => $b['best_ops_s'] <=> $a['best_ops_s']);
        $winner = $results[0];
        echo "Winner: {$winner['name']} (" . number_format($winner['best_ops_s'], 2) . " ops/s)\n";
    }

    if ($useCache && $cacheRoot !== null) {
        rrmdir($cacheRoot);
    }
}

/**
 * @return array{
 *   name:string,
 *   best_ops_s:float,
 *   avg_ops_s:float,
 *   best_ns_op:float,
 *   rounds_ns:list<float>
 * }
 */
function benchMatcher(
    string $name,
    callable $factory,
    int $iterations,
    int $rounds,
    int $warmup,
    string $method,
    string $host,
    string $path,
    callable $assertHit,
): array {
    /** @var MatcherInterface $matcher */
    $matcher = $factory();

    // Warm up internals/JIT/opcache-backed file loads before timed rounds.
    for ($i = 0; $i < $warmup; $i++) {
        $matcher->match($method, $host, $path);
    }

    $roundNs = [];
    for ($r = 0; $r < $rounds; $r++) {
        $start = hrtime(true);
        $last = null;
        for ($i = 0; $i < $iterations; $i++) {
            $last = $matcher->match($method, $host, $path);
        }
        $elapsed = (float)(hrtime(true) - $start);
        $roundNs[] = $elapsed;

        if (!is_array($last) || !$assertHit($last)) {
            throw new RuntimeException("Unexpected benchmark match result for {$name}.");
        }
    }

    $bestNs = min($roundNs);
    $avgNs = array_sum($roundNs) / count($roundNs);
    $bestOpsS = ($iterations * 1_000_000_000.0) / $bestNs;
    $avgOpsS = ($iterations * 1_000_000_000.0) / $avgNs;

    return [
        'name' => $name,
        'best_ops_s' => $bestOpsS,
        'avg_ops_s' => $avgOpsS,
        'best_ns_op' => $bestNs / $iterations,
        'rounds_ns' => $roundNs,
    ];
}

/**
 * Best-effort recursive directory cleanup for temporary benchmark cache.
 */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }
        @unlink($path);
    }

    @rmdir($dir);
}
