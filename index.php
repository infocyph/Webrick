<?php

/**
 * index.php – ultra-light Webrick demo
 * Run: php -S localhost:8000 index.php
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
use Infocyph\Webrick\Middleware\ErrorHandlerMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\SapiEmitter;
use Infocyph\Webrick\Response\Payloads\HtmlResponse;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

final readonly class DemoController
{
    public function hello(Request $request): Response
    {
        $name = $request->getAttribute('route_params')['name'] ?? 'World';

        return Response::json([
            'handler' => 'DemoController::hello',
            'hello' => $name,
            'request' => $request->all(),
            'time' => \date(DATE_ATOM),
        ]);
    }
}

/* --------------------------------------------------------------------------
 * 1.  Build the (runtime) route table
 * ----------------------------------------------------------------------- */
$routes = new Collection();
$registrar = new Registrar($routes, autoSlashRedirect: true);

/* ---- demo routes (existing + a few extras) ---------------------------- */
$registrar->get('/', function (): HtmlResponse {
    $links = [
        '/ping' => 'Static text',
        '/hello/Alice' => 'Dynamic placeholder',
        '/json' => 'JSON payload',
        '/download' => 'Download (attachment)',
        '/redirect' => 'Redirect 302 → /',
        '/color/ff00ff' => 'Regex-constrained placeholder',
        '/class/Bob' => '🆕 Class-based handler',

        // Newer ones
        '/post/echo' => 'POST echo',
        '/user/42 (PUT)' => 'Update user (PUT)',
        '/stream' => 'Streaming response',
        '/locale' => 'Show negotiated locale',
        '/xml' => 'XML payload (charset-aware)',
        '/status/418' => 'Status echo (I’m a teapot)',
        '/json/slow' => 'Lazy JSON via callable',
    ];

    $html = "<h1>Webrick demo</h1><ul>";
    foreach ($links as $href => $title) {
        $html .= "<li><a href=\"{$href}\">{$title}</a></li>";
    }
    $html .= '</ul>';

    return new HtmlResponse($html);
});

$registrar->get('/ping', fn() => 'pong');

$registrar->get(
    '/hello/{name}',
    fn(Request $r)
        => Response::json(['hello' => $r->getAttribute('route_params')['name'] ?? 'stranger']),
);

$registrar->get('/json', fn() => Response::json(['memory' => memory_get_usage(true)]));
$registrar->get('/redirect', fn() => Response::redirect('/', 302));
$registrar->get('/download', fn() => Response::attachment(__FILE__, 'index.php'));
$registrar->get(
    '/color/{hex:hex}',
    fn(Request $r)
        => Response::json(['you sent hex' => $r->getAttribute('route_params')['hex']]),
);

/* ---- class-based routes (existing) ------------------------------------ */
$registrar->get('/class/test/{name}', [DemoController::class, 'hello']);
$registrar->get('/class/rest/{name}', [DemoController::class, 'hello']);
$registrar->get('/plus/{name}/mine', [DemoController::class, 'hello']);

/* ---- extra variety routes -------------------------------------------- */
$registrar->post('/post/echo', function (Request $r): Response {
    return Response::json([
        'method' => $r->getMethod(),
        'payload' => $r->all(),
        'time' => \date(DATE_ATOM),
    ]);
});

$registrar->put('/user/{id}', function (Request $r): Response {
    $id = $r->getAttribute('route_params')['id'] ?? null;
    return Response::json([
        'updated' => $id,
        'input' => $r->all(),
    ]);
});

$registrar->get('/stream', function (): Response {
    return Response::stream(function () {
        for ($i = 1; $i <= 10; $i++) {
            yield "chunk {$i}\n";
            usleep(100_000);
        }
        return '';
    });
});

$registrar->get('/locale', function (Request $r): Response {
    return Response::json([
        'locale' => $r->getAttribute('locale') ?? 'unknown',
    ]);
});

