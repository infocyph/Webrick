<?php

declare(strict_types=1);
use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/benchmarks/Support/BenchmarkSupport.php';

function out(string $text): void
{
    file_put_contents('php://stdout', $text, FILE_APPEND);
}

/**
 * Small matcher hot-path benchmark using project route sets.
 *
 * Usage examples:
 *   php benchmark/hello_matchers.php
 *   php benchmark/hello_matchers.php --iters=1000000 --rounds=8 --warmup=50000
 *   php benchmark/hello_matchers.php --cache   (cache-only)
 *   php benchmark/hello_matchers.php --memory  (memory-only)
 */
$opts = getopt('', ['iters::', 'rounds::', 'warmup::', 'cache', 'memory', 'help']);

if (isset($opts['help'])) {
    out("Usage: php benchmark/hello_matchers.php [--iters=500000] [--rounds=5] [--warmup=20000] [--cache] [--memory]\n");

    return;
}

$iterations = max(1, (int) ($opts['iters'] ?? 500000));
$rounds = max(1, (int) ($opts['rounds'] ?? 5));
$warmup = max(0, (int) ($opts['warmup'] ?? 20000));
$cacheOnly = isset($opts['cache']);
$memoryOnly = isset($opts['memory']);

if ($cacheOnly && $memoryOnly) {
    fwrite(STDERR, "Choose either --cache or --memory, not both.\n");

    return;
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
        'routes' => BenchmarkSupport::compiledRoutesFor('index'),
    ],
    [
        'key' => 'route-cache-example',
        'label' => 'route-cache',
        'title' => 'RouteCache example closure routes',
        'register' => static function (Registrar $r): void {
            registerRouteCacheExampleRoutes($r);
        },
        'routes' => BenchmarkSupport::compiledRoutesFor('route-cache'),
    ],
];

/** @var array<string,bool> $modes label => useCache */
$modes = match (true) {
    $cacheOnly => ['cache-hot' => true],
    $memoryOnly => ['in-memory' => false],
    default => ['in-memory' => false, 'cache-hot' => true],
};

/**
 * @phpstan-type Scenario array{
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
 *   assertHit:callable(array{0:CompiledRoute,1:array<string,string>}):bool
 * }
 *
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
 *   assertHit:callable(array{0:CompiledRoute,1:array<string,string>}):bool
 * }>
 */
$scenarios = [];
$scenarioTemplates = [
    [
        'suffix' => 'static',
        'case' => 'static',
        'title' => 'Static /ping',
        'method' => HttpMethodEnum::GET->value,
        'host' => 'localhost',
        'path' => '/ping',
        'assertHit' => static fn(array $hit): bool => isset($hit[0])
            && $hit[0] instanceof CompiledRoute
            && $hit[0]->getPath() === '/ping'
            && (($hit[1] ?? []) === []),
    ],
    [
        'suffix' => 'dynamic',
        'case' => 'dynamic',
        'title' => 'Dynamic /hello/{name}',
        'method' => HttpMethodEnum::GET->value,
        'host' => 'localhost',
        'path' => '/hello/benchmark',
        'assertHit' => static fn(array $hit): bool => isset($hit[0])
            && $hit[0] instanceof CompiledRoute
            && \is_array($hit[1] ?? null)
            && isset($hit[1]['name'])
            && $hit[0]->getPath() === '/hello/{name}'
            && $hit[1]['name'] === 'benchmark',
    ],
];

foreach ($routeSets as $routeSet) {
    $routes = $routeSet['routes'];
    $routeCount = \count($routes);

    foreach ($scenarioTemplates as $template) {
        $scenarios[] = makeScenario($routeSet, $template, $routes, $routeCount);
    }
}

