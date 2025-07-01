<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
|  WEBRICK 1-FILE SANDBOX
|  • Intermix DI container
|  • Response macros
|  • Global & group middleware (registry API)
|  • Named routes, typed params, groups
|  • Automatic CLI / SAPI emitter
|--------------------------------------------------------------------------
*/

require __DIR__ . '/vendor/autoload.php';

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\{ServerRequest, Request};
use Infocyph\Webrick\Response\Emitter\{CliEmitter, SapiEmitter};
use Infocyph\Webrick\Response\Factory\{ResponseFactory, StreamFactory, UploadedFileFactory};
use Infocyph\Webrick\Response\Macros\ResponseMacros;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router_OLD\Router;
use Infocyph\Webrick\Middleware\{
    CompressionMiddleware,
    ConditionalMiddleware,
    ContentSecurityPolicyMiddleware,
    ConvertEmptyStringsToNullMiddleware,
    CorsMiddleware,
    CsrfMiddleware,
    ErrorHandlerMiddleware,
    ETagMiddleware,
    HttpsEnforceMiddleware,
    LocaleNegotiationMiddleware,
    MaintenanceModeMiddleware,
    MethodOverrideMiddleware,
    RequestLoggingMiddleware,
    ResponseTimeMiddleware,
    ThrottleMiddleware,
    TrimStringsMiddleware,
    TrustProxiesMiddleware,
    ValidatePostSizeMiddleware
};
use Psr\Http\Message\{
    ResponseFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface
};
use Psr\Log\{LoggerInterface, NullLogger};

/*───────────────────────────────────────────────────────────────────────────
 | 1)  DI container + PSR-17 factories
 ───────────────────────────────────────────────────────────────────────────*/
$container = Container::instance('intermix');

$container->definitions()
    ->bind(ResponseFactoryInterface::class, new ResponseFactory())
    ->bind(StreamFactoryInterface::class, new StreamFactory())
    ->bind(UploadedFileFactoryInterface::class, new UploadedFileFactory())
    ->bind(LoggerInterface::class, new NullLogger())
    ->bind(Invoker::class, Invoker::with($container));

/*───────────────────────────────────────────────────────────────────────────
 | 2)  Response macros (json(), redirect(), attachment() …)
 ───────────────────────────────────────────────────────────────────────────*/
ResponseMacros::boot();

/*───────────────────────────────────────────────────────────────────────────
 | 3)  Boot router in DEV mode
 ───────────────────────────────────────────────────────────────────────────*/
$router = Router::bootDev($container);

/*───────────────────────────────────────────────────────────────────────────
 | 4)  Middleware registry + global stack
 ───────────────────────────────────────────────────────────────────────────*/
$router
    ->alias([
        'trim'     => TrimStringsMiddleware::class,
        'csrf'     => CsrfMiddleware::class,
        'csp'      => ContentSecurityPolicyMiddleware::class,
        'cors'     => CorsMiddleware::class,
        'throttle' => ThrottleMiddleware::class,
    ])
    ->globalMiddleware(
        new ErrorHandlerMiddleware(devMode: true),
        new MaintenanceModeMiddleware(__DIR__ . '/storage/down'),
        new TrustProxiesMiddleware(
            allow: ['10.0.0.0/8', '192.168.0.0/16'],
            deny : ['203.0.113.0/24']
        ),
        new HttpsEnforceMiddleware(productionMode: true),
        new ValidatePostSizeMiddleware(),
        'trim',
        new ConvertEmptyStringsToNullMiddleware(),
        new MethodOverrideMiddleware(),
        new RequestLoggingMiddleware($container->get(LoggerInterface::class)),
        new ResponseTimeMiddleware(),
        new CompressionMiddleware(),
        'csp',
        new ETagMiddleware()
    );

/*───────────────────────────────────────────────────────────────────────────
 | 5)  Routes (feature showcase)
 ───────────────────────────────────────────────────────────────────────────*/

/* 5-a) Simple ping */
$router->get('/ping', fn () => Response::json(['pong' => true]))
    ->withName('ping');

/* 5-b) Typed placeholder */
$router->get(
    '/users/{id:int}',
    fn (Request $r, int $id) => Response::json(['user' => $id])
)->withName('users.show');

/* 5-c) CSRF-protected POST */
$router->post(
    '/profile/avatar',
    fn () => Response::json(['uploaded' => true], 201)
)->withMiddleware(['csrf'])
    ->withName('profile.avatar');

/* 5-d) Conditional download */
$filePath    = __DIR__ . '/.gitattributes';
$conditional = static fn () => ['"' . md5_file($filePath) . '"', filemtime($filePath) ?: null];

$router->get(
    '/download/large.zip',
    fn () => Response::attachment($filePath, 'large.zip', 'application/zip')
)->withMiddleware([new ConditionalMiddleware($conditional)]);

/* 5-e) Locale negotiation */
$router->get(
    '/welcome',
    fn (Request $r) => Response::json(['msg' => 'hello (' . $r->getAttribute('locale') . ')'])
)->withMiddleware([new LocaleNegotiationMiddleware(['en', 'fr', 'es'], 'en')]);

/* 5-f) API group with CORS + throttle */
$router->group('/api', function (Router $api) {

    $apiMw = ['cors', new ThrottleMiddleware(max: 100, window: 60)];

    $api->get('/status', fn () => Response::json(['status' => 'ok']))
        ->withMiddleware($apiMw)
        ->withName('api.status');

    $api->post('/echo', fn (Request $r) => Response::json($r->all()))
        ->withMiddleware($apiMw);

    /* nested v1 group  */
    $api->group('/v1', function (Router $v1) use ($apiMw) {
        $v1->get('/info', fn () => Response::json(['version' => '1.0']))
            ->withMiddleware($apiMw)
            ->withName('api.v1.info');
    });
});

/*───────────────────────────────────────────────────────────────────────────
 | 6)  Dispatch current request
 ───────────────────────────────────────────────────────────────────────────*/
$request  = ServerRequest::createFromGlobals();
$response = $router->handle($request);

/*───────────────────────────────────────────────────────────────────────────
 | 7)  Emit (CLI or web SAPI)
 ───────────────────────────────────────────────────────────────────────────*/
$emitter = PHP_SAPI === 'cli' ? new CliEmitter() : new SapiEmitter();
$emitter->emit($response);
