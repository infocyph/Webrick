<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

dataset('compiled outcome matchers', [
    'fused' => [static fn(): MatcherInterface => FusedMatcher::make()],
    'sharded' => [static fn(): MatcherInterface => ShardedMatcher::make()],
    'generated' => [static fn(): MatcherInterface => GeneratedMatcher::make()],
]);

it('returns only the compiled route index for static hits', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/health',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    $outcome = $matcher->matchCompiledOutcome('GET', '*', '/health');

    expect($outcome->type)->toBe(MatchOutcomeType::FOUND)
        ->and($outcome->route)->toBeNull()
        ->and($outcome->requireRouteIndex())->toBe($route->getIndex())
        ->and($outcome->params)->toBe([]);
})->with('compiled outcome matchers');

it('preserves dynamic params without materializing the route', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/users/{id}',
        static fn(string $id): Response => Response::plaintext($id),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    $outcome = $matcher->matchCompiledOutcome('GET', '*', '/users/42');

    expect($outcome->type)->toBe(MatchOutcomeType::FOUND)
        ->and($outcome->route)->toBeNull()
        ->and($outcome->requireRouteIndex())->toBe($route->getIndex())
        ->and($outcome->params)->toBe(['id' => '42']);
})->with('compiled outcome matchers');

it('preserves head fallback on the index-only path', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/resource',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    $outcome = $matcher->matchCompiledOutcome('HEAD', '*', '/resource');

    expect($outcome->type)->toBe(MatchOutcomeType::FOUND)
        ->and($outcome->route)->toBeNull()
        ->and($outcome->requireRouteIndex())->toBe($route->getIndex())
        ->and($outcome->headFallback)->toBeTrue();
})->with('compiled outcome matchers');
