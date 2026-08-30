<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Contract for compiled route matchers.
 *
 * The production hot path uses matchOutcome() with an already-normalized
 * method/host/path tuple. match() remains the compatibility/error-oriented
 * surface for direct callers.
 */
interface MatcherInterface
{
    public function add(CompiledRoute $route): void;

    public function canBootFromCache(): bool;

    public function enableCache(string $cacheLocation): self;

    public function finalize(): void;

    /**
     * Compatibility matcher surface.
     *
     * @return array{0:CompiledRoute,1:array<string,string>}
     *
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function match(string $method, string $host, string $path): array;

    /**
     * Match a tuple already normalized at the routing-input trust boundary.
     *
     * @param non-empty-string $method Canonical HTTP method.
     * @param non-empty-string $host Canonical host or '*'.
     * @param non-empty-string $path Canonical absolute path.
     */
    public function matchOutcome(string $method, string $host, string $path): MatchOutcome;

    /** @return list<string> */
    public function middlewareRequirements(): array;
}
