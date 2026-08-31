<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Matching\CanonicalMatcherIndex;
use Infocyph\Webrick\Router\Matching\CompiledMatcherIndexCompiler;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

function matcherRevisionRoute(string $method, string $path): CompiledRoute
{
    return CompiledRoute::fromRoute(new Route(
        $method,
        $path,
        static fn(): Response => Response::plaintext('ok'),
    ));
}

it('compiles regex routes into the pcre lane and callable constraints into fallback', function (): void {
    $index = new CanonicalMatcherIndex();
    $index->add('*', matcherRevisionRoute('GET', '/users/{name}'));
    $index->add('*', matcherRevisionRoute('GET', '/orders/{id:int}'));

    $compiled = new CompiledMatcherIndexCompiler()->compile($index->hosts());

    expect($compiled['*']['dynamic']['GET'][2]['users']['pcre'])->not->toBeEmpty()
        ->and($compiled['*']['dynamic']['GET'][2]['users']['fallback'])->toBe([])
        ->and($compiled['*']['dynamic']['GET'][2]['orders']['pcre'])->toBe([])
        ->and($compiled['*']['dynamic']['GET'][2]['orders']['fallback'])->toHaveCount(1);
});

it('dispatches regex dynamic routes through fused compiled matching', function (): void {
    $route = matcherRevisionRoute('GET', '/users/{name}');
    $matcher = FusedMatcher::make();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/users/hasan'))
        ->toBe([$route->getIndex(), ['name' => 'hasan']]);
});

it('keeps callable route constraints on the fused fallback lane', function (): void {
    $route = matcherRevisionRoute('GET', '/orders/{id:int}');
    $matcher = FusedMatcher::make();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/orders/42'))
        ->toBe([$route->getIndex(), ['id' => '42']]);

    $miss = $matcher->matchCompiled('GET', '*', '/orders/not-an-int');
    expect($miss)->toBeInstanceOf(MatchOutcome::class)
        ->and($miss->type)->toBe(MatchOutcomeType::NOT_FOUND);
});

it('uses method-first dynamic pcre buckets for overlapping route patterns', function (): void {
    $get = matcherRevisionRoute('GET', '/lookup/{value:uuid}');
    $post = matcherRevisionRoute('POST', '/lookup/{value:slug}');
    $matcher = FusedMatcher::make();
    $matcher->add($get);
    $matcher->add($post);
    $matcher->finalize();

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    expect($matcher->matchCompiled('GET', '*', '/lookup/' . $uuid))
        ->toBe([$get->getIndex(), ['value' => $uuid]])
        ->and($matcher->matchCompiled('POST', '*', '/lookup/' . $uuid))
        ->toBe([$post->getIndex(), ['value' => $uuid]]);
});

it('maps parameters correctly when a constraint contains nested capture groups', function (): void {
    $route = matcherRevisionRoute('GET', '/packages/{version:semver}');
    $matcher = FusedMatcher::make();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/packages/1.2.3-beta+build.7'))
        ->toBe([$route->getIndex(), ['version' => '1.2.3-beta+build.7']]);
});

it('preserves dynamic leading and trailing slash matching behavior', function (): void {
    $route = matcherRevisionRoute('GET', '/users/{name}');
    $matcher = FusedMatcher::make();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/users/hasan/'))
        ->toBe([$route->getIndex(), ['name' => 'hasan']])
        ->and($matcher->matchCompiled('GET', '*', '//users/hasan//'))
        ->toBe([$route->getIndex(), ['name' => 'hasan']]);
});

it('dispatches across multiple combined pcre chunks', function (): void {
    $matcher = FusedMatcher::make();
    $last = null;

    for ($i = 0; $i < 40; $i++) {
        $route = matcherRevisionRoute('GET', '/bulk/{group}/item-' . $i . '/{id}');
        $matcher->add($route);
        $last = $route;
    }
    $matcher->finalize();

    expect($last)->toBeInstanceOf(CompiledRoute::class)
        ->and($matcher->matchCompiled('GET', '*', '/bulk/main/item-39/99'))
        ->toBe([$last->getIndex(), ['group' => 'main', 'id' => '99']]);
});

it('keeps 405 and automatic options semantics on dynamic misses', function (): void {
    $get = matcherRevisionRoute('GET', '/resource/{id}');
    $post = matcherRevisionRoute('POST', '/resource/{id}');
    $matcher = FusedMatcher::make();
    $matcher->add($get);
    $matcher->add($post);
    $matcher->finalize();

    $miss = $matcher->matchCompiled('DELETE', '*', '/resource/1');
    expect($miss)->toBeInstanceOf(MatchOutcome::class)
        ->and($miss->type)->toBe(MatchOutcomeType::METHOD_NOT_ALLOWED)
        ->and($miss->allowed)->toContain('GET', 'HEAD', 'POST');

    $options = $matcher->matchCompiled('OPTIONS', '*', '/resource/1');
    expect($options)->toBeInstanceOf(MatchOutcome::class)
        ->and($options->type)->toBe(MatchOutcomeType::AUTO_OPTIONS)
        ->and($options->allowed)->toContain('GET', 'HEAD', 'POST');
});
