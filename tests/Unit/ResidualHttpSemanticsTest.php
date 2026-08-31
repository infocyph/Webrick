<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cache\CachePolicy;
use Infocyph\Webrick\Response\Response;

describe('Residual HTTP semantics', function () {
    it('preserves case-sensitive extension methods in merged method headers', function () {
        $response = Response::noContent()
            ->withSmartHeader('Allow', 'Foo')
            ->withSmartHeader('Allow', 'FOO')
            ->withSmartHeader('Access-Control-Allow-Methods', 'Bar')
            ->withSmartHeader('Access-Control-Allow-Methods', 'BAR');

        expect($response->getHeaderLine('Allow'))->toBe('Foo, FOO')
            ->and($response->getHeaderLine('Access-Control-Allow-Methods'))->toBe('Bar, BAR');
    });

    it('treats extension methods as case-sensitive during CORS preflight', function () {
        $middleware = new CorsMiddleware(
            origins: ['https://example.com'],
            methods: 'GET, Foo',
        );
        $request = Request::fake(
            method: 'OPTIONS',
            headers: [
                'Origin' => 'https://example.com',
                'Access-Control-Request-Method' => 'Foo',
            ],
        );
        $response = $middleware($request, static fn(Request $request): Response => Response::create('downstream'));

        expect($response->getStatusCode())->toBe(204)
            ->and($response->getHeaderLine('Access-Control-Allow-Methods'))->toBe('Foo');

        $wrongCase = $request->withHeader('Access-Control-Request-Method', 'FOO');
        expect(fn() => $middleware($wrongCase, static fn(Request $request): Response => Response::create('downstream')))
            ->toThrow(HttpException::class);
    });

    it('rejects path-bearing Origin values instead of treating them as serialized origins', function () {
        $middleware = new CorsMiddleware(origins: ['https://example.com']);
        $request = Request::fake(
            method: 'OPTIONS',
            headers: [
                'Origin' => 'https://example.com/path',
                'Access-Control-Request-Method' => 'GET',
            ],
        );

        expect(fn() => $middleware($request, static fn(Request $request): Response => Response::create('downstream')))
            ->toThrow(HttpException::class);
    });

    it('keeps commas inside quoted entity tags in dependency headers', function () {
        $request = Request::fake(headers: [
            'If-Match' => '"tag,one", W/"tag-two"',
            'If-None-Match' => '"other,tag", "final"',
        ]);

        expect($request->headers()->dependency('if_match'))->toBe(['"tag,one"', 'W/"tag-two"'])
            ->and($request->headers()->dependency('if_none_match'))->toBe(['"other,tag"', '"final"']);
    });

    it('does not reuse cache entries when the request requires revalidation', function () {
        $policy = new CachePolicy();

        expect($policy->lookupAllowed(Request::fake(headers: ['Cache-Control' => 'max-age=0'])))->toBeFalse()
            ->and($policy->lookupAllowed(Request::fake(headers: ['Pragma' => 'no-cache'])))->toBeFalse();
    });

    it('does not store no-cache responses without a revalidation path', function () {
        $policy = new CachePolicy();
        $request = Request::fake();
        $response = Response::create('payload', headers: ['Cache-Control' => 'no-cache']);

        expect($policy->storeTtl($request, $response, 30))->toBe(0);
    });

    it('returns 406 when automatic response negotiation has no acceptable representation', function () {
        $request = Request::fake(headers: ['Accept' => 'image/png']);
        $response = Response::auto($request, ['ok' => true]);

        expect($response->getStatusCode())->toBe(406)
            ->and($response->getHeaderLine('Vary'))->toBe('Accept')
            ->and((string) $response->getBody())->toBe('Not Acceptable');
    });
});
