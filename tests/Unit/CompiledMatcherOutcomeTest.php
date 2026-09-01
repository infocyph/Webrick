<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

dataset('compiled outcome matchers', [
    'fused' => [static fn(): MatcherInterface => FusedMatcher::make()],
    'sharded' => [static fn(): MatcherInterface => ShardedMatcher::make()],
    'generated' => [static fn(): MatcherInterface => GeneratedMatcher::make()],
]);

it('returns only the compiled route index for parameter-free hits', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/health',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/health'))->toBe($route->getIndex());
})->with('compiled outcome matchers');

it('returns route index and dynamic params without materializing the route', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/users/{id}',
        static fn(string $id): Response => Response::plaintext($id),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/users/42'))
        ->toBe([$route->getIndex(), ['id' => '42']]);
})->with('compiled outcome matchers');

it('uses the get route index for head fallback without allocating a success outcome', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/resource',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('HEAD', '*', '/resource'))->toBe($route->getIndex());
})->with('compiled outcome matchers');

it('keeps automatic options as an explicit control outcome', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/resource',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    $outcome = $matcher->matchCompiled('OPTIONS', '*', '/resource');

    expect($outcome)->toBeInstanceOf(MatchOutcome::class)
        ->and($outcome->type)->toBe(MatchOutcomeType::AUTO_OPTIONS)
        ->and($outcome->allowed)->toContain('GET', 'HEAD');
})->with('compiled outcome matchers');

it('keeps method misses as explicit control outcomes', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/resource',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    $outcome = $matcher->matchCompiled('POST', '*', '/resource');

    expect($outcome)->toBeInstanceOf(MatchOutcome::class)
        ->and($outcome->type)->toBe(MatchOutcomeType::METHOD_NOT_ALLOWED);
})->with('compiled outcome matchers');

it('keeps not found as an explicit control outcome', function (Closure $factory): void {
    $route = CompiledRoute::fromRoute(new Route(
        'GET',
        '/resource',
        static fn(): Response => Response::plaintext('ok'),
    ));
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    $outcome = $matcher->matchCompiled('GET', '*', '/missing');

    expect($outcome)->toBeInstanceOf(MatchOutcome::class)
        ->and($outcome->type)->toBe(MatchOutcomeType::NOT_FOUND);
})->with('compiled outcome matchers');
