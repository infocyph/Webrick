<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\InterMix\Cache\Cache;
use Psr\Log\NullLogger;

/**
 * Create a test registrar with empty collection.
 */
function testRegistrar(array $options = []): Registrar {
    $routes = new Collection();

    $defaults = [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => false,
        'signKey' => null,
        'signedDefaultTtl' => null,
    ];

    $opts = array_merge($defaults, $options);

    return new Registrar(
        routes: $routes,
        autoSlashRedirect: $opts['autoSlashRedirect'],
        exposeUrlServices: $opts['exposeUrlServices'],
        signKey: $opts['signKey'],
        signedDefaultTtl: $opts['signedDefaultTtl']
    );
}
/**
 * Create a mock PSR-7 Request for testing
 */
function mockRequest(string $method, string $uri, array $headers = [], array $body = []): \Infocyph\Webrick\Request\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_TIME'] = time();
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST'] = 'localhost';

    // Add headers to $_SERVER
    foreach ($headers as $name => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $_SERVER[$key] = $value;
    }

    // Create request from globals
    $request = \Infocyph\Webrick\Request\Request::fromGlobals();

    // Add body if provided
    if (!empty($body)) {
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $json = json_encode($body);
            $stream = new \Infocyph\Webrick\Request\Core\Stream($json);
            $request = $request->withBody($stream);

            if (!isset($headers['Content-Type'])) {
                $request = $request->withHeader('Content-Type', 'application/json');
            }
        }
    }

    return $request;
}
/**
 * Create a test cache instance.
 */
function testCache(string $namespace = 'test'): Cache {
    return Cache::local(sys_get_temp_dir() . '/webrick-test-' . $namespace);
}

/**
 * Create a test logger.
 */
function testLogger(): \Psr\Log\LoggerInterface {
    return new NullLogger();
}

/**
 * Generate a random 32-byte encryption key.
 */
function testEncryptionKey(): string {
    return random_bytes(32);
}

/**
 * Clean test cache directory.
 */
function cleanTestCache(string $path): void {
    if (!is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($path);
}

// testKernel() removed - use createTestKernel() from IntegrationBootstrap.php instead
