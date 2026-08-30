<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Interfaces\RouteInterface;

final class RouteIdentity
{
    private function __construct() {}

    public static function canonicalKey(string $method, ?string $domain, string $path): string
    {
        return strtoupper($method) . "\0" . self::canonicalDomain($domain) . "\0" . $path;
    }

    public static function forRoute(RouteInterface $route): string
    {
        return hash('xxh128', self::canonicalKey($route->getMethod(), $route->getDomain(), $route->getPath()));
    }

    private static function canonicalDomain(?string $domain): string
    {
        if ($domain === null || $domain === '' || $domain === '*') {
            return '*';
        }

        return strtolower(rtrim($domain, '.'));
    }
}
