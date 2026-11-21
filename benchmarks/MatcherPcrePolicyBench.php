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

    /**
     * @param array{matcher:string,path:non-empty-string} $params
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[Bench\ParamProviders('provideCases')]
    public function benchPcrePolicy(array $params): void
    {
        self::matcher($params['matcher'])
            ->matchCompiled('GET', '*', $params['path']);
    }

    /** @return iterable<string,array{matcher:string,path:non-empty-string}> */
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

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
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

        // Equivalent lexicographic order to five base-3 nested loops (3^5 = 243 routes).
        for ($index = 0; $index < 243; ++$index) {
            $a = intdiv($index, 81);
            $b = intdiv($index, 27) % 3;
            $c = intdiv($index, 9) % 3;
            $d = intdiv($index, 3) % 3;
            $e = $index % 3;

            $matcher->add(CompiledRoute::fromRoute(
                new Route('GET', "/pcre/a{$a}/b{$b}/c{$c}/d{$d}/e{$e}/{id:hex}", 'pcre-handler'),
                $index,
            ));
        }
        $matcher->finalize();

        return self::$matchers[$mode] = $matcher;
    }
}
