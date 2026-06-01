<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

dataset('matcherFactories', [
    'fused' => static fn (): MatcherInterface => FusedMatcher::make(),
    'sharded' => static fn (): MatcherInterface => ShardedMatcher::make(),
    'generated' => static fn (): MatcherInterface => GeneratedMatcher::make(),
]);

test('matchers isolate same path by domain and fall back to wildcard', function (callable $factory): void {
    /** @var MatcherInterface $matcher */
    $matcher = $factory();

    $route = static function (string $method, string $path, ?string $domain): CompiledRoute {
        $r = new Route($method, $path, 'strlen');
        if ($domain !== null) {
            $r = $r->withDomain($domain);
        }

        return CompiledRoute::fromRoute($r);
    };

    $matcher->add($route('GET', '/ping', 'a.example.com'));
    $matcher->add($route('GET', '/ping', 'b.example.com'));
    $matcher->add($route('GET', '/ping', null)); // wildcard fallback
    $matcher->add($route('GET', '/only-a', 'a.example.com'));
    $matcher->add($route('POST', '/only-a', 'a.example.com'));
    $matcher->add($route('GET', '/tenant/{id}', 'a.example.com'));
    $matcher->add($route('GET', '/tenant/{id}', 'b.example.com'));
    $matcher->finalize();

    [$aPing] = $matcher->match('GET', 'a.example.com', '/ping');
    [$bPing] = $matcher->match('GET', 'b.example.com', '/ping');
    [$cPing] = $matcher->match('GET', 'c.example.com', '/ping');

    expect($aPing->getDomain())->toBe('a.example.com')
        ->and($bPing->getDomain())->toBe('b.example.com')
        ->and($cPing->getDomain())->toBeNull();

    [$aDyn, $aParams] = $matcher->match('GET', 'a.example.com', '/tenant/42');
    [$bDyn, $bParams] = $matcher->match('GET', 'b.example.com', '/tenant/42');

    expect($aDyn->getDomain())->toBe('a.example.com')
        ->and($aParams)->toBe(['id' => '42'])
        ->and($bDyn->getDomain())->toBe('b.example.com')
        ->and($bParams)->toBe(['id' => '42']);

    expect(fn () => $matcher->match('PUT', 'a.example.com', '/only-a'))
        ->toThrow(MethodNotAllowedException::class);

    expect(fn () => $matcher->match('GET', 'b.example.com', '/only-a'))
        ->toThrow(RouteNotFoundException::class);
})->with('matcherFactories');
