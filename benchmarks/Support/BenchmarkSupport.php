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

    public static function freshSyntheticMatcher(int $routeCount, string $matcher): MatcherInterface
    {
        return self::buildMatcher('scale-' . $routeCount, $matcher);
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

    public static function syntheticHitPath(int $routeCount): string
    {
        $index = max(0, $routeCount - 1);

        return match ($index % 5) {
            0 => "/scale/static/{$index}",
            1 => "/scale/users/{$index}/42",
            2 => "/scale/colors/{$index}/ff00ff",
            3 => "/scale/deep/segment/{$index}/items/benchmark",
            default => "/scale/similar/prefix-{$index}",
        };
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
        if (str_starts_with($routeSet, 'scale-')) {
            $routeCount = filter_var(substr($routeSet, 6), FILTER_VALIDATE_INT);
            if (!\is_int($routeCount) || $routeCount < 1) {
                throw new RuntimeException("Unsupported route set '{$routeSet}'.");
            }

            return self::$compiledRoutes[$routeSet] ??= self::buildCompiledRoutes(
                static fn(Registrar $registrar): mixed => self::registerSyntheticRoutes($registrar, $routeCount),
            );
        }

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
            static function (): string {
                return ThrottleMiddleware::class;
            },
        );
        MiddlewareAliases::register(
            'verifySignedUrl',
            static function (): VerifySignedUrlMiddleware {
                return new VerifySignedUrlMiddleware(self::SIGN_KEY, 5);
            },
        );
        MiddlewareAliases::register(
            'verifySignedUrlAbsolute',
            static function (): VerifySignedUrlMiddleware {
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
        $registrar->get('/hello/{name}', static function (string $name): string {
            return $name;
        }, 'hello');
    }

    private static function registerSyntheticRoutes(Registrar $registrar, int $routeCount): void
    {
        for ($index = 0; $index < $routeCount; ++$index) {
            $path = match ($index % 5) {
                0 => "/scale/static/{$index}",
                1 => "/scale/users/{$index}/{id:int}",
                2 => "/scale/colors/{$index}/{color:hex}",
                3 => "/scale/deep/segment/{$index}/items/{slug}",
                default => "/scale/similar/prefix-{$index}",
            };
            $registrar->get($path, static fn(): string => 'ok');
        }
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