$indexRouteSet = $routeSets[0];
$scenarios[] = [
    'key' => $indexRouteSet['key'] . '-domain-dynamic',
    'route_set' => $indexRouteSet['label'],
    'case' => 'domain-dynamic',
    'title' => $indexRouteSet['title'] . ' - Domain dynamic /v1/users/{id:int}',
    'method' => HttpMethodEnum::GET->value,
    'host' => 'api.localhost',
    'path' => '/v1/users/7',
    'route_count' => \count($indexRouteSet['routes']),
    'register' => $indexRouteSet['register'],
    'routes' => $indexRouteSet['routes'],
    'assertHit' => static fn(array $hit): bool => isset($hit[0])
        && $hit[0] instanceof CompiledRoute
        && \is_array($hit[1] ?? null)
        && isset($hit[1]['id'])
        && $hit[0]->getPath() === '/v1/users/{id:int}'
        && $hit[1]['id'] === '7',
];

out("Webrick Matcher Benchmark\n");
out('Modes: ' . implode(', ', array_keys($modes)) . "\n");
out("Iterations/round: {$iterations}, rounds: {$rounds}, warmup: {$warmup}\n");
out('PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n");
if (extension_loaded('xdebug')) {
    out("Warning: xdebug loaded; scores will be slower.\n");
}
out("\n");
out("Metric notes:\n");
out("  ops/s  = iterations / elapsed_seconds for one timed round (higher is better).\n");
out("  ns/op  = elapsed_nanoseconds / iterations for one timed round (lower is better).\n");
out("  best   = fastest round.\n");
out("  avg    = mean across all rounds.\n");
out("  cache-hot mode prebuilds cache artifacts via RouteCache::build() before timing.\n");
out("Route sets:\n");
foreach ($routeSets as $set) {
    out("  - {$set['title']}: " . \count($set['routes']) . " compiled routes\n");
}

/** @var list<list<string>> $summaryRows */
$summaryRows = [];
$totalScenarioRuns = \count($modes) * \count($scenarios);
$totalMatcherTasks = $totalScenarioRuns * 3;
$scenarioRunNo = 0;
$completedMatcherTasks = 0;

out("\n");
progressPercent(0, $totalMatcherTasks);

$matcherSpecs = [
    [
        'name' => 'Fused',
        'mode' => MatcherModeEnum::FUSED->value,
        'cache_suffix' => '.fused.php',
        'factory' => FusedMatcher::make(...),
    ],
    [
        'name' => 'Generated',
        'mode' => MatcherModeEnum::GENERATED->value,
        'cache_suffix' => '.generated.php',
        'factory' => GeneratedMatcher::make(...),
    ],
    [
        'name' => 'Sharded',
        'mode' => MatcherModeEnum::SHARDED->value,
        'cache_suffix' => '-sharded',
        'factory' => ShardedMatcher::make(...),
    ],
];

foreach ($modes as $modeLabel => $useCache) {
    $cacheRoot = null;
    $cacheNamespace = null;
    if ($useCache) {
        $cacheRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.route-cache';
        $cacheNamespace = 'bench-' . getmypid() . '-' . uniqid('', true);
        if (!is_dir($cacheRoot) && !mkdir($cacheRoot, 0775, true) && !is_dir($cacheRoot)) {
            throw new RuntimeException("Failed to create benchmark cache directory: {$cacheRoot}");
        }
    }
    foreach ($scenarios as $scenario) {
        $scenarioRunNo++;

        $results = [];
        foreach ($matcherSpecs as $spec) {
            $results[] = runScenarioMatcher(
                $spec,
                $scenario,
                $useCache,
                $cacheRoot,
                $cacheNamespace,
                $iterations,
                $rounds,
                $warmup,
                $completedMatcherTasks,
                $totalMatcherTasks,
            );
        }

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

        usort($results, static fn(array $a, array $b): int => $b['best_ops_s'] <=> $a['best_ops_s']);
        $winner = $results[0];

        $summaryRows[] = [
            $modeLabel,
            $scenario['route_set'],
            $scenario['case'],
            "{$scenario['method']} {$scenario['host']} {$scenario['path']}",
            formatMetricCell($byName[MatcherModeEnum::FUSED->value] ?? null),
            formatMetricCell($byName[MatcherModeEnum::GENERATED->value] ?? null),
            formatMetricCell($byName[MatcherModeEnum::SHARDED->value] ?? null),
            $winner['name'],
        ];
    }

}

progressPercent($totalMatcherTasks, $totalMatcherTasks, true);
out("\n");
printTable(
    ['Mode', 'Route Set', 'Case', 'Request', 'Fused', 'Generated', 'Sharded', 'Winner'],
    $summaryRows,
);

$signedBenchIterations = max(10000, (int) floor($iterations / 5));
$signedBenchWarmup = max(1000, (int) floor($warmup / 5));
$signedBenchConfig = new SignedUrlConfig(
    generationKey: 'bench-sign-key',
    verificationKeys: ['bench-sign-key'],
    defaultTtl: 900,
);
$signedBenchAbsoluteConfig = new SignedUrlConfig(
    generationKey: 'bench-sign-key',
    verificationKeys: ['bench-sign-key'],
    defaultTtl: 900,
    payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
    ignoredQueryParams: ['preview'],
    leeway: 5,
);
$signedBenchGenerator = BenchmarkSupport::createSignedGenerator($signedBenchConfig);
$signedBenchAbsoluteGenerator = BenchmarkSupport::createSignedGenerator($signedBenchAbsoluteConfig);
$relativeSignedUrl = $signedBenchGenerator->temporary(
    'secure.show',
    ['id' => 42],
    ['dl' => 1],
    300,
    false,
);
$absoluteSignedUrl = $signedBenchAbsoluteGenerator->temporaryUntil(
    'secure.absolute',
    new \DateTimeImmutable('+10 minutes'),
    ['id' => 42],
    ['dl' => 1],
    true,
    SignedUrlConfig::MODE_ABSOLUTE,
);

$signedBenchRows = [
    benchCallable(
        'relative sign',
        static fn(): string => $signedBenchGenerator->temporary(
            'secure.show',
            ['id' => 42],
            ['dl' => 1],
            300,
            false,
        ),
        $signedBenchIterations,
        $rounds,
        $signedBenchWarmup,
    ),
    benchCallable(
        'absolute sign',
        static fn(): string => $signedBenchAbsoluteGenerator->signed(
            'secure.absolute',
            ['id' => 42],
            ['dl' => 1],
            null,
            true,
            SignedUrlConfig::MODE_ABSOLUTE,
        ),
        $signedBenchIterations,
        $rounds,
        $signedBenchWarmup,
    ),
    benchCallable(
        'absolute until',
        static fn(): string => $signedBenchAbsoluteGenerator->temporaryUntil(
            'secure.absolute',
            new \DateTimeImmutable('+10 minutes'),
            ['id' => 42],
            ['dl' => 1],
            true,
            SignedUrlConfig::MODE_ABSOLUTE,
        ),
        $signedBenchIterations,
        $rounds,
        $signedBenchWarmup,
    ),
    benchCallable(
        'relative verify',
        static fn(): Response => BenchmarkSupport::verifySignedUrlResponse(
            $relativeSignedUrl,
            new VerifySignedUrlMiddleware('bench-sign-key', 5),
        ),
        $signedBenchIterations,
        $rounds,
        $signedBenchWarmup,
    ),
    benchCallable(
        'absolute verify',
        static fn(): Response => BenchmarkSupport::verifySignedUrlResponse(
            $absoluteSignedUrl,
            new VerifySignedUrlMiddleware($signedBenchAbsoluteConfig),
            ['preview' => '1'],
        ),
        $signedBenchIterations,
        $rounds,
        $signedBenchWarmup,
    ),
];

out("\nSigned URL Benchmark\n");
out("Iterations/round: {$signedBenchIterations}, rounds: {$rounds}, warmup: {$signedBenchWarmup}\n");
printTable(
    ['Case', 'Best ops/s', 'Best ns/op', 'Avg ops/s', 'Avg ns/op'],
    array_map(
        static fn(array $row): array => [
            $row['name'],
            number_format($row['best_ops_s'], 2),
            number_format($row['best_ns_op'], 2),
            number_format($row['avg_ops_s'], 2),
            number_format($row['avg_ns_op'], 2),
        ],
        $signedBenchRows,
    ),
);

function progressPercent(int $completed, int $total, bool $forceNewline = false): void
{
    static $lastPercent = -1;
    if (!\is_int($lastPercent)) {
        $lastPercent = -1;
    }
    $total = max(1, $total);
    $percent = (int) floor(($completed / $total) * 100);
    $percent = max(0, min(100, $percent));

    if ($percent === $lastPercent) {
        if ($forceNewline) {
            out(PHP_EOL);
        }

        return;
    }

    out("\r[progress] " . $percent . '%');
    $lastPercent = $percent;

    if ($forceNewline) {
        out(PHP_EOL);
    }

    if (\function_exists('ob_get_level') && \ob_get_level() > 0) {
        \ob_flush();
    }
    flush();
}

/**
 * @param array{
 *   key:string,
 *   label:string,
 *   title:string,
 *   register:callable(Registrar):void
 * } $routeSet
 * @param array{
 *   suffix:string,
 *   case:string,
 *   title:string,
 *   method:string,
 *   host:string,
 *   path:string,
 *   assertHit:callable(array{0:CompiledRoute,1:array<string,string>}):bool
 * } $template
 * @param list<CompiledRoute> $routes
 * @return array{
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
 *   assertHit:callable(array{0:CompiledRoute,1:array<string,string>}):bool
 * }
 * @param array $routeSet
 * @param array $template
 * @param int $routeCount
 */
function makeScenario(array $routeSet, array $template, array $routes, int $routeCount): array
{
    return [
        'key' => $routeSet['key'] . '-' . $template['suffix'],
        'route_set' => $routeSet['label'],
        'case' => $template['case'],
        'title' => $routeSet['title'] . ' - ' . $template['title'],
        'method' => $template['method'],
        'host' => $template['host'],
        'path' => $template['path'],
        'route_count' => $routeCount,
        'register' => $routeSet['register'],
        'routes' => $routes,
        'assertHit' => $template['assertHit'],
    ];
}

/**
 * @param array{
 *   name:string,
 *   mode:string,
 *   cache_suffix:string,
 *   factory:callable():MatcherInterface
 * } $spec
 * @param array{
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
 *   assertHit:callable(array{0:CompiledRoute,1:array<string,string>}):bool
 * } $scenario
 * @return array{
 *   name:string,
 *   best_ops_s:float,
 *   avg_ops_s:float,
 *   best_ns_op:float,
 *   avg_ns_op:float
 * }
 * @param array $spec
 * @param array $scenario
 * @param bool $useCache
 * @param ?string $cacheRoot
 * @param ?string $cacheNamespace
 * @param int $iterations
 * @param int $rounds
 * @param int $warmup
 * @param int $completedMatcherTasks
 * @param int $totalMatcherTasks
 */
function runScenarioMatcher(
    array $spec,
    array $scenario,
    bool $useCache,
    ?string $cacheRoot,
    ?string $cacheNamespace,
    int $iterations,
    int $rounds,
    int $warmup,
    int &$completedMatcherTasks,
    int $totalMatcherTasks,
): array {
    $cachePath = '';
    if ($useCache) {
        $cachePath = $cacheRoot
            . DIRECTORY_SEPARATOR
            . $cacheNamespace
            . '-'
            . $scenario['key']
            . $spec['cache_suffix'];
    }

    try {
        $result = benchMatcher(
            $spec['name'],
            static fn(): MatcherInterface => buildScenarioMatcher($scenario, $useCache, $cachePath, $spec),
            $iterations,
            $rounds,
            $warmup,
            method: $scenario['method'],
            host: $scenario['host'],
            path: $scenario['path'],
            assertHit: $scenario['assertHit'],
        );
    } finally {
        if ($useCache) {
            clearBenchmarkCache($spec['mode'], $cachePath);
        }
    }

    $completedMatcherTasks++;
    progressPercent($completedMatcherTasks, $totalMatcherTasks);

    return $result;
}

/**
 * @return array{
 *   name:string,
 *   best_ops_s:float,
 *   avg_ops_s:float,
 *   best_ns_op:float,
 *   avg_ns_op:float
 * }
 * @param string $name
 * @param callable $factory
 * @param int $iterations
 * @param int $rounds
 * @param int $warmup
 * @param string $method
 * @param string $host
 * @param string $path
 * @param callable $assertHit
 * @param ?callable $progress
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
    ?callable $progress = null,
): array {
    $rounds = max(1, $rounds);
    if ($method === '' || $host === '' || $path === '') {
        throw new RuntimeException('Benchmark matcher inputs must be non-empty.');
    }

    if ($progress !== null) {
        $progress('building matcher');
    }
    /** @var MatcherInterface $matcher */
    $matcher = $factory();

    // Warm up internals/JIT/opcache-backed file loads before timed rounds.
    if ($progress !== null && $warmup > 0) {
        $progress("warmup ({$warmup} iterations)");
    }
    for ($i = 0; $i < $warmup; $i++) {
        $matcher->match($method, $host, $path);
    }

    $roundNs = [];
    for ($r = 0; $r < $rounds; $r++) {
        if ($progress !== null) {
            $progress('timed round ' . ($r + 1) . '/' . $rounds);
        }

        $start = hrtime(true);
        $last = null;
        for ($i = 0; $i < $iterations; $i++) {
            $last = $matcher->match($method, $host, $path);
        }
        $elapsed = (float) (hrtime(true) - $start);
        $roundNs[] = $elapsed;

        if (!is_array($last) || !$assertHit($last)) {
            throw new RuntimeException("Unexpected benchmark match result for {$name}.");
        }
    }

    $bestNs = min($roundNs);
    $avgNs = array_sum($roundNs) / count($roundNs);
    $bestOpsS = ($iterations * 1_000_000_000.0) / $bestNs;
    $avgOpsS = ($iterations * 1_000_000_000.0) / $avgNs;

    if ($progress !== null) {
        $progress('finished timing');
    }

    return [
        'name' => $name,
        'best_ops_s' => $bestOpsS,
        'avg_ops_s' => $avgOpsS,
        'best_ns_op' => $bestNs / $iterations,
        'avg_ns_op' => $avgNs / $iterations,
    ];
}

