<?php

/**
 * index.php – ultra-light Webrick demo
 * Run: php -S localhost:8000 index.php
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\InputSanitizerMiddleware;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\NormalizeMethodMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

final readonly class DemoController
{
    public function hello(Request $request, string $name): Response
    {
        return Response::json([
            'handler' => 'DemoController::hello',
            'prefers' => $request->prefers(['application/json', '+json', 'text/plain']),
            'hello' => $name,
            'request' => $request->all(),
            'server' => $request->server(),
            'time' => \date(DATE_ATOM),
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

    public function store(Request $r): Response
    {
        return Response::json(['action' => 'store', 'data' => $r->all()], 201);
    }

    public function update(Request $r, string $id): Response
    {
        return Response::json(['action' => 'update', 'id' => $id, 'data' => $r->all()]);
    }
}

/* --------------------------------------------------------------------------
 * 1) App config
 * ----------------------------------------------------------------------- */
$logger = new NullLogger();
$env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'prod';
$dev = ($env !== 'prod');
$signUrlSecret = 'hog';
$keyForCookie = 'tvcYp7XwEZaqpSItOyDgKql/xgqONToDogJ0Psxk/Lc=';
$keyForCookie = (static function (string $k): string {
    $k = trim($k);
    if ($k === '') {
        throw new RuntimeException('COOKIE_KEY missing. Provide a 32-byte key (raw/base64/hex).');
    }

    return match (true) {
        // base64 (commonly 44 chars incl. == padding)
        preg_match('#^[A-Za-z0-9+/]{43}=#', $k) === 1
        || preg_match('#^[A-Za-z0-9+/]{44}$#', $k) === 1
        => base64_decode($k, true) ?: '',

        // hex → raw
        ctype_xdigit($k) && strlen($k) === 64
        => hex2bin($k) ?: '',

        // length match
        strlen($k) === 32
        => $k,

        // raw (binary-safe envs)
        default => throw new RuntimeException('Invalid COOKIE_KEY: must decode to exactly 32 bytes.'),
    };
})($keyForCookie);

// 🔧 feature toggles for the three middlewares
$enable = [
    'cookie_encryption' => true,  // set true if you actually store sensitive data in cookies
    'normalize_method' => true,  //
    'input_sanitizer' => true,  // set true if you want global scalar sanitization
];

/* --------------------------------------------------------------------------
 * Middleware aliases (string-based), e.g. 'throttle:60,60'
 * ----------------------------------------------------------------------- */
// throttle:<max>,<perSeconds>
MiddlewareAliases::register(
    'throttle',
    static fn (...$p) => new ThrottleMiddleware((int)($p[0] ?? 60), (int)($p[1] ?? 60)),
);
MiddlewareAliases::register('verifySignedUrl', static function () use ($signUrlSecret) {
    return new VerifySignedUrlMiddleware($signUrlSecret, 5);
});

/* Pre-route (global) middleware – order matters */
$preGlobal = array_filter([
    GatewayHardeningMiddleware::class,
    TelemetryMiddleware::class,
    MaintenanceModeMiddleware::class,
    RequestLimitsMiddleware::class,
    ThrottleMiddleware::class,
    $enable['cookie_encryption'] ? new CookieEncryptionMiddleware($keyForCookie) : null,
    $enable['normalize_method'] ? NormalizeMethodMiddleware::class : null,
    $enable['input_sanitizer'] ? InputSanitizerMiddleware::class : null,
    NegotiationMiddleware::class,
    ResponseCacheMiddleware::class,
    CacheValidatorsMiddleware::class,
]);

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
$register = static function (Registrar $registrar): void {
    require_once __DIR__ . '/routes.php';
};

/* --------------------------------------------------------------------------
 * 3) Boot the router kernel (Option B)
 * ----------------------------------------------------------------------- */

// A) ShardedMatcher (segment-dir cache)
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/.route-cache',
    registrarOptions: [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
        'signKey' => $signUrlSecret,
        'signedDefaultTtl' => 900,
    ],
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
    bindUrlServices: static function (Collection $routes) use ($signUrlSecret): void {
        Response::bindUrlServices($routes, $signUrlSecret, 900);
    },
    // leave true while validating your cache’s __aliases.php
    fallbackAliasesFromRegistrar: true,
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
//     bindUrlServices: static function (Collection $routes) use ($signUrlSecret): void {
//         Response::bindUrlServices($routes, $signUrlSecret, 900);
//     },
//     fallbackAliasesFromRegistrar: true
// );

/* --------------------------------------------------------------------------
 * 4) Handle & emit
 * ----------------------------------------------------------------------- */
new AutoEmitter()->emit($kernel->handle());
