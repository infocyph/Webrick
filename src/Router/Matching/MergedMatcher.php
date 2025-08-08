<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * High-speed matcher
 * ─────────────────────────────────────────────────────────────
 *  • O(1) hash-look-ups for *all* static paths
 *      hosts[host]['static'][path][verb] = Route
 *  • Radix-style trie for dynamic placeholders
 *  • Optional single-file cache (enableCache + finalize)
 */
final class MergedMatcher implements MatcherInterface
{
    /*──────────── factory ────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    /*──────────── cache API ──────────*/
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

        /* write cache only if matcher built table and file is absent */
        if ($this->cacheEnabled && !is_file($this->cacheFile) && $this->hosts !== []) {
            $this->dumpCache();
        }
        $this->finalized = true;
    }

    /*──────────── Route registration ──────────*/
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalHost($route->getDomain());
        $verb = strtoupper($route->getMethod());

        $this->hosts[$host] ??= ['static' => [], 'trie' => $this->newNode()];

        $route->isDynamic()
            ? $this->insertDynamic($host, $verb, $route)
            : $this->insertStatic($host, $verb, $route);
    }

    /*──────────── Runtime match ───────────────*/
    public function match(string $method, string $host, string $path): array
    {
        /* lazy-load single cache file */
        if ($this->cacheEnabled && !$this->cacheLoaded) {
            if (!is_file($this->cacheFile)) {
                throw new RouteNotFoundException($method, $path);
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

        /* ① full-path static table */
        if ($hit = $this->matchStatic($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ② trie descent */
        if ($hit = $this->matchTrie($host, $verb, $path, $allowed)) {
            return $hit;
        }

        /* ③ verdict */
        if ($allowed !== []) {
            throw new MethodNotAllowedException($verb, $path, array_values(array_unique($allowed)));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /*---------------------------------------------------------------------
     *  Build-time helpers
     *-------------------------------------------------------------------*/
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

    /*—— static route insertion ——*/
    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        $table = &$this->hosts[$host]['static'];

        if (isset($table[$path][$verb])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $table[$path][$verb] = $r;
    }

    /*—— dynamic route insertion ——*/
    private function insertDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $node = &$this->hosts[$host]['trie'];
        foreach ($r->getSegments() as $seg) {
            if ($seg['type'] === 'lit') {
                $node = &$this->literalChild($node, $seg['val']);
            } else {
                $node = &$this->paramChild($node, $seg);
            }
        }
        if (isset($node['routes'][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$host}{$r->getPath()}");
        }
        $node['routes'][$verb] = $r;
    }

    private function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }

    private function &literalChild(array &$n, string $s): array
    {
        $n['children'][$s] ??= $this->newNode();
        return $n['children'][$s];
    }

    private function &paramChild(array &$n, array $spec): array
    {
        if ($n['param'] !== null) {
            if ($n['param']['name'] !== $spec['name'] || $n['param']['regex'] !== $spec['regex']) {
                throw new \LogicException("Conflicting placeholders at same depth");
            }
            return $n['param']['node'];
        }
        $n['param'] = ['name' => $spec['name'], 'regex' => $spec['regex'], 'node' => $this->newNode()];
        return $n['param']['node'];
    }

    /*---------------------------------------------------------------------
     *  Matching helpers
     *-------------------------------------------------------------------*/
    private function matchStatic(string $host, string $verb, string $path, array &$allowed): ?array
    {
        foreach ([$host, '*'] as $h) {
            $map = $this->hosts[$h]['static'][$path] ?? null;
            if ($map === null) {
                continue;
            }

            /* OPTIONS — first verb wins */
            if ($verb === 'OPTIONS') {
                return [$map[array_key_first($map)], []];
            }

            if (isset($map[$verb])) {           // exact verb
                return [$map[$verb], []];
            }
            if ($verb === 'HEAD' && isset($map['GET'])) { // HEAD→GET
                return [$map['GET'], []];
            }

            /* gather for 405 */
            $allowed = array_merge($allowed, array_keys($map));
        }
        return null;
    }

    /*—— radix trie descent ——*/
    private function matchTrie(string $host, string $verb, string $path, array &$allowed): ?array
    {
        $root = $this->hosts[$host]['trie'] ?? ($this->hosts['*']['trie'] ?? null);
        if (!$root) {
            return null;
        }

        $hit = null;
        if (!$this->walk($root, $this->explode($path), 0, $verb, [], $allowed, $hit)) {
            return null;
        }
        return $hit;   // [$route,$params]
    }

    private function walk(
        array $node,
        array $seg,
        int $i,
        string $verb,
        array $params,
        array &$allowed,
        ?array &$hit,
    ): bool {
        if ($i === count($seg)) {
            return $this->leafPick($node, $verb, $params, $allowed, $hit);
        }

        $piece = $seg[$i];
        if (isset($node['children'][$piece]) &&
            $this->walk($node['children'][$piece], $seg, $i + 1, $verb, $params, $allowed, $hit)) {
            return true;
        }
        $p = $node['param'];
        if ($p && preg_match($p['regex'], $piece) === 1 &&
            $this->walk($p['node'], $seg, $i + 1, $verb, $params + [$p['name'] => $piece], $allowed, $hit)) {
            return true;
        }
        return false;
    }

    private function leafPick(
        array $node,
        string $verb,
        array $params,
        array &$allowed,
        ?array &$hit,
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

    /* utils */
    private function explode(string $p): array
    {
        $t = trim($p, '/');
        return $t === '' ? [] : explode('/', $t);
    }

    /*---------------------------------------------------------------------
     *  Cache export helpers (unchanged API)
     *-------------------------------------------------------------------*/
    private function dumpCache(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }
        $php = "<?php\nreturn " . $this->exportArray($this->hosts) . ";\n";
        $tmp = $this->cacheFile . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        @chmod($tmp, 0664);
        @rename($tmp, $this->cacheFile);
    }

    private function exportArray(array $a, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . var_export($k, true) . ' => ';
            $out .= is_array($v) ? $this->exportArray($v, $depth + 1)
                : $this->exportValue($v, $depth + 1);
            $out .= ",\n";
        }
        return $indent . rtrim($out, ",\n") . "\n" . $indent . "]";
    }

    private function exportValue(mixed $v, int $d): string
    {
        return $v instanceof CompiledRoute
            ? $this->exportRoute($v)
            : (is_array($v) ? $this->exportArray($v, $d) : var_export($v, true));
    }

    private function exportRoute(CompiledRoute $r): string
    {
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
        return '\\' . ValueSerializer::class . '::unserialize('
            . var_export(ValueSerializer::serialize($r), true) . ')';
    }

    private function handlerHasClosure(callable|array|string $h): bool
    {
        return $h instanceof Closure
            || (is_array($h) && (($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure));
    }

    /*---------------------------------------------------------------------
     *  State
     *-------------------------------------------------------------------*/
    private array $hosts = [];   // host-bucket data
    private bool $cacheEnabled = false;
    private string $cacheFile = '';
    private bool $cacheLoaded = false;
    private bool $finalized = false;
}
