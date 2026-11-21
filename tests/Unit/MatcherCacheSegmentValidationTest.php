<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\CanonicalMatcherIndex;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\CompiledRouteCachePayload;
use Infocyph\Webrick\Router\Route\Route;

it('keeps valid variable regex segments in both persisted cache formats', function (): void {
    $route = CompiledRoute::fromRoute(new Route('GET', '/records/{id}', 'record-handler'));
    $segments = $route->getSegments();
    $index = new CanonicalMatcherIndex();

    $index->replaceFromCache([
        '*' => [
            'static' => [],
            'dynamic' => [
                count($segments) => [
                    'records' => [
                        '/records/{id}' => [
                            'segments' => $segments,
                            'verbs' => ['GET' => $route],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($index->hosts()['*']['dynamic'][count($segments)]['records']['/records/{id}']['segments'])
        ->toBe($segments)
        ->and(CompiledRouteCachePayload::validate($route->toCachePayload())[13])
        ->toBe($segments);
});

it('rejects a non-string cached regex without a callable alternative', function (): void {
    $route = CompiledRoute::fromRoute(new Route('GET', '/records/{id}', 'record-handler'));
    $segments = [
        ['type' => 'lit', 'val' => 'records'],
        ['type' => 'var', 'name' => 'id', 'regex' => 42],
    ];
    $payload = $route->toCachePayload();
    $payload[13] = $segments;

    expect(static fn(): array => CompiledRouteCachePayload::validate($payload))
        ->toThrow(UnexpectedValueException::class);

    $index = new CanonicalMatcherIndex();
    expect(static function () use ($index, $segments, $route): void {
        $index->replaceFromCache([
            '*' => [
                'static' => [],
                'dynamic' => [
                    count($segments) => [
                        'records' => [
                            '/records/{id}' => [
                                'segments' => $segments,
                                'verbs' => ['GET' => $route],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    })->toThrow(UnexpectedValueException::class);
});
