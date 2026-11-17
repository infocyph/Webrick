<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

/**
 * PHPBench discovers this benchmark through reflection.
 * @psalm-api
 */
#[Bench\Groups(['matcher'])]
#[Bench\Iterations(5)]
#[Bench\Revs(2000)]
#[Bench\Warmup(1)]
final class MatcherBench
{
    /**
     * @param array{matcher:string,route_set:string,method:string,host:string,path:string} $params
     * @throws \Infocyph\Webrick\Exceptions\RouteNotFoundException
     * @throws \Infocyph\Webrick\Exceptions\MethodNotAllowedException
     */
    #[Bench\ParamProviders('provideMatchCases')]
    public function benchMatch(array $params): void
    {
        BenchmarkSupport::matcher($params['route_set'], $params['matcher'])
            ->match($params['method'], $params['host'], $params['path']);
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
