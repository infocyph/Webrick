<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['matcher-memory'])]
#[Bench\Iterations(2)]
#[Bench\Revs(1)]
final class MatcherMemoryBench
{
    #[Bench\ParamProviders('provideRouteScales')]
    public function benchMatcherBuild(array $params): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        BenchmarkSupport::freshSyntheticMatcher((int) $params['size'], (string) $params['matcher']);
    }

    /** @return iterable<string, array{matcher:string,size:int}> */
    public function provideRouteScales(): iterable
    {
        foreach (['fused', 'generated', 'sharded'] as $matcher) {
            foreach ([10, 100, 1_000, 10_000] as $size) {
                yield "{$matcher}-{$size}" => ['matcher' => $matcher, 'size' => $size];
            }
        }
    }
}
