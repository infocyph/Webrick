<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
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
    /** @var list<array{level:mixed,message:string,context:array<array-key,mixed>}> */
    public array $records = [];

    #[\Override]
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function clear(): void
    {
        $this->records = [];
    }

    #[\Override]
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    #[\Override]
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    #[\Override]
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    #[\Override]
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /** @return array<int,array{level:mixed,message:string,context:array<array-key,mixed>}> */
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

    #[\Override]
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    #[\Override]
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    #[\Override]
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }
}
/**
 * Create a test registrar with empty collection.
 */
/**
 * @param array{autoSlashRedirect?:bool,exposeUrlServices?:bool,signKey?:?string,signedDefaultTtl?:?int,signedUrlConfig?:?SignedUrlConfig,urlBaseUri?:string} $options
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
        urlBaseUri: $opts['urlBaseUri'],
    );
}
/**
 * Create a mock PSR-7 Request for testing
 */
/**
 * @param array<string,mixed> $headers
 * @param array<array-key,mixed> $body
 */
function mockRequest(string $method, string $uri, array $headers = [], array $body = []): Request
{
    foreach (array_keys($_SERVER) as $key) {
        if (str_starts_with($key, 'HTTP_')) {
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

    return Cache::file(
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
 *
 * @throws UnexpectedValueException For non-filesystem iterator entries.
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
        if (!$file instanceof SplFileInfo) {
            throw new UnexpectedValueException('Unexpected filesystem entry during test cache cleanup.');
        }
        $entryPath = $file->getPathname();
        if ($file->isDir() && !$file->isLink()) {
            rmdir($entryPath);
        } else {
            unlink($entryPath);
        }
    }

    rmdir($path);
}

/**
 * Validate headers supplied by untyped test callbacks before constructing a response.
 *
 * @param array<array-key,mixed> $headers
 * @return array<string,string|list<string>>
 * @throws UnexpectedValueException
 */
function webrickTestHeaders(array $headers): array
{
    $validated = [];
    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            throw new UnexpectedValueException('Test header names must be strings.');
        }

        if (is_string($value)) {
            $validated[$name] = $value;

            continue;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException('Test header values must be strings or string lists.');
        }

        $members = [];
        foreach ($value as $member) {
            if (!is_string($member)) {
                throw new UnexpectedValueException('Test header list members must be strings.');
            }
            $members[] = $member;
        }
        $validated[$name] = $members;
    }

    return $validated;
}

/**
 * Validate the Pest-provided matcher factory at the dataset boundary.
 *
 * @throws UnexpectedValueException When the dataset does not return a matcher.
 */
function makeTestMatcher(Closure $factory): MatcherInterface
{
    $matcher = $factory();
    if (!$matcher instanceof MatcherInterface) {
        throw new UnexpectedValueException('Matcher dataset factory must return MatcherInterface.');
    }

    return $matcher;
}
