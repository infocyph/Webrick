<?php

/**
 * index.php – ultra-light Webrick demo
 * Run: php -S localhost:8000 index.php
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Payloads\HtmlResponse;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\UrlGenerator;

// ← for alias-based redirects
use Psr\Log\NullLogger;

final readonly class DemoController
{
    public function hello(Request $request, string $name): Response
    {
        return Response::json([
            'handler' => 'DemoController::hello',
            'hello' => $name,
            'request' => $request->all(),
            'algos' => hash_algos(),
            'time' => \date(DATE_ATOM),
        ]);
    }
}

final readonly class UsersController
{
    public function index(): Response
    {
        return Response::json(['action' => 'index']);
    }

    public function create(): Response
    {
        return Response::json(['action' => 'create']);
    }

    public function store(Request $r): Response
    {
        return Response::json(['action' => 'store', 'data' => $r->all()], 201);
    }

    public function show(string $id): Response
    {
        return Response::json(['action' => 'show', 'id' => $id]);
    }

    public function edit(string $id): Response
    {
        return Response::json(['action' => 'edit', 'id' => $id]);
    }

    public function update(Request $r, string $id): Response
    {
        return Response::json(['action' => 'update', 'id' => $id, 'data' => $r->all()]);
    }

    public function destroy(string $id): Response
    {
        return Response::json(['action' => 'destroy', 'id' => $id]);
    }
}

/* --------------------------------------------------------------------------
 * 1.  Build the (runtime) route table
 * ----------------------------------------------------------------------- */
$routes = new Collection();
$registrar = new Registrar(
    routes: $routes,
    autoSlashRedirect: false,
    exposeUrlServices: true,
    signKey: 'hog',    // optional
    signedDefaultTtl: 900,                      // optional
);

/* ---- demo routes (existing + a few extras) ---------------------------- */
$registrar->get('/', function (): HtmlResponse {
    $links = [
        '/ping' => 'Static text',
        '/hello/Alice' => 'Dynamic placeholder',
        '/json' => 'JSON payload (named: json)',
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

        // Resource & alias-redirect demos
        '/users' => 'Resource: users.index',
        '/users/create' => 'Resource: users.create',
        '/users/42' => 'Resource: users.show',
        '/users/42/edit' => 'Resource: users.edit',
        '/to-json' => 'Redirect to route alias: json',
        '/to-user-42' => 'Redirect to route alias: users.show (id=42)',
        '/signed-demo' => 'Signed Demo',
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
    fn(Request $r, $name)
        => Response::json(['hello' => $name]),
);

$registrar->get('/json', fn() => Response::json(['memory' => memory_get_usage(true)]), 'json');
$registrar->get('/redirect', fn() => Response::redirect('/', 302));
$registrar->get('/download', fn() => Response::attachment(__FILE__, 'index.php'));
$registrar->get(
    '/color/{color:hex}',
    fn(Request $r, $hex)
        => Response::json(['you sent hex' => $hex]),
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

$registrar->put('/user/{id:int}', function (Request $r, $id): Response {
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

$registrar->get('/status/{code}', function (Request $r, $code): Response {
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

/* ---- resource routes (Laravel-ish) ----------------------------------- */
// Names produced: users.index, users.create, users.store, users.show, users.edit, users.update, users.destroy
$registrar->resource('users', '/users', UsersController::class);

/* ---- redirects using aliases ----------------------------------------- */
$registrar->get('/to-json', fn()
    => Response::redirect(Response::urlFor('json'), 302),
);

$registrar->get('/to-user-42', fn()
    => Response::redirect(Response::urlFor('users.show', ['id' => 42], absolute: true), 302),
);

$registrar->get('/signed-demo', fn()
    => Response::json([
    'rel' => Response::signedUrlFor('users.show', ['id' => 42]),
    'abs' => Response::signedUrlFor('users.show', ['id' => 42], absolute: true),
]),
);


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
    GatewayHardeningMiddleware::class,
    TelemetryMiddleware::class,
    MaintenanceModeMiddleware::class,
    RequestLimitsMiddleware::class,
//    ThrottleMiddleware::class,
    NegotiationMiddleware::class,
    CacheValidatorsMiddleware::class,
];

// Post-controller (global) middleware stack
$postGlobal = [
    CompressionMiddleware::class,
    CorsAndPoliciesMiddleware::class,
    VaryAccumulatorMiddleware::class,
];

if ($dev) {
    $postGlobal[] = ResponseLinterMiddleware::class;
}

// A) ShardedMatcher (segment-dir cache)
//$kernel = RouterKernel::boot(
//    $logger,
//    compiler: $compiler,
//    matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
//    routeCache: __DIR__ . '/.route-cache',
//    preGlobal: $preGlobal,
//    postGlobal: $postGlobal,
//);

// B) FusedMatcher (single-file cache)
$kernel = RouterKernel::boot(
    $logger,
    compiler: $compiler,
    matcher:  Infocyph\Webrick\Router\Matching\FusedMatcher::make(),
    routeCache: __DIR__ . '/.route-cache/__routes.php',
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
);

/* --------------------------------------------------------------------------
 * 4.  Handle & emit
 * ----------------------------------------------------------------------- */
$request = Request::fromGlobals();
$response = $kernel->handle($request);

new AutoEmitter()->emit($response);
