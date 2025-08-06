<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Fast matcher = static hash-map ➊  +  radix-trie ➋  (+ optional PHP cache).
 *
 * ➊  O(1) lookup for completely static paths.
 * ➋  O(m) descent where  m = segment-count  (no N×regex scans).
 *
 * No domain/host dimension – use one matcher per host if required.
 */
final class UnifiedMatcherOld implements MatcherInterface
{
    /* --------------------------------------------------------------------- *
     * ✧ Data structures
     * --------------------------------------------------------------------- */
    /** [verb][path] ⇒ CompiledRoute */
    private array $static = [];

    /** [path] ⇒ ['GET'=>true,'POST'=>true,…]  for 405 aggregation               */
    private array $verbs = [];

    /** Radix-like trie root. */
    private array $trie;

    /** route-id ⇒ CompiledRoute  (pool used by cache files)                     */
    private array $pool = [];

    /* -------- optional lazy cache ---------------------------------------- */
    private ?string $cacheDir = null;
    /** [groupKey] already included? */
    private array $loadedGroup = [];

    public function __construct()
    {
        $this->trie = self::newNode();
    }

    /* --------------------------------------------------------------------- *
     * ✧ Public API
     * --------------------------------------------------------------------- */

    public function add(CompiledRoute $route): void
    {
        $verb = strtoupper($route->getMethod());
        $id = $route->getIndex();
        $this->pool[$id] = $route;                      // pool for cache files

        if ($route->isDynamic()) {
            $this->insertDynamic($verb, $route, $id);
        } else {
            $this->insertStatic($verb, $route->getPath(), $route);
        }
    }

    /**
     * Turn on lazy loading.  `$dir` must contain files like “batch.php”, “__fallback.php”, …;
     * every file `return`s the array structure produced by {@see dumpCache()}.
     */
    public function enableCache(string $dir): void
    {
        $this->cacheDir = rtrim($dir, '/\\');
    }

