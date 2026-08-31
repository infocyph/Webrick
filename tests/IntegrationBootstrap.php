<?php

declare(strict_types=1);

/**
 * Integration Test Bootstrap
 *
 * This file sets up a real RouterKernel instance for integration testing,
 * using the same configuration as index.php but in a test-friendly way.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\NullLogger;

if (! class_exists('DemoController', false)) {
    final readonly class DemoController
    {
        public function hello(string $name): Response
        {
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

/** Create a test RouterKernel with actual routes. */
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
    $throttlePool = Cache::memory('webrick.integration.throttle.' . bin2hex(random_bytes(6)));

    MiddlewareAliases::reset();
    MiddlewareAliases::register(
        'throttle',
        static function (mixed ...$parameters) use ($throttlePool): ThrottleMiddleware {
            $max = isset($parameters[0]) && is_numeric($parameters[0]) ? (int) $parameters[0] : 60;
            $window = isset($parameters[1]) && is_numeric($parameters[1]) ? (int) $parameters[1] : 60;

            return new ThrottleMiddleware(
                max: $max,
                window: $window,
                pool: $throttlePool,
                allowApproximateFallback: true,
            );
        },
    );
    MiddlewareAliases::register(
        'verifySignedUrl',
        static fn() => new \Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware($signUrlSecret, 5),
    );
    MiddlewareAliases::register(
        'verifySignedUrlAbsolute',
        static fn() => new \Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware($signedAbsoluteUrlConfig),
    );
    $errorHandler = new ErrorHandler(
        logger: $logger,
        debug: true,
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

    $register = function () {
        require __DIR__.'/../routes.php';
    };

    return RouterKernel::bootWithRegistrar(
        log: $logger,
        matcher: FusedMatcher::make(),
        register: $register,
        invoker: Invoker::with(new Container('webrick.tests.integration')),
        registrarOptions: [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => false,
            'signKey' => $signUrlSecret,
            'signedDefaultTtl' => 900,
            'signedUrlConfig' => $signedUrlConfig,
            'urlBaseUri' => $urlBaseUri,
        ],
        preGlobal: $extraMiddleware,
        postGlobal: [],
        errorHandler: $errorHandler,
        bindUrlServices: function (Collection $routes) use (
            $signUrlSecret,
            $signedUrlConfig,
            $urlBaseUri,
        ): void {
            Route::bindUrlServices($routes, $signUrlSecret, 900, $signedUrlConfig, $urlBaseUri);
        },
    );
}
