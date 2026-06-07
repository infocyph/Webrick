<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks\Support;

use DateTimeImmutable;
use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use RuntimeException;

final class BenchmarkSupport
{
    private const string BASE_URI = 'http://localhost';

    private const string SIGN_KEY = 'bench-sign-key';

    private static ?UrlGenerator $absoluteSignedGenerator = null;

    private static ?string $absoluteSignedUrl = null;

    private static ?VerifySignedUrlMiddleware $absoluteVerifier = null;

    /** @var array<string, list<CompiledRoute>> */
    private static array $compiledRoutes = [];

    /** @var array<string, MatcherInterface> */
    private static array $matchers = [];

    private static ?UrlGenerator $relativeSignedGenerator = null;

    private static ?string $relativeSignedUrl = null;

    private static ?VerifySignedUrlMiddleware $relativeVerifier = null;

    /**
     * @return list<CompiledRoute>
     */
    public static function compiledRoutesFor(string $routeSet): array
    {
        return self::compiledRoutes($routeSet);
    }

    public static function createSignedGenerator(SignedUrlConfig $config): UrlGenerator
    {
        return self::buildSignedUrlGenerator($config);
    }

    public static function generateAbsoluteSignedUrl(): string
    {
        return self::absoluteSignedGenerator()->signed(
            'secure.absolute',
            ['id' => 42],
            ['dl' => 1],
            null,
            true,
            SignedUrlConfig::MODE_ABSOLUTE,
        );
    }

    public static function generateAbsoluteUntilUrl(): string
    {
        return self::absoluteSignedGenerator()->temporaryUntil(
            'secure.absolute',
            new DateTimeImmutable('+10 minutes'),
            ['id' => 42],
            ['dl' => 1],
            true,
            SignedUrlConfig::MODE_ABSOLUTE,
        );
    }

    public static function generateRelativeTemporaryUrl(): string
    {
        return self::relativeSignedGenerator()->temporary(
            'secure.show',
            ['id' => 42],
            ['dl' => 1],
            300,
            false,
        );
    }

    public static function matcher(string $routeSet, string $matcher): MatcherInterface
    {
        $key = $routeSet . ':' . $matcher;

        return self::$matchers[$key] ??= self::buildMatcher($routeSet, $matcher);
    }

    public static function verifyAbsoluteSignedUrl(): Response
    {
        return self::verifySignedUrl(
            self::$absoluteSignedUrl ??= self::generateAbsoluteUntilUrl(),
            self::$absoluteVerifier ??= new VerifySignedUrlMiddleware(self::absoluteSignedConfig()),
            ['preview' => '1'],
        );
    }

    public static function verifyRelativeSignedUrl(): Response
    {
        return self::verifySignedUrl(
            self::$relativeSignedUrl ??= self::generateRelativeTemporaryUrl(),
            self::$relativeVerifier ??= new VerifySignedUrlMiddleware(self::SIGN_KEY, 5),
        );
    }

    /**
     * @param array<string, string> $extraQuery
     */
    public static function verifySignedUrlResponse(
        string $signedUrl,
        VerifySignedUrlMiddleware $middleware,
        array $extraQuery = [],
    ): Response {
        return self::verifySignedUrl($signedUrl, $middleware, $extraQuery);
    }

    private static function absoluteSignedConfig(): SignedUrlConfig
    {
        return new SignedUrlConfig(
            generationKey: self::SIGN_KEY,
            verificationKeys: [self::SIGN_KEY],
            defaultTtl: 900,
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
            ignoredQueryParams: ['preview'],
            leeway: 5,
        );
    }

    private static function absoluteSignedGenerator(): UrlGenerator
    {
        return self::$absoluteSignedGenerator ??= self::buildSignedUrlGenerator(self::absoluteSignedConfig());
    }

    private static function baseSignedConfig(): SignedUrlConfig
    {
        return new SignedUrlConfig(
            generationKey: self::SIGN_KEY,
            verificationKeys: [self::SIGN_KEY],
            defaultTtl: 900,
        );
    }

    /**
     * @param callable(Registrar):void $register
     * @return list<CompiledRoute>
     */
    private static function buildCompiledRoutes(callable $register): array
    {
        $routes = new Collection();
        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: false,
            exposeUrlServices: false,
            signKey: self::SIGN_KEY,
            signedDefaultTtl: 900,
            signedUrlConfig: self::baseSignedConfig(),
            urlBaseUri: self::BASE_URI,
        );

