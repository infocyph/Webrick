<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Router\Url\Signature;
use Infocyph\Webrick\Router\Url\SignedUrlGenerator;
use Infocyph\Webrick\Router\Url\TemporaryUrlGenerator;

describe('Signed URLs', function () {
    beforeEach(function () {
        $this->secret = 'test-secret-key-32-bytes-long!!';
        $this->routes = new Collection();

        // Add test route
        $route = new Route('GET', '/download/{file}', fn () => Response::plaintext('OK'));
        $route = $route->withName('download');
        $this->routes->add($route);

        $this->generator = new SignedUrlGenerator(
            baseUri: 'https://example.com',
            routes: $this->routes,
            secret: $this->secret
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
        expect((int)$query['_exp'])
            ->toBeGreaterThan(time())
            ->and((int)$query['_exp'])->toBeLessThanOrEqual(time() + 3600);
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
            $payload .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        // Verify signature
        expect(Signature::check($payload, $sig, $this->secret))->toBeTrue();
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
        expect(Signature::check($tamperedPath, $sig, $this->secret))->toBeFalse();
    });

    it('uses TemporaryUrlGenerator with default TTL', function () {
        $tempGenerator = new TemporaryUrlGenerator(
            baseUri: 'https://example.com',
            routes: $this->routes,
            secret: $this->secret,
            defaultTtl: 900  // 15 minutes
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
        $expiry = (int)$query['_exp'];

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
});
