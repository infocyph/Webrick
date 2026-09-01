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

#[Bench\Groups(['matcher', 'matcher-pcre-policy'])]
#[Bench\Iterations(7)]
#[Bench\Revs(2500)]
#[Bench\Warmup(2)]
final class MatcherPcrePolicyBench
{
    /** @var array<string,MatcherInterface> */
    private static array $matchers = [];

    #[Bench\ParamProviders('provideCases')]
    public function benchPcrePolicy(array $params): void
    {
        self::matcher((string) $params['matcher'])
            ->matchCompiled('GET', '*', (string) $params['path']);
    }

    /** @return iterable<string,array{matcher:string,path:string}> */
    public function provideCases(): iterable
    {
        foreach ([MatcherModeEnum::FUSED->value, MatcherModeEnum::SHARDED->value] as $matcher) {
            yield $matcher . '-early-hit' => [
                'matcher' => $matcher,
                'path' => '/pcre/a0/b0/c0/d0/e0/deadbeef',
            ];
            yield $matcher . '-late-hit' => [
                'matcher' => $matcher,
                'path' => '/pcre/a2/b2/c2/d2/e2/deadbeef',
            ];
            yield $matcher . '-miss' => [
                'matcher' => $matcher,
                'path' => '/pcre/a2/b2/c2/d2/e2/not-hex',
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
        for ($a = 0; $a < 3; ++$a) {
            for ($b = 0; $b < 3; ++$b) {
                for ($c = 0; $c < 3; ++$c) {
                    for ($d = 0; $d < 3; ++$d) {
                        for ($e = 0; $e < 3; ++$e) {
                            $matcher->add(CompiledRoute::fromRoute(
                                new Route('GET', "/pcre/a{$a}/b{$b}/c{$c}/d{$d}/e{$e}/{id:hex}", 'pcre-handler'),
                                $index++,
                            ));
                        }
                    }
                }
            }
        }
        $matcher->finalize();

        return self::$matchers[$mode] = $matcher;
    }
}
