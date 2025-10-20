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

// testKernel() removed - use createTestKernel() from IntegrationBootstrap.php instead