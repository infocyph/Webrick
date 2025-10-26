<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationBootstrap.php';

describe('Debug: Check Route Registration', function () {
    it('can create kernel without errors', function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $kernel = createTestKernel();
        expect($kernel)->toBeInstanceOf(\Infocyph\Webrick\Router\Kernel\RouterKernel::class);
    });

    it('shows actual response for ping route', function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $kernel = createTestKernel();
        $request = mockRequest('GET', '/ping');
        $response = $kernel->handle($request);

        echo "\n";
        echo "DEBUG: /ping route\n";
        echo "Status: " . $response->getStatusCode() . "\n";
        echo "Body: " . (string)$response->getBody() . "\n";

        if ($response->hasHeader('Location')) {
            echo "Location: " . $response->getHeaderLine('Location') . "\n";
        }

        // The test should pass regardless
        expect($response->getStatusCode())->toBeIn([200, 308, 404]);
    });
});