/**
 * @return array{
 *   name:string,
 *   best_ops_s:float,
 *   avg_ops_s:float,
 *   best_ns_op:float,
 *   avg_ns_op:float
 * }
 * @param string $name
 * @param callable $callable
 * @param int $iterations
 * @param int $rounds
 * @param int $warmup
 */
function benchCallable(
    string $name,
    callable $callable,
    int $iterations,
    int $rounds,
    int $warmup,
): array {
    $rounds = max(1, $rounds);

    for ($i = 0; $i < $warmup; $i++) {
        $callable();
    }

    $roundNs = [];
    for ($round = 0; $round < $rounds; $round++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $callable();
        }
        $roundNs[] = (float) (hrtime(true) - $start);
    }

    $bestNs = min($roundNs);
    $avgNs = array_sum($roundNs) / count($roundNs);

    return [
        'name' => $name,
        'best_ops_s' => ($iterations * 1_000_000_000.0) / $bestNs,
        'avg_ops_s' => ($iterations * 1_000_000_000.0) / $avgNs,
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
 * @param ?array $row
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
 * @param string $matcher One of MatcherModeEnum::values().
 * @param callable(Registrar):void $register
 * @param string $cachePath
 */
function buildBenchmarkCache(string $matcher, string $cachePath, callable $register): void
{
    RouteCache::build([
        'matcher' => $matcher,
        'cache' => $cachePath,
        'register' => $register,
        'signKey' => 'bench-sign-key',
        'signedDefaultTtl' => 900,
        'signedUrlConfig' => new SignedUrlConfig(
            generationKey: 'bench-sign-key',
            verificationKeys: ['bench-sign-key'],
            defaultTtl: 900,
        ),
        'urlBaseUri' => 'http://localhost',
        'fallbackAliasesFromRegistrar' => true,
        'logger' => new NullLogger(),
        'registrarOptions' => [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => false,
        ],
    ]);
}

function clearBenchmarkCache(string $matcher, string $cachePath): void
{
    RouteCache::clear([
        'matcher' => $matcher,
        'cache' => $cachePath,
        'aggressive' => true,
    ]);

    if ($matcher === MatcherModeEnum::SHARDED->value && is_dir($cachePath)) {
        $entries = scandir($cachePath);
        if (is_array($entries) && $entries === ['.', '..']) {
            rmdir($cachePath);
        }
    }
}

/**
 * @param array{
 *   register:callable(Registrar):void,
 *   routes:list<CompiledRoute>
 * } $scenario
 * @param array{
 *   mode:string,
 *   factory:callable():MatcherInterface,
 *   name:string
 * } $spec
 * @param array $scenario
 * @param bool $useCache
 * @param string $cachePath
 * @param array $spec
 */
function buildScenarioMatcher(array $scenario, bool $useCache, string $cachePath, array $spec): MatcherInterface
{
    if ($useCache) {
        buildBenchmarkCache($spec['mode'], $cachePath, $scenario['register']);
    }

    $matcher = ($spec['factory'])();
    if ($useCache) {
        $matcher->enableCache($cachePath);
    } else {
        foreach ($scenario['routes'] as $route) {
            $matcher->add($route);
        }
    }

    $matcher->finalize();

    return $matcher;
}

/**
 * Registration callback for project `routes.php` + attribute fixture routes.
 * @param Registrar $registrar
 */
function registerIndexRoutes(Registrar $registrar): void
{
    MiddlewareAliases::reset();
    MiddlewareAliases::register(
        'throttle',
        static fn(): string => ThrottleMiddleware::class,
    );
    MiddlewareAliases::register(
        'verifySignedUrl',
        static fn(): VerifySignedUrlMiddleware => new VerifySignedUrlMiddleware('bench-sign-key', 5),
    );
    MiddlewareAliases::register(
        'verifySignedUrlAbsolute',
        static fn(): VerifySignedUrlMiddleware => new VerifySignedUrlMiddleware(new SignedUrlConfig(
            verificationKeys: ['bench-sign-key'],
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
            ignoredQueryParams: ['preview'],
            leeway: 5,
        )),
    );

    Router::setInstance($registrar);
    require dirname(__DIR__) . '/routes.php';

    $fixtureDirs = [
        dirname(__DIR__) . '/tests/Fixture',
        dirname(__DIR__) . '/tests/Fixtures',
    ];

    foreach ($fixtureDirs as $fixtureDir) {
        if (!\is_dir($fixtureDir)) {
            continue;
        }

        AttributeRouteLoader::registerFromDirs(
            $registrar,
            ['Infocyph\\Webrick\\Tests\\Fixture\\' => $fixtureDir],
        );
    }
}

/**
 * Registration callback that mirrors route-cache closure demo routes.
 * @param Registrar $registrar
 */
function registerRouteCacheExampleRoutes(Registrar $registrar): void
{
    $registrar->get('/ping', static fn(): string => 'pong', 'ping');
    $registrar->get('/hello/{name}', static fn(string $name): string => $name, 'hello');
}

/**
 * Render an ASCII table with auto-sized columns.
 *
 * @param list<string> $headers
 * @param list<list<string>> $rows
 */
function printTable(array $headers, array $rows): void
{
    $widths = array_map(strlen(...), $headers);

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

    out($sep . "\n");
    out('|');
    foreach ($headers as $i => $h) {
        out(' ' . str_pad($h, $widths[$i], ' ', STR_PAD_RIGHT) . ' |');
    }
    out("\n" . $sep . "\n");

    foreach ($rows as $row) {
        out('|');
        foreach ($row as $i => $cell) {
            out(' ' . str_pad($cell, $widths[$i], ' ', STR_PAD_RIGHT) . ' |');
        }
        out("\n");
    }
    out($sep . "\n");
}
