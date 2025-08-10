<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Application router = fluent registrar **plus** PSR-15 handler.
 *
 * Adding routes returns the freshly-built Route so
 * you can chain `.withName()` / `.withMiddleware()`.
 */
interface RouterInterface extends RequestHandlerInterface
{
    /* ---------- registration (generic) ---------- */
    public function addRoute(
        string $method,
        string $path,
        callable $handler,
    ): RouteInterface;

    /* ---------- HTTP verb shortcuts ------------- */
    public function get(string $path, callable $handler): RouteInterface;

    public function post(string $path, callable $handler): RouteInterface;

    public function put(string $path, callable $handler): RouteInterface;

    public function patch(string $path, callable $handler): RouteInterface;

    public function delete(string $path, callable $handler): RouteInterface;

    public function head(string $path, callable $handler): RouteInterface;

    public function options(string $path, callable $handler): RouteInterface;

    /* ---------- PSR-15 ---------- */
    public function handle(ServerRequestInterface $request): ResponseInterface;

    /* ---------- URL generator --- */
    /**
     * Build a URI from a named route.
     *
     * @param array<string,string|int|float> $params Placeholder values.
     * @throws RuntimeException If name or params invalid.
     */
    public function urlFor(
        string $name,
        array $params = [],
        bool $absolute = false,
    ): string;
}