$registrar->get('/xml', function (): Response {
    $xml = "<note><to>You</to><from>Me</from><msg>Hello</msg></note>";
    return Response::create($xml, 200, ['Content-Type' => 'application/xml']);
});

$registrar->get('/status/{code}', function (Request $r): Response {
    $code = (int)($r->getAttribute('route_params')['code'] ?? 200);
    return Response::plaintext("Status: {$code}", $code);
});

$registrar->get('/json/slow', function (): Response {
    return Response::json(function () {
        return [
            'now' => time(),
            'items' => array_map(fn($i) => ['n' => $i, 'v' => bin2hex(random_bytes(4))], range(1, 100)),
        ];
    });
});

/* --------------------------------------------------------------------------
 * 2.  Compiler callback
 * ----------------------------------------------------------------------- */
$compiler = static fn() => $registrar->compile()->all();

/* --------------------------------------------------------------------------
 * 3.  Boot the router kernel
 * ----------------------------------------------------------------------- */
$logger = new NullLogger();

$env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'prod';
$dev = ($env !== 'prod');

// Pre-route (global) middleware stack – order matters
$preGlobal = [
    // If any of these need DI/args, pass an INSTANCE instead of a class-string.
    GatewayHardeningMiddleware::class,
    new ErrorHandlerMiddleware(
        logger: $logger,
        debug: $dev,                          // show traces in non-prod
        capturePhpErrors: true,               // convert warnings/notices to exceptions
        requestIdHeader: 'X-Request-Id',
        exceptionMap: [
            RouteNotFoundException::class => 404,
            MethodNotAllowedException::class => 405,
        ],
    ),
    TelemetryMiddleware::class,
    MaintenanceModeMiddleware::class,
    new RequestLimitsMiddleware(),
    ThrottleMiddleware::class,
    new NegotiationMiddleware(),

    // CacheValidators requires a metaProvider
    new CacheValidatorsMiddleware(
        metaProvider: static function (Request $r): array {
            $path = $r->getUri()->getPath();
            $nowMtime = @filemtime(__FILE__) ?: null;

            if ($path === '/download' && is_file(__FILE__)) {
                $size = @filesize(__FILE__) ?: null;
                $mtime = @filemtime(__FILE__) ?: $nowMtime;
                $seed = ($size ?? -1) . '|' . ($mtime ?? -1) . '|index.php';
                $etag = '"' . substr(sha1($seed), 0, 16) . '"';
                return [$etag, $mtime];
            }

            $etag = '"' . substr(sha1('demo|' . $path . '|' . (string)$nowMtime), 0, 16) . '"';
            return [$etag, $nowMtime];
        },
        autoEtagWhenMissing: true,
        includeQueryInEtag: true,
        autoEtagMinSize: 0,
    ),
];

// Post-controller (global) middleware stack
$postGlobal = [
    new CompressionMiddleware(), // default: WEAK_ON_ENCODE
    new CorsAndPoliciesMiddleware(), // ← instance to avoid DI invoking __invoke
    new VaryAccumulatorMiddleware(),
];

if ($dev) {
    $postGlobal[] = new ResponseLinterMiddleware(true);
}

// A) UnifiedMatcher with segment-dir cache
$kernel = RouterKernel::boot(
    $logger,
    compiler: $compiler,
    matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
    routeCache: __DIR__ . '/.route-cache',
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
);

// B) MergedMatcher with single-file cache
// $kernel = RouterKernel::boot(
//     $logger,
//     compiler: $compiler,
//     matcher:  Infocyph\Webrick\Router\Matching\FusedMatcher::make(),
//     routeCache: __DIR__ . '/.route-cache/__routes.php',
//     preGlobal: $preGlobal,
//     postGlobal: $postGlobal,
// );

/* --------------------------------------------------------------------------
 * 4.  Handle & emit
 * ----------------------------------------------------------------------- */
$request = Request::fromGlobals();
$response = $kernel->handle($request);

new SapiEmitter()->emit($response);
