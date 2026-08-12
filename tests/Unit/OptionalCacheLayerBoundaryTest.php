<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;

test('core routing does not load the optional CacheLayer package', function (): void {
    $loaded = [];
    $guard = static function (string $class) use (&$loaded): void {
        if (str_starts_with($class, 'Infocyph\\CacheLayer\\')) {
            $loaded[] = $class;
        }
    };
    spl_autoload_register($guard, true, true);

    try {
        $routes = new Collection();
        $registrar = new Registrar($routes);
        $registrar->get('/core', static fn(): Response => Response::plaintext('ok'));
        $matcher = FusedMatcher::make();
        foreach ($routes->compile()->all() as $route) {
            $matcher->add($route);
        }
        $matcher->finalize();
        [$matched] = $matcher->match('GET', 'localhost', '/core');

        expect($matched)->toBeInstanceOf(CompiledRoute::class)
            ->and($loaded)->toBe([]);
    } finally {
        spl_autoload_unregister($guard);
    }
});
