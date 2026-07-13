<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;

it('discovers routes declared by controller attributes', function (): void {
    $routes = new Collection();
    $registrar = new Registrar($routes);

    AttributeRouteLoader::registerFromDirs(
        $registrar,
        ['Infocyph\\Webrick\\Tests\\Fixture\\' => __DIR__ . '/../Fixtures'],
        AttributeRouteLoader::controllerFileFilter(),
    );

    $route = $routes->findByName('test.hello');

    expect($route)->not->toBeNull()
        ->and($route?->getMethod())->toBe('GET')
        ->and($route?->getPath())->toBe('/test/hello/{name}');
});
