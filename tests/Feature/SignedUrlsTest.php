<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as RouteFacade;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Psr\Log\NullLogger;

if (!class_exists('SignedUrlCacheController', false)) {
    final readonly class SignedUrlCacheController
    {
        public static function handle(): Response
        {
            return Response::plaintext('ok');
        }
    }
}

/**
 * @return array<string,string>
 */
function signedUrlQuery(string $url): array
{
    $query = [];
    $queryString = \parse_url($url, \PHP_URL_QUERY);
    if (\is_string($queryString)) {
        \parse_str($queryString, $query);
    }

    return $query;
}

function requestFromSignedUrl(string $url): Request
{
    $query = signedUrlQuery($url);
    $uri = (string) (\parse_url($url, \PHP_URL_SCHEME) !== null
        ? ((string) \parse_url($url, \PHP_URL_SCHEME)) . '://' . ((string) \parse_url($url, \PHP_URL_HOST))
            . ((\parse_url($url, \PHP_URL_PORT) !== null) ? ':' . (string) \parse_url($url, \PHP_URL_PORT) : '')
            . ((string) \parse_url($url, \PHP_URL_PATH))
        : ((string) \parse_url($url, \PHP_URL_PATH)));

    return Request::fake(query: $query, uri: $uri);
}

describe('Signed URLs', function () {
    beforeEach(function () {
        $this->secret = 'test-secret-key-32-bytes-long!!';
        $this->routes = new Collection();
        $route = new Route('GET', '/download/{file}', fn() => Response::plaintext('OK'));
        $this->routes->add($route->withName('download'));

        $this->config = new SignedUrlConfig(
            generationKey: $this->secret,
            defaultTtl: 900,
            verificationKeys: [$this->secret],
        );

        $this->generator = new UrlGenerator(
            baseUri: 'https://example.com',
            routes: $this->routes,
            signedConfig: $this->config,
        );
    });

    it('generates signed URLs with the default profile', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'document.pdf'],
            query: ['inline' => '1'],
            absolute: false,
        );

        expect($signedUrl)
            ->toContain('_sig=')
            ->and($signedUrl)->toContain('inline=1')
            ->and($signedUrl)->toContain('/download/document.pdf');
    });

    it('generates named URLs directly from a cached alias index', function () {
        $generator = new UrlGenerator(
            baseUri: 'https://example.com',
            routes: ['download' => ['/download/{file}', null]],
            signedConfig: $this->config,
        );

        expect($generator->urlFor('download', ['file' => 'cached.pdf']))
            ->toBe('/download/cached.pdf')
            ->and($generator->signed('download', ['file' => 'cached.pdf'], absolute: false))
            ->toContain('/download/cached.pdf?_sig=');
    });

    it('generates temporary URLs with ttl-based expiry', function () {
        $signedUrl = $this->generator->temporary(
            name: 'download',
            params: ['file' => 'temp.pdf'],
            absolute: false,
        );

        $query = signedUrlQuery($signedUrl);

        expect($signedUrl)->toContain('_exp=')
            ->and((int) $query['_exp'])->toBeGreaterThan(time())
            ->and((int) $query['_exp'])->toBeLessThanOrEqual(time() + 900);
    });

    it('supports explicit expiration timestamps', function () {
        $expiresAt = new DateTimeImmutable('+30 minutes');
        $signedUrl = $this->generator->temporaryUntil(
            name: 'download',
            expiresAt: $expiresAt,
            params: ['file' => 'report.pdf'],
            absolute: false,
        );

        $query = signedUrlQuery($signedUrl);

        expect((int) $query['_exp'])->toBe($expiresAt->getTimestamp());
    });

    it('validates signatures with the configured algorithm', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'test.pdf'],
            query: ['v' => '2'],
            absolute: false,
        );

        $query = signedUrlQuery($signedUrl);
        $sig = $query['_sig'];
        unset($query['_sig']);
        ksort($query);

        $path = (string) \parse_url($signedUrl, \PHP_URL_PATH);
        $payload = $path . '?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        expect(UrlGenerator::checkSignature($payload, $sig, $this->secret, $this->config->algorithm))
            ->toBeTrue();
    });

    it('rejects tampered signed URLs', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'original.pdf'],
            absolute: false,
        );

        $query = signedUrlQuery($signedUrl);
        $sig = $query['_sig'];
        $tamperedPath = '/download/hacked.pdf';

        expect(UrlGenerator::checkSignature($tamperedPath, $sig, $this->secret, $this->config->algorithm))
            ->toBeFalse();
    });

    it('supports absolute payload validation', function () {
        $config = new SignedUrlConfig(
            generationKey: $this->secret,
            verificationKeys: [$this->secret],
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
        );
        $generator = new UrlGenerator('https://example.com', $this->routes, signedConfig: $config);
        $signedUrl = $generator->signed(
            name: 'download',
            params: ['file' => 'image.png'],
            query: ['inline' => '1'],
            absolute: true,
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
        );

        $middleware = new VerifySignedUrlMiddleware($config);
        $response = $middleware(
            requestFromSignedUrl($signedUrl),
            static fn(): Response => Response::plaintext('ok', 200),
        );

        expect($response->getStatusCode())->toBe(200);
    });

    it('supports ignored query parameters during verification', function () {
        $config = new SignedUrlConfig(
            generationKey: $this->secret,
            verificationKeys: [$this->secret],
            ignoredQueryParams: ['download'],
        );
        $generator = new UrlGenerator('https://example.com', $this->routes, signedConfig: $config);
        $signedUrl = $generator->signed(
            name: 'download',
            params: ['file' => 'manual.pdf'],
            query: ['inline' => '1'],
            absolute: false,
        );

        $request = requestFromSignedUrl($signedUrl);
        $request = $request->withQueryParams($request->getQueryParams() + ['download' => '1']);

        $response = (new VerifySignedUrlMiddleware($config))(
            $request,
            static fn(): Response => Response::plaintext('ok', 200),
        );

        expect($response->getStatusCode())->toBe(200);
    });

    it('supports verification key rotation', function () {
        $generatorConfig = new SignedUrlConfig(
            generationKey: 'new-signing-key',
            verificationKeys: ['old-signing-key', 'new-signing-key'],
        );
        $middlewareConfig = new SignedUrlConfig(
            verificationKeys: ['old-signing-key', 'new-signing-key'],
        );

        $generator = new UrlGenerator('https://example.com', $this->routes, signedConfig: $generatorConfig);
        $signedUrl = $generator->signed('download', ['file' => 'archive.zip'], absolute: true);
        $response = (new VerifySignedUrlMiddleware($middlewareConfig))(
            requestFromSignedUrl($signedUrl),
            static fn(): Response => Response::plaintext('ok', 200),
        );

        expect($response->getStatusCode())->toBe(200);
    });

    it('supports custom signature parameters and algorithms', function () {
        $config = new SignedUrlConfig(
            generationKey: $this->secret,
            verificationKeys: [$this->secret],
            signatureParam: 'signature',
            expiryParam: 'expires',
            algorithm: 'sha256',
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
        );
        $generator = new UrlGenerator('https://example.com', $this->routes, signedConfig: $config);
        $signedUrl = $generator->temporaryUntil(
            name: 'download',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            params: ['file' => 'digest.txt'],
            absolute: true,
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
        );

        expect($signedUrl)->toContain('signature=')
            ->and($signedUrl)->toContain('expires=');

        $response = (new VerifySignedUrlMiddleware($config))(
            requestFromSignedUrl($signedUrl),
            static fn(): Response => Response::plaintext('ok', 200),
        );

        expect($response->getStatusCode())->toBe(200);
    });

    it('preserves the legacy constructor path', function () {
        $generator = new UrlGenerator(
            baseUri: 'https://example.com',
            routes: $this->routes,
            secret: $this->secret,
            defaultTtl: 900,
        );

        expect($generator->temporary('download', ['file' => 'legacy.pdf'], absolute: false))
            ->toContain('_exp=');
    });

    it('prevents query parameter injection for reserved parameters', function () {
        expect(fn() => $this->generator->signed(
            name: 'download',
            params: ['file' => 'test.pdf'],
            query: ['_sig' => 'malicious'],
            absolute: false,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('rejects base uri when query or fragment is present', function () {
        expect(fn() => new UrlGenerator(
            baseUri: 'https://example.com?x=1',
            routes: $this->routes,
            signedConfig: $this->config,
        ))->toThrow(InvalidArgumentException::class);

        expect(fn() => new UrlGenerator(
            baseUri: 'https://example.com#frag',
            routes: $this->routes,
            signedConfig: $this->config,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('keeps signed URL helpers bound after hot-cache boot', function () {
        RouteFacade::resetUrlServices();

        $cacheDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'webrick-route-cache-' . \bin2hex(\random_bytes(6));
        $secret = 'hot-cache-sign-secret';
        $signedUrlConfig = new SignedUrlConfig(
            generationKey: $secret,
            verificationKeys: [$secret],
            defaultTtl: 300,
            payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
        );
        $register = static function (Registrar $registrar): void {
            $registrar->get('/download/{file}', [SignedUrlCacheController::class, 'handle'], 'download');
        };

        try {
            RouterKernel::bootWithRegistrar(
                log: new NullLogger(),
                matcher: ShardedMatcher::make(),
                register: $register,
                routeCache: $cacheDir,
                registrarOptions: [
                    'autoSlashRedirect' => false,
                    'exposeUrlServices' => true,
                    'signKey' => $secret,
                    'signedDefaultTtl' => 300,
                    'signedUrlConfig' => $signedUrlConfig,
                    'urlBaseUri' => 'https://example.com',
                ],
            );

            expect(RouteFacade::temporaryUrlUntil(
                'download',
                new DateTimeImmutable('+5 minutes'),
                ['file' => 'cold.txt'],
                absolute: true,
                payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
            ))->toContain('_sig=');

            RouterKernel::bootWithRegistrar(
                log: new NullLogger(),
                matcher: ShardedMatcher::make(),
                register: $register,
                routeCache: $cacheDir,
                registrarOptions: [
                    'autoSlashRedirect' => false,
                    'exposeUrlServices' => true,
                    'signKey' => $secret,
                    'signedDefaultTtl' => 300,
                    'signedUrlConfig' => $signedUrlConfig,
                    'urlBaseUri' => 'https://example.com',
                ],
            );

            expect(RouteFacade::signedUrlFor(
                'download',
                ['file' => 'hot.txt'],
                absolute: true,
                payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
            ))->toContain('_sig=');
        } finally {
            cleanTestCache($cacheDir);
            RouteFacade::resetUrlServices();
        }
    });
});
