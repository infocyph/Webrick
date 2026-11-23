<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Kernel\RouterKernel;

require_once __DIR__.'/../IntegrationBootstrap.php';

describe('Real Middleware Integration', function () {
    beforeEach(function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    });
    it('executes routes without middleware', function () {
        // No middleware - just pure routing
        $kernel = createTestKernel();

        $request = mockRequest('GET', '/ping');
        $response = $kernel->handle($request);

        expect($response->getStatusCode())
            ->toBe(200)
            ->and((string) $response->getBody())->toBe('"pong"');
        // JSON-encoded
    });

    it('can add middleware when needed', function () {
        // Add middleware explicitly for this test
        $kernel = createTestKernel([
            new NegotiationMiddleware(
                produces: ['application/json', 'text/html']
            ),
        ]);

        $request = mockRequest('GET', '/json', [
            'Accept' => 'application/json',
        ]);

        $response = $kernel->handle($request);

        expect($response->getStatusCode())
            ->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toContain('application/json');
    });

    it('handles POST requests with data', function () {
        $kernel = createTestKernel();
        $request = mockRequest('POST', '/post/echo', [], ['action' => 'create']);
        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('POST echo fixture must return a JSON object.');
        }
        expect($payload['method'] ?? null)->toBe('POST');
    });

    it('handles PUT requests', function () {
        $kernel = createTestKernel();

        $request = mockRequest('PUT', '/user/99', [], [
            'name' => 'Updated User',
        ]);

        $response = $kernel->handle($request);

        expect($response->getStatusCode())->toBe(200);

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new UnexpectedValueException('Expected a JSON object for the updated resource.');
        }
        expect($body['updated'])
            ->toBe('99')
            ->and($body)->toHaveKey('updated')
            ->and($body['updated'])->toBe('99');
        // Check that response has expected structure
    });
});
