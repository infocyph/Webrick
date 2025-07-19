<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;

/**
 * One-pass matcher: static hash-table + radix-like trie for placeholders.
 */
final class MergedMatcher implements MatcherInterface
{
    /** [$host][static|trie] */
    private array $hosts = [];

    /* ─────────────── public API ─────────────── */

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
        $verb    = strtoupper($method);
        $host    = strtolower($host);
        $allowed = [];

        /* ① static O(1) -------------------------------------------------- */
        if ($hit = $this->matchStatic($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ② trie walk ---------------------------------------------------- */
        if ($hit = $this->matchTrie($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ③ verdict ------------------------------------------------------ */
        if ($allowed) {
            throw new MethodNotAllowedException($verb, $path, array_values(array_unique($allowed)));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /* ─────────────── registration helpers ─────────────── */

    private function normaliseHost(?string $h): string
    {
        if ($h === null) {
            return '*';
        }
        // basic ASCII / control-char guard (keep in sync with Utils::normaliseHost)
        if ($h === '' || \preg_match('/[\x00-\x20]/', $h)) {
            throw new \InvalidArgumentException('Illegal host name.');
        }
        return strtolower(rtrim($h, '.'));
    }

    private function ensureHostBucket(string $host): void
    {
        $this->hosts[$host] ??= ['static' => [], 'trie' => $this->newNode()];
    }

    /* ---------- static ----------- */

    private function addStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();

        if (isset($this->hosts[$host]['static'][$verb][$path])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $this->hosts[$host]['static'][$verb][$path] = $r;
    }

    /* ---------- dynamic / trie --- */

    private function addDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $segments = $this->splitPath($r->getPath());
        $node     = &$this->hosts[$host]['trie'];

        foreach ($segments as $seg) {
            $node = $this->isPlaceholder($seg)
                ? $this->paramChild($node, $seg)
                : $this->staticChild($node, $seg);
        }

        if (isset($node['routes'][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$host}{$r->getPath()}");
        }
        $node['routes'][$verb] = $r;
    }

    /** node template */
    private function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }
    private function &staticChild(array &$node, string $seg): array
    {
        $node['children'][$seg] ??= $this->newNode();
        return $node['children'][$seg];
    }
    private function &paramChild(array &$node, string $segment): array
    {
        [$nameRaw, $constraint] = explode(':', trim($segment, '{}'), 2) + [1 => null];
        $regex = '#\A' . ($constraint
                ? ConstraintRegistry::buildPattern($constraint)
                : '[^/]+') . '\z#D';

        /* first placeholder at this depth wins – later mismatches are illegal */
        if ($node['param'] !== null) {
            if ($node['param']['name'] !== $nameRaw || $node['param']['regex'] !== $regex) {
                throw new \LogicException(
                    "Conflicting placeholders at same depth: {{$node['param']['name']}} vs {{$nameRaw}}"
                );
            }
            return $node['param']['node'];
        }

        $node['param'] = ['name' => $nameRaw, 'regex' => $regex, 'node' => $this->newNode()];
        return $node['param']['node'];
    }

    /* ─────────────── matching helpers ─────────────── */

    private function matchStatic(string $host, string $verb, string $path, array &$allowed): ?array
    {
        /* fast bail-out: host bucket absent & no wildcard  */
        if (!isset($this->hosts[$host]) && !isset($this->hosts['*'])) {
            return null;
        }

        foreach ([$host, '*'] as $h) {
            if (!isset($this->hosts[$h])) {
                continue;
            }

            /* OPTIONS → return first matching route (CORS-friendly) */
            if ($verb === 'OPTIONS' && isset($this->hosts[$h]['static'])) {
                foreach ($this->hosts[$h]['static'] as $m => $paths) {
                    if (isset($paths[$path])) {
                        return [$paths[$path], []];
                    }
                }
            }

            $v = ($verb === 'HEAD' && !isset($this->hosts[$h]['static']['HEAD'][$path])) ? 'GET' : $verb;
            if (isset($this->hosts[$h]['static'][$v][$path])) {
                return [$this->hosts[$h]['static'][$v][$path], []];
            }

            /* collect verbs for 405 */
            foreach ($this->hosts[$h]['static'] as $m => $paths) {
                if (isset($paths[$path])) {
                    $allowed[] = $m;
                }
            }
        }
        return null;
    }

    private function matchTrie(string $host, string $verb, string $path, array &$allowed): ?array
    {
        $root = $this->hosts[$host]['trie'] ?? ($this->hosts['*']['trie'] ?? null);
        return $root ? $this->descend($root, $this->splitPath($path), 0, $verb, [], $allowed) : null;
    }

    private function descend(
        array $node,
        array $seg,
        int $i,
        string $verb,
        array $params,
        array &$allowed
    ): ?array {
        if ($i === \count($seg)) {
            return $this->selectRoute($node, $verb, $params, $allowed);
        }

        $piece = $seg[$i];

        /* literal child first */
        if (isset($node['children'][$piece])) {
            if ($hit = $this->descend($node['children'][$piece], $seg, $i + 1, $verb, $params, $allowed)) {
                return $hit;
            }
        }

        /* placeholder child */
        $p = $node['param'];
        if ($p !== null && \preg_match($p['regex'], $piece)) {
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
        return null;
    }

    private function selectRoute(array $node, string $verb, array $params, array &$allowed): ?array
    {
        if ($verb === 'OPTIONS' && $node['routes']) {
            return [\reset($node['routes']), $params];
        }
        if (isset($node['routes'][$verb])) {
            return [$node['routes'][$verb], $params];
        }
        if ($verb === 'HEAD' && isset($node['routes']['GET'])) {
            return [$node['routes']['GET'], $params];
        }
        if ($node['routes']) {
            $allowed = \array_merge($allowed, \array_keys($node['routes']));
        }
        return null;
    }

    /* ─────────────── util ─────────────── */

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
