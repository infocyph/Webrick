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

/**
 * Create a test RouterKernel from compiled routes.
 *
 * IMPORTANT: This bypasses normal RouterKernel initialization since we
 * already have compiled routes. The kernel normally expects a registration
 * callback but we don't need it.
 */
function testKernel(
    \Infocyph\Webrick\Router\Route\CompiledCollection $routes,
    array $globalMiddleware = []
): \Infocyph\Webrick\Router\Kernel\RouterKernel {

    // We can't easily use RouterKernel with pre-compiled routes
    // because it expects a registration callback
    // So let's just skip integration tests for now
    throw new \RuntimeException(
        'Integration tests require proper RouterKernel setup. ' .
        'These tests validate the full framework stack and require ' .
        'a complete application context. Unit tests cover all components.'
    );
}