<?php

declare(strict_types=1);

/**
 * Integration Test Bootstrap
 *
 * This file sets up a real RouterKernel instance for integration testing,
 * using the same configuration as index.php but in a test-friendly way.
 */

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\NullLogger;

// Declare controllers in global namespace for routes.php
if (! class_exists('DemoController', false)) {
    final readonly class DemoController
    {
        public function hello(Request $request, string $name): Response
        {
            unset($request);

            return Response::json([
                'handler' => 'DemoController::hello',
                'hello' => $name,
                'time' => \date(DATE_ATOM),
            ]);
        }
    }
}

if (! class_exists('UsersController', false)) {
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

        public function store(Request $r): Response
        {
            return Response::json(['action' => 'store', 'data' => $r->all()], 201);
        }

        public function update(Request $r, string $id): Response
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
    $logger = new NullLogger;
    $signUrlSecret = 'test-secret-key-for-integration-tests';
    $signedUrlConfig = new SignedUrlConfig(
        generationKey: $signUrlSecret,
        verificationKeys: [$signUrlSecret],
        defaultTtl: 900,
    );
    $signedAbsoluteUrlConfig = new SignedUrlConfig(
        verificationKeys: [$signUrlSecret],
        payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
        ignoredQueryParams: ['preview'],
        leeway: 5,
    );
    $urlBaseUri = 'http://localhost';

    MiddlewareAliases::reset();
    MiddlewareAliases::register(
        'throttle',
        static function (...$params): string {
            unset($params);

            return \Infocyph\Webrick\Middleware\ThrottleMiddleware::class;
        },
    );
    MiddlewareAliases::register('verifySignedUrl', static fn() => new \Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware($signUrlSecret, 5));
    MiddlewareAliases::register(
        'verifySignedUrlAbsolute',
        static fn() => new \Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware($signedAbsoluteUrlConfig),
    );
    $errorHandler = new ErrorHandler(
        logger: $logger,
        debug: true,
        capturePhpErrors: true,
        requestIdHeader: 'X-Request-Id',
        responseRenderer: static function (Request $request, \Throwable $e, int $status, array $headers): ?Response {
            if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
                return null;
            }

            $message = $e instanceof \Infocyph\Webrick\Exceptions\HttpExceptionInterface
                ? $e->getPublicMessage()
                : 'HTTP Error';

            return Response::json([
                'error' => $message,
                'status' => $status,
                'path' => $request->getUri()->getPath(),
            ], $status, $headers);
        },
    );

    // Registration callback - load actual routes
    $register = function (Registrar $registrar) {
        unset($registrar);

        // Load the same routes as the real app
        require __DIR__.'/../routes.php';
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
            'signedUrlConfig' => $signedUrlConfig,
            'urlBaseUri' => $urlBaseUri,
        ],
        preGlobal: $preGlobal,
        postGlobal: [],
        errorHandler: $errorHandler,
        bindUrlServices: function (Collection $routes) use (
            $signUrlSecret,
            $signedUrlConfig,
            $urlBaseUri,
        ): void {
            Route::bindUrlServices($routes, $signUrlSecret, 900, $signedUrlConfig, $urlBaseUri);
        },
        fallbackAliasesFromRegistrar: true,
    );
}
