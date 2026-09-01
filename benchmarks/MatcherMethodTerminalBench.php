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

#[Bench\Groups(['matcher', 'matcher-method-terminal'])]
#[Bench\Iterations(7)]
#[Bench\Revs(3000)]
#[Bench\Warmup(2)]
final class MatcherMethodTerminalBench
{
    /** @var array<string,MatcherInterface> */
    private static array $matchers = [];

    #[Bench\ParamProviders('provideCases')]
    public function benchMethodTerminal(array $params): void
    {
        self::matcher((string) $params['matcher'])->matchCompiled(
            (string) $params['method'],
            '*',
            (string) $params['path'],
        );
    }

    /** @return iterable<string,array{matcher:string,method:string,path:string}> */
    public function provideCases(): iterable
    {
        foreach ([MatcherModeEnum::FUSED->value, MatcherModeEnum::SHARDED->value] as $matcher) {
            yield $matcher . '-hit' => [
                'matcher' => $matcher,
                'method' => 'GET',
                'path' => '/terminal/users/deadbeef',
            ];
            yield $matcher . '-options' => [
                'matcher' => $matcher,
                'method' => 'OPTIONS',
                'path' => '/terminal/users/deadbeef',
            ];
            yield $matcher . '-405' => [
                'matcher' => $matcher,
                'method' => 'TRACE',
                'path' => '/terminal/users/deadbeef',
            ];
            yield $matcher . '-404' => [
                'matcher' => $matcher,
                'method' => 'TRACE',
                'path' => '/terminal/users/not-hex',
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
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $matcher->add(CompiledRoute::fromRoute(
                new Route($method, '/terminal/users/{id:hex}', 'terminal-handler'),
                $index++,
            ));
        }
        $matcher->finalize();

        return self::$matchers[$mode] = $matcher;
    }
}
