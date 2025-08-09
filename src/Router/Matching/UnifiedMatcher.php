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
    private const K_STATIC = 'static';   // [path][verb] = CompiledRoute
    private const K_TRIE = 'trie';     // shard-local trie root
    private const K_CHILDREN = 'children';
    private const K_PARAM = 'param';
    private const K_ROUTES = 'routes';   // [verb] = CompiledRoute

    private const H_HASH = '_hash';
    private const H_DATA = '_data';
    private const SHARD_ROOT = '__root';

    /*──────────── state ────────────*/
    /** @var array<string,array<string,list<CompiledRoute>>> prefix → method → routes (build-time only) */
    private array $prefixMap = [];

    /** cache of loaded shard arrays keyed by absolute file path */
    private array $loadedFiles = [];

    private bool $cacheEnabled = false;
    private string $cacheDir = '';
    private bool $finalized = false;

    /** duplicate guard: host → method → path */
    private array $pathGuard = [];

    /** dev-mode in-memory shards: memGroups[host][bucket] = array|null */
    private array $memGroups = [];

    /** Optional: verify shard hash while loading (dev/CI) */
    private bool $verifyCacheOnLoad = false;

    /*──────────── factory ────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    /*──────────── config ────────────*/
    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheDir = rtrim($cacheLocation, '/\\');
        return $this;
    }

    public function verifyCacheOnLoad(bool $enable = true): self
    {
        $this->verifyCacheOnLoad = $enable;
        return $this;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        // Cold-dump only once; check wildcard root shard as sentinel.
        $sentinel = $this->shardFilePath('*', self::SHARD_ROOT);
        if ($this->cacheEnabled && !\file_exists($sentinel)) {
            $this->dumpCacheFiles();
            $this->prefixMap = []; // free build-time memory
        }
        $this->finalized = true;
    }

    /*──────────── registration ────────────*/
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalRouteHost($route->getDomain());
        $method = \strtoupper($route->getMethod());
        $prefix = $this->extractPrefix($route);

        // duplicate guard: host+method+path
        if (isset($this->pathGuard[$host][$method][$route->getPath()])) {
            throw new \LogicException("Duplicate route {$method} {$host}{$route->getPath()}");
        }
        $this->pathGuard[$host][$method][$route->getPath()] = true;

        $this->prefixMap[$prefix][$method][] = $route;
    }

    /*──────────── runtime match ────────────*/
    public function match(string $method, string $host, string $path): array
    {
        [$method, $host, $path] = $this->normalizeRequest($method, $host, $path);
        $bucket = $this->fileKeyForPath($path);
        $segments = $this->explodePath($path); // split once

        // Load per-host + wildcard groups (two tiny files max)
        $grpHost = $this->loadGroupFor($host, $bucket);
        $grpAny = ($host === '*') ? null : $this->loadGroupFor('*', $bucket);

        if ($grpHost === null && $grpAny === null) {
            throw new RouteNotFoundException($method, $path);
        }

        // use a set to aggregate allowed verbs without duplicates
        $allowedSet = [];

        // ① static O(1) (host then wildcard)
        if ($hit = $this->tryStatic($grpHost, $method, $path, $allowedSet)) {
            return $hit;
        }
        if ($hit = $this->tryStatic($grpAny, $method, $path, $allowedSet)) {
            return $hit;
        }

        // ② dynamic tries (host then wildcard) — reusing pre-split segments
        if ($hit = $this->tryDynamic($grpHost, $method, $segments, $allowedSet)) {
            return $hit;
        }
        if ($hit = $this->tryDynamic($grpAny, $method, $segments, $allowedSet)) {
            return $hit;
        }

        // ③ verdict
        if ($allowedSet !== []) {
            throw new MethodNotAllowedException($method, $path, \array_keys($allowedSet));
        }
        throw new RouteNotFoundException($method, $path);
    }

    /*──────────────────────── match helpers ─────────────────*/

    private function normalizeRequest(string $method, string $host, string $path): array
    {
        return [\strtoupper($method), \strtolower($host ?: '*'), ($path === '' ? '/' : $path)];
    }

    /** Static bucket fast path (per-host shard). */
    private function tryStatic(?array $group, string $method, string $path, array &$allowedSet): ?array
    {
        if ($group === null) {
            return null;
        }
        /** @var array<string,array<string,CompiledRoute>> $static */
        $static = $group[self::K_STATIC] ?? [];
        $map = $static[$path] ?? null;
        if ($map === null) {
            return null;
        }

        if ($r = $this->pickVerbRoute($map, $method)) {
            return [$r, []];
        }

        // collect for 405 (+implicit HEAD when GET exists)
        foreach ($map as $verb => $_) {
            $allowedSet[$verb] = true;
        }
        if (isset($map['GET'])) {
            $allowedSet['HEAD'] = true;
        }
        return null;
    }

    /** Dynamic via shard-local trie (per-host shard). */
    private function tryDynamic(?array $group, string $method, array $segments, array &$allowedSet): ?array
    {
        if ($group === null) {
            return null;
        }
        $root = $group[self::K_TRIE] ?? null;
        if (!$root) {
            return null;
        }

        $hit = null;
        $params = [];
        if ($this->trieWalk($root, $segments, 0, $method, $params, $allowedSet, $hit)) {
            return $hit; // [$route, $params]
        }
        return null;
    }

    /** Unified verb selection for both statics and trie-leaves. */
    private function pickVerbRoute(array $buckets, string $verb): ?CompiledRoute
    {
        if ($verb === 'OPTIONS' && $buckets) {
            if (isset($buckets['GET'])) {
                return $buckets['GET']; // deterministic preference
            }
            /** @var mixed $first */
            $first = \reset($buckets);
            return $first instanceof CompiledRoute ? $first : null;
        }
        if (isset($buckets[$verb])) {
            return $buckets[$verb];
        }
        if ($verb === 'HEAD' && isset($buckets['GET'])) {
            return $buckets['GET'];
        }
        return null;
    }

    /*──────────── path→bucket key ────────────*/
    private function fileKeyForPath(string $path): string
    {
        if ($path === '/' || $path === '') {
            return self::SHARD_ROOT;
        }
        $p = $path[0] === '/' ? \substr($path, 1) : $path;
        $pos = \strpos($p, '/');
        return $pos === false ? $p : \substr($p, 0, $pos);
    }

    /*──────────── build-time helpers ────────────*/
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

    /** Canonicalize route domain to match RouterKernel's host normalization. */
    private function canonicalRouteHost(?string $raw): string
    {
        if ($raw === null || $raw === '' || $raw === '*') {
            return '*';
        }
        $host = \rtrim(\strtolower($raw), '.');

        // disallow spaces/control chars early
        if (\preg_match('/[\x00-\x20]/', $host)) {
            throw new \InvalidArgumentException("Illegal host name: {$raw}");
        }

        // IDN → ASCII (punycode) if available and not already punycoded
        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = @\idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException("Invalid IDN host name: {$raw}");
            }
            $host = $ascii;
        }

        // ensure printable ASCII
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException("Host contains non-ASCII bytes: {$raw}");
        }

        return $host;
    }

    /*──────────── cache dump (per-host *and* per-bucket) ────────────*/
    private function dumpCacheFiles(): void
    {
        // Build shards: $shards[host][bucket] = ['static'=>[path][verb]=Route, 'trie'=>node]
        $shards = [];
        foreach ($this->prefixMap as $_prefix => $byMethod) {
            foreach ($byMethod as $verb => $routes) {
                foreach ($routes as $r) {
                    $bucket = $this->fileKeyForPath($r->getPath());
                    $hostKey = $this->canonicalRouteHost($r->getDomain());

                    $shards[$hostKey][$bucket] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];

                    if ($r->isDynamic()) {
                        $this->trieInsert($shards[$hostKey][$bucket][self::K_TRIE], $r, $verb);
                    } else {
                        $p = $r->getPath();
                        // single route per (path, verb) guaranteed by duplicate guard
                        $shards[$hostKey][$bucket][self::K_STATIC][$p][$verb] = $r;
                    }
                }
            }
        }

        // Write each (host,bucket) shard into separate file
        foreach ($shards as $hostKey => $byBucket) {
            foreach ($byBucket as $bucket => $payload) {
                $this->writeShard($hostKey, $bucket, $payload);
            }
        }

        // Also ensure wildcard root exists (useful sentinel)
        $shards['*'][self::SHARD_ROOT] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];
        $this->writeShard('*', self::SHARD_ROOT, $shards['*'][self::SHARD_ROOT]);
    }

    private function writeShard(string $hostKey, string $bucket, array $payload): void
    {
        $file = $this->shardFilePath($hostKey, $bucket);
        if (!\is_dir($d = \dirname($file)) && !@\mkdir($d, 0775, true) && !\is_dir($d)) {
            throw new \RuntimeException("Failed to create cache dir {$d}");
        }
        $crc = $this->hashPayload($payload);
        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "' => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_DATA . "' => " . $this->exportArray($payload) . ",\n"
            . "];\n";
        $tmp = $file . '.' . \uniqid('', true) . '.tmp';
        \file_put_contents($tmp, $php, \LOCK_EX);
        @\chmod($tmp, 0664);
        @\rename($tmp, $file);

        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($file);
        }
    }

    private function shardFilePath(string $hostKey, string $bucket): string
    {
        $bucketSafe = $this->sanitizeForFilename($bucket);
        $name = ($hostKey === '*')
            ? $bucketSafe . '.php'
            : $this->sanitizeForFilename($hostKey) . '.' . $bucketSafe . '.php';

        return $this->cacheDir . DIRECTORY_SEPARATOR . $name;
    }

    /** ASCII-only fast sanitizer (no regex). */
    private function sanitizeForFilename(string $s): string
    {
        $out = '';
        $prevUnderscore = false;
        $len = \strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            $ord = \ord($ch);

            $isAlphaNum =
                ($ord >= 48 && $ord <= 57) || // 0-9
                ($ord >= 65 && $ord <= 90) || // A-Z
                ($ord >= 97 && $ord <= 122);    // a-z

            if ($isAlphaNum || $ch === '.' || $ch === '_' || $ch === '-') {
                $out .= $ch;
                $prevUnderscore = false;
            } elseif (!$prevUnderscore) {
                $out .= '_';
                $prevUnderscore = true;
            }
        }

        // avoid leading dot / Windows trailing dot/space
        $out = \ltrim($out, '.');
        $out = \rtrim($out, ' .');

        // avoid empty or dot-only results
        if ($out === '') {
            $out = '_';
        }

        // avoid Windows reserved basenames
        static $reserved = [
            'CON',
            'PRN',
            'AUX',
            'NUL',
            'COM1',
            'COM2',
            'COM3',
            'COM4',
            'COM5',
            'COM6',
            'COM7',
            'COM8',
            'COM9',
            'LPT1',
            'LPT2',
            'LPT3',
            'LPT4',
            'LPT5',
            'LPT6',
            'LPT7',
            'LPT8',
            'LPT9',
        ];
        if (\in_array(\strtoupper($out), $reserved, true)) {
            $out = '_' . $out;
        }

        return $out;
    }

    private function hashPayload(array $payload): string
    {
        return \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /*──────────── export helpers ────────────*/
    private function exportArray(array $a, int $depth = 0): string
    {
        $indent = \str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . \var_export($k, true) . ' => ';
            $out .= \is_array($v) ? $this->exportArray($v, $depth + 1)
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
        return $h instanceof Closure
            || (\is_array($h) && (($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure));
    }

    /*──────────── runtime shard loading ────────────*/
    private function loadGroupFor(string $hostKey, string $bucket): ?array
    {
        if ($this->cacheEnabled) {
            return $this->loadGroupFromCache($hostKey, $bucket);
        }
        return $this->buildDevGroupOnce($hostKey, $bucket);
    }

    private function loadGroupFromCache(string $hostKey, string $bucket): ?array
    {
        $file = $this->shardFilePath($hostKey, $bucket);
        if (isset($this->loadedFiles[$file])) {
            return $this->loadedFiles[$file];
        }
        if (!\is_file($file)) {
            return $this->loadedFiles[$file] = null;
        }

        /** @var array{_hash:string,_data:array} $blob */
        $blob = require $file;

        if (!isset($blob[self::H_HASH], $blob[self::H_DATA])) {
            throw new \RuntimeException("Cache file {$file} missing Hash.");
        }
        if ($this->verifyCacheOnLoad) {
            $calc = $this->hashPayload($blob[self::H_DATA]);
            if (!\hash_equals($blob[self::H_HASH], $calc)) {
                throw new \RuntimeException("Cache hash mismatch ($file).");
            }
        }

        return $this->loadedFiles[$file] = $blob[self::H_DATA];
    }

    /**
     * Iterate all (verb, route) pairs for given bucket; host comes from route->domain.
     *
     * @return \Generator<array{0:string,1:CompiledRoute}>
     */
    private function iterShardRoutes(string $bucket): \Generator
    {
        foreach ($this->prefixMap as $byMethod) {
            foreach ($byMethod as $verb => $routes) {
                foreach ($routes as $r) {
                    if ($this->fileKeyForPath($r->getPath()) === $bucket) {
                        yield [$verb, $r];
                    }
                }
            }
        }
    }

    private function buildDevGroupOnce(string $hostKey, string $bucket): ?array
    {
        if (isset($this->memGroups[$hostKey][$bucket]) || array_key_exists($bucket, $this->memGroups[$hostKey] ?? [])) {
            return $this->memGroups[$hostKey][$bucket];
        }

        $static = [];
        $trie = $this->newNode();

        foreach ($this->iterShardRoutes($bucket) as [$verb, $r]) {
            $h = $this->canonicalRouteHost($r->getDomain());
            if ($h !== $hostKey) {
                continue;
            }

            if ($r->isDynamic()) {
                $this->trieInsert($trie, $r, $verb);
            } else {
                // single route per (path, verb) guaranteed by duplicate guard
                $static[$r->getPath()][$verb] = $r;
            }
        }

        $group = ($static === [] && $this->isEmptyTrieNode($trie))
            ? null
            : [self::K_STATIC => $static, self::K_TRIE => $trie];

        $this->memGroups[$hostKey][$bucket] = $group;
        return $group;
    }

    /*──────────── trie (build + runtime) ────────────*/
    private function newNode(): array
    {
        return [self::K_CHILDREN => [], self::K_PARAM => null, self::K_ROUTES => []];
    }

    private function &trieLiteralChild(array &$node, string $seg): array
    {
        $node[self::K_CHILDREN][$seg] ??= $this->newNode();
        return $node[self::K_CHILDREN][$seg];
    }

    private function &trieParamChild(array &$node, array $spec): array
    {
        if ($node[self::K_PARAM] !== null) {
            if ($node[self::K_PARAM]['name'] !== $spec['name'] || $node[self::K_PARAM]['regex'] !== $spec['regex']) {
                throw new \LogicException("Conflicting placeholders at same depth");
            }
            return $node[self::K_PARAM]['node'];
        }
        $node[self::K_PARAM] = ['name' => $spec['name'], 'regex' => $spec['regex'], 'node' => $this->newNode()];
        return $node[self::K_PARAM]['node'];
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
        if (isset($node[self::K_ROUTES][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$r->getPath()}");
        }
        $node[self::K_ROUTES][$verb] = $r;
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
        string $verb,
        array &$params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        if ($i === \count($seg)) {
            $routes = $node[self::K_ROUTES] ?? [];
            if ($r = $this->pickVerbRoute($routes, $verb)) {
                $hit = [$r, $params];
                return true;
            }
            if ($routes) {
                foreach ($routes as $v => $_) {
                    $allowedSet[$v] = true;
                }
                if (isset($routes['GET'])) {
                    $allowedSet['HEAD'] = true;
                }
            }
            return false;
        }

        $piece = $seg[$i];

        if (isset($node[self::K_CHILDREN][$piece]) &&
            $this->trieWalk($node[self::K_CHILDREN][$piece], $seg, $i + 1, $verb, $params, $allowedSet, $hit)) {
            return true;
        }

        $p = $node[self::K_PARAM];
        if ($p && \preg_match($p['regex'], $piece) === 1) {
            $params[$p['name']] = $piece; // push
            $ok = $this->trieWalk($p['node'], $seg, $i + 1, $verb, $params, $allowedSet, $hit);
            unset($params[$p['name']]);   // pop
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function isEmptyTrieNode(array $n): bool
    {
        return ($n[self::K_CHILDREN] ?? []) === [] && ($n[self::K_PARAM] ?? null) === null && ($n[self::K_ROUTES] ?? []) === [];
    }
}
