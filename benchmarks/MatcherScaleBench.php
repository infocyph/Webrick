<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['matcher-scale'])]
#[Bench\Iterations(3)]
#[Bench\Revs(50)]
#[Bench\Warmup(1)]
final class MatcherScaleBench
{
    #[Bench\ParamProviders('provideRouteScales')]
    public function benchRepresentativeHit(array $params): void
    {
        $size = (int) $params['size'];
        BenchmarkSupport::matcher('scale-' . $size, (string) $params['matcher'])
            ->match('GET', 'localhost', BenchmarkSupport::syntheticHitPath($size));
    }

    /** @return iterable<string, array{matcher:string,size:int}> */
    public function provideRouteScales(): iterable
    {
        foreach (['fused', 'generated', 'sharded'] as $matcher) {
            foreach ([10, 50, 100, 250, 500, 1_000, 5_000, 10_000] as $size) {
                yield "{$matcher}-{$size}" => ['matcher' => $matcher, 'size' => $size];
            }
        }
    }
}
