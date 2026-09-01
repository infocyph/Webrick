<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;
use PhpBench\Attributes as Bench;

/** End-to-end callable built-in constraint benchmark for matcher dispatch. */
#[Bench\Groups(['matcher', 'matcher-typed-constraint'])]
#[Bench\Iterations(5)]
#[Bench\Revs(3000)]
#[Bench\Warmup(1)]
final class MatcherTypedConstraintBench
{
    /** @var array<string,MatcherInterface> */
    private static array $matchers = [];

    #[Bench\ParamProviders('provideCases')]
    public function benchTypedConstraint(array $params): void
    {
        self::matcher((string) $params['matcher'])
            ->matchCompiled('GET', '*', (string) $params['path']);
    }

    /** @return iterable<string,array{matcher:string,path:string}> */
    public function provideCases(): iterable
    {
        foreach ([
            MatcherModeEnum::FUSED->value,
            MatcherModeEnum::GENERATED->value,
            MatcherModeEnum::SHARDED->value,
        ] as $matcher) {
            yield $matcher . '-int-hit' => ['matcher' => $matcher, 'path' => '/typed/int/123456'];
            yield $matcher . '-int-miss' => ['matcher' => $matcher, 'path' => '/typed/int/12x456'];
            yield $matcher . '-numeric-hit' => ['matcher' => $matcher, 'path' => '/typed/numeric/-123.45e2'];
            yield $matcher . '-alpha-hit' => ['matcher' => $matcher, 'path' => '/typed/alpha/Benchmark'];
            yield $matcher . '-alnum-hit' => ['matcher' => $matcher, 'path' => '/typed/alnum/Bench42'];
            yield $matcher . '-bool-hit' => ['matcher' => $matcher, 'path' => '/typed/bool/FALSE'];
            yield $matcher . '-json-hit' => ['matcher' => $matcher, 'path' => '/typed/json/{"ok":true}'];
            yield $matcher . '-ipv6-hit' => ['matcher' => $matcher, 'path' => '/typed/ipv6/2001:db8::1'];
        }
    }

    private static function matcher(string $mode): MatcherInterface
    {
        if (isset(self::$matchers[$mode])) {
            return self::$matchers[$mode];
        }

        $matcher = match ($mode) {
            MatcherModeEnum::FUSED->value => FusedMatcher::make(),
            MatcherModeEnum::GENERATED->value => GeneratedMatcher::make(),
            MatcherModeEnum::SHARDED->value => ShardedMatcher::make(),
            default => throw new \LogicException('Unsupported matcher mode.'),
        };

        $index = 0;
        foreach (['int', 'numeric', 'alpha', 'alnum', 'bool', 'json', 'ipv6'] as $constraint) {
            $matcher->add(CompiledRoute::fromRoute(
                new Route('GET', "/typed/{$constraint}/{value:{$constraint}}", 'typed-handler'),
                $index++,
            ));
        }
        $matcher->finalize();

        return self::$matchers[$mode] = $matcher;
    }
}
