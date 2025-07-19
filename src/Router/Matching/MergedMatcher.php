<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * One-pass matcher:
 *   • O(1) hash-tables for static paths
 *   • compact radix-like trie for dynamic placeholders
 *
 *   — now consumes the <segment-spec> that CompiledRoute already prepared —
 */
final class MergedMatcher implements MatcherInterface
{
    /** [$host][ 'static' => [verb][path] = Route , 'trie' => node ] */
    private array $hosts = [];

    /* ────────────────────── public API ────────────────────── */

    public function add(CompiledRoute $route): void
    {
        $host   = $this->canonicalHost($route->getDomain());
        $verb   = strtoupper($route->getMethod());

        $this->hosts[$host] ??= ['static' => [], 'trie' => $this->newNode()];

        /* static vs dynamic decided **by CompiledRoute** */
        $route->isDynamic()
            ? $this->insertDynamic($host, $verb, $route)
            : $this->insertStatic($host, $verb, $route);
    }

    /** @inheritDoc */
    public function match(string $method, string $host, string $path): array
    {
        $verb    = strtoupper($method);
        $host    = strtolower($host);
        $allowed = [];

        /* ① hash-table -------------------------------------------------- */
        if ($hit = $this->matchStatic($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ② trie descent ----------------------------------------------- */
        if ($hit = $this->matchTrie($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ③ verdict ----------------------------------------------------- */
        if ($allowed !== []) {
            throw new MethodNotAllowedException($verb, $path, array_values(array_unique($allowed)));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /* ────────────────────── registration ────────────────────── */

    private function canonicalHost(?string $h): string
    {
        if ($h === null) {
            return '*';
        }
        if ($h === '' || preg_match('/[\x00-\x20]/', $h)) {
            throw new \InvalidArgumentException('Illegal host name.');
        }
        return strtolower(rtrim($h, '.'));
    }

    /* ---------- static ---------- */

    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();

        if (isset($this->hosts[$host]['static'][$verb][$path])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $this->hosts[$host]['static'][$verb][$path] = $r;
    }

    /* ---------- dynamic ---------- */

    private function insertDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $node = &$this->hosts[$host]['trie'];

        foreach ($r->getSegments() as $seg) {
            if ($seg['type'] === 'lit') {
                $node = &$this->literalChild($node, $seg['val']);
                continue;
            }

            // param-segment
            $node = &$this->paramChild($node, $seg);      // $seg is the spec array
        }

        if (isset($node['routes'][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$host}{$r->getPath()}");
        }
        $node['routes'][$verb] = $r;
    }

    /**
     * Node layout:
     *   children : literal-segment ⇒ node
     *   param    : ?array{name,regex,node}
     *   routes   : verb ⇒ CompiledRoute
     */
    private function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }
    private function &literalChild(array &$node, string $seg): array
    {
        $node['children'][$seg] ??= $this->newNode();
        return $node['children'][$seg];
    }
    private function &paramChild(array &$node, array $spec): array
    {
        if ($node['param'] !== null) {
            // ensure identical placeholder at same depth
            if (
                $node['param']['name']  !== $spec['name'] ||
                $node['param']['regex'] !== $spec['regex']
            ) {
                throw new \LogicException(
                    "Conflicting placeholders at same depth: "
                    . "{${node['param']['name']}} vs {${spec['name']}}"
                );
            }
            return $node['param']['node'];
        }

        $node['param'] = [
            'name'  => $spec['name'],
            'regex' => $spec['regex'],
            'node'  => $this->newNode(),
        ];
        return $node['param']['node'];
    }

    /* ────────────────────── matching helpers ────────────────────── */

    /**
     * Static L1-lookup with HEAD→GET & 405 aggregation.
     */
    private function matchStatic(string $host, string $verb, string $path, array &$allowed): ?array
    {
        if (!isset($this->hosts[$host]) && !isset($this->hosts['*'])) {
            return null;                                // host bucket absent
        }

        foreach ([$host, '*'] as $h) {
            if (!isset($this->hosts[$h])) {
                continue;
            }

            /* OPTIONS → first path wins */
            if ($verb === 'OPTIONS') {
                foreach ($this->hosts[$h]['static'] as $m => $paths) {
                    if (isset($paths[$path])) {
                        return [$paths[$path], []];
                    }
                }
            }

            $v = ($verb === 'HEAD' && !isset($this->hosts[$h]['static']['HEAD'][$path]))
                ? 'GET' : $verb;

            if (isset($this->hosts[$h]['static'][$v][$path])) {
                return [$this->hosts[$h]['static'][$v][$path], []];
            }

            /* gather verbs for 405 */
            foreach ($this->hosts[$h]['static'] as $m => $paths) {
                if (isset($paths[$path])) {
                    $allowed[] = $m;
                }
            }
        }
        return null;
    }

    /* ---------- trie descent ---------- */

    private function matchTrie(string $host, string $verb, string $path, array &$allowed): ?array
    {
        $root = $this->hosts[$host]['trie'] ?? ($this->hosts['*']['trie'] ?? null);
        return $root ? $this->walk($root, $this->explodePath($path), 0, $verb, [], $allowed) : null;
    }

    private function walk(
        array $node,
        array $seg,
        int   $i,
        string $verb,
        array $params,
        array &$allowed,
    ): ?array {
        if ($i === \count($seg)) {                    // leaf
            return $this->pickRoute($node, $verb, $params, $allowed);
        }

        $piece = $seg[$i];

        /* 1) literal child */
        if (isset($node['children'][$piece])) {
            if ($hit = $this->walk($node['children'][$piece], $seg, $i + 1, $verb, $params, $allowed)) {
                return $hit;
            }
        }

        /* 2) param child */
        $p = $node['param'];
        if ($p !== null && preg_match($p['regex'], $piece) === 1) {
            $hit = $this->walk(
                $p['node'],
                $seg,
                $i + 1,
                $verb,
                $params + [$p['name'] => $piece],
                $allowed
            );
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private function pickRoute(array $node, string $verb, array $params, array &$allowed): ?array
    {
        if ($verb === 'OPTIONS' && $node['routes']) {           // any will do
            return [reset($node['routes']), $params];
        }
        if (isset($node['routes'][$verb])) {                    // exact
            return [$node['routes'][$verb], $params];
        }
        if ($verb === 'HEAD' && isset($node['routes']['GET'])) {
            return [$node['routes']['GET'], $params];
        }
        if ($node['routes']) {                                  // gather 405
            $allowed = array_merge($allowed, array_keys($node['routes']));
        }
        return null;
    }

    /* ────────────────────── utils ────────────────────── */

    private function explodePath(string $p): array
    {
        $t = trim($p, '/');
        return $t === '' ? [] : explode('/', $t);
    }
}
