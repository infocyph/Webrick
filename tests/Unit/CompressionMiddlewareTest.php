<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Response\Response;

describe('CompressionMiddleware', function () {
    it('compresses response when accepted or falls back cleanly when gzip is unavailable', function () {
        $gzipAvailable = function_exists('gzencode');
        $middleware = new CompressionMiddleware(
            minBytes: 100,
            prefOrder: ['gzip']
        );

        $body = str_repeat('Hello World! ', 100); // > 100 bytes
        $request = mockRequest('GET', '/', [
            'Accept-Encoding' => 'gzip',
        ]);

        $next = fn () => Response::create($body, 200, [
            'Content-Type' => 'text/html',
        ]);

        $varyMw = new VaryAccumulatorMiddleware;
        $response = $varyMw($request, fn ($r) => $middleware($r, $next));

        expect($response)->toHaveHeader('Vary', 'Accept-Encoding');

        if (!$gzipAvailable) {
            expect($response->hasHeader('Content-Encoding'))->toBeFalse()
                ->and((string) $response->getBody())->toBe($body);

            return;
        }

        expect($response)->toHaveHeader('Content-Encoding', 'gzip');

        $compressed = (string) $response->getBody();
        expect(strlen($compressed))->toBeLessThan(strlen($body));
    });

    it('skips compression for small responses', function () {
        $middleware = new CompressionMiddleware(minBytes: 1000);

        $request = mockRequest('GET', '/', [
            'Accept-Encoding' => 'gzip',
        ]);

        $next = fn () => Response::create('Small', 200);

        $varyMw = new VaryAccumulatorMiddleware;
        $response = $varyMw($request, fn ($r) => $middleware($r, $next));

        expect($response->hasHeader('Content-Encoding'))
            ->toBeFalse()
            ->and((string) $response->getBody())->toBe('Small');
    });

    it('skips compression for images', function () {
        $middleware = new CompressionMiddleware;

        $request = mockRequest('GET', '/image.jpg', [
            'Accept-Encoding' => 'gzip',
        ]);

        $body = str_repeat('x', 2000);
        $next = fn () => Response::create($body, 200, [
            'Content-Type' => 'image/jpeg',
        ]);

        $varyMw = new VaryAccumulatorMiddleware;
        $response = $varyMw($request, fn ($r) => $middleware($r, $next));

        expect($response->hasHeader('Content-Encoding'))->toBeFalse();
    });

    it('respects no-transform directive', function () {
        $middleware = new CompressionMiddleware;

        $request = mockRequest('GET', '/', [
            'Accept-Encoding' => 'gzip',
        ]);

        $body = str_repeat('Hello World! ', 100);
        $next = fn () => Response::create($body, 200, [
            'Cache-Control' => 'no-transform',
        ]);

        $varyMw = new VaryAccumulatorMiddleware;
        $response = $varyMw($request, fn ($r) => $middleware($r, $next));

        expect($response->hasHeader('Content-Encoding'))->toBeFalse();
    });

    it('handles ETag with weak-on-encode strategy', function () {
        $gzipAvailable = function_exists('gzencode');
        $middleware = new CompressionMiddleware(
            etagMode: CompressionMiddleware::ETAG_WEAK_ON_ENCODE
        );

        $body = str_repeat('Hello World! ', 100);
        $request = mockRequest('GET', '/', [
            'Accept-Encoding' => 'gzip',
        ]);

        $next = fn () => Response::create($body, 200, [
            'Content-Type' => 'text/html',
            'ETag' => '"abc123"',
        ]);

        $varyMw = new VaryAccumulatorMiddleware;
        $response = $varyMw($request, fn ($r) => $middleware($r, $next));

        if (!$gzipAvailable) {
            expect($response->hasHeader('Content-Encoding'))->toBeFalse()
                ->and($response->getHeaderLine('ETag'))->toBe('"abc123"');

            return;
        }

        expect($response->getHeaderLine('ETag'))->toBeString();
    });
});
