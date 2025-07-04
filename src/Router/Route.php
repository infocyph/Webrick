<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Router\Runtime\Route as RuntimeRoute;

/**
 * Tiny static façade mirroring {@see Router}’s fluent API.
 *
 * ```php
 * Route::get('/users', fn() => 'hi');
 * Route::group(['prefix' => 'admin'], function () { … });
 * ```
 */
final class Route
{
    public static function __callStatic(string $m, array $a): mixed
    {
        return Router::instance()->$m(...$a);
    }

    /* give IDEs a hint */
    public static function get(string $p, callable $h): RuntimeRoute   {}
    public static function post(string $p, callable $h): RuntimeRoute  {}
    public static function put(string $p, callable $h): RuntimeRoute   {}
    public static function patch(string $p, callable $h): RuntimeRoute {}
    public static function delete(string $p, callable $h): RuntimeRoute{}
    public static function head(string $p, callable $h): RuntimeRoute  {}
    public static function options(string $p, callable $h): RuntimeRoute{}
}
