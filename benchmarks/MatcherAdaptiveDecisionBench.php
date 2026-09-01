<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['matcher', 'matcher-adaptive-decision'])]
#[Bench\Iterations(7)]
#[Bench\Revs(3000)]
#[Bench\Warmup(2)]
final class MatcherAdaptiveDecisionBench
{
    /** @var array<string,MatcherInterface> */
    private static array $matchers = [];

    #[Bench\ParamProviders('provideCases')]
    public function benchAdaptiveDecision(array $params): void
    {
        self::matcher((string) $params['matcher'])
            ->matchCompiled('GET', '*', (string) $params['path']);
    }

    /** @return iterable<string,array{matcher:string,path:string}> */
    public function provideCases(): iterable
    {
        foreach ([MatcherModeEnum::FUSED->value, MatcherModeEnum::SHARDED->value] as $matcher) {
            yield $matcher . '-hit' => [
                'matcher' => $matcher,
                'path' => '/adaptive/g15/r15/deadbeef',
            ];
            yield $matcher . '-miss' => [
                'matcher' => $matcher,
                'path' => '/adaptive/g15/r15/not-hex',
            ];
        }
    }

    private static function matcher(string $mode): MatcherInterface
    {
        if (isset(self::$matchers[$mode])) {
            return self::$matchers[$mode];
        }

        $matcher = match ($mode) {
            MatcherModeEnum::FUSED->value => FusedMatcher::make(),
            MatcherModeEnum::SHARDED->value => ShardedMatcher::make(),
            default => throw new \LogicException('Unsupported matcher mode.'),
        };

        $index = 0;
        for ($group = 0; $group < 16; ++$group) {
            for ($route = 0; $route < 16; ++$route) {
                $matcher->add(CompiledRoute::fromRoute(
                    new Route('GET', "/adaptive/g{$group}/r{$route}/{id:hex}", 'adaptive-handler'),
                    $index++,
                ));
            }
        }
        $matcher->finalize();

        return self::$matchers[$mode] = $matcher;
    }
}
