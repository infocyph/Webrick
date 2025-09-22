<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * ShardedMatcher
 *
 * Matcher implementation that shards compiled routes into many small cache files
 * (per-host and per-path-prefix). During build-time routes are collected into
 * an in-memory prefix map; on finalize() these are written into multiple PHP
 * shard files plus an alias file. At runtime the matcher loads at most two
 * shard files (the requested host bucket and the wildcard) to resolve a match.
 *
 * Responsibilities:
 *  - Collect compiled routes during registration and prevent duplicates.
 *  - Emit per-host/per-prefix cache files for fast lazy-loading in production.
 *  - Resolve requests by loading only the minimal shard files required.
 *  - Provide alias (named route) resolution via a separate __aliases.php blob.
 *
 * Notes:
 *  - When cache is disabled the matcher operates in "dev" mode and keeps
 *    shards in memory for every request without writing files.
 *  - The matcher enforces no further route additions after finalize() is called.
 *
 * @package Infocyph\Webrick\Router\Matching
 */
#[\AllowDynamicProperties(false)]
final class ShardedMatcher extends AbstractMatcher implements MatcherInterface
{
    /**
     * Special bucket name representing the root ('/') shard.
     */
    private const SHARD_ROOT = '__root';

    /**
     * Windows reserved base names that should not be used as filesystem basenames.
     *
     * Used by sanitizeForFilename() to avoid creating files with reserved names.
     *
     * @var list<string>
     */
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

    /**
     * Alias index (name => [path, domain]) collected at build-time or loaded from alias cache.
     *
     * @var array<string, array{0:string,1:?string}>
     */
    private array $alias = [];

    /**
     * Whether alias file was loaded (null = not attempted, true/false after load).
     *
     * @var bool|null
     */
    private ?bool $aliasLoaded = null; // null = not attempted; true/false after load

    /**
     * Path to cache directory when caching is enabled. Empty when caching is off.
     *
     * @var string
     */
    private string $cacheDir = '';

    /**
     * Whether multi-file caching has been enabled.
     *
     * @var bool
     */
    private bool $cacheEnabled = false;

    /**
     * Whether the matcher has been finalized (no further route additions allowed).
     *
     * @var bool
     */
    private bool $finalized = false;

    /**
     * Cache of loaded shard arrays keyed by absolute file path.
     *
     * Value is the array returned from the required shard file, or null when
     * the file exists but contains no data for the requested group.
     *
     * @var array<string, array|null>
     */
    private array $loadedFiles = [];

    /**
     * In-memory shards used in dev-mode: memGroups[host][bucket] => group array|null.
     *
     * @var array<string, array<string, array|null>>
     */
    private array $memGroups = [];

    /**
     * Duplicate guard to prevent adding the same host+method+path twice.
     *
     * Shape: host => method => path => true
     *
     * @var array<string, array<string, array<string, bool>>>
     */
    private array $pathGuard = [];

    /*──────────── state ────────────*/

    /**
     * Build-time prefix map used to group routes for shard output.
     *
     * Shape: prefix => method => list<CompiledRoute>
     *
     * @var array<string, array<string, list<CompiledRoute>>>
     */
    private array $prefixMap = [];

    /**
     * Private constructor to enforce factory usage.
     */
    private function __construct()
    {
    }

    /*──────────── factory/config ────────────*/

    /**
     * Create a new ShardedMatcher instance.
     *
     * @return self
     */
    public static function make(): self
    {
        return new self();
    }

    /*──────────── registration ────────────*/

    /**
     * Register a compiled route with the matcher.
     *
     * The route is grouped by its literal prefix and method for later shard
     * emission. Duplicate host+method+path entries are rejected.
     *
     * @param CompiledRoute $route Compiled route instance.
     * @return void
     * @throws \LogicException When attempting to add routes after finalize() or when a duplicate is detected.
     */
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

    /*──────────── alias helpers (public API) ────────────*/

