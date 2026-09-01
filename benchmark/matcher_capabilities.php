<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['routes::', 'iters::', 'rounds::', 'warmup::']);
$routeCount = max(100, (int) ($opts['routes'] ?? 1000));
$iterations = max(1000, (int) ($opts['iters'] ?? 20000));
$rounds = max(3, (int) ($opts['rounds'] ?? 5));
$warmup = max(100, (int) ($opts['warmup'] ?? 2000));

/** @return array{median:float,best:float} */
function capabilityBench(Closure $operation, int $iterations, int $rounds, int $warmup): array
{
    for ($i = 0; $i < $warmup; $i++) {
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

    return [
        'median' => $samples[intdiv(count($samples), 2)],
        'best' => $samples[0],
    ];
}

function capabilityRoute(string $method, string $path, string $handler, ?string $domain = null): CompiledRoute
{
    return CompiledRoute::fromRoute(new Route($method, $path, $handler, $domain));
}

/**
 * @param non-empty-string $method
 * @param non-empty-string $host
 * @param non-empty-string $path
 */
function capabilityChecksum(MatcherInterface $matcher, string $method, string $host, string $path): int
{
    $hit = $matcher->matchCompiled($method, $host, $path);
    if (is_int($hit)) {
        return $hit;
    }
    if (is_array($hit)) {
        return $hit[0] ^ count($hit[1]);
    }

    return $hit->type->value === 'not_found' ? 0 : count($hit->allowed);
}

$routes = [];
$sharedCount = intdiv($routeCount, 2);
$distinctCount = max(1, $routeCount - $sharedCount - 10);
for ($i = 0; $i < $sharedCount; $i++) {
    $routes[] = capabilityRoute('GET', '/shared/{group}/item-' . $i . '/{id}', 'shared-' . $i);
}
for ($i = 0; $i < $distinctCount; $i++) {
    $routes[] = capabilityRoute('GET', '/zone-' . $i . '/{id}', 'distinct-' . $i);
}

$head = capabilityRoute('GET', '/cap/head/{id}', 'cap-head');
$optionsGet = capabilityRoute('GET', '/cap/options/{id}', 'cap-options-get');
$custom = capabilityRoute('SYNCX', '/cap/custom/{id}', 'cap-custom');
$callable = capabilityRoute('GET', '/cap/callable/{id:int}', 'cap-callable');
$multi = capabilityRoute('GET', '/cap/multi/{a}/{b}/{c}', 'cap-multi');
$hostExact = capabilityRoute('GET', '/cap/host/{id}', 'cap-host-exact', 'api.example.test');
$hostWildcard = capabilityRoute('GET', '/cap/host/{id}', 'cap-host-wildcard');
array_push($routes, $head, $optionsGet, $custom, $callable, $multi, $hostExact, $hostWildcard);

$sharedMiddle = intdiv($sharedCount, 2);
$sharedLast = max(0, $sharedCount - 1);
$distinctLast = max(0, $distinctCount - 1);

/** @var array<string,Closure():MatcherInterface> $factories */
$factories = [
    'Fused' => FusedMatcher::make(...),
    'Generated' => GeneratedMatcher::make(...),
    'Sharded' => ShardedMatcher::make(...),
];

fwrite(STDOUT, "Matcher capability benchmark\n");
fwrite(STDOUT, "Routes: {$routeCount}; iterations: {$iterations}; rounds: {$rounds}; warmup: {$warmup}\n");
fwrite(STDOUT, 'PHP: ' . PHP_VERSION . "\n\n");
fwrite(STDOUT, sprintf("%-22s %-12s %14s %14s\n", 'scenario', 'matcher', 'median ns/op', 'best ns/op'));
fwrite(STDOUT, str_repeat('-', 68) . "\n");

$checksum = 0;
foreach ($factories as $matcherName => $factory) {
    $matcher = $factory();
    foreach ($routes as $route) {
        $matcher->add($route);
    }
    $matcher->finalize();

    $scenarios = [
        'shared-middle' => static fn(): int => capabilityChecksum($matcher, 'GET', '*', '/shared/main/item-' . $sharedMiddle . '/42'),
        'shared-last' => static fn(): int => capabilityChecksum($matcher, 'GET', '*', '/shared/main/item-' . $sharedLast . '/42'),
        'distinct-prefix' => static fn(): int => capabilityChecksum($matcher, 'GET', '*', '/zone-' . $distinctLast . '/42'),
        'multi-param' => static fn(): int => capabilityChecksum($matcher, 'GET', '*', '/cap/multi/a/b/c'),
        'callable-fallback' => static fn(): int => capabilityChecksum($matcher, 'GET', '*', '/cap/callable/42'),
        'head-fallback' => static fn(): int => capabilityChecksum($matcher, 'HEAD', '*', '/cap/head/42'),
        'auto-options' => static fn(): int => capabilityChecksum($matcher, 'OPTIONS', '*', '/cap/options/42'),
        'custom-method' => static fn(): int => capabilityChecksum($matcher, 'SYNCX', '*', '/cap/custom/42'),
        'exact-host' => static fn(): int => capabilityChecksum($matcher, 'GET', 'api.example.test', '/cap/host/42'),
        'wildcard-host' => static fn(): int => capabilityChecksum($matcher, 'GET', 'www.example.test', '/cap/host/42'),
        'not-found' => static fn(): int => capabilityChecksum($matcher, 'GET', '*', '/cap/missing/42'),
    ];

    foreach ($scenarios as $scenario => $operation) {
        $result = capabilityBench($operation, $iterations, $rounds, $warmup);
        $checksum ^= $operation();
        fwrite(STDOUT, sprintf(
            "%-22s %-12s %14.1f %14.1f\n",
            $scenario,
            $matcherName,
            $result['median'],
            $result['best'],
        ));
    }
}

fwrite(STDOUT, "\nChecksum: {$checksum}\n");
