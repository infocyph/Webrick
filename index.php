<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpExceptionInterface;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Webrick;
use Psr\Log\NullLogger;

require __DIR__ . '/vendor/autoload.php';

/** Development front controller. Production uses CompiledRouterKernel. */
final readonly class DemoController
{
    public function hello(Request $request, string $name): Response
    {
        return Response::json([
            'handler' => self::class . '::hello',
            'prefers' => $request->prefers([
                MediaTypeEnum::JSON->base(),
                '+json',
                MediaTypeEnum::PLAIN->base(),
            ]),
            'hello' => $name,
            'request' => $request->all(),
            'time' => date(DATE_ATOM),
        ]);
    }
}

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

    public function store(Request $request): Response
    {
        return Response::json(
            ['action' => 'store', 'data' => $request->all()],
            StatusEnum::CREATED->value,
        );
    }

    public function update(Request $request, string $id): Response
    {
        return Response::json(['action' => 'update', 'id' => $id, 'data' => $request->all()]);
    }
}

$logger = new NullLogger();
$signingKey = 'webrick-development-signing-key';
$urlBaseUri = getenv('WEBRICK_URL_BASE_URI') ?: 'http://localhost:8000';
$signedUrlConfig = new SignedUrlConfig(
    generationKey: $signingKey,
    verificationKeys: [$signingKey],
    defaultTtl: 900,
);
$signedAbsoluteUrlConfig = new SignedUrlConfig(
    verificationKeys: [$signingKey],
    payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
    ignoredQueryParams: ['preview'],
    leeway: 5,
);

MiddlewareAliases::register(
    'throttle',
    static function (mixed ...$parameters): ThrottleMiddleware {
        $max = isset($parameters[0]) && is_numeric($parameters[0]) ? (int) $parameters[0] : 60;
        $window = isset($parameters[1]) && is_numeric($parameters[1]) ? (int) $parameters[1] : 60;

        return new ThrottleMiddleware(
            max: $max,
            window: $window,
            allowApproximateFallback: true,
        );
    },
);
MiddlewareAliases::register(
    'verifySignedUrl',
    static fn(): VerifySignedUrlMiddleware => new VerifySignedUrlMiddleware($signingKey, 5),
);
MiddlewareAliases::register(
    'verifySignedUrlAbsolute',
    static fn(): VerifySignedUrlMiddleware => new VerifySignedUrlMiddleware($signedAbsoluteUrlConfig),
);

$register = static function (Registrar $registrar): void {
    require __DIR__ . '/routes.php';

    foreach ([__DIR__ . '/tests/Fixture', __DIR__ . '/tests/Fixtures'] as $fixtureDir) {
        if (!is_dir($fixtureDir)) {
            continue;
        }

        AttributeRouteLoader::registerFromDirs(
            $registrar,
            ['Infocyph\\Webrick\\Tests\\Fixture\\' => $fixtureDir],
        );
    }
};

$container = Webrick::standaloneDevelopment()->development();
$invoker = Invoker::with($container);

$errorHandler = new ErrorHandler(
    logger: $logger,
    debug: true,
    requestIdHeader: 'X-Request-Id',
    responseRenderer: static function (Request $request, Throwable $error, int $status, array $headers): ?Response {
        if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
            return null;
        }

        $message = $error instanceof HttpExceptionInterface ? $error->getPublicMessage() : 'HTTP Error';

        return Response::json([
            'error' => $message,
            'status' => $status,
            'path' => $request->getUri()->getPath(),
        ], $status, $headers);
    },
);

$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: GeneratedMatcher::make(),
    register: $register,
    invoker: $invoker,
    registrarOptions: [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
        'signKey' => $signingKey,
        'signedDefaultTtl' => 900,
        'signedUrlConfig' => $signedUrlConfig,
        'urlBaseUri' => $urlBaseUri,
    ],
    preGlobal: [
        GatewayHardeningMiddleware::class,
        TelemetryMiddleware::class,
        MaintenanceModeMiddleware::class,
        RequestLimitsMiddleware::class,
        NegotiationMiddleware::class,
        ResponseCacheMiddleware::class,
        CacheValidatorsMiddleware::class,
    ],
    postGlobal: [
        CompressionMiddleware::class,
        CorsAndPoliciesMiddleware::class,
        VaryAccumulatorMiddleware::class,
        ResponseLinterMiddleware::class,
    ],
    errorHandler: $errorHandler,
);

$request = Request::fromGlobals();
new DefaultEmitter()->emit($kernel->handle($request), $request);
