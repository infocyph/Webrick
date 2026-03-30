<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

test('GeneratedMatcher matches static and dynamic routes', function (): void {
    $matcher = GeneratedMatcher::make();

    $static = CompiledRoute::fromRoute(
        (new Route('GET', '/hello', static fn (): string => 'ok'))
            ->withDomain('example.com'),
    );
    $dynamic = CompiledRoute::fromRoute(
        (new Route('GET', '/hello/{name}', static fn (): string => 'ok'))
            ->withDomain('example.com'),
    );

    $matcher->add($static);
    $matcher->add($dynamic);
    $matcher->finalize();

    [$r1, $p1] = $matcher->match('GET', 'example.com', '/hello');
    expect($r1->getPath())->toBe('/hello')
        ->and($p1)->toBe([]);

    [$r2, $p2] = $matcher->match('GET', 'example.com', '/hello/alice');
    expect($r2->getPath())->toBe('/hello/{name}')
        ->and($p2)->toBe(['name' => 'alice']);

    expect(fn () => $matcher->match('POST', 'example.com', '/hello'))
        ->toThrow(MethodNotAllowedException::class);

    expect(fn () => $matcher->match('GET', 'example.com', '/missing'))
        ->toThrow(RouteNotFoundException::class);
});

test('GeneratedMatcher boots from generated cache file', function (): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-generated-' . uniqid('', true) . '.php';

    try {
        $route = CompiledRoute::fromRoute(
            (new Route('GET', '/hello', static fn (): string => 'ok'))
                ->withDomain('example.com')
                ->withName('hello.route'),
        );

        $writer = GeneratedMatcher::make()->enableCache($file);
        $writer->add($route);
        $writer->finalize();

        expect(is_file($file))->toBeTrue();

        $reader = GeneratedMatcher::make()->enableCache($file);
        expect($reader->canBootFromCache())->toBeTrue();
        $reader->finalize();

        [$hit, $params] = $reader->match('GET', 'example.com', '/hello');
        expect($hit->getPath())->toBe('/hello')
            ->and($params)->toBe([])
            ->and($reader->resolveAlias('hello.route'))->toBe(['/hello', 'example.com']);
    } finally {
        @unlink($file);
    }
});

