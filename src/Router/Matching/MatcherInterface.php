<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Contract for canonical compiled route matchers. */
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
     * Strict compiled-runtime surface. FOUND outcomes may carry only routeIndex,
     * allowing persisted/generated matchers to avoid route materialization.
     */
    public function matchCompiledOutcome(string $method, string $host, string $path): MatchOutcome;

    /** Generic/development surface returning a materialized route on FOUND. */
    public function matchOutcome(string $method, string $host, string $path): MatchOutcome;

    /** @return list<string> */
    public function middlewareRequirements(): array;
}