        $register($registrar);

        /** @var list<CompiledRoute> $all */
        $all = $routes->compile()->all();
        if ($all === []) {
            throw new RuntimeException('Failed to build benchmark route set.');
        }

        return $all;
    }

    private static function buildMatcher(string $routeSet, string $matcher): MatcherInterface
    {
        $instance = match ($matcher) {
            MatcherModeEnum::FUSED->value => FusedMatcher::make(),
            MatcherModeEnum::GENERATED->value => GeneratedMatcher::make(),
            MatcherModeEnum::SHARDED->value => ShardedMatcher::make(),
            default => throw new RuntimeException("Unsupported matcher '{$matcher}'."),
        };

        foreach (self::compiledRoutes($routeSet) as $route) {
            $instance->add($route);
        }

        $instance->finalize();

        return $instance;
    }

    private static function buildSignedUrlGenerator(SignedUrlConfig $config): UrlGenerator
    {
        $routes = new Collection();
        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: false,
            exposeUrlServices: false,
            signKey: self::SIGN_KEY,
            signedDefaultTtl: 900,
            signedUrlConfig: $config,
            urlBaseUri: self::BASE_URI,
        );

        self::registerIndexRoutes($registrar);

        return new UrlGenerator(
            baseUri: self::BASE_URI,
            routes: $routes,
            signedConfig: $config,
        );
    }

    /**
     * @return list<CompiledRoute>
     */
    private static function compiledRoutes(string $routeSet): array
    {
        return self::$compiledRoutes[$routeSet] ??= match ($routeSet) {
            'index' => self::buildCompiledRoutes(self::registerIndexRoutes(...)),
            'route-cache' => self::buildCompiledRoutes(self::registerRouteCacheExampleRoutes(...)),
            default => throw new RuntimeException("Unsupported route set '{$routeSet}'."),
        };
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function registerIndexRoutes(Registrar $registrar): void
    {
        MiddlewareAliases::reset();
        MiddlewareAliases::register(
            'throttle',
            static function (...$_params): string {
                unset($_params);

                return ThrottleMiddleware::class;
            },
        );
        MiddlewareAliases::register(
            'verifySignedUrl',
            static function (...$_params): VerifySignedUrlMiddleware {
                unset($_params);

                return new VerifySignedUrlMiddleware(self::SIGN_KEY, 5);
            },
        );
        MiddlewareAliases::register(
            'verifySignedUrlAbsolute',
            static function (...$_params): VerifySignedUrlMiddleware {
                unset($_params);

                return new VerifySignedUrlMiddleware(self::absoluteSignedConfig());
            },
        );

        Router::setInstance($registrar);
        require self::projectRoot() . '/routes.php';

        $fixtureDirs = [
            self::projectRoot() . '/tests/Fixture',
            self::projectRoot() . '/tests/Fixtures',
        ];

        foreach ($fixtureDirs as $fixtureDir) {
            if (!is_dir($fixtureDir)) {
                continue;
            }

            AttributeRouteLoader::registerFromDirs(
                $registrar,
                ['Infocyph\\Webrick\\Tests\\Fixture\\' => $fixtureDir],
            );
        }
    }

    private static function registerRouteCacheExampleRoutes(Registrar $registrar): void
    {
        $registrar->get('/ping', static fn(): string => 'pong', 'ping');
        $registrar->get('/hello/{name}', static function ($request, string $name): string {
            unset($request);

            return $name;
        }, 'hello');
    }

    private static function relativeSignedGenerator(): UrlGenerator
    {
        return self::$relativeSignedGenerator ??= self::buildSignedUrlGenerator(self::baseSignedConfig());
    }

    /**
     * @param array<string, string> $extraQuery
     */
    private static function verifySignedUrl(
        string $signedUrl,
        VerifySignedUrlMiddleware $middleware,
        array $extraQuery = [],
    ): Response {
        $path = (string) parse_url($signedUrl, PHP_URL_PATH);
        $query = [];
        $queryString = parse_url($signedUrl, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }

        /** @var array<string, string|array<int|string, mixed>|bool|float|int|null> $mergedQuery */
        $mergedQuery = array_merge($query, $extraQuery);
        $request = Request::fake(query: $mergedQuery, uri: self::BASE_URI . $path);

        return $middleware(
            $request,
            static fn(): Response => Response::plaintext('ok', 200),
        );
    }
}
