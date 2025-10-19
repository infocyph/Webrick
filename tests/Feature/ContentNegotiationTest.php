<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

describe('Content Negotiation Feature', function () {
    it('negotiates JSON vs HTML', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/resource', function ($req) {
                    $type = $req->getAttribute('negotiated.type');

                    if ($type === 'application/json') {
                        return Response::json(['format' => 'json']);
                    }

                    return Response::html('<h1>HTML Format</h1>');
                });
            },
            preGlobal: [
                new NegotiationMiddleware(
                    produces: ['application/json', 'text/html']
                ),
            ]
        );

        // Request JSON
        $jsonRequest = mockRequest('GET', '/resource', [
            'Accept' => 'application/json',
        ]);
        $jsonResponse = $kernel->handle($jsonRequest);

        expect($jsonResponse)
            ->toHaveStatus(200)
            ->toHaveHeader('Content-Type', 'application/json');

        // Request HTML
        $htmlRequest = mockRequest('GET', '/resource', [
            'Accept' => 'text/html',
        ]);
        $htmlResponse = $kernel->handle($htmlRequest);

        expect($htmlResponse)
            ->toHaveStatus(200)
            ->toHaveHeader('Content-Type');

        expect($htmlResponse->getHeaderLine('Content-Type'))
            ->toContain('text/html');
    });

    it('handles locale negotiation', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/greeting', function ($req) {
                    $locale = $req->getAttribute('locale');

                    $greetings = [
                        'en' => 'Hello',
                        'es' => 'Hola',
                        'fr' => 'Bonjour',
                    ];

                    return Response::json([
                        'greeting' => $greetings[$locale] ?? 'Hello',
                        'locale' => $locale,
                    ]);
                });
            },
            preGlobal: [
                new NegotiationMiddleware(
                    locales: ['en', 'es', 'fr'],
                    localeFallback: 'en'
                ),
            ]
        );

        // Spanish request
        $esRequest = mockRequest('GET', '/greeting', [
            'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
        ]);
        $esResponse = $kernel->handle($esRequest);

        $esBody = json_decode((string)$esResponse->getBody(), true);
        expect($esBody['greeting'])->toBe('Hola');
        expect($esBody['locale'])->toBe('es');

        // French request
        $frRequest = mockRequest('GET', '/greeting', [
            'Accept-Language' => 'fr-FR,fr;q=0.9',
        ]);
        $frResponse = $kernel->handle($frRequest);

        $frBody = json_decode((string)$frResponse->getBody(), true);
        expect($frBody['greeting'])->toBe('Bonjour');
        expect($frBody['locale'])->toBe('fr');
    });

    it('returns 406 for unacceptable content types', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/api', fn() => Response::json(['ok' => true]));
            },
            preGlobal: [
                new NegotiationMiddleware(
                    produces: ['application/json']  // Only JSON
                ),
            ]
        );

        // Request XML (not supported)
        $request = mockRequest('GET', '/api', [
            'Accept' => 'application/xml',
        ]);
        $response = $kernel->handle($request);

        expect($response)->toHaveStatus(406);
    });

    it('uses quality values for preference', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/data', function ($req) {
                    return Response::json([
                        'type' => $req->getAttribute('negotiated.type'),
                    ]);
                });
            },
            preGlobal: [
                new NegotiationMiddleware(
                    produces: ['application/json', 'text/html']
                ),
            ]
        );

        // HTML preferred
        $request = mockRequest('GET', '/data', [
            'Accept' => 'text/html,application/json;q=0.9',
        ]);
        $response = $kernel->handle($request);

        $body = json_decode((string)$response->getBody(), true);
        expect($body['type'])->toBe('text/html');
    });
});
