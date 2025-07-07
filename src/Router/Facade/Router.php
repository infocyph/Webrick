<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Facade;

use Infocyph\Webrick\Router\RouterManager;
use Infocyph\Webrick\Router\Contracts\RouterInterface;

/**
 * Static entry-point. 100 % BC-safe: same API as `Router::instance()`
 * but shorter import for controllers & tests.
 */
final class Router
{
    public static function instance(): RouterInterface
    {
        return RouterManager::instance();
    }

    /** Forward verb helpers via __callStatic. */
    public static function __callStatic(string $method, array $args): mixed
    {
        return self::instance()->{$method}(...$args);
    }
}
