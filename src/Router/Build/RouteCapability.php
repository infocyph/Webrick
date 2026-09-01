<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

final class RouteCapability
{
    public const int CORS = 1 << 4;

    public const int DOMAIN = 1 << 3;

    public const int MIDDLEWARE = 1 << 2;

    public const int PRODUCES = 1 << 5;

    public const int REQUEST = 1 << 0;

    public const int ROUTE_ARGS = 1 << 6;

    public const int SCOPE = 1 << 1;

    private function __construct() {}

    public static function has(int $mask, int $capability): bool
    {
        return ($mask & $capability) !== 0;
    }
}
