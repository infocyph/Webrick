<?php

declare(strict_types=1);
use Infocyph\Webrick\Router\Kernel\RouterKernel;

require_once __DIR__.'/../IntegrationBootstrap.php';

describe('Simple Integration Tests', function () {
    beforeEach(function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $this->kernel = createTestKernel();
    });
    it('can create and use a RouterKernel', function () {
        expect($this->kernel)->toBeInstanceOf(RouterKernel::class);
    });

    it('routes return 200 OK status', function () {
        $routes = [
            '/',
            '/ping',
            '/json',
            '/hello/World',
        ];

        foreach ($routes as $route) {
            $request = mockRequest('GET', $route);
            $response = $this->kernel->handle($request);
            expect($response->getStatusCode())->toBe(200, "Route $route failed");
        }
    });

    it('returns 404 for non-existent routes', function () {
        $request = mockRequest('GET', '/this-definitely-does-not-exist');
        $response = $this->kernel->handle($request);
        expect($response->getStatusCode())->toBe(404);
    });

    it('handles JSON responses', function () {
        $request = mockRequest('GET', '/json');
        $response = $this->kernel->handle($request);

        expect($response)->toHaveStatus(200);

        $contentType = $response->getHeaderLine('Content-Type');
        expect($contentType)->toContain('application/json');

        $body = json_decode((string) $response->getBody(), true);
        expect($body)->toBeArray();
    });

    it('handles dynamic route parameters', function () {
        $request = mockRequest('GET', '/hello/Alice');
        $response = $this->kernel->handle($request);

        expect($response)->toHaveStatus(200);

        $body = json_decode((string) $response->getBody(), true);
        expect($body)
            ->toHaveKey('hello')
            ->and($body['hello'])->toBe('Alice');
    });

    it('handles redirects', function () {
        $request = mockRequest('GET', '/redirect');
        $response = $this->kernel->handle($request);

        expect($response->getStatusCode())
            ->toBeIn([302, 301, 307, 308])
            ->and($response)->toHaveHeader('Location');
    });
});
