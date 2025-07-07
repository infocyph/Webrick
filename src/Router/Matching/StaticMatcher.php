<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Constant-time matcher for *static* routes.
 *
 * Internal table shape:
 *     [$host][$path][$method] = CompiledRoute
 * Host key “*” is used for routes without an explicit domain.
 */
final class StaticMatcher implements MatcherInterface
{
    /** @var array<string,array<string,array<string,CompiledRoute>>> */
    private array $table = [];

    public function add(CompiledRoute $route): void
    {
        $host = strtolower($route->getDomain() ?? '*');
        $path = $route->getPath();
        $verb = $route->getMethod();

        // ── duplicate detection ───────────────────────────────────
        if (isset($this->table[$host][$path][$verb])) {
            throw new \LogicException("Route $verb $host$path already registered");
        }

        $this->table[$host][$path][$verb] = $route;
    }

    public function match(string $method, string $host, string $path): array
    {
        $host = strtolower($host);

        foreach ([$host, '*'] as $h) {
            $bucket = $this->table[$h][$path] ?? null;
            if ($bucket === null) {
                continue;
            }

            // HEAD falls back to GET if not explicitly declared
            if ($method === 'HEAD' && !isset($bucket['HEAD']) && isset($bucket['GET'])) {
                return [$bucket['GET'], /*params*/ []];
            }

            if (isset($bucket[$method])) {
                return [$bucket[$method], /*params*/ []];
            }

            throw new MethodNotAllowedException($method, $path, array_keys($bucket));
        }

        throw new RouteNotFoundException($method, $path);
    }
}

