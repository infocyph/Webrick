<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;

/**
 * One-pass matcher that handles
 *   • static routes via O(1) hash-tables
 *   • dynamic routes via a compact radix-like trie
 *
 * The trie stores at most one placeholder child per depth; that child
 * carries its compiled PCRE so constraints are enforced in-place.
 */
final class MergedMatcher implements MatcherInterface
{
    /**
     * [$host][ 'static' => [verb][path] = Route ,
     *          'trie'   => node ]
     *
     * @var array<string,array{static:array<string,array<string,CompiledRoute>>,trie:array}>
     */
    private array $hosts = [];

    /* ────────────────────────────── public API ─────────────────────────── */

    public function add(CompiledRoute $route): void
    {
        $host   = $this->normaliseHost($route->getDomain());
        $method = strtoupper($route->getMethod());

        $this->ensureHostBucket($host);

        $route->isDynamic()
            ? $this->addDynamic($host, $method, $route)
            : $this->addStatic($host, $method, $route);
    }

    /** @inheritDoc */
    public function match(string $method, string $host, string $path): array
    {
        $host    = strtolower($host);
        $verb    = strtoupper($method);
        $allowed = [];

        /* ① exact literal ------------------------------------------------- */
        if ($hit = $this->matchStatic($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ② trie walk ----------------------------------------------------- */
        if ($hit = $this->matchTrie($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ③ verdict ------------------------------------------------------- */
        if ($allowed) {
            throw new MethodNotAllowedException($verb, $path, array_values(array_unique($allowed)));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /* ────────────────────────── registration ──────────────────────────── */

    private function normaliseHost(?string $h): string
    {
        return strtolower($h ?? '*');
    }

    private function ensureHostBucket(string $host): void
    {
        if (!isset($this->hosts[$host])) {
            $this->hosts[$host] = [
                'static' => [],               // verb ⇒ [path⇒route]
                'trie'   => $this->newNode(), // root node
            ];
        }
    }

    /* ---------- static -------------------------------------------------- */

    private function addStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        if (isset($this->hosts[$host]['static'][$verb][$path])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $this->hosts[$host]['static'][$verb][$path] = $r;
    }

    /* ---------- dynamic / trie ----------------------------------------- */

    private function addDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $segments = $this->splitPath($r->getPath());
        $node     = &$this->hosts[$host]['trie'];

        foreach ($segments as $seg) {
            if ($this->isPlaceholder($seg)) {
                $node = &$this->paramChild($node, $seg);   // creates or returns existing
            } else {
                $node = &$this->staticChild($node, $seg);
            }
        }

        /* duplicate‐route guard (same verb + shape) */
        if (isset($node['routes'][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$host}{$r->getPath()}");
        }
        $node['routes'][$verb] = $r;
    }

    /**
     * Structure of a trie node.
     *
     *  children : literal-segment ⇒ node
     *  param    : ?array{ name:string, regex:string, node:array }
     *  routes   : verb ⇒ CompiledRoute
     *
     * @return array<string,mixed>
     */
    private function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }

    private function &staticChild(array &$node, string $seg): array
    {
        $node['children'][$seg] ??= $this->newNode();
        return $node['children'][$seg];
    }

    /**
     * Ensure (or fetch) a single param child per depth.
     *
     * @return array the child *node* (reference)
     */
    private function &paramChild(array &$node, string $segment): array
    {
        if ($node['param'] === null) {
            [$nameRaw, $constraint] = explode(':', trim($segment, '{}'), 2) + [1 => null];

            $node['param'] = [
                'name'  => $nameRaw,
                'regex' => '#\A' . ($constraint
                        ? ConstraintRegistry::buildPattern($constraint)
                        : '[^/]+') . '\z#D',
                'node'  => $this->newNode(),
            ];
        }
        return $node['param']['node'];
    }

    /* ────────────────────────── matching helpers ──────────────────────── */

    /**
     * Static hash-table lookup with HEAD→GET & 405 aggregation.
     */
    private function matchStatic(
        string $host,
        string $verb,
        string $path,
        array  &$allowed
    ): ?array {
        foreach ([$host, '*'] as $bucket) {
            if (!isset($this->hosts[$bucket])) {           // no such host bucket
                continue;
            }

            // HEAD → GET fall-through
            $v = ($verb === 'HEAD' && !isset($this->hosts[$bucket]['static']['HEAD'][$path]))
                ? 'GET'
                : $verb;

            if (isset($this->hosts[$bucket]['static'][$v][$path])) {
                return [$this->hosts[$bucket]['static'][$v][$path], []];
            }

            // collect verbs for 405
            foreach ($this->hosts[$bucket]['static'] as $m => $paths) {
                if (isset($paths[$path])) {
                    $allowed[] = $m;
                }
            }
        }
        return null;
    }

    /**
     * Entry wrapper for the recursive descent.
     */
    private function matchTrie(
        string $host,
        string $verb,
        string $path,
        array  &$allowed
    ): ?array {
        $root = $this->hosts[$host]['trie']
            ?? ($this->hosts['*']['trie'] ?? null);

        return $root ? $this->descend($root, $this->splitPath($path), 0, $verb, [], $allowed)
            : null;
    }

    /**
     * Tail-recursive DFS through the trie.
     *
     * @param array               $node    current node
     * @param string[]            $seg     full segment list
     * @param int                 $i       depth pointer
     * @param array<string,string>$params  accumulated params
     * @param string[]            &$allowed aggregated verbs for 405
     *
     * @return array|null `[route, params]`
     */
    private function descend(
        array  $node,
        array  $seg,
        int    $i,
        string $verb,
        array  $params,
        array  &$allowed
    ): ?array {
        /* leaf — all segments consumed */
        if ($i === \count($seg)) {
            return $this->selectRoute($node, $verb, $params, $allowed);
        }

        $piece = $seg[$i];

        /* 1) exact literal child first */
        if (isset($node['children'][$piece])) {
            if ($hit = $this->descend($node['children'][$piece], $seg, $i + 1, $verb, $params, $allowed)) {
                return $hit;
            }
        }

        /* 2) param child (constraint-guarded) */
        $p = $node['param'];
        if ($p !== null && \preg_match($p['regex'], $piece) === 1) {
            $hit = $this->descend(
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

        return null;                                    // dead end
    }

    /**
     * Decide on verb / HEAD / OPTIONS and maintain 405 bookkeeping.
     */
    private function selectRoute(
        array  $node,
        string $verb,
        array  $params,
        array  &$allowed
    ): ?array {
        /* OPTIONS  → any matching path is fine (1st route) */
        if ($verb === 'OPTIONS' && $node['routes']) {
            return [\reset($node['routes']), $params];
        }

        /* exact verb hit */
        if (isset($node['routes'][$verb])) {
            return [$node['routes'][$verb], $params];
        }

        /* HEAD → GET fallback */
        if ($verb === 'HEAD' && isset($node['routes']['GET'])) {
            return [$node['routes']['GET'], $params];
        }

        /* verb mismatch on existing path */
        if ($node['routes']) {
            $allowed = \array_merge($allowed, \array_keys($node['routes']));
        }
        return null;
    }

    /* ───────────────────────────── utilities ─────────────────────────── */

    private function splitPath(string $p): array
    {
        $t = \trim($p, '/');
        return $t === '' ? [] : \explode('/', $t);
    }

    private function isPlaceholder(string $seg): bool
    {
        return $seg !== '' && $seg[0] === '{' && $seg[-1] === '}';
    }
}
