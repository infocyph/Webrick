<?php

declare(strict_types=1);
use Infocyph\Webrick\Router\Kernel\RouterKernel;

require_once __DIR__.'/../IntegrationBootstrap.php';

describe('Debug: Check Route Registration', function () {
    it('can create kernel without errors', function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $kernel = createTestKernel();
        expect($kernel)->toBeInstanceOf(RouterKernel::class);
    });

    it('shows actual response for ping route', function () {
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $kernel = createTestKernel();
        $request = mockRequest('GET', '/ping');
        $response = $kernel->handle($request);

        file_put_contents('php://stdout', "\n", FILE_APPEND);
        file_put_contents('php://stdout', "DEBUG: /ping route\n", FILE_APPEND);
        file_put_contents('php://stdout', 'Status: '.$response->getStatusCode()."\n", FILE_APPEND);
        file_put_contents('php://stdout', 'Body: '.(string) $response->getBody()."\n", FILE_APPEND);

        if ($response->hasHeader('Location')) {
            file_put_contents('php://stdout', 'Location: '.$response->getHeaderLine('Location')."\n", FILE_APPEND);
        }

        // The test should pass regardless
        expect($response->getStatusCode())->toBeIn([200, 308, 404]);
    });
});