    /** @inheritDoc */
    public function match(string $method, string $host, string $path): array
    {
        $verb = strtoupper($method);

        /* 1) Static ------------------------------------------------------ */
        if ($route = $this->static[$verb][$path] ?? null) {
            return [$route, []];
        }

        /* 2) Cached dynamic (group file lazy-load) ----------------------- */
        if ($this->cacheDir && ($hit = $this->matchCached($verb, $path))) {
            return $hit;
        }

        /* 3) In-memory trie --------------------------------------------- */
        [$route, $vars, $allowed] = $this->matchTrie($verb, $path);
        if ($route) {
            return [$route, $vars];
        }

        /* 4) Verdict ----------------------------------------------------- */
        if ($allowed) {
            throw new MethodNotAllowedException($verb, $path, array_keys($allowed));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /* --------------------------------------------------------------------- *
     * ✧ Static routes
     * --------------------------------------------------------------------- */

    private function insertStatic(string $verb, string $path, CompiledRoute $r): void
    {
        if (isset($this->static[$verb][$path])) {
            throw new \LogicException("Duplicate route {$verb} {$path}");
        }
        $this->static[$verb][$path] = $r;

        /* HEAD ⇆ GET alias + verbs set for 405 */
        $this->verbs[$path][$verb] = true;
        if ($verb === 'GET') {
            $this->verbs[$path]['HEAD'] = true;
        }
        if ($verb === 'HEAD') {
            $this->verbs[$path]['GET'] = true;
        }
    }

    /* --------------------------------------------------------------------- *
     * ✧ Dynamic routes – trie insertion
     * --------------------------------------------------------------------- */

    private function insertDynamic(string $verb, CompiledRoute $r, int $id): void
    {
        $node = & $this->trie;
        foreach ($r->getSegments() as $seg) {
            $prepared = ($seg['type'] === 'lit')
                ? $node['children'][$seg['val']] ??= self::newNode()
                : $this->paramChild($node, $seg['name'], $seg['regex']);
            $node = & $prepared;
        }
        if (isset($node['routes'][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$r->getPath()}");
        }
        $node['routes'][$verb] = $id;                   // store only id → smaller dump
    }

    private function &paramChild(array &$node, string $name, string $regex): array
    {
        if ($node['param'] === null) {
            $node['param'] = ['name' => $name, 'regex' => $regex, 'node' => self::newNode()];
        }
        return $node['param']['node'];
    }

    /* --------------------------------------------------------------------- *
     * ✧ Trie matching
     * --------------------------------------------------------------------- */

    /**
     * @return array{CompiledRoute|null,array, array<string,bool>}  route | vars | allowed-verbs
     */
    private function matchTrie(string $verb, string $path): array
    {
        $segments = $path === '/' ? [] : explode('/', trim($path, '/'));
        $allowed = [];
        $hit = $this->walk($this->trie, $segments, 0, $verb, [], $allowed);

        return [$hit ? $this->pool[$hit[0]] : null, $hit[1] ?? [], $allowed];
    }

    /** Recursive descent; returns [routeId, params] on success or null. */
    private function walk(
        array $node,
        array $seg,
        int $i,
        string $verb,
        array $params,
        array &$allowed,
    ): ?array {
        if ($i === \count($seg)) {
            return $this->routeAtLeaf($node, $verb, $params, $allowed);
        }

        $piece = $seg[$i];

        /* literal branch */
        if (isset($node['children'][$piece]) &&
            ($res = $this->walk($node['children'][$piece], $seg, $i + 1, $verb, $params, $allowed))) {
            return $res;
        }

        /* param branch */
        if ($node['param']) {
            $p = $node['param'];
            if (preg_match($p['regex'], $piece) === 1 &&
                ($res = $this->walk(
                    $p['node'],
                    $seg,
                    $i + 1,
                    $verb,
                    $params + [$p['name'] => $piece],
                    $allowed,
                ))) {
                return $res;
            }
        }
        return null;
    }

    private function routeAtLeaf(array $node, string $verb, array $params, array &$allowed): ?array
    {
        if ($verb === 'OPTIONS' && $node['routes']) {
            return [reset($node['routes']), $params];
        }
        if (isset($node['routes'][$verb])) {
            return [$node['routes'][$verb], $params];
        }
        if ($verb === 'HEAD' && isset($node['routes']['GET'])) {
            return [$node['routes']['GET'], $params];
        }
        $allowed += $node['routes'];    // keys only needed
        return null;
    }

    /* --------------------------------------------------------------------- *
     * ✧ Cache matching
     * --------------------------------------------------------------------- */

    private function matchCached(string $verb, string $path): ?array
    {
        $key = $this->firstSeg($path) ?: '__fallback';
        $bucket = $this->loadGroup($key)[$verb] ?? null;
        if (!$bucket) {
            return null;                                    // no routes for this verb
        }

        /* static first */
        if (isset($bucket['static'][$path])) {
            $id = $bucket['static'][$path];
            return [$this->pool[$id], []];
        }

        /* dynamic list */
        foreach ($bucket['dynamic'] ?? [] as $spec) {
            if (preg_match($spec['regex'], $path, $m)) {
                $vars = [];
                foreach ($spec['params'] as $p) {
                    $vars[$p] = $m[$p];
                }
                return [$this->pool[$spec['id']], $vars];
            }
        }
        return null;
    }

    /** load + cache PHP file once */
    private function loadGroup(string $key): array
    {
        if (isset($this->loadedGroup[$key])) {
            return $this->loadedGroup[$key];
        }
        $file = $this->cacheDir . '/' . $key . '.php';
        return $this->loadedGroup[$key] = \is_file($file) ? include $file : [];
    }

    /* --------------------------------------------------------------------- *
     * ✧ Cache dump (helper – call from a deploy script / Console cmd)
     * --------------------------------------------------------------------- */

    /**
     * Walks current matcher and writes 1 PHP file per first segment
     * (plus “__fallback.php”) into $dir.
     * Each file returns:
     *   ['GET'=>['static'=>[…], 'dynamic'=>[…]], 'POST'=>…]
     * Static paths map to route-id, dynamic spec holds regex + param list + id.
     */
    public function dumpCache(string $dir): void
    {
        $groups = [];

        /* static -------------------------------------------------------- */
        foreach ($this->static as $verb => $map) {
            foreach ($map as $path => $route) {
                $g = & $groups[$this->firstSeg($path) ?: '__fallback'][$verb]['static'];
                $g[$path] = $route->getIndex();
            }
        }

        /* dynamic ------------------------------------------------------- */
        $this->collectDynamic($this->trie, '', $groups);

        /* write --------------------------------------------------------- */
        foreach ($groups as $seg => $data) {
            $code = "<?php\nreturn " . var_export($data, true) . ";\n";
            file_put_contents(rtrim($dir, '/\\') . '/' . $seg . '.php', $code);
        }
    }

    private function collectDynamic(array $node, string $prefix, array &$g): void
    {
        foreach ($node['routes'] as $verb => $id) {
            $regex = '#^' . ltrim($prefix, '/') . '$#';
            $params = [];                                // param names captured via (?P<…>)
            if (preg_match_all('/\(\?P<([^>]+)>/', $regex, $m)) {
                $params = $m[1];
            }
            $first = $this->firstSeg($prefix) ?: '__fallback';
            $g[$first][$verb]['dynamic'][] = [
                'regex' => $regex,
                'params' => $params,
                'id' => $id,
            ];
        }
        foreach ($node['children'] as $seg => $child) {
            $this->collectDynamic($child, $prefix . '/' . $seg, $g);
        }
        if ($node['param']) {
            $p = $node['param'];
            $pl = '{' . $p['name'] . '}';
            $this->collectDynamic($p['node'], $prefix . '/' . $pl, $g);
        }
    }

    /* --------------------------------------------------------------------- *
     * ✧ Utils
     * --------------------------------------------------------------------- */

    private static function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }

    private function firstSeg(string $path): string
    {
        $p = ltrim($path, '/');
        $s = str_contains($p, '/') ? strstr($p, '/', true) : $p;
        return $s && $s[0] !== '{' ? $s : '';
    }
}
