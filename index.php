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
 *
 * Generate Route Cache (after clearing):
 * ./webrick route:clear --matcher=sharded   --cache=.route-cache --aggressive=1
 * ./webrick route:clear --matcher=fused     --cache=.route-cache/__routes.php
 * ./webrick route:clear --matcher=generated --cache=.route-cache/__generated.php
 *
 * ./webrick route:cache --matcher=sharded   --cache=.route-cache --routes=routes.php
 * ./webrick route:cache --matcher=fused     --cache=.route-cache/__routes.php --routes=routes.php
 * ./webrick route:cache --matcher=generated --cache=.route-cache/__generated.php --routes=routes.php
 */
declare(strict_types=1);

namespace {
    $_ENV['WEBRICK_MATCHER_DEFAULT'] = 'sharded'; // sharded/fused/generated

    require __DIR__ . '/vendor/autoload.php';

    use Infocyph\Webrick\Constants\MatcherModeEnum;
    use Infocyph\Webrick\Constants\MediaTypeEnum;
    use Infocyph\Webrick\Constants\StatusEnum;
    use Infocyph\Webrick\Exceptions\HttpExceptionInterface;
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
    use Infocyph\Webrick\Router\Facade\Router as Route;
    use Infocyph\Webrick\Router\Kernel\ErrorHandler;
    use Infocyph\Webrick\Router\Kernel\RouterKernel;
    use Infocyph\Webrick\Router\Matching\FusedMatcher;
    use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
    use Infocyph\Webrick\Router\Matching\ShardedMatcher;
    use Infocyph\Webrick\Router\Url\SignedUrlConfig;
    use Psr\Log\NullLogger;

    final readonly class DemoController
    {
        public function hello(Request $request, string $name): Response
        {
            return Response::json([
                'handler' => 'DemoController::hello',
                'prefers' => $request->prefers([MediaTypeEnum::JSON->base(), '+json', MediaTypeEnum::PLAIN->base()]),
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
            return Response::json(['action' => 'store', 'data' => $r->all()], StatusEnum::CREATED->value);
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
    $envRaw = \getenv('APP_ENV');
    $env = (\is_string($envRaw) && $envRaw !== '') ? $envRaw : 'prod';
    $dev = ($env !== 'prod');
    $matcherDefaultEnvRaw = \getenv('WEBRICK_MATCHER_DEFAULT');
    $matcherDefaultEnv = (\is_string($matcherDefaultEnvRaw) && $matcherDefaultEnvRaw !== '')
        ? $matcherDefaultEnvRaw
        : MatcherModeEnum::SHARDED->value;
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
            || preg_match('#^[A-Za-z0-9+/]{44}$#', $k) === 1 => base64_decode($k, true) ?: '',

            // hex → raw
            ctype_xdigit($k) && strlen($k) === 64 => hex2bin($k) ?: '',

            // length match
            strlen($k) === 32 => $k,

            // raw (binary-safe envs)
            default => throw new RuntimeException('Invalid COOKIE_KEY: must decode to exactly 32 bytes.'),
        };
    })(
        $keyForCookie,
    );

    // 🔧 feature toggles for the three middlewares
    $envBool = static function (string $key, bool $default): bool {
        $raw = \getenv($key);
        if (!\is_string($raw) || $raw === '') {
            return $default;
        }

        $parsed = \filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    };
    $enable = [
        'cookie_encryption' => $envBool('WEBRICK_ENABLE_COOKIE_ENCRYPTION', true),
        'normalize_method' => $envBool('WEBRICK_ENABLE_NORMALIZE_METHOD', true),
        'input_sanitizer' => $envBool('WEBRICK_ENABLE_INPUT_SANITIZER', true),
    ];

    // Runtime matcher switching (header driven).
    $matcherDefault = \strtolower(
        $matcherDefaultEnv,
    );
    $matcherHeaderRaw = \getenv('WEBRICK_MATCHER_HEADER');
    $matcherHeaderName = (\is_string($matcherHeaderRaw) && $matcherHeaderRaw !== '')
        ? $matcherHeaderRaw
        : 'X-Webrick-Matcher';
    $matcherKeyHeaderRaw = \getenv('WEBRICK_MATCHER_KEY_HEADER');
    $matcherKeyHeaderName = (\is_string($matcherKeyHeaderRaw) && $matcherKeyHeaderRaw !== '')
        ? $matcherKeyHeaderRaw
        : 'X-Webrick-Matcher-Key';
    $matcherKeyRaw = \getenv('WEBRICK_MATCHER_KEY');
    $matcherKey = \is_string($matcherKeyRaw) ? $matcherKeyRaw : '';
    $allowedMatchers = MatcherModeEnum::values();
    if (!\in_array($matcherDefault, $allowedMatchers, true)) {
        $matcherDefault = MatcherModeEnum::SHARDED->value;
    }

    $readHeader = static function (string $header): ?string {
        $serverKey = 'HTTP_' . \str_replace('-', '_', \strtoupper(\trim($header)));
        if (!isset($_SERVER[$serverKey])) {
            return null;
        }
        $raw = $_SERVER[$serverKey];
        if (\is_array($raw)) {
            return null;
        }
        if (!\is_scalar($raw) && !($raw instanceof Stringable)) {
            return null;
        }

        $v = \trim((string) $raw);

        return $v === '' ? null : $v;
    };

    $requestedMatcher = \strtolower($readHeader($matcherHeaderName) ?? '');
    $providedKey = $readHeader($matcherKeyHeaderName) ?? '';
    $overrideAllowed = $requestedMatcher !== ''
        && (
            $dev
            || $matcherKey === ''
            || \hash_equals($matcherKey, $providedKey)
        );
    $selectedMatcher = ($overrideAllowed && \in_array($requestedMatcher, $allowedMatchers, true))
        ? $requestedMatcher
        : $matcherDefault;

    $matcher = match ($selectedMatcher) {
        MatcherModeEnum::FUSED->value => FusedMatcher::make(),
        MatcherModeEnum::GENERATED->value => GeneratedMatcher::make(),
        default => ShardedMatcher::make(),
    };

    $routeCachePath = match ($selectedMatcher) {
        MatcherModeEnum::FUSED->value => __DIR__ . '/.route-cache/__routes.php',
        MatcherModeEnum::GENERATED->value => __DIR__ . '/.route-cache/__generated.php',
        default => __DIR__ . '/.route-cache',
    };

    /* --------------------------------------------------------------------------
     * Middleware aliases (string-based), e.g. 'throttle:60,60'
     * ----------------------------------------------------------------------- */
    // throttle:<max>,<perSeconds>
    MiddlewareAliases::register(
        'throttle',
        static function (...$p): ThrottleMiddleware {
            $maxRaw = $p[0] ?? 60;
            $windowRaw = $p[1] ?? 60;

            $max = \is_numeric($maxRaw) ? (int) $maxRaw : 60;
            $window = \is_numeric($windowRaw) ? (int) $windowRaw : 60;

            return new ThrottleMiddleware($max, $window);
        },
    );
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
    MiddlewareAliases::register('verifySignedUrl', static fn() => new VerifySignedUrlMiddleware($signUrlSecret, 5));
    MiddlewareAliases::register(
        'verifySignedUrlAbsolute',
        static fn() => new VerifySignedUrlMiddleware($signedAbsoluteUrlConfig),
    );
    $urlBaseUri = getenv('WEBRICK_URL_BASE_URI') ?: 'http://localhost';
    $errorHandler = new ErrorHandler(
        logger: $logger,
        debug: $dev,
        capturePhpErrors: true,
        requestIdHeader: 'X-Request-Id',
        responseRenderer: static function (Request $request, \Throwable $e, int $status, array $headers): ?Response {
            if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
                return null;
            }

            $message = $e instanceof HttpExceptionInterface ? $e->getPublicMessage() : 'HTTP Error';

            return Response::json([
                'error' => $message,
                'status' => $status,
                'path' => $request->getUri()->getPath(),
            ], $status, $headers);
        },
    );

