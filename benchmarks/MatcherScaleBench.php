<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

/**
 * PHPBench discovers this benchmark through reflection.
 * @psalm-api
 */
#[Bench\Groups(['matcher-scale'])]
#[Bench\Iterations(3)]
#[Bench\Revs(50)]
#[Bench\Warmup(1)]
final class MatcherScaleBench
{
    /**
     * @param array{matcher:string,size:int} $params
     * @throws \Infocyph\Webrick\Exceptions\RouteNotFoundException
     * @throws \Infocyph\Webrick\Exceptions\MethodNotAllowedException
     */
    #[Bench\ParamProviders('provideRouteScales')]
    public function benchRepresentativeHit(array $params): void
    {
        $size = $params['size'];
        BenchmarkSupport::matcher('scale-' . $size, $params['matcher'])
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
