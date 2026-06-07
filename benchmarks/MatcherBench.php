<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['matcher'])]
#[Bench\Iterations(5)]
#[Bench\Revs(2000)]
#[Bench\Warmup(1)]
final class MatcherBench
{
    #[Bench\ParamProviders('provideMatchCases')]
    public function benchMatch(array $params): void
    {
        BenchmarkSupport::matcher((string) $params['route_set'], (string) $params['matcher'])
            ->match((string) $params['method'], (string) $params['host'], (string) $params['path']);
    }

    /**
     * @return iterable<string, array{matcher:string,route_set:string,method:string,host:string,path:string}>
     */
    public function provideMatchCases(): iterable
    {
        $matchers = ['fused', 'generated', 'sharded'];
        $cases = [
            'index-static' => [
                'route_set' => 'index',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/ping',
            ],
            'index-dynamic' => [
                'route_set' => 'index',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/hello/benchmark',
            ],
            'index-domain-dynamic' => [
                'route_set' => 'index',
                'method' => 'GET',
                'host' => 'api.localhost',
                'path' => '/v1/users/7',
            ],
            'route-cache-static' => [
                'route_set' => 'route-cache',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/ping',
            ],
            'route-cache-dynamic' => [
                'route_set' => 'route-cache',
                'method' => 'GET',
                'host' => 'localhost',
                'path' => '/hello/benchmark',
            ],
        ];

        foreach ($matchers as $matcher) {
            foreach ($cases as $label => $case) {
                yield $matcher . '-' . $label => ['matcher' => $matcher] + $case;
            }
        }
    }
}