    /* Pre-route (global) middleware – order matters */
    $preGlobal = [
        GatewayHardeningMiddleware::class,
        TelemetryMiddleware::class,
        MaintenanceModeMiddleware::class,
        RequestLimitsMiddleware::class,
        NegotiationMiddleware::class,
        ResponseCacheMiddleware::class,
        CacheValidatorsMiddleware::class,
    ];
    if ($enable['cookie_encryption']) {
        $preGlobal[] = new CookieEncryptionMiddleware($keyForCookie);
    }
    if ($enable['normalize_method']) {
        $preGlobal[] = NormalizeMethodMiddleware::class;
    }
    if ($enable['input_sanitizer']) {
        $preGlobal[] = InputSanitizerMiddleware::class;
    }

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
    //        require __DIR__ . '/routes.php';
    //    };
    $register = static function (Registrar $registrar): void {
        // Registration may run more than once in a persistent worker when a
        // matcher cache is missing or refreshed. The route file is executable
        // registration input, so it must not be suppressed by require_once.
        require __DIR__ . '/routes.php';
        $fixtureDirs = [
            __DIR__ . '/tests/Fixture',
            __DIR__ . '/tests/Fixtures',
        ];

        foreach ($fixtureDirs as $fixtureDir) {
            if (!is_dir($fixtureDir)) {
                continue;
            }

            AttributeRouteLoader::registerFromDirs(
                $registrar,
                ['Infocyph\\Webrick\\Tests\\Fixture\\' => $fixtureDir],
                //            AttributeRouteLoader::controllerFileFilter()   // ← scans only *Controller.php
            );
        }
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
            'signedUrlConfig' => $signedUrlConfig,
            'urlBaseUri' => $urlBaseUri,
        ],
        // URL signing is bound from the options above. On a hot matcher cache,
        // RouterKernel keeps the alias table lazy until a URL helper is called.
        preGlobal: $preGlobal,
        postGlobal: $postGlobal,
        errorHandler: $errorHandler,
        // leave true while validating your cache’s __aliases.php
        fallbackAliasesFromRegistrar: true,
    );

    /* --------------------------------------------------------------------------
     * 4) Handle & emit
     * ----------------------------------------------------------------------- */
    new AutoEmitter()->emit($kernel->handle());
}
