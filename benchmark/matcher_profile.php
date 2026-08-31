<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['routes::', 'warm-iters::']);
$routeCount = max(100, (int) ($opts['routes'] ?? 5000));
$warmIterations = max(1000, (int) ($opts['warm-iters'] ?? 25000));

function profileRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $target = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            @unlink($target);
        } elseif ($entry->isDir()) {
            profileRemoveTree($target);
        }
    }
    @rmdir($path);
}

function profileDirectorySize(string $path): int
{
    if (is_file($path)) {
        return filesize($path) ?: 0;
    }
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

/** @return list<CompiledRoute> */
function profileRoutes(int $routeCount): array
{
    $routes = [];
    $staticCount = intdiv($routeCount, 2);
    $dynamicCount = $routeCount - $staticCount;

    for ($i = 0; $i < $staticCount; $i++) {
        $routes[] = CompiledRoute::fromRoute(new Route('GET', '/static/route-' . $i, 'static-' . $i));
    }
    for ($i = 0; $i < $dynamicCount; $i++) {
        // Spread routes over many first prefixes so Sharded can demonstrate its
        // intended working-set benefit rather than sharing one giant shard.
        $prefix = 'area-' . ($i % 100);
        $routes[] = CompiledRoute::fromRoute(new Route(
            'GET',
            '/' . $prefix . '/item-' . $i . '/{id}',
            'dynamic-' . $i,
        ));
    }

    return $routes;
}

/** @return array{ns:float,result:int} */
function profileWarm(MatcherInterface $matcher, string $path, int $iterations): array
{
    $checksum = 0;
    for ($i = 0; $i < min(2000, $iterations); $i++) {
        $hit = $matcher->matchCompiled('GET', '*', $path);
        $checksum ^= is_int($hit) ? $hit : (is_array($hit) ? $hit[0] : 0);
    }
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $hit = $matcher->matchCompiled('GET', '*', $path);
        $checksum ^= is_int($hit) ? $hit : (is_array($hit) ? $hit[0] : 0);
    }

    return ['ns' => (hrtime(true) - $start) / $iterations, 'result' => $checksum];
}

$routes = profileRoutes($routeCount);
$dynamicCount = $routeCount - intdiv($routeCount, 2);
$lastDynamic = $dynamicCount - 1;
$targetPrefix = 'area-' . ($lastDynamic % 100);
$targetPath = '/' . $targetPrefix . '/item-' . $lastDynamic . '/42';
$root = sys_get_temp_dir() . '/webrick-matcher-profile-' . bin2hex(random_bytes(6));
$fusedFile = $root . '/fused.php';
$shardedDir = $root . '/sharded';
mkdir($root, 0775, true);

fwrite(STDOUT, "Matcher cache/working-set profile\n");
fwrite(STDOUT, "Routes: {$routeCount}; warm iterations: {$warmIterations}\n");
fwrite(STDOUT, 'PHP: ' . PHP_VERSION . "\n\n");

try {
    foreach ([
        'Fused' => ['builder' => FusedMatcher::make(), 'cache' => $fusedFile],
        'Sharded' => ['builder' => ShardedMatcher::make(), 'cache' => $shardedDir],
    ] as $name => $spec) {
        /** @var MatcherInterface&object $builder */
        $builder = $spec['builder'];
        $cache = $spec['cache'];
        $builder->enableCache($cache)->enableCacheWrite();

        $buildStart = hrtime(true);
        foreach ($routes as $route) {
            $builder->add($route);
        }
        $builder->finalize();
        $buildMs = (hrtime(true) - $buildStart) / 1_000_000;
        unset($builder);
        gc_collect_cycles();

        $artifactBytes = profileDirectorySize($cache);
        $beforeBoot = memory_get_usage(true);
        /** @var MatcherInterface&object $reader */
        $reader = $name === 'Fused' ? FusedMatcher::make() : ShardedMatcher::make();
        $reader->enableCache($cache);
        $bootStart = hrtime(true);
        $reader->finalize();
        $bootUs = (hrtime(true) - $bootStart) / 1000;
        $afterBoot = memory_get_usage(true);

        $firstStart = hrtime(true);
        $first = $reader->matchCompiled('GET', '*', $targetPath);
        $firstUs = (hrtime(true) - $firstStart) / 1000;
        if ($first instanceof MatchOutcome) {
            throw new RuntimeException($name . ' failed the profile target route.');
        }
        $afterFirst = memory_get_usage(true);
        $warm = profileWarm($reader, $targetPath, $warmIterations);

        fwrite(STDOUT, $name . "\n");
        fwrite(STDOUT, sprintf("  build:          %10.2f ms\n", $buildMs));
        fwrite(STDOUT, sprintf("  artifact size:  %10d bytes\n", $artifactBytes));
        fwrite(STDOUT, sprintf("  cold boot:      %10.2f us\n", $bootUs));
        fwrite(STDOUT, sprintf("  first hit:      %10.2f us\n", $firstUs));
        fwrite(STDOUT, sprintf("  warm hit:       %10.1f ns/op\n", $warm['ns']));
        fwrite(STDOUT, sprintf("  boot memory:    %+10d bytes\n", $afterBoot - $beforeBoot));
        fwrite(STDOUT, sprintf("  first-hit mem:  %+10d bytes\n", $afterFirst - $afterBoot));
        fwrite(STDOUT, "\n");

        unset($reader);
        gc_collect_cycles();
    }
} finally {
    profileRemoveTree($root);
}
