<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class DynamicMatcher implements MatcherInterface
{
    /**
     * $indexed[host][verb][segments] = list<CompiledRoute>
     * e.g. "api.example.com" → "GET" → 3 → [...]
     */
    private array $indexed = [];

    public function add(CompiledRoute $route): void
    {
        $host = strtolower($route->getDomain() ?? '*');
        $verb = $route->getMethod();
        $arity  = $route->getPathLength();

        // ── optional duplicate guard ──────────────────────────────────
        foreach ($this->indexed[$host][$verb] ?? [] as $r) {
            if ($r->getPath() === $route->getPath()) {
                throw new \LogicException("Duplicate dynamic route {$verb} {$host}{$r->getPath()}");
            }
        }

        $this->indexed[$host][$verb][$arity][] = $route;
    }

    public function match(string $method, string $host, string $path): array
    {
        $host = strtolower($host);

        // -----------------------------------------------------------------
        // 1. Calculate candidate buckets             (HEAD → GET fallback)
        // -----------------------------------------------------------------
        $verbs = [$method];
        if ($method === 'HEAD') {
            $verbs[] = 'GET';
        }
        if ($method === 'OPTIONS') {
            $verbs = array_keys($this->indexed[$host] ?? []);
        }

        $arity = substr_count(trim($path, '/'), '/') + ($path !== '/' ? 1 : 0);
        $candidates = [];
        foreach ([$host, '*'] as $hKey) {
            foreach ($verbs as $vKey) {
                $candidates = array_merge(
                    $candidates,
                    $this->indexed[$hKey][$vKey][$arity] ?? []
                );
            }
        }

        if ($candidates === []) {
            throw new RouteNotFoundException($method, $path);
        }

        // -----------------------------------------------------------------
        // 2. Regex scan (ordered) + param extraction
        // -----------------------------------------------------------------
        $allowed = [];

        foreach ($candidates as $route) {
            if (!\preg_match($route->getRegex(), $path, $m)) {
                continue;                             // path miss
            }

            if ($route->getMethod() !== $method && !($method === 'HEAD' && $route->getMethod() === 'GET')) {
                $allowed[] = $route->getMethod();    // verb miss
                continue;
            }

            return [$route, $this->paramsFrom($route, $m)];
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException($method, $path, array_unique($allowed));
        }

        throw new RouteNotFoundException($method, $path);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------
    /**
     * @return array<string,string>
     */
    private function paramsFrom(CompiledRoute $route, array $matches): array
    {
        $map = [];
        $names = $route->getVariables();               // 0-based list
        foreach ($names as $i => $name) {
            if (isset($matches[$i + 1])) {
                $map[$name] = $matches[$i + 1];
            }
        }
        return $map;
    }
}
