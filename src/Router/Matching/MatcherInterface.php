<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Contract for route matchers.
 *
 * @phpstan-type CompiledMatch int|array{0:int,1:array<string,string>}|MatchOutcome
 */
interface MatcherInterface
{
    public function add(CompiledRoute $route): void;

    public function canBootFromCache(): bool;

    public function enableCache(string $cacheLocation): self;

    public function enableCacheWrite(bool $enable = true): self;

    public function finalize(): void;

    /**
     * Compatibility matcher surface.
     *
     * @return array{0:CompiledRoute,1:array<string,string>}
     *
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     * @param string $method
     * @param string $host
     * @param string $path
     */
    public function match(string $method, string $host, string $path): array;

    /**
     * Strict already-finalized compiled-runtime surface.
     *
     * Parameter-free FOUND returns the non-negative compiled route index directly.
     * FOUND with route parameters returns [routeIndex, params]. HTTP control-flow
     * misses remain explicit MatchOutcome values. No route object is materialized.
     *
     * @param non-empty-string $method Canonical HTTP method.
     * @param non-empty-string $host Canonical host or '*'.
     * @param non-empty-string $path Canonical absolute path.
     * @return CompiledMatch
     */
    public function matchCompiled(string $method, string $host, string $path): int|array|MatchOutcome;

    /**
     * Generic/dev surface returning the full matched route.
     *
     * @param non-empty-string $method Canonical HTTP method.
     * @param non-empty-string $host Canonical host or '*'.
     * @param non-empty-string $path Canonical absolute path.
     */
    public function matchOutcome(string $method, string $host, string $path): MatchOutcome;

    /** @return list<string> */
    public function middlewareRequirements(): array;
}
