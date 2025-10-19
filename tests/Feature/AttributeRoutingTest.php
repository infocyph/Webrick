<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Definition\Attribute\Route as RouteAttr;
use Infocyph\Webrick\Router\Definition\Attribute\Get;
use Infocyph\Webrick\Router\Definition\Attribute\Post;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Response\Response;

#[RouteAttr('/api')]
class ApiController {
    #[Get('/users', name: 'api.users.index')]
    public function index(): Response {
        return Response::json(['users' => []]);
    }

    #[Get('/users/{id:int}', name: 'api.users.show')]
    public function show(int $id): Response {
        return Response::json(['user_id' => $id]);
    }

    #[Post('/users', name: 'api.users.store')]
    public function store(): Response {
        return Response::json(['created' => true], 201);
    }
}

describe('Attribute Routing', function () {
    it('discovers routes from attributes', function () {
        $routes = new Collection();
        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: false,
            exposeUrlServices: false
        );

        // Register from controller class
        AttributeRouteLoader::registerFromClasses(
            $registrar,
            [ApiController::class]
        );

        // Check that routes were registered
        $index = $routes->findByName('api.users.index');
        $show = $routes->findByName('api.users.show');
        $store = $routes->findByName('api.users.store');

        expect($index)->not->toBeNull();
        expect($show)->not->toBeNull();
        expect($store)->not->toBeNull();

        expect($index->getPath())->toBe('/api/users');
        expect($show->getPath())->toBe('/api/users/{id:int}');
        expect($store->getMethod())->toBe('POST');
    });

    it('applies prefix from class-level attribute', function () {
        $routes = new Collection();
        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: false,
            exposeUrlServices: false
        );

        AttributeRouteLoader::registerFromClasses($registrar, [ApiController::class]);

        $route = $routes->findByName('api.users.index');

        // Prefix from #[Route('/api')] should be applied
        expect($route->getPath())->toBe('/api/users');
    });

    it('discovers routes from directories', function () {
        $testDir = __DIR__ . '/../Fixtures';

        $routes = new Collection();
        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: false,
            exposeUrlServices: false
        );

        // This would scan the test fixtures directory
        AttributeRouteLoader::registerFromDirs(
            $registrar,
            ['Tests\\Fixtures\\' => $testDir]
        );

        // Should find routes in fixture controllers
        expect($routes->all())->not->toBeEmpty();
    });
});
