<?php

declare(strict_types=1);


/**
 * Integration Test Bootstrap
 *
 * This file sets up a real RouterKernel instance for integration testing,
 * using the same configuration as index.php but in a test-friendly way.
 */

use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

// Declare controllers in global namespace for routes.php
if (!class_exists('DemoController', false)) {
    final readonly class DemoController
    {
        public function hello(\Infocyph\Webrick\Request\Request $request, string $name): Response
        {
            return Response::json([
                'handler' => 'DemoController::hello',
                'hello' => $name,
                'time' => \date(DATE_ATOM),
            ]);
        }
    }
}

if (!class_exists('UsersController', false)) {
    final readonly class UsersController
    {
        public function create(): Response
        {
            return Response::json(['action' => 'create']);
        }

        public function destroy(string $id): Response
        {
            return Response::json(['action' => 'destroy', 'id' => $id]);
        }

        public function edit(string $id): Response
        {
            return Response::json(['action' => 'edit', 'id' => $id]);
        }

        public function index(): Response
        {
            return Response::json(['action' => 'index']);
        }

        public function show(string $id): Response
        {
            return Response::json(['action' => 'show', 'id' => $id]);
        }

        public function store(\Infocyph\Webrick\Request\Request $r): Response
        {
            return Response::json(['action' => 'store', 'data' => $r->all()], 201);
        }

        public function update(\Infocyph\Webrick\Request\Request $r, string $id): Response
        {
            return Response::json(['action' => 'update', 'id' => $id, 'data' => $r->all()]);
        }
    }
}

/**
 * Create a test RouterKernel with actual routes
 */
function createTestKernel(array $extraMiddleware = []): RouterKernel
{
    $logger = new NullLogger();
    $signUrlSecret = 'test-secret-key-for-integration-tests';

    // Registration callback - load actual routes
    $register = function (Registrar $registrar) {
        // Load the same routes as the real app
        require __DIR__ . '/../routes.php';
    };

    // NO global middleware by default - tests should be simple
    // If tests need middleware, they can pass it via $extraMiddleware
    $preGlobal = $extraMiddleware;

    return RouterKernel::bootWithRegistrar(
        log: $logger,
        matcher: FusedMatcher::make(),
        register: $register,
        routeCache: null, // No cache for tests
        registrarOptions: [
            'autoSlashRedirect' => false, // No automatic redirects
            'exposeUrlServices' => false,
            'signKey' => $signUrlSecret,
            'signedDefaultTtl' => 900,
        ],
        preGlobal: $preGlobal,
        postGlobal: [],
        bindUrlServices: function (Collection $routes) use ($signUrlSecret): void {
            Response::bindUrlServices($routes, $signUrlSecret, 900);
        },
        fallbackAliasesFromRegistrar: true,
    );
}
