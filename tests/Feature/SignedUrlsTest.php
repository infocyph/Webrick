<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as RouteFacade;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Psr\Log\NullLogger;

if (! class_exists('SignedUrlCacheController', false)) {
    final readonly class SignedUrlCacheController
    {
        public static function handle(): Response
        {
            return Response::plaintext('ok');
        }
    }
}

describe('Signed URLs', function () {
    beforeEach(function () {
        $this->secret = 'test-secret-key-32-bytes-long!!';
        $this->routes = new Collection;

        // Add test route
        $route = new Route('GET', '/download/{file}', fn () => Response::plaintext('OK'));
        $route = $route->withName('download');
        $this->routes->add($route);

        $this->generator = new UrlGenerator(
            baseUri: 'https://example.com',
            routes: $this->routes,
            secret: $this->secret,
        );
    });

    it('generates signed URLs', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'document.pdf'],
            query: ['inline' => '1'],
            ttl: null,
            absolute: false
        );

        expect($signedUrl)
            ->toContain('_sig=')
            ->and($signedUrl)->toContain('inline=1')
            ->and($signedUrl)->toContain('/download/document.pdf');
    });

    it('generates temporary URLs with expiry', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'temp.pdf'],
            query: [],
            ttl: 3600,  // 1 hour
            absolute: false
        );

        expect($signedUrl)
            ->toContain('_sig=')
            ->and($signedUrl)->toContain('_exp=');

        // Parse expiry timestamp
        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
        expect((int) $query['_exp'])
            ->toBeGreaterThan(time())
            ->and((int) $query['_exp'])->toBeLessThanOrEqual(time() + 3600);
    });

    it('validates signed URL signatures', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'test.pdf'],
            query: ['v' => '2'],
            ttl: null,
            absolute: false
        );

        // Parse signature
        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
        $sig = $query['_sig'];
        unset($query['_sig']);

        // Reconstruct payload
        $path = parse_url($signedUrl, PHP_URL_PATH);
        ksort($query);
        $payload = $path;
        if ($query) {
            $payload .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        // Verify signature
        expect(UrlGenerator::checkSignature($payload, $sig, $this->secret))->toBeTrue();
    });

    it('generates invalid signature for tampered URLs', function () {
        $signedUrl = $this->generator->signed(
            name: 'download',
            params: ['file' => 'original.pdf'],
            query: [],
            ttl: null,
            absolute: false
        );

        // Parse and tamper
        $parts = parse_url($signedUrl);
        parse_str($parts['query'], $query);
        $sig = $query['_sig'];

        // Change the file parameter (tamper)
        $tamperedPath = str_replace('original.pdf', 'hacked.pdf', $parts['path']);

        // Try to verify with original signature
        expect(UrlGenerator::checkSignature($tamperedPath, $sig, $this->secret))->toBeFalse();
    });

    it('uses UrlGenerator with default TTL', function () {
        $tempGenerator = new UrlGenerator(
            baseUri: 'https://example.com',
            routes: $this->routes,
            secret: $this->secret,
            defaultTtl: 900,
        );

        // Generate without specifying TTL (should use default)
        $signedUrl = $tempGenerator->temporary(
            name: 'download',
            params: ['file' => 'temp.pdf'],
            absolute: false
        );

        expect($signedUrl)->toContain('_exp=');

        // Verify it uses default TTL
        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
        $expiry = (int) $query['_exp'];

        expect($expiry)
            ->toBeGreaterThan(time())
            ->and($expiry)->toBeLessThanOrEqual(time() + 900);
    });

    it('prevents query parameter injection', function () {
        // Try to inject reserved parameters
        expect(fn () => $this->generator->signed(
            name: 'download',
            params: ['file' => 'test.pdf'],
            query: ['_sig' => 'malicious'],  // Reserved parameter
            ttl: null,
            absolute: false
        ))->toThrow(InvalidArgumentException::class);
    });

    it('rejects baseUri when query or fragment is present', function () {
        expect(fn () => new UrlGenerator(
            baseUri: 'https://example.com?x=1',
            routes: $this->routes,
            secret: $this->secret,
            defaultTtl: 900
        ))->toThrow(InvalidArgumentException::class);

        expect(fn () => new UrlGenerator(
            baseUri: 'https://example.com#frag',
            routes: $this->routes,
            secret: $this->secret,
            defaultTtl: 900
        ))->toThrow(InvalidArgumentException::class);
    });

    it('keeps signed URL helpers bound after hot-cache boot', function () {
        RouteFacade::resetUrlServices();

        $cacheDir = \sys_get_temp_dir().DIRECTORY_SEPARATOR.'webrick-route-cache-'.\bin2hex(\random_bytes(6));
        $secret = 'hot-cache-sign-secret';
        $register = static function (Registrar $r): void {
            $r->get('/download/{file}', [SignedUrlCacheController::class, 'handle'], 'download');
        };

        try {
            RouterKernel::bootWithRegistrar(
                log: new NullLogger,
                matcher: ShardedMatcher::make(),
                register: $register,
                routeCache: $cacheDir,
                registrarOptions: [
                    'autoSlashRedirect' => false,
                    'exposeUrlServices' => true,
                    'signKey' => $secret,
                    'signedDefaultTtl' => 300,
                ],
            );

            expect(RouteFacade::signedUrlFor('download', ['file' => 'cold.txt']))
                ->toContain('_sig=');

            RouterKernel::bootWithRegistrar(
                log: new NullLogger,
                matcher: ShardedMatcher::make(),
                register: $register,
                routeCache: $cacheDir,
                registrarOptions: [
                    'autoSlashRedirect' => false,
                    'exposeUrlServices' => true,
                    'signKey' => $secret,
                    'signedDefaultTtl' => 300,
                ],
            );

            expect(RouteFacade::signedUrlFor('download', ['file' => 'hot.txt']))
                ->toContain('_sig=');
        } finally {
            cleanTestCache($cacheDir);
            RouteFacade::resetUrlServices();
        }
    });
});
