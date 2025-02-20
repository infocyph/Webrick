<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * Extends PSR-15's RequestHandlerInterface to provide route registration methods.
 */
interface RouterInterface extends RequestHandlerInterface
{
    public function addRoute(string $method, string $path, callable $handler): RouteInterface;

    public function get(string $path, callable $handler): RouteInterface;

    public function post(string $path, callable $handler): RouteInterface;

    public function put(string $path, callable $handler): RouteInterface;

    public function delete(string $path, callable $handler): RouteInterface;

    public function patch(string $path, callable $handler): RouteInterface;

    public function options(string $path, callable $handler): RouteInterface;

    public function head(string $path, callable $handler): RouteInterface;
}
