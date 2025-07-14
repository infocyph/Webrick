<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Facade;

use Infocyph\Webrick\Interfaces\RouterInterface;
use RuntimeException;

/**
 * 📌 Public static entry-point — “Laravel-style” façade.
 *
 * Examples
 * --------
 * ```php
 * use Infocyph\Webrick\Router\Facade\Router;
 *
 * Router::get('/ping', fn () => 'pong');
 * Router::group(prefix: '/v1', middleware: [Auth::class], function (RouterInterface $r) {
 *     $r->post('/login',  [AuthController::class, 'login']);
 * });
 * ```
 *
 * The actual router instance is injected once (usually during bootstrap)
 * and every subsequent static call is proxied to that instance.
 */
final class Router
{
    /** Concrete router implementation (singleton). */
    private static ?RouterInterface $instance = null;

    /**
     * Inject the concrete router at runtime.
     *
     * Call this *once* during application bootstrap:
     *
     * ```php
     * Router::setInstance($container->get(RouterInterface::class));
     * ```
     */
    public static function setInstance(RouterInterface $router): void
    {
        self::$instance = $router;
    }

    /**
     * Fetch the live router or raise if none was bound.
     *
     * @throws RuntimeException when called before `setInstance()`
     */
    private static function getInstance(): RouterInterface
    {
        if (self::$instance === null) {
            throw new RuntimeException(
                'Router façade used before a concrete instance was set.'
            );
        }

        return self::$instance;
    }

    /**
     * Forward any static call to the concrete router.
     *
     * @param  non-empty-string $method
     * @param  array<int,mixed> $args
     * @return mixed
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        $router = self::getInstance();

        if (!method_exists($router, $method)) {
            throw new RuntimeException(
                sprintf('Method %s::%s() does not exist on concrete router.', $router::class, $method)
            );
        }

        return $router->$method(...$args);
    }

    /**
     * Façade is a purely static utility — no instances allowed.
     */
    private function __construct()
    {
    }
}
