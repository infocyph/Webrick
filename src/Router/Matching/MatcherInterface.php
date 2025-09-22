<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Contract for request path matchers used by the router kernel.
 *
 * Implementations are responsible for:
 *  - accepting compiled routes via add() during router warm-up,
 *  - resolving an incoming request (method, host, path) to a CompiledRoute
 *    and extracted parameter map via match(),
 *  - optionally supporting persistent caching of compiled route data.
 *
 * The interface is intentionally minimal — routing is always performed via
 * match() so concrete implementations may expose additional helper methods
 * without affecting the kernel's usage.
 *
 * @package Infocyph\Webrick\Router\Matching
 */
interface MatcherInterface
{
    /**
     * Register a single compiled route with the matcher.
     *
     * Implementations should insert the route into their internal data
     * structures so subsequent match() calls can locate it.
     *
     * @param CompiledRoute $route Compiled route instance to register.
     * @return void
     */
    public function add(CompiledRoute $route): void;

    /**
     * Whether the matcher can boot directly from an existing cache artifact.
     *
     * The RouterKernel uses this to decide whether to attempt a cache-based
     * warm-up or to re-run the registrar to build compiled routes.
     *
     * @return bool True when a valid cache exists and the matcher can load it.
     */
    public function canBootFromCache(): bool;

    /**
     * Enable persistent caching for the matcher.
     *
     * Implementations that support caching should write/read cache artifacts
     * to/from the supplied location. The semantics of the location (file vs
     * directory) are matcher-specific and documented by the concrete class.
     *
     * @param string $cacheLocation Path to cache file or directory (implementation defined).
     * @return static Fluent self for chaining.
     */
    public function enableCache(string $cacheLocation): self;

    /**
     * Finalize the matcher after all routes have been added.
     *
     * Implementations may perform optimization, serialization or integrity
     * checks in this step. The method should be idempotent and safe to call
     * multiple times.
     *
     * @return void
     */
    public function finalize(): void;

    /**
     * Resolve a request method + host + path to a compiled route and variables.
     *
     * @param non-empty-string $method Upper-cased HTTP verb (e.g. "GET").
     * @param non-empty-string $host   Lower-cased host without port (ASCII).
     * @param non-empty-string $path   Absolute request path beginning with '/'.
     *
     * @return array{0:CompiledRoute,1:array<string,string>} Tuple of matched
     *         CompiledRoute and a map of extracted path variables.
     *
     * @throws RouteNotFoundException    When no route matches the path/host.
     * @throws MethodNotAllowedException When a matching path exists but the HTTP verb is not allowed.
     */
    public function match(string $method, string $host, string $path): array;
}
