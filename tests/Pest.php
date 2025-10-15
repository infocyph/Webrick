<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Tests;

use Psr\Log\NullLogger;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;

function httpGlobals(string $method, string $path, array $headers = [], ?string $body = null): void
{
    $_GET = $_POST = $_COOKIE = $_FILES = [];
    $_SERVER = [
        'REQUEST_METHOD' => strtoupper($method),
        'REQUEST_URI'    => $path,
        'HTTP_HOST'      => $headers['Host'] ?? 'localhost',
    ];

    foreach ($headers as $k => $v) {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($k));
        $_SERVER[$key] = $v;
    }

    if ($body !== null) {
        if (($headers['Content-Type'] ?? '') === 'application/json') {
            $_POST = json_decode($body, true) ?: [];
        }
    }
}

function status(object $response): int
{
    if (method_exists($response, 'getStatusCode')) {
        return (int) $response->getStatusCode();
    }
    return (int) ($response->status ?? 200);
}

function headerLine(object $response, string $name): ?string
{
    if (method_exists($response, 'getHeaderLine')) {
        $v = $response->getHeaderLine($name);
        return $v !== '' ? $v : null;
    }
    if (method_exists($response, 'getHeaders')) {
        $headers = (array) $response->getHeaders();
        $k = strtolower($name);
        foreach ($headers as $hn => $vals) {
            if (strtolower($hn) === $k) {
                return is_array($vals) ? implode(', ', $vals) : (string) $vals;
            }
        }
    }
    return null;
}

function body(object $response): string
{
    if (method_exists($response, 'getBody')) {
        $b = $response->getBody();
        if (is_string($b)) return $b;
        if (is_object($b) && method_exists($b, '__toString')) return (string) $b;
        if (is_object($b) && method_exists($b, 'getContents')) return (string) $b->getContents();
    }
    if (property_exists($response, 'body')) {
        return (string) $response->body;
    }
    return '';
}

function makeKernel(?string $cacheDir = null, ?callable $routes = null, array $opts = []): RouterKernel
{
    $logger   = new NullLogger();
    $matcher  = \Infocyph\Webrick\Router\Matching\ShardedMatcher::make();
    $signKey  = $opts['signKey'] ?? 'tests-secret';
    $signTtl  = $opts['signTtl'] ?? 60;

    $register = static function (Registrar $r) use ($routes): void {
        if ($routes) { $routes($r); }
    };

    return RouterKernel::bootWithRegistrar(
        log: $logger,
        matcher: $matcher,
        register: $register,
        routeCache: $cacheDir,
        registrarOptions: [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => true,
            'signKey'           => $signKey,
            'signedDefaultTtl'  => $signTtl,
        ],
        preGlobal: [],
        postGlobal: [],
        bindUrlServices: static function (\Infocyph\Webrick\Router\Route\Collection $routes) use ($signKey, $signTtl): void {
            Response::bindUrlServices($routes, $signKey, $signTtl);
        },
        fallbackAliasesFromRegistrar: true
    );
}

uses()->in('Feature');
