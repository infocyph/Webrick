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
        [$method, $host, $path] = $this->normalizeRequest($method, $host, $path);

        $group = $this->requireShardForPath($path, $method); // throws 404 if no shard
        $allowed = [];

        // ① static O(1) check
        if ($hit = $this->tryStatic($group, $method, $host, $path, $allowed)) {
            return $hit;
        }

        // ② dynamic via shard-local trie
        if ($hit = $this->tryDynamic($group, $method, $host, $path, $allowed)) {
            return $hit;
        }

        // ③ verdict (host-filtered allowed verbs decide 405 vs 404)
        $this->throw405or404($method, $path, \array_keys($allowed));
    }

    /*──────────────────────── match helpers (decomposed) ─────────────────*/

    /** Normalize verb/host/path once. */
    private function normalizeRequest(string $method, string $host, string $path): array
    {
        return [
            \strtoupper($method),
            \strtolower($host ?: '*'),
            $path === '' ? '/' : $path,
        ];
    }

    /** Fail-fast: fetch shard (static+trie) for $path or throw 404. */
    private function requireShardForPath(string $path, string $method): array
    {
        $fileKey = $this->fileKeyForPath($path);
        $group = $this->loadGroup($fileKey);
        if ($group === null) {
            throw new RouteNotFoundException($method, $path);
        }
        return $group;
    }

    /** Static bucket fast path (isset + centralized verb resolution). */
    private function tryStatic(array $group, string $method, string $host, string $path, array &$allowed): ?array
    {
        /** @var array<string,array<string,list<CompiledRoute>>> $static */
        $static = $group['static'] ?? [];
        $buckets = $static[$path] ?? null;
        if ($buckets === null) {
            return null;
        }

        $hit = null;
        if ($this->selectFromVerbBuckets($buckets, $method, $host, [], $allowed, $hit)) {
            return $hit;
        }
        return null;
    }

    /** Trie path for dynamic routes. Walk only the needed nodes. */
    private function tryDynamic(array $group, string $method, string $host, string $path, array &$allowed): ?array
    {
        $root = $group['trie'] ?? null;
        if (!$root) {
            // Shard exists but had only statics; none matched → 404/405 decided by caller
            return null;
        }

        $hit = null;
        if ($this->trieWalk($root, $this->explodePath($path), 0, $method, $host, [], $allowed, $hit)) {
            return $hit; // [$route, $params]
        }
        return null;
    }

    /** Centralized verb/HEAD/OPTIONS handling + host filtering + 405 aggregation. */
    private function selectFromVerbBuckets(
        array $buckets,                    // verb => list<CompiledRoute>
        string $method,
        string $host,
        array $params,
        array &$allowed,
        ?array &$hit,                        // out-param: [$route, $params]
    ): bool {
        // OPTIONS → first host-matching route across any verb
        if ($method === 'OPTIONS' && $buckets) {
            foreach ($buckets as $list) {
                if ($r = $this->routeFrom($list, $host)) {
                    $hit = [$r, $params];
                    return true;
                }
            }
        }

        // exact verb
        if (isset($buckets[$method]) && ($r = $this->routeFrom($buckets[$method], $host))) {
            $hit = [$r, $params];
            return true;
        }

        // HEAD→GET
        if ($method === 'HEAD' && isset($buckets['GET']) && ($r = $this->routeFrom($buckets['GET'], $host))) {
            $hit = [$r, $params];
            return true;
        }

        // collect allowed verbs respecting host filter
        $this->addAllowedVerbs($buckets, $host, $allowed);
        return false;
    }

    /** Return first route in $list that matches $host, or null. */
    private function routeFrom(array $list, string $host): ?CompiledRoute
    {
        return array_find($list, fn ($r) => $this->hostMatches($r, $host));
    }

    /** Mark verbs as allowed if at least one route for that verb matches $host. */
    private function addAllowedVerbs(array $buckets, string $host, array &$allowed): void
    {
        foreach ($buckets as $verb => $list) {
            if ($this->routeFrom($list, $host)) {
                $allowed[$verb] = true;
            }
        }
    }


    /*──────────── path→shard key ───────────────────────────────────────*/
    private function fileKeyForPath(string $path): string
    {
        if ($path === '/' || $path === '') {
            return '__root';
        }
        return \explode('/', \ltrim($path, '/'), 2)[0];
    }

    /*──────────────────────── data members ───────────────────*/
    /** @var array<string,array<string,list<CompiledRoute>>>  prefix → method → routes */
    private array $prefixMap = [];     // build-time only

    /** @var array<string,true|array> cached shard contents (path → group or null marker) */
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

    /*──────────────────────── cache dump (static+trie per shard) ───────*/
    private function dumpCacheFiles(): void
    {
        $shards = $this->buildShardsFromPrefixMap();

        foreach ($shards as $fileKey => $data) {
            $this->writeShard($fileKey, ['static' => $data['static'], 'trie' => $data['trie']]);
        }
    }

    /** Build per-shard structures: ['static'=>[path][verb]=list<Route>,'trie'=>node] */
    private function buildShardsFromPrefixMap(): array
    {
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
        return $shards;
    }

    private function writeShard(string $fileKey, array $payload): void
    {
        $file = "$this->cacheDir/$fileKey.php";
        if (!\is_dir($d = \dirname($file)) && !@\mkdir($d, 0775, true) && !\is_dir($d)) {
            throw new \RuntimeException("Failed to create cache dir {$d}");
        }

        $crc = $this->hashPayload($payload);

        $php = "<?php\nreturn [\n"
            . "    '_hash' => " . \var_export($crc, true) . ",\n"
            . "    '_data' => " . $this->exportArray($payload) . ",\n"
            . "];\n";

        $this->atomicWrite($file, $php);

        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($file);
        }
    }

    private function hashPayload(array $payload): string
    {
        return \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function atomicWrite(string $file, string $contents): void
    {
        $tmp = $file . '.' . \uniqid('', true) . '.tmp';
        \file_put_contents($tmp, $contents, \LOCK_EX);
        @\chmod($tmp, 0664);
        @\rename($tmp, $file);
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
        if ($this->cacheEnabled) {
            return $this->loadGroupFromCache($fileKey);
        }

        // dev (in-memory) shard build once
        return $this->buildDevGroupOnce($fileKey);
    }

    private function loadGroupFromCache(string $fileKey): ?array
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . $fileKey . '.php';
        if (isset($this->loadedFiles[$file])) {
            return $this->loadedFiles[$file];
        }

        if (!\is_file($file)) {
            return $this->loadedFiles[$file] = null;
        }

        /** @var array{_hash:string,_data:array} $blob */
        $blob = require $file;
        $this->validateCacheBlob($file, $blob);

        return $this->loadedFiles[$file] = $blob['_data'];
    }

    private function validateCacheBlob(string $file, array $blob): void
    {
        if (!isset($blob['_hash'], $blob['_data'])) {
            throw new \RuntimeException("Cache file {$file} missing Hash.");
        }
        $calc = $this->hashPayload($blob['_data']);
        if (!\hash_equals($blob['_hash'], $calc)) {
            throw new \RuntimeException("Cache hash mismatch ($file).");
        }
    }

    /**
     * Iterate all (verb, route) pairs that belong to the given shard key.
     *
     * @return \Generator<array{0:string,1:CompiledRoute}>
     */
    private function iterShardRoutes(string $fileKey): \Generator
    {
        foreach ($this->prefixMap as $byMethod) {
            foreach ($byMethod as $verb => $routes) {
                foreach ($routes as $r) {
                    if ($this->fileKeyForPath($r->getPath()) === $fileKey) {
                        yield [$verb, $r];
                    }
                }
            }
        }
    }

    private function buildDevGroupOnce(string $fileKey): ?array
    {
        if (\array_key_exists($fileKey, $this->memGroups)) {
            return $this->memGroups[$fileKey];
        }

        $static = [];
        $trie = $this->newNode();

        foreach ($this->iterShardRoutes($fileKey) as [$verb, $r]) {
            if ($r->isDynamic()) {
                $this->trieInsert($trie, $r, $verb);
                continue;
            }
            $static[$r->getPath()][$verb][] = $r;
        }

        $bucket = ['static' => $static, 'trie' => $trie];

        return $this->memGroups[$fileKey] =
            ($static === [] && $this->isEmptyTrieNode($trie)) ? null : $bucket;
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
                continue;
            }
            $node = &$this->trieParamChild($node, $seg);
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
        if ($i === \count($seg)) {
            return $this->selectFromVerbBuckets($node['routes'] ?? [], $method, $host, $params, $allowed, $hit);
        }

        $piece = $seg[$i];

        if (isset($node['children'][$piece]) &&
            $this->trieWalk($node['children'][$piece], $seg, $i + 1, $method, $host, $params, $allowed, $hit)) {
            return true;
        }

        $p = $node['param'] ?? null;
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

    private function isEmptyTrieNode(array $n): bool
    {
        return ($n['children'] ?? []) === [] && ($n['param'] ?? null) === null && ($n['routes'] ?? []) === [];
    }
}
