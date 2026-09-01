<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['matcher:', 'routes::', 'warm-iters::']);
$matcherName = strtolower((string) ($opts['matcher'] ?? ''));
$routeCount = max(100, (int) ($opts['routes'] ?? 5000));
$warmIterations = max(1000, (int) ($opts['warm-iters'] ?? 25000));

if (!in_array($matcherName, ['fused', 'generated', 'sharded'], true)) {
    throw new InvalidArgumentException('--matcher must be fused, generated, or sharded.');
}

function envelopeRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $entryPath = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entryPath);
        } elseif ($entry->isDir()) {
            envelopeRemoveTree($entryPath);
        }
    }
    rmdir($path);
}

function envelopeDirectorySize(string $path): int
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

function envelopeMatcher(string $name): MatcherInterface
{
    return match ($name) {
        'fused' => FusedMatcher::make(),
        'generated' => GeneratedMatcher::make(),
        'sharded' => ShardedMatcher::make(),
        default => throw new LogicException('Unsupported matcher.'),
    };
}

function envelopePopulate(MatcherInterface $matcher, int $routeCount): string
{
    $staticCount = intdiv($routeCount, 2);
    $dynamicCount = $routeCount - $staticCount;

    for ($index = 0; $index < $staticCount; ++$index) {
        $matcher->add(CompiledRoute::fromRoute(new Route(
            'GET',
            '/static/route-' . $index,
            'static-' . $index,
        )));
    }

    for ($index = 0; $index < $dynamicCount; ++$index) {
        $prefix = 'area-' . ($index % 100);
        $matcher->add(CompiledRoute::fromRoute(new Route(
            'GET',
            '/' . $prefix . '/item-' . $index . '/{id}',
            'dynamic-' . $index,
        )));
    }

    $lastDynamic = $dynamicCount - 1;

    return '/area-' . ($lastDynamic % 100) . '/item-' . $lastDynamic . '/42';
}

/** @return array{ns:float,checksum:int} */
function envelopeWarm(MatcherInterface $matcher, string $path, int $iterations): array
{
    $checksum = 0;
    for ($index = 0; $index < min(2000, $iterations); ++$index) {
        $hit = $matcher->matchCompiled('GET', '*', $path);
        $checksum ^= is_int($hit) ? $hit : (is_array($hit) ? $hit[0] : 0);
    }

    $start = hrtime(true);
    for ($index = 0; $index < $iterations; ++$index) {
        $hit = $matcher->matchCompiled('GET', '*', $path);
        $checksum ^= is_int($hit) ? $hit : (is_array($hit) ? $hit[0] : 0);
    }

    return [
        'ns' => (hrtime(true) - $start) / $iterations,
        'checksum' => $checksum,
    ];
}

$root = sys_get_temp_dir() . '/webrick-matcher-envelope-' . bin2hex(random_bytes(6));
$cache = $matcherName === 'sharded' ? $root . '/sharded' : $root . '/' . $matcherName . '.php';
mkdir($root, 0775, true);

try {
    $builder = envelopeMatcher($matcherName);
    $builder->enableCache($cache)->enableCacheWrite();

    $buildStart = hrtime(true);
    $targetPath = envelopePopulate($builder, $routeCount);
    $builder->finalize();
    $buildMs = (hrtime(true) - $buildStart) / 1_000_000;
    $artifactBytes = envelopeDirectorySize($cache);
    $buildPeakBytes = memory_get_peak_usage(false);

    unset($builder);
    gc_collect_cycles();

    $beforeBoot = memory_get_usage(false);
    $reader = envelopeMatcher($matcherName);
    $reader->enableCache($cache);
    $bootStart = hrtime(true);
    $reader->finalize();
    $coldBootUs = (hrtime(true) - $bootStart) / 1000;
    $afterBoot = memory_get_usage(false);

    $firstStart = hrtime(true);
    $first = $reader->matchCompiled('GET', '*', $targetPath);
    $firstHitUs = (hrtime(true) - $firstStart) / 1000;
    if ($first instanceof MatchOutcome) {
        throw new RuntimeException($matcherName . ' failed the profile target route.');
    }
    $afterFirst = memory_get_usage(false);
    $warm = envelopeWarm($reader, $targetPath, $warmIterations);

    echo json_encode([
        'matcher' => $matcherName,
        'routes' => $routeCount,
        'build_ms' => round($buildMs, 3),
        'artifact_bytes' => $artifactBytes,
        'cold_boot_us' => round($coldBootUs, 3),
        'first_hit_us' => round($firstHitUs, 3),
        'warm_ns' => round($warm['ns'], 3),
        'boot_memory_bytes' => $afterBoot - $beforeBoot,
        'first_hit_memory_bytes' => $afterFirst - $afterBoot,
        'build_peak_bytes' => $buildPeakBytes,
        'checksum' => $warm['checksum'],
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    envelopeRemoveTree($root);
}
