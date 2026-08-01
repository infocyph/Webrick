<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Simple test logger for capturing log messages during tests.
 */
class TestLogger implements LoggerInterface
{
    public array $records = [];

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function clear(): void
    {
        $this->records = [];
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function getRecords(?string $level = null): array
    {
        if ($level === null) {
            return $this->records;
        }

        return array_filter($this->records, fn($r) => $r['level'] === $level);
    }

    public function hasDebugRecords(): bool
    {
        return $this->hasRecords(LogLevel::DEBUG);
    }

    public function hasErrorRecords(): bool
    {
        return $this->hasRecords(LogLevel::ERROR);
    }

    public function hasInfoRecords(): bool
    {
        return $this->hasRecords(LogLevel::INFO);
    }

    public function hasRecords(?string $level = null): bool
    {
        if ($level === null) {
            return !empty($this->records);
        }

        return !empty(array_filter($this->records, fn($r) => $r['level'] === $level));
    }

    public function hasWarningRecords(): bool
    {
        return $this->hasRecords(LogLevel::WARNING);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }
}
/**
 * Create a test registrar with empty collection.
 */
function testRegistrar(array $options = []): Registrar
{
    $routes = new Collection();

    $defaults = [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => false,
        'signKey' => null,
        'signedDefaultTtl' => null,
        'signedUrlConfig' => null,
        'urlBaseUri' => '',
    ];

    $opts = array_merge($defaults, $options);

    return new Registrar(
        routes: $routes,
        autoSlashRedirect: $opts['autoSlashRedirect'],
        exposeUrlServices: $opts['exposeUrlServices'],
        signKey: $opts['signKey'],
        signedDefaultTtl: $opts['signedDefaultTtl'],
        signedUrlConfig: $opts['signedUrlConfig'] instanceof SignedUrlConfig
            ? $opts['signedUrlConfig']
            : null,
        urlBaseUri: is_string($opts['urlBaseUri']) ? $opts['urlBaseUri'] : '',
    );
}
/**
 * Create a mock PSR-7 Request for testing
 */
function mockRequest(string $method, string $uri, array $headers = [], array $body = []): Request
{
    foreach (array_keys($_SERVER) as $key) {
        if (is_string($key) && str_starts_with($key, 'HTTP_')) {
            unset($_SERVER[$key]);
        }
    }

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
    $request = Request::fromGlobals();

    // Add body if provided
    if (!empty($body)) {
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $json = json_encode($body);
            $stream = new Stream($json);
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
function testCache(string $namespace = 'test'): Cache
{
    if (\PHP_OS_FAMILY === 'Windows' && !\extension_loaded('apcu')) {
        return Cache::memory('webrick-test-' . $namespace);
    }

    return Cache::local(
        'webrick-test-' . $namespace,
        sys_get_temp_dir() . '/webrick-test-' . $namespace,
    );
}

/**
 * Create a test logger.
 */
function testLogger(): LoggerInterface
{
    return new NullLogger();
}

/**
 * Generate a random 32-byte encryption key.
 */
function testEncryptionKey(): string
{
    return random_bytes(32);
}

/**
 * Clean test cache directory.
 */
function cleanTestCache(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
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
