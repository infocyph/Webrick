<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\NegotiationMiddleware;

require_once __DIR__.'/../IntegrationBootstrap.php';

describe('Middleware Features', function () {
    beforeEach(function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    });

    it('handles routes without middleware', function () {
        $kernel = createTestKernel();
        $response = $kernel->handle(mockRequest('GET', '/ping'));
        expect($response)->toHaveStatus(200);
    });

    it('can use content negotiation middleware', function () {
        $kernel = createTestKernel([
            new NegotiationMiddleware(produces: ['application/json']),
        ]);

        $response = $kernel->handle(mockRequest('GET', '/json', [
            'Accept' => 'application/json',
        ]));

        expect($response)
            ->toHaveStatus(200)
            ->and($response->getHeaderLine('Content-Type'))->toContain('application/json');
    });

    it('handles locale negotiation', function () {
        $kernel = createTestKernel([
            new NegotiationMiddleware(locales: ['en', 'fr', 'es']),
        ]);

        $response = $kernel->handle(mockRequest('GET', '/locale', [
            'Accept-Language' => 'fr',
        ]));

        expect($response)->toHaveStatus(200);
    });
});
