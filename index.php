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
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Payloads\HtmlResponse;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

final readonly class DemoController
{
    public function hello(Request $request, string $name): Response
    {
        return Response::json([
            'handler' => 'DemoController::hello',
            // show what client prefers among common types:
            'prefers' => $request->prefers(['application/json', '+json', 'text/plain']),
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
 * 1) App config
 * ----------------------------------------------------------------------- */
$logger = new NullLogger();
$env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'prod';
$dev = ($env !== 'prod');
$signUrlSecret = 'hog';

// toggle cache here if you like:
$routeCache = __DIR__ . '/.route-cache';            // enable (dir for Sharded)
// $routeCache = null;                               // disable cache

/* Pre-route (global) middleware – order matters */
$preGlobal = [
    GatewayHardeningMiddleware::class,
    TelemetryMiddleware::class,
    MaintenanceModeMiddleware::class,
    RequestLimitsMiddleware::class,
    //ThrottleMiddleware::class,
    NegotiationMiddleware::class,
    CacheValidatorsMiddleware::class,
];

/* Post-controller (global) middleware */
$postGlobal = [
    CompressionMiddleware::class,
    CorsAndPoliciesMiddleware::class,
    VaryAccumulatorMiddleware::class,
];
if ($dev) {
    $postGlobal[] = ResponseLinterMiddleware::class;
}

/* --------------------------------------------------------------------------
 * 2) Registration closure (executed only when cache is NOT hot)
 * ----------------------------------------------------------------------- */
$register = static function (Registrar $registrar) use ($signUrlSecret): void {
    /* ---- homepage with links ---- */
    $registrar->get('/', function (): HtmlResponse {
        $links = [
            '/ping' => 'Static text',
            '/hello/Alice' => 'Dynamic placeholder',
            '/json' => 'JSON payload (named: json)',
            '/download' => 'Download (attachment)',
            '/redirect' => 'Redirect 302 → /',
            '/color/ff00ff' => 'Regex-constrained placeholder',
            '/class/Bob' => '🆕 Class-based handler',
            // Extra
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
            '/auto-demo' => 'Auto Demo',
            '/auto-hello' => 'Auto Hello',
            '/xml-demo' => 'XML Demo',
        ];

        $html = "<h1>Webrick demo</h1><ul>";
        foreach ($links as $href => $title) {
            $html .= "<li><a href=\"{$href}\">{$title}</a></li>";
        }
        $html .= '</ul>';

        return new HtmlResponse($html);
    });

    $registrar->get('/ping', fn () => 'pong');

    $registrar->get(
        '/hello/{name}',
        fn (Request $r, $name) => Response::json(['hello' => $name]),
    );

    $registrar->get('/json', fn () => Response::json(['memory' => memory_get_usage(true)]), 'json');
    $registrar->get('/redirect', fn () => Response::redirect('/', 302));
    $registrar->get('/download', fn () => Response::attachment(__FILE__, 'index.php'));

    $registrar->get(
        '/color/{color:hex}',
        fn (Request $r, $hex) => Response::json(['you sent hex' => $hex]),
    );

    /* ---- class-based routes ---- */
    $registrar->get('/class/test/{name}', [DemoController::class, 'hello']);
    $registrar->get('/class/rest/{name}', [DemoController::class, 'hello']);
    $registrar->get('/plus/{name}/mine', [DemoController::class, 'hello']);

    /* ---- extra variety routes ---- */
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

    // convenience alias to /xml
    $registrar->get('/xml-demo', fn ()
        => Response::create(
        "<note><to>You</to><from>Me</from><msg>Hello</msg></note>",
        200,
        ['Content-Type' => 'application/xml'],
    ));

    $registrar->get('/status/{code}', function (Request $r, $code): Response {
        return Response::plaintext("Status: $code", (int)$code);
    });

    $registrar->get('/json/slow', function (): Response {
        return Response::json(function () {
            return [
                'now' => time(),
                'items' => array_map(fn ($i) => ['n' => $i, 'v' => bin2hex(random_bytes(4))], range(1, 100)),
            ];
        });
    });

    /* ---- resource routes (Laravel-ish) ---- */
    // Produces: users.index, users.create, users.store, users.show, users.edit, users.update, users.destroy
    $registrar->resource('users', '/users', UsersController::class);

    /* ---- redirects using aliases ---- */
    $registrar->get('/to-json', fn () => Response::redirect(Response::urlFor('json'), 302));

    $registrar->get(
        '/to-user-42',
        fn () => Response::redirect(Response::urlFor('users.show', ['id' => 42], absolute: true), 302),
    );

    $registrar->get('/signed-demo', fn () => Response::json([
        'rel' => Response::signedUrlFor('users.show', ['id' => 42]),
        'abs' => Response::signedUrlFor('users.show', ['id' => 42], absolute: true),
    ]));

    // 1) Generate a signed URL (relative) and redirect to it
    $registrar->get('/make-signed/{id:int}', function ($id) {
        $signed = Response::temporaryUrlFor(
            'secure.show',         // name below
            ['id' => $id],
            ['dl' => 1],           // extra query included in signature
            false,                 // relative (recommended)
        );
        return Response::redirect($signed, 302);
    }, 'make.signed');

    // 2) Protected endpoint (verified by middleware)
    $registrar->get('/secure/{id:int}', function (Request $r, $id) {
        return Response::json([
            'ok' => true,
            'id' => $id,
            'qs' => $r->getQueryParams(),
            'time' => \date(DATE_ATOM),
        ]);
    }, [
        'as' => 'secure.show',
        'middleware' => [
            new VerifySignedUrlMiddleware(
                $signUrlSecret,
                leeway: 5,
            ),
        ],
    ]);

    $registrar->get('/auto-demo', function (Request $r) {
        $data = ['now' => time(), 'msg' => 'hello'];
        return Response::auto($r, $data);
    });

    $registrar->get('/auto-hello', function (Request $r) {
        return Response::auto($r, 'Hello world!');
    });
};

/* --------------------------------------------------------------------------
 * 3) Boot the router kernel (Option B)
 * ----------------------------------------------------------------------- */

// A) ShardedMatcher (segment-dir cache)
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
    register: $register,                          // ← 3rd arg
    routeCache: $routeCache,                      // set null to disable cache
    registrarOptions: [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
        'signKey' => $signUrlSecret,
        'signedDefaultTtl' => 900,
    ],
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
    invokerOnMiddleware: false,
    errorHandler: null,
    bindUrlServices: static function (Collection $routes) use ($signUrlSecret): void {
        // Called in both modes; in hot-cache we pass the alias-only Collection
        Response::bindUrlServices($routes, $signUrlSecret, 900);
    },
    // while you’re validating your cache’s __aliases.php, leave this true;
    // once the alias file is produced reliably, set to false to never run registration in hot mode:
    fallbackAliasesFromRegistrar: true
);

// B) FusedMatcher (single-file cache)
// $kernel = RouterKernel::bootWithRegistrar(
//     log: $logger,
//     matcher: Infocyph\Webrick\Router\Matching\FusedMatcher::make(),
//     register: $register,
//     routeCache: __DIR__ . '/.route-cache/__routes.php',
//     registrarOptions: [
//         'autoSlashRedirect' => false,
//         'exposeUrlServices' => true,
//         'signKey'           => $signUrlSecret,
//         'signedDefaultTtl'  => 900,
//     ],
//     preGlobal: $preGlobal,
//     postGlobal: $postGlobal,
//     invokerOnMiddleware: false,
//     errorHandler: null,
//     bindUrlServices: static function (Collection $routes) use ($signUrlSecret): void {
//         Response::bindUrlServices($routes, $signUrlSecret, 900);
//     },
//     fallbackAliasesFromRegistrar: true
// );

/* --------------------------------------------------------------------------
 * 4) Handle & emit
 * ----------------------------------------------------------------------- */
$request = Request::fromGlobals();
$response = $kernel->handle($request);
new AutoEmitter()->emit($response);
