<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Headers\Range;
use Infocyph\Webrick\Response\Range\RangeResponder;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;

describe('Deep correctness batch C', function () {
    it('rejects invalid response statuses at construction', function () {
        expect(fn() => new Response(99))->toThrow(RuntimeException::class)
            ->and(fn() => new Response(600))->toThrow(RuntimeException::class);
    });

    it('treats response helper header names case-insensitively', function () {
        $json = Response::json(['ok' => true], headers: ['content-type' => 'application/vnd.test+json']);
        $stream = Response::stream(['chunk'], headers: ['cOnTeNt-LeNgTh' => '999']);
        $empty = Response::empty(204, ['CONTENT-LENGTH' => '999']);

        expect($json->getHeaderLine('Content-Type'))->toBe('application/vnd.test+json')
            ->and($stream->hasHeader('Content-Length'))->toBeFalse()
            ->and($empty->hasHeader('Content-Length'))->toBeFalse();
    });

    it('keeps cookies and server metadata outside application input validation', function () {
        $request = Request::fake()->withCookieParams(['email' => 'cookie@example.com']);

        expect($request->data('email'))->toBeNull()
            ->and(fn() => $request->validate(['email' => 'required']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('uses structured media suffixes consistently on Request', function () {
        expect(Request::fake(headers: ['Content-Type' => 'application/problem+json'])->isJson())->toBeTrue()
            ->and(Request::fake(headers: ['Content-Type' => 'application/jsonp'])->isJson())->toBeFalse()
            ->and(Request::fake(headers: ['Content-Type' => 'application/problem+xml'])->isXml())->toBeTrue();
    });

    it('drops malformed Cache-Control delta-seconds while preserving valid directives', function () {
        $cache = CacheControl::fromHeaderBag(new HeaderBag([
            'Cache-Control' => 'public, max-age=+60, stale-if-error=30',
        ]));

        expect((string) $cache)->toBe('public, stale-if-error=30');
    });

    it('rejects externally supplied ranges built for another representation length', function () {
        $handle = fopen('php://temp', 'w+b');
        expect($handle)->toBeResource();
        fwrite($handle, '0123456789');
        rewind($handle);

        $response = RangeResponder::fromSeekable($handle, 10, new Range(0, 4, 5));

        expect($response->getStatusCode())->toBe(416)
            ->and($response->getHeaderLine('Content-Range'))->toBe('bytes */10');

        fclose($handle);
    });

    it('rejects canonical route duplicates during collection registration', function () {
        $routes = new Collection();
        $routes->add(new Route('GET', '/same', static fn(): string => 'one'));

        expect(fn() => $routes->add(new Route('GET', '/same', static fn(): string => 'two')))
            ->toThrow(LogicException::class);
    });
});
