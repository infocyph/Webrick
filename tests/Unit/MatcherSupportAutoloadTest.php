<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\CanonicalMatcherIndex;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

it('loads matcher support functions from Composer without a matcher base-class include', function (): void {
    expect(function_exists('Infocyph\\Webrick\\Router\\Matching\\matcher_normalize_compiled_route_map'))
        ->toBeTrue();

    $route = CompiledRoute::fromRoute(new Route('GET', '/autoload-probe', 'autoload-handler'));
    $index = new CanonicalMatcherIndex();
    $index->replaceFromCache([
        '*' => [
            'static' => ['/autoload-probe' => ['GET' => $route]],
            'dynamic' => [],
        ],
    ]);

    expect($index->hosts()['*']['static']['/autoload-probe']['GET'])->toBe($route);
});