    /**
     * Get the alias index mapping name => [path, domain].
     *
     * Dev mode: returns in-memory $this->alias.
     * Cached mode: lazy-loads __aliases.php on first call.
     *
     * @return array<string, array{0:string,1:?string}>
     * @throws \RuntimeException When alias cache format is invalid or hash mismatches (when verify enabled)
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
     * Whether a ready shard set exists so the matcher can boot directly from files.
     *
     * The sentinel used is the wildcard root shard; if present we treat the cache
     * as available for lazy loading.
     *
     * @return bool True when cache is enabled and sentinel shard file exists.
     */
    public function canBootFromCache(): bool
    {
        // Sentinel is wildcard root shard; alias file will be lazy-loaded.
        return $this->cacheEnabled && \is_file($this->shardFilePath('*', self::SHARD_ROOT));
    }

    /**
     * Enable per-file cache output and set the target directory.
     *
     * @param string $cacheLocation Absolute or relative directory path for shard files.
     * @return self Fluent self for chaining.
     */
    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheDir = \rtrim($cacheLocation, '/\\');
        return $this;
    }

    /**
     * Finalize the matcher and emit shard files when caching is enabled.
     *
     * Behavior:
     *  - Writes shard files and alias file only once if cache does not exist.
     *  - Frees build-time in-memory maps after successful dump.
     *
     * This method is idempotent.
     *
     * @return void
     * @throws \RuntimeException When cache directory cannot be created or files cannot be written.
     */
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

    /*──────────── runtime match ────────────*/

    /**
     * Match an incoming request method/host/path to a compiled route and params.
     *
     * Matching strategy:
     *  1. Normalize the request tuple.
     *  2. Determine the shard bucket for the path.
     *  3. Load the host-specific and wildcard shard groups (at most two files).
     *  4. Try static lookup then dynamic trie for host then wildcard.
     *  5. Throw MethodNotAllowedException when a path exists but verb not allowed.
     *  6. Throw RouteNotFoundException otherwise.
     *
     * @param string $method HTTP method (any case)
     * @param string $host Host header value (lower-cased ASCII expected)
     * @param string $path Request path
     * @return array{0:CompiledRoute,1:array<string,string>} Tuple [route, params]
     * @throws MethodNotAllowedException When resource exists but verb not allowed
     * @throws RouteNotFoundException When no route matches the path/host
     */
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

    /**
     * Resolve a named route to its [path, domain] tuple.
     *
     * @param string $name Route name to resolve.
     * @return array{0:string,1:?string}|null Tuple [path, domain] or null when unknown.
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();
        return $idx[$name] ?? null;
    }

    /**
     * Compute the canonical alias file path inside the configured cache directory.
     *
     * @return string Path to __aliases.php
     */
    private function aliasFilePath(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . self::F_ALIASES;
    }

    /**
     * Build an in-memory group for dev mode on first request and memoise it.
     *
     * The built group has keys K_STATIC and K_TRIE or null when empty.
     *
     * @param string $hostKey
     * @param string $bucket
     * @return array|null Group or null when empty
     */
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

    /*──────────── alias cache dump ────────────*/

    /**
     * Dump the alias index into the canonical __aliases.php file.
     *
     * @return void
     * @throws \RuntimeException When cache directory cannot be created or file write fails.
     */
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

    /*──────────── cache dump (per-host *and* per-bucket) ───────────*/

    /**
     * Produce and write all shard files based on the build-time prefix map.
     *
     * Resulting structure: $shards[host][bucket] = ['static'=>..., 'trie'=>...]
     *
     * @return void
     */
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

    /*──────────── build-time helpers ───────────*/

    /**
     * Extract the literal prefix for a route based on its leading literal segments.
     *
     * Examples:
     *  - '/users/{id}'   => '/users'
     *  - '/about'        => '/about'
     *  - '/'             => '/'
     *
     * @param CompiledRoute $r
     * @return string Prefix beginning with '/'
     */
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

    /*──────────── path→bucket key ────────────*/

    /**
     * Compute the shard bucket key for a given path.
     *
     * Root path maps to SHARD_ROOT. Otherwise the first segment is used.
     *
     * @param string $path Request path
     * @return string Bucket key
     */
    private function fileKeyForPath(string $path): string
    {
        if ($path === '/' || $path === '') {
            return self::SHARD_ROOT;
        }
        $p = $path[0] === '/' ? \substr($path, 1) : $path;
        $pos = \strpos($p, '/');
        return $pos === false ? $p : \substr($p, 0, $pos);
    }

    /**
     * Iterate all (verb, route) pairs contained in the build-time prefix map for a bucket.
     *
     * @param string $bucket
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

    /*──────────── runtime shard loading ────────────*/

    /**
     * Load a group (hostKey + bucket) either from cache files or build in-memory for dev.
     *
     * @param string $hostKey Canonical host key or '*'
     * @param string $bucket Bucket key
     * @return array|null Group payload or null when no data exists
     */
    private function loadGroupFor(string $hostKey, string $bucket): ?array
    {
        if ($this->cacheEnabled) {
            return $this->loadGroupFromCache($hostKey, $bucket);
        }
        return $this->buildDevGroupOnce($hostKey, $bucket);
    }

    /**
     * Load a shard group from a cache file, memoising the loaded content.
     *
     * Expects the shard file to return an array with keys H_HASH and H_DATA.
     *
     * @param string $hostKey
     * @param string $bucket
     * @return array|null Loaded group data or null when file missing
     * @throws \RuntimeException When cache file format is invalid or hash mismatches (when verify enabled)
     */
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

    /*──────────────────────── match helpers ─────────────────*/

    /**
     * Normalize incoming request tuple.
     *
     * @param string $method
     * @param string $host
     * @param string $path
     * @return array{0:string,1:string,2:string} Normalized [METHOD, hostOrWildcard, path]
     */
    private function normalizeRequest(string $method, string $host, string $path): array
    {
        return [\strtoupper($method), \strtolower($host ?: '*'), ($path === '' ? '/' : $path)];
    }

    /**
     * Fast ASCII-only filename sanitizer.
     *
     * Replaces runs of invalid characters with underscores, trims leading dots
     * and trailing spaces/dots, ensures non-empty output and avoids Windows
     * reserved basenames by prefixing an underscore when necessary.
     *
     * @param string $s Input string to sanitise
     * @return string Sanitised filename-safe string
     */
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

    /**
     * Compute the shard file path for a given hostKey and bucket.
     *
     * HostKey '*' maps to bucketSafe.php, otherwise hostKey.bucketSafe.php.
     *
     * @param string $hostKey Canonical host key or '*'
     * @param string $bucket Bucket name
     * @return string Absolute/relative file path inside $this->cacheDir
     */
    private function shardFilePath(string $hostKey, string $bucket): string
    {
        $bucketSafe = $this->sanitizeForFilename($bucket);
        $name = ($hostKey === '*')
            ? $bucketSafe . '.php'
            : $this->sanitizeForFilename($hostKey) . '.' . $bucketSafe . '.php';

        return $this->cacheDir . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Try dynamic trie descent for a preloaded group.
     *
     * @param array|null $group Group payload (or null when absent)
     * @param string $method Uppercased HTTP method
     * @param string $path Request path
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
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

    /**
     * Try static (exact path) lookup in a preloaded group.
     *
     * On success returns [$route, []] (no params). When a path exists but
     * the verb does not match this method populates $allowedSet.
     *
     * @param array|null $group Group payload (or null when absent)
     * @param string $method Uppercased HTTP method
     * @param string $path Request path
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
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

    /**
     * Serialize and write a single shard file.
     *
     * @param string $hostKey Canonical host key or '*' for wildcard
     * @param string $bucket Bucket name for the shard
     * @param array $payload Data payload to export into PHP array form
     * @return void
     * @throws \RuntimeException When the cache directory cannot be created.
     */
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
}
