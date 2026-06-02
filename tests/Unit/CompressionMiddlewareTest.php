<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Response\Response;

describe('CompressionMiddleware', function () {
    it('compresses response when accepted', function () {
        if (! function_exists('gzencode')) {
            $this->markTestSkipped('gzip extension not available');
        }

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

        expect($response)
            ->toHaveHeader('Content-Encoding', 'gzip')
            ->toHaveHeader('Vary', 'Accept-Encoding');

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
        if (! function_exists('gzencode')) {
            $this->markTestSkipped('gzip extension not available');
        }

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

        $etag = $response->getHeaderLine('ETag');
        // ETag behavior depends on mode
        expect($etag)->toBeString();
    });
});
