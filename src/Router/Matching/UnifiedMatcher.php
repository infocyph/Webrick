<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

#[\AllowDynamicProperties(false)]
final class UnifiedMatcher implements MatcherInterface
{
    /*──────────────────────── factory ────────────────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    /*──────────────────────── public config ───────────────────*/
    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheDir = rtrim($cacheLocation, '/\\');
        return $this;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        // Cold-dump only once; runtime always loads.
        if ($this->cacheEnabled && !\file_exists($this->cacheDir . DIRECTORY_SEPARATOR . '__root.php')) {
            $this->dumpCacheFiles();
            $this->prefixMap = []; // free build-time memory
        }
        $this->finalized = true;
    }

    /*──────────────────────── route registration ─────────────*/
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->normHost($route->getDomain());
        $method = \strtoupper($route->getMethod());
        $prefix = $this->extractPrefix($route);

        // duplicate guard: host+method+path
        if (isset($this->pathGuard[$host][$method][$route->getPath()])) {
            throw new \LogicException("Duplicate route {$method} {$host}{$route->getPath()}");
        }
        $this->pathGuard[$host][$method][$route->getPath()] = true;

        $this->prefixMap[$prefix][$method][] = $route;
    }

    /*──────────────────────── runtime match ──────────────────*/
    public function match(string $method, string $host, string $path): array
    {
        $method = \strtoupper($method);
        $host = \strtolower($host ?: '*');
        $path = $path === '' ? '/' : $path;

        // shard by first segment (no __root fallback)
        $fileKey = $this->fileKeyForPath($path);
        $group = $this->loadGroup($fileKey);
        if ($group === null) {
            throw new RouteNotFoundException($method, $path);
        }

        // ① static O(1) check
        $allowed = [];
        if ($hit = $this->matchStaticBucket($method, $host, $path, $group['static'] ?? [], $allowed)) {
            return $hit;
        }

        // ② dynamic via shard-local trie
        $root = $group['trie'] ?? null;
        if (!$root) {
            // Shard exists but had only statics; none matched → 404
            throw new RouteNotFoundException($method, $path);
        }

        $hit = null;
        if ($this->trieWalk($root, $this->explodePath($path), 0, $method, $host, [], $allowed, $hit)) {
            return $hit; // [$route, $params]
        }

        $this->throw405or404($method, $path, \array_keys($allowed));
    }

    /*──────────── helpers ────────────────────────────────────*/
    private function fileKeyForPath(string $path): string
    {
        if ($path === '/' || $path === '') {
            return '__root';
        }
        return \explode('/', \ltrim($path, '/'), 2)[0];
    }

    /** Match exact full-path routes in a shard's static bucket. */
    private function matchStaticBucket(
        string $method,
        string $host,
        string $path,
        array $staticBucket,
        array &$allowed,
    ): ?array {
        $map = $staticBucket[$path] ?? null;
        if ($map === null) {
            return null; // no static entry → try trie
        }

        // exact verb
        if (isset($map[$method])) {
            foreach ($map[$method] as $r) {
                if ($this->hostMatches($r, $host)) {
                    return [$r, []];
                }
            }
        }

        // HEAD→GET
        if ($method === 'HEAD' && isset($map['GET'])) {
            foreach ($map['GET'] as $r) {
                if ($this->hostMatches($r, $host)) {
                    return [$r, []];
                }
            }
        }

        // OPTIONS: any first host-matching verb wins
        if ($method === 'OPTIONS') {
            foreach ($map as $verb => $list) {
                foreach ($list as $r) {
                    if ($this->hostMatches($r, $host)) {
                        return [$r, []];
                    }
                }
            }
        }

        // Gather host-filtered allowed verbs → 405 if any
        foreach ($map as $verb => $list) {
            foreach ($list as $r) {
                if ($this->hostMatches($r, $host)) {
                    $allowed[$verb] = true;
                    break;
                }
            }
        }
        // If none allowed, fallthrough lets trie try; if trie misses too → 404
        return null;
    }

    /*──────────────────────── data members ───────────────────*/
    /** @var array<string,array<string,list<CompiledRoute>>>  prefix → method → routes */
    private array $prefixMap = [];     // build-time only
    /** @var array<string,true|array> cached shard contents */
    private array $loadedFiles = [];
    private bool $cacheEnabled = false;
    private string $cacheDir = '';
    private bool $finalized = false;
    /** duplicate guard: host → method → path */
    private array $pathGuard = [];

    /** @var array<string,?array> shard cache for dev mode (static+trie) */
    private array $memGroups = [];

    /*──────────────────────── helpers (build-time) ───────────*/
    private function extractPrefix(CompiledRoute $r): string
    {
        $parts = [];
        foreach ($r->getSegments() as $s) {
            if ($s['type'] !== 'lit') {
                break;
            }
            $parts[] = $s['val'];
        }
        return '/' . \implode('/', $parts);
    }

    private function normHost(?string $h): string
    {
        if ($h === null || $h === '') {
            return '*';
        }
        if (\preg_match('/[\x00-\x20]/', $h)) {
            throw new \InvalidArgumentException("Illegal host name: {$h}");
        }
        return \strtolower(\rtrim($h, '.'));
    }

    /*──────────────────────── cache dump ----------------------*/
    private function dumpCacheFiles(): void
    {
        // Build per-shard structures: ['static' => [path][verb] = list<Route>], 'trie' => node
        $shards = []; // key => ['static' => [], 'trie' => node]
        foreach ($this->prefixMap as $_prefix => $byMethod) {
            foreach ($byMethod as $verb => $routes) {
                foreach ($routes as $r) {
                    $fileKey = $this->fileKeyForPath($r->getPath());
                    $shards[$fileKey] ??= ['static' => [], 'trie' => $this->newNode()];

                    if ($r->isDynamic()) {
                        $this->trieInsert($shards[$fileKey]['trie'], $r, $verb);
                    } else {
                        $p = $r->getPath();
                        $shards[$fileKey]['static'][$p][$verb][] = $r;
                    }
                }
            }
        }

        // write each shard atomically with hash header
        foreach ($shards as $fileKey => $data) {
            $file = "{$this->cacheDir}/{$fileKey}.php";
            if (!\is_dir($d = \dirname($file)) && !@\mkdir($d, 0775, true) && !\is_dir($d)) {
                throw new \RuntimeException("Failed to create cache dir {$d}");
            }

            $payload = [
                'static' => $data['static'],
                'trie' => $data['trie'],
            ];

            $crc = \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));
            $php = "<?php\nreturn [\n"
                . "    '_hash' => " . \var_export($crc, true) . ",\n"
                . "    '_data' => " . $this->exportArray($payload) . ",\n"
                . "];\n";

            $tmp = $file . '.' . \uniqid('', true) . '.tmp';
            \file_put_contents($tmp, $php, \LOCK_EX);
            @\chmod($tmp, 0664);
            @\rename($tmp, $file);

            if (\function_exists('opcache_compile_file')) {
                @\opcache_compile_file($file);
            }
        }
    }

    /* export helpers ----------------------------------------------------*/
    private function exportArray(array $a, int $depth = 0): string
    {
        $indent = \str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . \var_export($k, true) . ' => ';
            $out .= \is_array($v)
                ? $this->exportArray($v, $depth + 1)
                : $this->exportValue($v, $depth + 1);
            $out .= ",\n";
        }
        return $indent . \rtrim($out, ",\n") . "\n" . $indent . "]";
    }

    private function exportValue(mixed $v, int $depth): string
    {
        return $v instanceof CompiledRoute
            ? $this->exportRoute($v)
            : (\is_array($v) ? $this->exportArray($v, $depth) : \var_export($v, true));
    }

    private function exportRoute(CompiledRoute $r): string
    {
        // Fast path – handler has NO Closure
        if (!$this->handlerHasClosure($r->getHandler())) {
            return 'new \\' . CompiledRoute::class . '('
                . \var_export($r->getMethod(), true) . ', '
                . \var_export($r->getPath(), true) . ', '
                . \var_export($r->getHandler(), true) . ', '
                . \var_export($r->getDomain(), true) . ', '
                . \var_export($r->getMiddlewares(), true) . ', '
                . \var_export($r->getName(), true) . ', '
                . ($r->isDynamic() ? 'true' : 'false') . ', '
                . \var_export($r->getRegex(), true) . ', '
                . \var_export($r->getVariables(), true) . ', '
                . \var_export($r->getIndex(), true) . ', '
                . \var_export($r->getCorsPolicy(), true) . ', '
                . \var_export($r->getSegments(), true)
                . ')';
        }

        // Slow path – Closure handler via ValueSerializer
        return '\\' . ValueSerializer::class
            . '::unserialize(' . \var_export(ValueSerializer::serialize($r), true) . ')';
    }

    private function handlerHasClosure(callable|array|string $h): bool
    {
        if ($h instanceof Closure) {
            return true;
        }
        if (\is_array($h)) {
            return ($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure;
        }
        return false;
    }

    /*──────────────────────── helpers (runtime) ---------------*/
    private function loadGroup(string $fileKey): ?array
    {
        /* 1) cached-file mode ------------------------------------------ */
        if ($this->cacheEnabled) {
            $file = "{$this->cacheDir}/{$fileKey}.php";
            if (!isset($this->loadedFiles[$file])) {
                if (!\is_file($file)) {
                    return $this->loadedFiles[$file] = null;
                }
                /** @var array{_hash:string,_data:array} $blob */
                $blob = require $file;
                if (!isset($blob['_hash'], $blob['_data'])) {
                    throw new \RuntimeException("Cache file {$file} missing Hash.");
                }
                $calc = \hash('xxh3', \json_encode($blob['_data'], \JSON_THROW_ON_ERROR));
                if (!\hash_equals($blob['_hash'], $calc)) {
                    throw new \RuntimeException("Cache hash mismatch ($file).");
                }
                $this->loadedFiles[$file] = $blob['_data'];
            }
            return $this->loadedFiles[$file];
        }

        /* 2) dev (in-memory) mode: build shard once (static + trie) ---- */
        if (\array_key_exists($fileKey, $this->memGroups)) {
            return $this->memGroups[$fileKey];
        }

        $bucket = ['static' => [], 'trie' => $this->newNode()];

        foreach ($this->prefixMap as $_prefix => $byMethod) {
            foreach ($byMethod as $verb => $routes) {
                foreach ($routes as $r) {
                    if ($this->fileKeyForPath($r->getPath()) !== $fileKey) {
                        continue;
                    }
                    if ($r->isDynamic()) {
                        $this->trieInsert($bucket['trie'], $r, $verb);
                    } else {
                        $p = $r->getPath();
                        $bucket['static'][$p][$verb][] = $r;
                    }
                }
            }
        }

        // if bucket is completely empty → null (signals 404 at callsite)
        if ($bucket['static'] === [] && $this->isEmptyTrieNode($bucket['trie'])) {
            return $this->memGroups[$fileKey] = null;
        }
        return $this->memGroups[$fileKey] = $bucket;
    }

    private function hostMatches(CompiledRoute $r, string $host): bool
    {
        $need = $r->getDomain();
        return $need === null || $need === '' || \strcasecmp($need, $host) === 0 || $need === '*';
    }

    private function throw405or404(string $m, string $p, array $allowed): never
    {
        if ($allowed !== []) {
            throw new MethodNotAllowedException($m, $p, $allowed);
        }
        throw new RouteNotFoundException($m, $p);
    }

    /*──────────────────────── trie (build + runtime) ───────────────────*/

    /** shard-local trie node: children, param{name,regex,node}|null, routes: verb => list<CompiledRoute> */
    private function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }

    private function &trieLiteralChild(array &$node, string $seg): array
    {
        $node['children'][$seg] ??= $this->newNode();
        return $node['children'][$seg];
    }

    private function &trieParamChild(array &$node, array $spec): array
    {
        if ($node['param'] !== null) {
            if ($node['param']['name'] !== $spec['name'] || $node['param']['regex'] !== $spec['regex']) {
                throw new \LogicException("Conflicting placeholders at same depth");
            }
            return $node['param']['node'];
        }
        $node['param'] = ['name' => $spec['name'], 'regex' => $spec['regex'], 'node' => $this->newNode()];
        return $node['param']['node'];
    }

    private function trieInsert(array &$root, CompiledRoute $r, string $verb): void
    {
        $node = &$root;
        foreach ($r->getSegments() as $seg) {
            if ($seg['type'] === 'lit') {
                $node = &$this->trieLiteralChild($node, $seg['val']);
            } else {
                $node = &$this->trieParamChild($node, $seg);
            }
        }
        $node['routes'][$verb][] = $r;
    }

    private function explodePath(string $p): array
    {
        $t = \trim($p, '/');
        return $t === '' ? [] : \explode('/', $t);
    }

    private function trieWalk(
        array $node,
        array $seg,
        int $i,
        string $method,
        string $host,
        array $params,
        array &$allowed,
        ?array &$hit,
    ): bool {
        if ($i === \count($seg)) { // leaf
            return $this->triePick($node, $method, $host, $params, $allowed, $hit);
        }

        $piece = $seg[$i];

        if (isset($node['children'][$piece]) &&
            $this->trieWalk($node['children'][$piece], $seg, $i + 1, $method, $host, $params, $allowed, $hit)) {
            return true;
        }

        $p = $node['param'];
        if ($p !== null && \preg_match($p['regex'], $piece) === 1 &&
            $this->trieWalk(
                $p['node'],
                $seg,
                $i + 1,
                $method,
                $host,
                $params + [$p['name'] => $piece],
                $allowed,
                $hit,
            )) {
            return true;
        }

        return false;
    }

    private function triePick(
        array $node,
        string $method,
        string $host,
        array $params,
        array &$allowed,
        ?array &$hit,
    ): bool {
        // OPTIONS: any host-matching verb wins
        if ($method === 'OPTIONS' && $node['routes']) {
            foreach ($node['routes'] as $verb => $list) {
                foreach ($list as $r) {
                    if ($this->hostMatches($r, $host)) {
                        $hit = [$r, $params];
                        return true;
                    }
                }
            }
        }

        // exact verb
        if (isset($node['routes'][$method])) {
            foreach ($node['routes'][$method] as $r) {
                if ($this->hostMatches($r, $host)) {
                    $hit = [$r, $params];
                    return true;
                }
            }
        }

        // HEAD→GET
        if ($method === 'HEAD' && isset($node['routes']['GET'])) {
            foreach ($node['routes']['GET'] as $r) {
                if ($this->hostMatches($r, $host)) {
                    $hit = [$r, $params];
                    return true;
                }
            }
        }

        // gather host-filtered allowed
        if ($node['routes']) {
            foreach ($node['routes'] as $verb => $list) {
                foreach ($list as $r) {
                    if ($this->hostMatches($r, $host)) {
                        $allowed[$verb] = true;
                        break;
                    }
                }
            }
        }
        return false;
    }

    private function isEmptyTrieNode(array $n): bool
    {
        return ($n['children'] ?? []) === [] && ($n['param'] ?? null) === null && ($n['routes'] ?? []) === [];
    }
}
