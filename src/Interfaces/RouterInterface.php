<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * Application router = PSR-15 request handler *plus*
 * fluent route-registration helpers and URL generator.
 */
interface RouterInterface extends RequestHandlerInterface
{
    /* ---------- generic registration ---------- */
    public function addRoute(string $method, string $path, callable $handler): RouteInterface;

    /* ---------- HTTP-verb shortcuts ------------ */
    public function get(string $path, callable $handler): RouteInterface;
    public function post(string $path, callable $handler): RouteInterface;
    public function put(string $path, callable $handler): RouteInterface;
    public function patch(string $path, callable $handler): RouteInterface;
    public function delete(string $path, callable $handler): RouteInterface;
    public function head(string $path, callable $handler): RouteInterface;
    public function options(string $path, callable $handler): RouteInterface;

    /* ---------- helpers ------------------------ */
    /**
     * Generate a URL from a named route.
     *
     * @param array<string,string|int|float> $params  placeholder values
     * @throws \RuntimeException if the route or parameters are invalid
     */
    public function urlFor(string $name, array $params = [], bool $absolute = false): string;
}
