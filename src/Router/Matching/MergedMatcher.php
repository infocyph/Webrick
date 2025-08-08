<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
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
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    public function enableCache(string $file): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $file;
        return $this;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        if ($this->cacheEnabled && !file_exists($this->cacheFile)) {
            if ($this->hosts !== []) {          // routes were added → dump
                $this->dumpCache();
            }
            /* if hosts are empty but file exists we’ll lazy-load on match() */
        }
        $this->finalized = true;
    }

    /*------------------------------------------------------------------------
     * 4.  Cache-writing helpers  (new)
     *----------------------------------------------------------------------*/
    private function dumpCache(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        $php  = "<?php\nreturn " . $this->exportArray($this->hosts) . ";\n";
        $tmp  = $this->cacheFile . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        @chmod($tmp, 0664);
        @rename($tmp, $this->cacheFile);
    }

    private function exportArray(array $a, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);
        $out    = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . var_export($k, true) . ' => ';
            $out .= is_array($v)
                ? $this->exportArray($v, $depth + 1)
                : $this->exportValue($v, $depth + 1);
            $out .= ",\n";
        }
        return $indent . rtrim($out, ",\n") . "\n" . $indent . "]";
    }

    private function exportValue(mixed $v, int $d): string
    {
        if ($v instanceof CompiledRoute) {
            return $this->exportRoute($v);
        }
        return is_array($v) ? $this->exportArray($v, $d) : var_export($v, true);
    }

    private function exportRoute(CompiledRoute $r): string
    {
        // fast path – no Closure inside handler
        if (!$this->handlerHasClosure($r->getHandler())) {
            return 'new \\' . CompiledRoute::class . '('
                . var_export($r->getMethod(), true) . ', '
                . var_export($r->getPath(), true) . ', '
                . var_export($r->getHandler(), true) . ', '
                . var_export($r->getDomain(), true) . ', '
                . var_export($r->getMiddlewares(), true) . ', '
                . var_export($r->getName(), true) . ', '
                . ($r->isDynamic() ? 'true' : 'false') . ', '
                . var_export($r->getRegex(), true) . ', '
                . var_export($r->getVariables(), true) . ', '
                . var_export($r->getIndex(), true) . ', '
                . var_export($r->getCorsPolicy(), true) . ', '
                . var_export($r->getSegments(), true)
                . ')';
        }

        // slow path – Closure handler needs serialise
        $blob = ValueSerializer::serialize($r);
        return '\\' . ValueSerializer::class
            . '::unserialize(' . var_export($blob, true) . ')';
    }

    private function handlerHasClosure(callable|array|string $h): bool
    {
        if ($h instanceof Closure) {
            return true;
        }
        if (is_array($h)) {
            return ($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure;
        }
        return false;
    }

    /**
     * [$host] = [
     *   'static' => [verb][path] = Route ,
     *   'single' => [path][verb] = Route ,
     *   'verbs'  => [path] = ['GET'=>true,'POST'=>true …],   //  ← NEW
     *   'trie'   => node
     * ]
     */
    private array $hosts = [];
    private bool $cacheEnabled = false;
    private string $cacheFile = '';
    private bool $cacheLoaded = false;
    private bool $finalized = false;

    /* ────────────────────── public API ────────────────────── */

    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }
        $host = $this->canonicalHost($route->getDomain());
        $verb = strtoupper($route->getMethod());

        $this->hosts[$host] ??= ['static' => [], 'single' => [], 'verbs' => [], 'trie' => $this->newNode()];

        /* static vs dynamic decided **by CompiledRoute** */
        $route->isDynamic()
            ? $this->insertDynamic($host, $verb, $route)
            : $this->insertStatic($host, $verb, $route);
    }

    /** @inheritDoc */
    public function match(string $method, string $host, string $path): array
    {
        // lazy-load cache into $this->hosts once
        if ($this->cacheEnabled && !$this->cacheLoaded) {
            if (!is_file($this->cacheFile)) {
                throw new RouteNotFoundException($method, $path);   // 1) no file ⇒ 404
            }
            /** @var array $data */
            $data = require $this->cacheFile;
            $this->hosts = $data;
            $this->cacheLoaded = true;
        }
        $verb = strtoupper($method);
        $host = strtolower($host);
        static $verbsCache = [];
        $cacheKey = $host . '|' . $path;
        $allowed = $verbsCache[$cacheKey] ?? [];

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

    /* ─────── registration ─────── */
    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        $group = &$this->hosts[$host];

        /* ① route table (as before) ---------------------------------- */
        if (substr_count($path, '/') === 1) {
            $group['single'][$path][$verb] = $r;
        } else {
            if (isset($group['static'][$verb][$path])) {
                throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
            }
            $group['static'][$verb][$path] = $r;
        }

        /* ② verbs set for O(1) 405 tests ----------------------------- */
        $set = &$group['verbs'][$path];
        $set[$verb] = true;
        if ($verb === 'GET') {          // cheap HEAD⇆GET alias
            $set['HEAD'] = true;
        } elseif ($verb === 'HEAD') {
            $set['GET'] = true;
        }
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
        return ['children' => [], 'param' => null, 'routes' => [], 'single' => []];
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
                $node['param']['name'] !== $spec['name'] ||
                $node['param']['regex'] !== $spec['regex']
            ) {
                throw new \LogicException(
                    "Conflicting placeholders at same depth: "
                    . "{$node['param']['name']} vs {$spec['name']}",
                );
            }
            return $node['param']['node'];
        }

        $node['param'] = [
            'name' => $spec['name'],
            'regex' => $spec['regex'],
            'node' => $this->newNode(),
        ];
        return $node['param']['node'];
    }

    /* ────────────────────── matching helpers ────────────────────── */

    /**
     * Static L1-lookup with HEAD→GET & 405 aggregation.
     */
    private function matchStatic(string $host, string $verb, string $path, array &$allowed): ?array
    {
        foreach ([$host, '*'] as $h) {
            if (!isset($this->hosts[$h]['verbs'][$path])) {
                continue;                       // no such path in this bucket
            }

            $verbs = $this->hosts[$h]['verbs'][$path];

            /* OPTIONS ⇒ first verb wins (spec) */
            if ($verb === 'OPTIONS') {
                $first = array_key_first($verbs);
                return [$this->hosts[$h]['static'][$first][$path] ?? $this->hosts[$h]['single'][$path][$first], []];
            }

            /* HEAD fallback handled by verbs-set alias */
            if (isset($verbs[$verb])) {
                $v = ($verb === 'HEAD' && !isset($this->hosts[$h]['static']['HEAD'][$path])) ? 'GET' : $verb;
                $route = $this->hosts[$h]['static'][$v][$path] ?? $this->hosts[$h]['single'][$path][$v];
                return [$route, []];
            }

            /* gather for 405 */
            $allowed = array_merge($allowed, array_keys($verbs));
        }
        return null;
    }

    /* ---------- trie descent ---------- */

    private function matchTrie(string $host, string $verb, string $path, array &$allowed): ?array
    {
        $root = $this->hosts[$host]['trie'] ?? ($this->hosts['*']['trie'] ?? null);
        if ($root === null) {
            return null;
        }

        $hit = null;
        if (!$this->walk($root, $this->explodePath($path), 0, $verb, [], $allowed, $hit)) {
            return null;            // no match
        }

        return $hit;                // [$route, $params]
    }


    private function walk(
        array $node,
        array $seg,
        int $i,
        string $verb,
        array $params,
        array &$allowed,
        ?array &$hit,                //  ← out-param
    ): bool {
        if ($i === \count($seg)) {                   // leaf
            return $this->pickRoute($node, $verb, $params, $allowed, $hit);
        }

        $piece = $seg[$i];

        /* 1) literal */
        if (isset($node['children'][$piece]) &&
            $this->walk($node['children'][$piece], $seg, $i + 1, $verb, $params, $allowed, $hit)) {
            return true;
        }

        /* 2) placeholder */
        $p = $node['param'];
        if ($p !== null && preg_match($p['regex'], $piece) === 1 &&
            $this->walk($p['node'], $seg, $i + 1, $verb, $params + [$p['name'] => $piece], $allowed, $hit)) {
            return true;
        }

        return false;
    }

    private function pickRoute(
        array $node,
        string $verb,
        array $params,
        array &$allowed,
        ?array &$hit,          //  ← out-param
    ): bool {
        if ($verb === 'OPTIONS' && $node['routes']) {
            $hit = [reset($node['routes']), $params];
            return true;
        }
        if (isset($node['routes'][$verb])) {
            $hit = [$node['routes'][$verb], $params];
            return true;
        }
        if ($verb === 'HEAD' && isset($node['routes']['GET'])) {
            $hit = [$node['routes']['GET'], $params];
            return true;
        }

        if ($node['routes']) {
            $allowed = array_merge($allowed, array_keys($node['routes']));
        }
        return false;
    }

    /* ────────────────────── utils ────────────────────── */

    private function explodePath(string $p): array
    {
        $t = trim($p, '/');
        return $t === '' ? [] : explode('/', $t);
    }
}
