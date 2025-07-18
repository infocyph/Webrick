<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class MergedMatcher implements MatcherInterface
{
    private array $hosts = [];

    public function add(CompiledRoute $route): void
    {
        $host = $this->normalizeHost($route->getDomain());
        $method = strtoupper($route->getMethod());

        $this->ensureHostBucket($host);

        if ($route->isDynamic()) {
            $this->addDynamic($host, $method, $route);
        } else {
            $this->addStatic($host, $method, $route);
        }
    }

    public function match(string $method, string $host, string $path): array
    {
        $host = strtolower($host);
        $methodU = strtoupper($method);
        $allowed = [];

        // 1) static literal
        if ($result = $this->matchStatic($host, $methodU, $path, $allowed)) {
            return $result;
        }

        // 2) dynamic (trie)
        if ($result = $this->matchTrie($host, $methodU, $path)) {
            return $result;
        }

        // 3) final decision
        if ($allowed !== []) {
            throw new MethodNotAllowedException($methodU, $path, array_unique($allowed));
        }

        throw new RouteNotFoundException($methodU, $path);
    }

    //──────────────────────────────────────────────────────────────────────────
    // add() helpers
    //──────────────────────────────────────────────────────────────────────────

    private function normalizeHost(?string $host): string
    {
        return strtolower($host ?? '*');
    }

    private function ensureHostBucket(string $host): void
    {
        $this->hosts[$host] ??= [
            'static' => [],    // [ method => [ path => CompiledRoute ] ]
            'trie' => $this->newTrieNode(),
        ];
    }

    private function addStatic(string $host, string $method, CompiledRoute $route): void
    {
        $path = $route->getPath();

        if (isset($this->hosts[$host]['static'][$method][$path])) {
            throw new \LogicException("Route $method $host$path already registered");
        }

        $this->hosts[$host]['static'][$method][$path] = $route;
    }

    private function addDynamic(string $host, string $method, CompiledRoute $route): void
    {
        $segments = $this->splitPath($route->getPath());
        $node = &$this->hosts[$host]['trie'];

        foreach ($segments as $seg) {
            if ($this->isParam($seg)) {
                $this->ensureParamChild($node, $seg);
                $node = &$node['param'];
            } else {
                $this->ensureStaticChild($node, $seg);
                $node = &$node['children'][$seg];
            }
        }

        $node['routes'][$method] = $route;
    }

    private function newTrieNode(): array
    {
        return [
            'children' => [],   // exact-segment → node
            'param' => null, // node or null
            'varName' => null, // only set on param-nodes
            'routes' => [],   // method → CompiledRoute
        ];
    }

    private function ensureStaticChild(array &$node, string $seg): void
    {
        $node['children'][$seg] ??= $this->newTrieNode();
    }

    private function ensureParamChild(array &$node, string $seg): void
    {
        if ($node['param'] === null) {
            $child = $this->newTrieNode();
            $child['varName'] = trim($seg, '{}');
            $node['param'] = $child;
        }
    }

    //──────────────────────────────────────────────────────────────────────────
    // match() helpers
    //──────────────────────────────────────────────────────────────────────────

    /**
     * Try literal lookup in static table.
     *
     * @return array|null  [route, params] or null
     */
    private function matchStatic(
        string $host,
        string $method,
        string $path,
        array &$allowed,
    ): ?array {
        foreach ([$host, '*'] as $h) {
            $methodTable = $this->hosts[$h]['static'][$method] ?? [];
            if (isset($methodTable[$path])) {
                return [$methodTable[$path], []];
            }

            // collect allowed verbs if path exists under other methods
            if (!empty($this->hosts[$h]['static'])) {
                foreach ($this->hosts[$h]['static'] as $m => $paths) {
                    if (isset($paths[$path])) {
                        $allowed[] = $m;
                    }
                }
            }
        }

        // HEAD → GET fallback
        if ($method === 'HEAD') {
            return $this->matchStatic($host, 'GET', $path, $allowed);
        }

        return null;
    }

    /**
     * Walk the trie for dynamic routes.
     *
     * @return array|null  [route, params] or null
     */
    private function matchTrie(string $host, string $method, string $path): ?array
    {
        $node = $this->hosts[$host]['trie']
            ?? $this->hosts['*']['trie']
            ?? null;

        if ($node === null) {
            return null;
        }

        $segments = $this->splitPath($path);
        $params = [];

        foreach ($segments as $seg) {
            if (isset($node['children'][$seg])) {
                $node = $node['children'][$seg];
                continue;
            }

            if ($node['param'] !== null) {
                $params[$node['param']['varName']] = $seg;
                $node = $node['param'];
                continue;
            }

            return null;
        }

        // exact node reached
        if (isset($node['routes'][$method])) {
            return [$node['routes'][$method], $params];
        }

        // HEAD → GET fallback
        if ($method === 'HEAD' && isset($node['routes']['GET'])) {
            return [$node['routes']['GET'], $params];
        }

        // method mismatch on a matching path
        if (!empty($node['routes'])) {
            throw new MethodNotAllowedException(
                $method,
                $path,
                array_keys($node['routes']),
            );
        }

        return null;
    }

    private function splitPath(string $path): array
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    private function isParam(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }
}
