<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Small matcher hot-path benchmark using project route sets.
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

/**
 * @var array<int, array{
 *   key:string,
 *   label:string,
 *   title:string,
 *   register:callable(Registrar):void,
 *   routes:list<CompiledRoute>
 * }>
 */
$routeSets = [
    [
        'key' => 'index-routes',
        'label' => 'index',
        'title' => 'Index routes.php (+ attribute routes)',
        'register' => static function (Registrar $r): void {
            registerIndexRoutes($r);
        },
        'routes' => buildCompiledRoutes(static function (Registrar $r): void {
            registerIndexRoutes($r);
        }),
    ],
    [
        'key' => 'route-cache-example',
        'label' => 'route-cache',
        'title' => 'RouteCache example closure routes',
        'register' => static function (Registrar $r): void {
            registerRouteCacheExampleRoutes($r);
        },
        'routes' => buildCompiledRoutes(static function (Registrar $r): void {
            registerRouteCacheExampleRoutes($r);
        }),
    ],
];

/** @var array<string,bool> $modes label => useCache */
$modes = match (true) {
    $cacheOnly => ['cache-hot' => true],
    $memoryOnly => ['in-memory' => false],
    default => ['in-memory' => false, 'cache-hot' => true],
};

/**
 * @var array<int, array{
 *   key:string,
 *   route_set:string,
 *   case:string,
 *   title:string,
 *   method:string,
 *   host:string,
 *   path:string,
 *   route_count:int,
 *   register:callable(Registrar):void,
 *   routes:list<CompiledRoute>,
 *   assertHit:callable(array):bool
 * }>
 */
$scenarios = [];
foreach ($routeSets as $routeSet) {
    $routes = $routeSet['routes'];
    $routeCount = \count($routes);

    $scenarios[] = [
        'key' => $routeSet['key'] . '-static',
        'route_set' => $routeSet['label'],
        'case' => 'static',
        'title' => $routeSet['title'] . ' - Static /ping',
        'method' => 'GET',
        'host' => 'localhost',
        'path' => '/ping',
        'route_count' => $routeCount,
        'register' => $routeSet['register'],
        'routes' => $routes,
        'assertHit' => static function (array $hit): bool {
            return isset($hit[0]) && $hit[0]->getPath() === '/ping' && (($hit[1] ?? []) === []);
        },
    ];

    $scenarios[] = [
        'key' => $routeSet['key'] . '-dynamic',
        'route_set' => $routeSet['label'],
        'case' => 'dynamic',
        'title' => $routeSet['title'] . ' - Dynamic /hello/{name}',
        'method' => 'GET',
        'host' => 'localhost',
        'path' => '/hello/benchmark',
        'route_count' => $routeCount,
        'register' => $routeSet['register'],
        'routes' => $routes,
        'assertHit' => static function (array $hit): bool {
            return isset($hit[0], $hit[1]['name'])
                && $hit[0]->getPath() === '/hello/{name}'
                && $hit[1]['name'] === 'benchmark';
        },
    ];
}

