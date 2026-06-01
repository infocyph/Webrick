<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Response\Response;

describe('NegotiationMiddleware', function () {
    it('negotiates content type', function () {
        $middleware = new NegotiationMiddleware(
            produces: ['application/json', 'text/html']
        );

        $request = mockRequest('GET', '/', [
            'Accept' => 'application/json',
        ]);

        $negotiated = null;
        $next = function ($req) use (&$negotiated) {
            $negotiated = $req->getAttribute('negotiated.type');

            return Response::json(['ok' => true]);
        };

        $middleware($request, $next);

        expect($negotiated)->toBe('application/json');
    });

    it('returns 406 for unacceptable type', function () {
        $middleware = new NegotiationMiddleware(
            produces: ['application/json']
        );

        $request = mockRequest('GET', '/', [
            'Accept' => 'text/xml',  // Not supported
        ]);

        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)->toHaveStatus(406);
    });

    it('negotiates charset', function () {
        $middleware = new NegotiationMiddleware(
            produces: ['text/html'],
            charsets: ['utf-8', 'iso-8859-1']
        );

        $request = mockRequest('GET', '/', [
            'Accept' => 'text/html',
            'Accept-Charset' => 'iso-8859-1',
        ]);

        $negotiated = null;
        $next = function ($req) use (&$negotiated) {
            $negotiated = $req->getAttribute('negotiated.charset');

            return Response::create('<h1>Test</h1>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
        };

        $middleware($request, $next);

        expect($negotiated)->toBe('iso-8859-1');
    });

    it('negotiates locale', function () {
        $middleware = new NegotiationMiddleware(
            locales: ['en', 'fr', 'es']
        );

        $request = mockRequest('GET', '/', [
            'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
        ]);

        $locale = null;
        $next = function ($req) use (&$locale) {
            $locale = $req->getAttribute('locale');

            return Response::json(['ok' => true]);
        };

        $middleware($request, $next);

        expect($locale)->toBe('fr');
    });

    it('ensures Content-Type header', function () {
        $middleware = new NegotiationMiddleware(
            produces: ['application/json']
        );

        $request = mockRequest('GET', '/', [
            'Accept' => 'application/json',
        ]);

        $next = fn () => Response::create('{"ok":true}', 200);

        $response = $middleware($request, $next);

        expect($response)->toHaveHeader('Content-Type', 'application/json');
    });

    it('sets Content-Language header', function () {
        $middleware = new NegotiationMiddleware(
            locales: ['en', 'fr']
        );

        $request = mockRequest('GET', '/', [
            'Accept-Language' => 'fr',
        ]);

        $next = fn () => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)->toHaveHeader('Content-Language', 'fr');
    });
});
