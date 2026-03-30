<?php

/**
 * index.php – ultra-light Webrick demo (runtime matcher switch)
 *
 * Run: php -S localhost:8000 index.php
 *
 * Matcher selection:
 * - Default matcher: `sharded`
 * - Optional runtime override via HTTP header (default: `X-Webrick-Matcher`)
 * - Supported values: `sharded`, `fused`, `generated`
 *
 * Optional header-key protection:
 * - If `WEBRICK_MATCHER_KEY` is set, request must also send
 *   `X-Webrick-Matcher-Key: <same value>` (header name configurable).
 * - In non-prod (`APP_ENV != prod`) matcher override is always allowed.
 *
 * Environment knobs:
 * - `WEBRICK_MATCHER_DEFAULT` (default: `sharded`)
 * - `WEBRICK_MATCHER_HEADER` (default: `X-Webrick-Matcher`)
 * - `WEBRICK_MATCHER_KEY_HEADER` (default: `X-Webrick-Matcher-Key`)
 * - `WEBRICK_MATCHER_KEY` (optional shared secret for matcher override)
 */
declare(strict_types=1);

namespace {

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
    use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
    use Infocyph\Webrick\Router\Definition\Registrar;
    use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
    use Infocyph\Webrick\Router\Kernel\RouterKernel;
    use Infocyph\Webrick\Router\Matching\FusedMatcher;
    use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
    use Infocyph\Webrick\Router\Matching\MatcherInterface;
    use Infocyph\Webrick\Router\Matching\ShardedMatcher;
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
    })(
        $keyForCookie,
    );

    // 🔧 feature toggles for the three middlewares
    $enable = [
        'cookie_encryption' => true,  // set true if you actually store sensitive data in cookies
        'normalize_method' => true,  //
        'input_sanitizer' => true,  // set true if you want global scalar sanitization
    ];

    // Runtime matcher switching (header driven).
    $matcherDefault = \strtolower(
        (string)($_ENV['WEBRICK_MATCHER_DEFAULT'] ?? \getenv('WEBRICK_MATCHER_DEFAULT') ?? 'sharded'),
    );
    $matcherHeaderName = (string)($_ENV['WEBRICK_MATCHER_HEADER'] ?? \getenv('WEBRICK_MATCHER_HEADER') ?? 'X-Webrick-Matcher');
    $matcherKeyHeaderName = (string)($_ENV['WEBRICK_MATCHER_KEY_HEADER'] ?? \getenv('WEBRICK_MATCHER_KEY_HEADER') ?? 'X-Webrick-Matcher-Key');
    $matcherKey = (string)($_ENV['WEBRICK_MATCHER_KEY'] ?? \getenv('WEBRICK_MATCHER_KEY') ?? '');
    $allowedMatchers = ['sharded', 'fused', 'generated'];
    if (!\in_array($matcherDefault, $allowedMatchers, true)) {
        $matcherDefault = 'sharded';
    }

    $readHeader = static function (string $header): ?string {
        $serverKey = 'HTTP_' . \str_replace('-', '_', \strtoupper(\trim($header)));
        if (!isset($_SERVER[$serverKey])) {
            return null;
        }
        $v = \trim((string)$_SERVER[$serverKey]);
        return $v === '' ? null : $v;
    };

    $requestedMatcher = \strtolower((string)($readHeader($matcherHeaderName) ?? ''));
    $providedKey = (string)($readHeader($matcherKeyHeaderName) ?? '');
    $overrideAllowed = $requestedMatcher !== ''
        && (
            $dev
            || $matcherKey === ''
            || \hash_equals($matcherKey, $providedKey)
        );
    $selectedMatcher = ($overrideAllowed && \in_array($requestedMatcher, $allowedMatchers, true))
        ? $requestedMatcher
        : $matcherDefault;

    /** @var MatcherInterface $matcher */
    $matcher = match ($selectedMatcher) {
        'fused' => FusedMatcher::make(),
        'generated' => GeneratedMatcher::make(),
        default => ShardedMatcher::make(),
    };

    $routeCachePath = match ($selectedMatcher) {
        'fused' => __DIR__ . '/.route-cache/__routes.php',
        'generated' => __DIR__ . '/.route-cache/__generated.php',
        default => __DIR__ . '/.route-cache',
    };

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
    //    $register = static function (Registrar $registrar): void {
    //        require_once __DIR__ . '/routes.php';
    //    };
    $register = static function (Registrar $registrar): void {
        require_once __DIR__ . '/routes.php';
        AttributeRouteLoader::registerFromDirs(
            $registrar,
            ['Infocyph\\Webrick\\Tests\\Fixture\\' => __DIR__.'/tests/Fixture'],
            //            AttributeRouteLoader::controllerFileFilter()   // ← scans only *Controller.php
        );
    };

    /* --------------------------------------------------------------------------
     * 3) Boot the router kernel (header-selectable matcher backend)
     * ----------------------------------------------------------------------- */
    $kernel = RouterKernel::bootWithRegistrar(
        log: $logger,
        matcher: $matcher,
        register: $register,
        routeCache: $routeCachePath,
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

    /* --------------------------------------------------------------------------
     * 4) Handle & emit
     * ----------------------------------------------------------------------- */
    new AutoEmitter()->emit($kernel->handle());
}