$indexRouteSet = $routeSets[0];
$scenarios[] = [
    'key' => $indexRouteSet['key'] . '-domain-dynamic',
    'route_set' => $indexRouteSet['label'],
    'case' => 'domain-dynamic',
    'title' => $indexRouteSet['title'] . ' - Domain dynamic /v1/users/{id:int}',
    'method' => 'GET',
    'host' => 'api.localhost',
    'path' => '/v1/users/7',
    'route_count' => \count($indexRouteSet['routes']),
    'register' => $indexRouteSet['register'],
    'routes' => $indexRouteSet['routes'],
    'assertHit' => static function (array $hit): bool {
        return isset($hit[0], $hit[1]['id'])
            && $hit[0]->getPath() === '/v1/users/{id:int}'
            && $hit[1]['id'] === '7';
    },
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
echo "  ops/s  = iterations / elapsed_seconds for one timed round (higher is better).\n";
echo "  ns/op  = elapsed_nanoseconds / iterations for one timed round (lower is better).\n";
echo "  best   = fastest round.\n";
echo "  avg    = mean across all rounds.\n";
echo "  cache-hot mode prebuilds cache artifacts via RouteCache::build() before timing.\n";
echo "Route sets:\n";
foreach ($routeSets as $set) {
    echo "  - {$set['title']}: " . \count($set['routes']) . " compiled routes\n";
}

/** @var list<list<string>> $summaryRows */
$summaryRows = [];

foreach ($modes as $modeLabel => $useCache) {
    $cacheRoot = null;
    if ($useCache) {
        $cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-bench-' . getmypid() . '-' . uniqid('', true);
        if (!is_dir($cacheRoot)) {
            @mkdir($cacheRoot, 0775, true);
        }
    }
    foreach ($scenarios as $scenario) {
        $results = [];
        $results[] = benchMatcher(
            'Fused',
            static function () use ($scenario, $useCache, $cacheRoot): MatcherInterface {
                $cachePath = $cacheRoot . DIRECTORY_SEPARATOR . $scenario['key'] . '.fused.php';
                if ($useCache) {
                    buildBenchmarkCache('fused', $cachePath, $scenario['register']);
                }

                $m = FusedMatcher::make();
                if ($useCache) {
                    $m->enableCache($cachePath);
                } else {
                    foreach ($scenario['routes'] as $route) {
                        $m->add($route);
                    }
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
            'Generated',
            static function () use ($scenario, $useCache, $cacheRoot): MatcherInterface {
                $cachePath = $cacheRoot . DIRECTORY_SEPARATOR . $scenario['key'] . '.generated.php';
                if ($useCache) {
                    buildBenchmarkCache('generated', $cachePath, $scenario['register']);
                }

                $m = GeneratedMatcher::make();
                if ($useCache) {
                    $m->enableCache($cachePath);
                } else {
                    foreach ($scenario['routes'] as $route) {
                        $m->add($route);
                    }
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
                $cachePath = $cacheRoot . DIRECTORY_SEPARATOR . $scenario['key'] . '-sharded';
                if ($useCache) {
                    buildBenchmarkCache('sharded', $cachePath, $scenario['register']);
                }

                $m = ShardedMatcher::make();
                if ($useCache) {
                    $m->enableCache($cachePath);
                } else {
                    foreach ($scenario['routes'] as $route) {
                        $m->add($route);
                    }
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

        /** @var array<string, array{
         *   name:string,
         *   best_ops_s:float,
         *   avg_ops_s:float,
         *   best_ns_op:float,
         *   avg_ns_op:float
         * }> $byName
         */
        $byName = [];
        foreach ($results as $row) {
            $byName[strtolower($row['name'])] = $row;
        }

        usort($results, static fn (array $a, array $b): int => $b['best_ops_s'] <=> $a['best_ops_s']);
        $winner = $results[0];

        $summaryRows[] = [
            $modeLabel,
            $scenario['route_set'],
            $scenario['case'],
            "{$scenario['method']} {$scenario['host']} {$scenario['path']}",
            formatMetricCell($byName['fused'] ?? null),
            formatMetricCell($byName['generated'] ?? null),
            formatMetricCell($byName['sharded'] ?? null),
            $winner['name'],
        ];
    }

    if ($useCache && $cacheRoot !== null) {
        rrmdir($cacheRoot);
    }
}

echo "\n";
printTable(
    ['Mode', 'Route Set', 'Case', 'Request', 'Fused', 'Generated', 'Sharded', 'Winner'],
    $summaryRows,
);

/**
 * @return array{
 *   name:string,
 *   best_ops_s:float,
 *   avg_ops_s:float,
 *   best_ns_op:float,
 *   avg_ns_op:float
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
        'avg_ns_op' => $avgNs / $iterations,
    ];
}

/**
 * @param array{
 *   name:string,
 *   best_ops_s:float,
 *   avg_ops_s:float,
 *   best_ns_op:float,
 *   avg_ns_op:float
 * }|null $row
 */
function formatMetricCell(?array $row): string
{
    if ($row === null) {
        return '-';
    }

    return number_format($row['best_ops_s'], 2) . ' ops/s (' . number_format($row['best_ns_op'], 2) . ' ns/op)';
}

/**
 * Build matcher cache artifacts for benchmark cache-hot mode.
 *
 * @param 'fused'|'sharded'|'generated' $matcher
 * @param callable(Registrar):void $register
 */
function buildBenchmarkCache(string $matcher, string $cachePath, callable $register): void
{
    RouteCache::build([
        'matcher' => $matcher,
        'cache' => $cachePath,
        'register' => $register,
        'signKey' => 'bench-sign-key',
        'signedDefaultTtl' => 900,
        'fallbackAliasesFromRegistrar' => true,
        'logger' => new NullLogger(),
        'registrarOptions' => [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => false,
        ],
    ]);
}

/**
 * Compile routes from a registration callback.
 *
 * @param callable(Registrar):void $register
 * @return list<CompiledRoute>
 */
function buildCompiledRoutes(callable $register): array
{
    $routes = new Collection();
    $registrar = new Registrar(
        routes: $routes,
        autoSlashRedirect: false,
        exposeUrlServices: false,
        signKey: 'bench-sign-key',
        signedDefaultTtl: 900,
    );

    $register($registrar);

    /** @var list<CompiledRoute> $all */
    $all = $routes->compile()->all();
    if ($all === []) {
        throw new RuntimeException('Failed to build benchmark route set.');
    }
    return $all;
}

/**
 * Registration callback for project `routes.php` + attribute fixture routes.
 */
function registerIndexRoutes(Registrar $registrar): void
{
    MiddlewareAliases::reset();
    MiddlewareAliases::register(
        'throttle',
        static fn (...$_params): string => \Infocyph\Webrick\Middleware\ThrottleMiddleware::class,
    );
    MiddlewareAliases::register(
        'verifySignedUrl',
        static fn (...$_params): string => \Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware::class,
    );

    Router::setInstance($registrar);
    $signUrlSecret = 'bench-sign-key';
    require dirname(__DIR__) . '/routes.php';

    $fixtureDir = dirname(__DIR__) . '/tests/Fixture';
    if (\is_dir($fixtureDir)) {
        AttributeRouteLoader::registerFromDirs(
            $registrar,
            ['Infocyph\\Webrick\\Tests\\Fixture\\' => $fixtureDir],
        );
    }
}

/**
 * Registration callback that mirrors route-cache closure demo routes.
 */
function registerRouteCacheExampleRoutes(Registrar $registrar): void
{
    $registrar->get('/ping', static fn (): string => 'pong', 'ping');
    $registrar->get('/hello/{name}', static fn ($req, $name): string => (string)$name, 'hello');
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

/**
 * Render an ASCII table with auto-sized columns.
 *
 * @param list<string> $headers
 * @param list<list<string>> $rows
 */
function printTable(array $headers, array $rows): void
{
    $widths = array_map(static fn (string $h): int => strlen($h), $headers);

    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $len = strlen($cell);
            if ($len > $widths[$i]) {
                $widths[$i] = $len;
            }
        }
    }

    $sep = '+';
    foreach ($widths as $w) {
        $sep .= str_repeat('-', $w + 2) . '+';
    }

    echo $sep . "\n";
    echo '|';
    foreach ($headers as $i => $h) {
        echo ' ' . str_pad($h, $widths[$i], ' ', STR_PAD_RIGHT) . ' |';
    }
    echo "\n" . $sep . "\n";

    foreach ($rows as $row) {
        echo '|';
        foreach ($row as $i => $cell) {
            echo ' ' . str_pad($cell, $widths[$i], ' ', STR_PAD_RIGHT) . ' |';
        }
        echo "\n";
    }
    echo $sep . "\n";
}
