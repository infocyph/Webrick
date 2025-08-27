<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Ultra-lean contract that every hot-path matcher must fulfil.
 *
 * Implementations MAY keep additional public methods, but *routing* is always
 * done through {@see match()} for consistency.
 */
interface MatcherInterface
{
    /**
     * Register a single route.
     *
     * This is a convenience method that's used by the RouterKernel to
     * add all routes from the router definition.
     *
     * @param CompiledRoute $route
     */
    public function add(CompiledRoute $route): void;

    /**
     * Resolve a request verb + host + path into the target route + variables.
     *
     * @param non-empty-string $method HTTP verb (already upper-cased)
     * @param non-empty-string $host Lower-cased host without port
     * @param non-empty-string $path Absolute path beginning with “/”
     *
     * @return array{0:CompiledRoute,1:array<string,string>}
     *
     * @throws RouteNotFoundException      No matching path.
     * @throws MethodNotAllowedException   Path found but verb not allowed.
     */
    public function match(string $method, string $host, string $path): array;


    /**
     * Enable caching for the matcher.
     *
     * This method configures the matcher to use caching, storing cache files
     * in the specified directory. The cache directory should be a valid path,
     * and the matcher implementation will handle the specifics of cache
     * creation and retrieval.
     *
     * @param string $cacheLocation Directory path for cache storage.
     */
    public function enableCache(string $cacheLocation): self;

    /**
     * True when the matcher can skip compilation and use an existing cache.
     *
     * This method is used by the RouterKernel to determine whether the
     * matcher can load its compiled routes from a cache, or if it must
     * recompile the routes from scratch.
     *
     * @return bool True if the matcher can boot from cache, false otherwise.
     */
    public function canBootFromCache(): bool;

    /**
     * Finalize the matcher after all routes are added.
     *
     * Some matchers may need to perform additional work after all routes
     * have been added. This step is optional and should be used sparingly.
     * Implementations should be idempotent and safe to call multiple times.
     */
    public function finalize(): void;
}
