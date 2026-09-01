<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['matcher', 'matcher-outcome'])]
#[Bench\Iterations(5)]
#[Bench\Revs(2000)]
#[Bench\Warmup(1)]
final class MatcherOutcomeBench
{
    #[Bench\ParamProviders('provideOutcomeCases')]
    public function benchCompiledOutcome(array $params): void
    {
        BenchmarkSupport::matcher((string) $params['route_set'], (string) $params['matcher'])
            ->matchCompiled((string) $params['method'], (string) $params['host'], (string) $params['path']);
    }

    /**
     * @return iterable<string, array{matcher:string,route_set:string,method:string,host:string,path:string}>
     */
    public function provideOutcomeCases(): iterable
    {
        $cases = [
            'static-hit' => [
                'route_set' => 'route-cache',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/ping',
            ],
            'dynamic-hit' => [
                'route_set' => 'route-cache',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/hello/benchmark',
            ],
            'static-404' => [
                'route_set' => 'route-cache',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/missing',
            ],
            'dynamic-head-fallback' => [
                'route_set' => 'route-cache',
                'method' => 'HEAD',
                'host' => 'localhost',
                'path' => '/hello/benchmark',
            ],
            'dynamic-404' => [
                'route_set' => 'route-cache',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/hello/benchmark/extra',
            ],
            'dynamic-405' => [
                'route_set' => 'route-cache',
                'method' => 'POST',
                'host' => 'localhost',
                'path' => '/hello/benchmark',
            ],
            'dynamic-options' => [
                'route_set' => 'route-cache',
                'method' => 'OPTIONS',
                'host' => 'localhost',
                'path' => '/hello/benchmark',
            ],
            'domain-dynamic-hit' => [
                'route_set' => 'index',
                'method' => 'GET',
                'host' => 'api.localhost',
                'path' => '/v1/users/7',
            ],
        ];

        foreach (['fused', 'generated', 'sharded'] as $matcher) {
            foreach ($cases as $label => $case) {
                yield $matcher . '-' . $label => ['matcher' => $matcher] + $case;
            }
        }
    }
}
