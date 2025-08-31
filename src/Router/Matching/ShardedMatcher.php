<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

#[\AllowDynamicProperties(false)]
final class ShardedMatcher extends AbstractMatcher implements MatcherInterface
{
    private const SHARD_ROOT = '__root';

    /* Windows reserved base names for safety in filenames */
    private const WIN_RESERVED = [
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

    /** name => [path, domain] (dev or when cache not yet loaded) */
    /** @var array<string, array{0:string,1:?string}> */
    private array $alias = [];

    private ?bool $aliasLoaded = null; // null = not attempted; true/false after load

    /*──────────── factory/config ────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheDir = \rtrim($cacheLocation, '/\\');
        return $this;
    }

    /** true when multi-file cache already exists and we can skip compile */
    public function canBootFromCache(): bool
    {
        // Sentinel is wildcard root shard; alias file will be lazy-loaded.
        return $this->cacheEnabled && \is_file($this->shardFilePath('*', self::SHARD_ROOT));
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        // Cold-dump only once; check wildcard root shard as sentinel.
        $sentinel = $this->shardFilePath('*', self::SHARD_ROOT);
        if ($this->cacheEnabled && !\file_exists($sentinel)) {
            $this->dumpCacheFiles();     // writes all shards
            $this->dumpAliasFile();      // writes __aliases.php
            // free build-time memory
            $this->prefixMap = [];
            $this->alias = [];
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

        if (isset($this->pathGuard[$host][$method][$route->getPath()])) {
            throw new \LogicException("Duplicate route {$method} {$host}{$route->getPath()}");
        }
        $this->pathGuard[$host][$method][$route->getPath()] = true;

        $this->prefixMap[$prefix][$method][] = $route;

        // capture alias for dev-mode and for later cache dump
        if (($name = $route->getName()) !== null && $name !== '') {
            $this->alias[$name] = [$route->getPath(), $route->getDomain()];
        }
    }

    /*──────────── runtime match ────────────*/
    public function match(string $method, string $host, string $path): array
    {
        [$method, $host, $path] = $this->normalizeRequest($method, $host, $path);
        $bucket = $this->fileKeyForPath($path);

        // Load per-host + wildcard groups (two tiny files max)
        $grpHost = $this->loadGroupFor($host, $bucket);
        $grpAny = ($host === '*') ? null : $this->loadGroupFor('*', $bucket);

        if ($grpHost === null && $grpAny === null) {
            throw new RouteNotFoundException($method, $path);
        }

        /** @var array<string,bool> $allowedSet */
        $allowedSet = [];

        // ① static (host then wildcard)
        if ($hit = $this->tryStatic($grpHost, $method, $path, $allowedSet)) {
            return $hit;
        }
        if ($hit = $this->tryStatic($grpAny, $method, $path, $allowedSet)) {
            return $hit;
        }

        // ② dynamic trie (host then wildcard)
        if ($hit = $this->tryDynamic($grpHost, $method, $path, $allowedSet)) {
            return $hit;
        }
        if ($hit = $this->tryDynamic($grpAny, $method, $path, $allowedSet)) {
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
        $this->addAllowedFromMap($map, $allowedSet);
        return null;
    }

    private function tryDynamic(?array $group, string $method, string $path, array &$allowedSet): ?array
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
        if ($this->trieWalkNode($root, $this->explodePath($path), 0, $method, $params, $allowedSet, $hit)) {
            return $hit;
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

    /*──────────── build-time helpers ───────────*/
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

    /*──────────── cache dump (per-host *and* per-bucket) ───────────*/
    private function dumpCacheFiles(): void
    {
        // $shards[host][bucket] = ['static'=>[path][verb]=Route, 'trie'=>node]
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
                        $shards[$hostKey][$bucket][self::K_STATIC][$p][$verb] = $r;
                    }
                }
            }
        }

        foreach ($shards as $hostKey => $byBucket) {
            foreach ($byBucket as $bucket => $payload) {
                $this->writeShard($hostKey, $bucket, $payload);
            }
        }

        // ensure wildcard root exists (sentinel)
        $shards['*'][self::SHARD_ROOT] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];
        $this->writeShard('*', self::SHARD_ROOT, $shards['*'][self::SHARD_ROOT]);
    }

    private function writeShard(string $hostKey, string $bucket, array $payload): void
    {
        $file = $this->shardFilePath($hostKey, $bucket);
        if (!\is_dir($d = \dirname($file)) && !@\mkdir($d, 0775, true) && !\is_dir($d)) {
            throw new \RuntimeException("Failed to create cache dir {$d}");
        }
        $crc = \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));
        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "' => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_TS . "'  => " . \var_export(date(DATE_ATOM), true) . ",\n"
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
            $o = \ord($ch);

            $isAlphaNum = ($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122);
            if ($isAlphaNum || $ch === '.' || $ch === '_' || $ch === '-') {
                $out .= $ch;
                $prevUnderscore = false;
            } else {
                if (!$prevUnderscore) {
                    $out .= '_';
                    $prevUnderscore = true;
                }
            }
        }

        // avoid leading dot / Windows trailing dot or space
        $out = \ltrim($out, '.');
        $out = \rtrim($out, ' .');

        if ($out === '') {
            $out = '_';
        }

        // avoid Windows reserved basenames
        if (\in_array(\strtoupper($out), self::WIN_RESERVED, true)) {
            $out = '_' . $out;
        }

        return $out;
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
            $calc = \hash('xxh3', \json_encode($blob[self::H_DATA], \JSON_THROW_ON_ERROR));
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
        if (isset($this->memGroups[$hostKey][$bucket]) || \array_key_exists(
            $bucket,
            $this->memGroups[$hostKey] ?? [],
        )) {
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
                $static[$r->getPath()][$verb] = $r;
            }
        }

        $group = ($static === [] && $this->isEmptyTrieNode($trie))
            ? null
            : [self::K_STATIC => $static, self::K_TRIE => $trie];

        $this->memGroups[$hostKey][$bucket] = $group;
        return $group;
    }

    /*──────────── alias helpers (public API) ────────────*/

    /**
     * Get the alias index (name => [path, domain]).
     *  • Dev mode: from in-memory $alias.
     *  • Cached mode: lazy-load __aliases.php once.
     *
     * @return array<string, array{0:string,1:?string}>
     */
    public function aliasIndex(): array
    {
        if (!$this->cacheEnabled) {
            return $this->alias;
        }

        if ($this->aliasLoaded === true) {
            return $this->alias;
        }

        $file = $this->aliasFilePath();
        if (!\is_file($file)) {
            // No alias file (e.g., cache not dumped) → fall back to memory
            $this->aliasLoaded = false;
            return $this->alias;
        }

        /** @var array{_hash:string,_data:array<string,array{0:string,1:?string}>} $blob */
        $blob = require $file;

        if (!isset($blob[self::H_HASH], $blob[self::H_DATA])) {
            throw new \RuntimeException("Alias cache file {$file} missing Hash.");
        }
        if ($this->verifyCacheOnLoad) {
            $calc = \hash('xxh3', \json_encode($blob[self::H_DATA], \JSON_THROW_ON_ERROR));
            if (!\hash_equals($blob[self::H_HASH], $calc)) {
                throw new \RuntimeException("Alias cache hash mismatch ({$file}).");
            }
        }

        $this->alias = $blob[self::H_DATA] ?? [];
        $this->aliasLoaded = true;

        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($file);
        }

        return $this->alias;
    }

    /**
     * Fast resolve name → [path, domain] (or null if unknown).
     *
     * @return array{0:string,1:?string}|null
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();
        return $idx[$name] ?? null;
    }

    /*──────────── alias cache dump ────────────*/

    private function dumpAliasFile(): void
    {
        $file = $this->aliasFilePath();
        if (!\is_dir($d = \dirname($file)) && !@\mkdir($d, 0775, true) && !\is_dir($d)) {
            throw new \RuntimeException("Failed to create cache dir {$d}");
        }

        $payload = $this->alias;
        $crc = \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "' => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_TS . "' => " . \var_export(date(DATE_ATOM), true) . ",\n"
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

    private function aliasFilePath(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . self::F_ALIASES;
    }
}
