<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * The RouterInterface extends the PSR-15 RequestHandlerInterface,
 * so it must implement `handle(ServerRequestInterface $request): ResponseInterface`.
 *
 * In addition, it defines methods for registering routes.
 */
interface RouterInterface extends RequestHandlerInterface
{
    /**
     * Register a route for a specific HTTP method.
     */
    public function addRoute(string $method, string $path, callable $handler): RouteInterface;

    /**
     * Shortcut methods for common HTTP verbs.
     */
    public function get(string $path, callable $handler): RouteInterface;
    public function post(string $path, callable $handler): RouteInterface;
    public function put(string $path, callable $handler): RouteInterface;
    public function delete(string $path, callable $handler): RouteInterface;
    public function patch(string $path, callable $handler): RouteInterface;
    public function options(string $path, callable $handler): RouteInterface;
    public function head(string $path, callable $handler): RouteInterface;
}
