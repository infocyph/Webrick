<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * ShardedMatcher
 *
 * Matcher implementation that shards compiled routes into many small cache files
 * (per-host and per-path-prefix). During build-time routes are collected into
 * an in-memory bucket map; dedicated tooling can persist those into multiple PHP
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
 * @phpstan-type AliasIndex array<string, array{0:string,1:?string}>
 * @phpstan-type VerbRouteMap array<string, CompiledRoute|array<mixed>|string>
 * @phpstan-type StaticBucket array<string, VerbRouteMap>
 * @phpstan-type Group array{static: StaticBucket, trie: array<string,mixed>}
 */
final class ShardedMatcher extends AbstractMatcher implements MatcherInterface
{
    use MatcherFactoryTrait;

    /**
     * Special bucket name representing the root ('/') shard.
     */
    private const string SHARD_ROOT = '__root';

    /**
     * Windows reserved base names that should not be used as filesystem basenames.
     *
     * Used by sanitizeForFilename() to avoid creating files with reserved names.
     *
     * @var list<string>
     */
    private const array WIN_RESERVED = [
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

    /** Active immutable generation selected by the published manifest. */
    private ?string $activeCacheDir = null;

    /**
     * Alias index (name => [path, domain]) collected at build-time or loaded from alias cache.
     *
     * @var AliasIndex
     */
    private array $alias = [];

    /**
     * Whether alias file was loaded (null = not attempted, true/false after load).
     */
    private ?bool $aliasLoaded = null; // null = not attempted; true/false after load

    /* ──────────── state ──────────── */

    /**
     * Build-time bucket map used by dev-mode and cache dump.
     *
     * Shape: bucket => method => list<CompiledRoute>
     *
     * @var array<string, array<string, list<CompiledRoute>>>
     */
    private array $bucketMap = [];

    /**
     * Path to cache directory when caching is enabled. Empty when caching is off.
     */
    private string $cacheDir = '';

    /**
     * Whether multi-file caching has been enabled.
     */
    private bool $cacheEnabled = false;

    /**
     * Whether runtime should prefer loading shard files over in-memory groups.
     */
    private bool $cacheReadable = false;

    /**
     * Whether cache file writing is explicitly enabled (tooling-only path).
     */
    private bool $cacheWriteEnabled = false;

    /**
     * Whether the matcher has been finalized (no further route additions allowed).
     */
    private bool $finalized = false;

    /**
     * Cache of loaded shard arrays keyed by absolute file path.
     *
     * Value is the array returned from the required shard file, or null when
     * the file exists but contains no data for the requested group.
     *
     * @var array<string, Group|null>
     */
    private array $loadedFiles = [];

    /**
     * In-memory shards used in dev-mode: memGroups[host][bucket] => group array|null.
     *
     * @var array<string, array<string, Group|null>>
     */
    private array $memGroups = [];

    /** @var array<string,true> */
    private array $middlewareRequirements = [];

    /**
     * Duplicate guard to prevent adding the same host+method+path twice.
     *
     * Shape: host => method => path => true
     *
     * @var array<string, array<string, array<string, bool>>>
     */
    private array $pathGuard = [];

    /* ──────────── registration ──────────── */
    /**
     * Register a compiled route with the matcher.
     *
     * The route is grouped by shard bucket and method for later shard
     * emission. Duplicate host+method+path entries are rejected.
     *
     * @param CompiledRoute $route Compiled route instance.
     *
     * @throws \LogicException When attempting to add routes after finalize() or when a duplicate is detected.
     */
    public function add(CompiledRoute $route): void
    {
        [$host, $method, $path] = matcher_prepare_route_registration(
            $this->finalized,
            $this->pathGuard,
            $this->canonicalRouteHost(...),
            $route,
        );
        $bucket = sharded_matcher_file_key_for_path($path, self::SHARD_ROOT);

        $this->bucketMap[$bucket][$method][] = $route;
        matcher_capture_route_alias($this->alias, $route);
        matcher_capture_middleware_requirements($this->middlewareRequirements, $route);
    }

    /* ──────────── alias helpers (public API) ──────────── */

    /**
     * Get the alias index mapping name => [path, domain].
     *
     * Dev mode: returns in-memory $this->alias.
     * Cached mode: lazy-loads __aliases.php on first call.
     *
     * @return array<string, array{0:string,1:?string}>
     *
     * @throws \RuntimeException When alias cache format is invalid or hash mismatches (when verify enabled)
     */
    public function aliasIndex(): array
    {
        if (!$this->cacheEnabled || !$this->cacheReadable) {
            return $this->alias;
        }

        $file = $this->aliasFilePath();
        if ($this->aliasLoaded === true) {
            return $this->alias;
        }

        if (!\is_file($file)) {
            // No alias file (e.g., cache not dumped) → fall back to memory
            $this->aliasLoaded = false;

            return $this->alias;
        }

        /** @var array{_version?:int,_hash?:string,_data?:AliasIndex} $blob */
        $blob = require $file;

        if (($blob[self::H_VERSION] ?? null) !== self::CACHE_FORMAT_VERSION) {
            throw new \RuntimeException("Stale alias route cache ({$file}). Rebuild the route cache.");
        }

        if (!isset($blob[self::H_DATA])) {
            throw new \RuntimeException("Alias cache file {$file} missing data payload.");
        }
        if ($this->verifyCacheOnLoad) {
            if (!isset($blob[self::H_HASH])) {
                throw new \RuntimeException("Alias cache file {$file} missing Hash.");
            }
            $calc = $this->computeAliasHash($blob[self::H_DATA]);
            if (!\hash_equals($blob[self::H_HASH], $calc)) {
                throw new \RuntimeException("Alias cache hash mismatch ({$file}).");
            }
        }

        $this->alias = matcher_normalize_alias_pairs($blob[self::H_DATA]);
        $this->aliasLoaded = true;

        return $this->alias;
    }

    /**
     * Whether a ready shard set exists so the matcher can boot directly from files.
     *
     * A published immutable generation is complete by construction: publication
     * validates its root sentinel and alias metadata before switching the pointer.
     *
     * @return bool True when cache is enabled and an active generation exists.
     */
    #[\Override]
    public function canBootFromCache(): bool
    {
        if (!$this->cacheEnabled || $this->cacheWriteEnabled) {
            return false;
        }

        $active = $this->resolveActiveCacheDir();
        if ($active === null) {
            $legacySentinel = sharded_matcher_shard_file_path(
                $this->cacheDir,
                '*',
                self::SHARD_ROOT,
                self::WIN_RESERVED,
            );
            if (\is_file($legacySentinel)) {
                throw new \RuntimeException('Stale sharded route cache layout. Rebuild the route cache.');
            }

            return false;
        }

        return true;
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
        $this->activeCacheDir = null;
        $this->cacheReadable = false;

        return $this;
    }

    /**
     * Explicitly allow cache-file writes from finalize().
     *
     * This is intentionally opt-in and should only be used by route-cache tooling.
     */
    public function enableCacheWrite(bool $enable = true): self
    {
        $this->cacheWriteEnabled = $enable;

        return $this;
    }

    /**
     * Finalize the matcher and emit shard files when caching is enabled.
     *
     * Behavior:
     *  - In normal runtime mode, this only seals matcher state and chooses
     *    whether runtime should load from cache files (when they already exist)
     *    or from in-memory groups.
     *  - In tooling mode (cache-write explicitly enabled), it writes shard and
     *    alias files and frees build-time in-memory maps.
     *
     * This method is idempotent.
     *
     * @throws \RuntimeException When cache directory cannot be created or files cannot be written.
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        if ($this->cacheEnabled && $this->cacheWriteEnabled) {
            $this->publishGeneration();
            $this->discardBuildState();
        }

        $active = $this->resolveActiveCacheDir();
        if ($active === null) {
            $this->cacheReadable = false;
            $this->finalized = true;

            return;
        }

        $this->cacheReadable = $this->cacheEnabled;
        $this->finalized = true;
    }

    /* ──────────── runtime match ──────────── */

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
     *
     * @throws MethodNotAllowedException When resource exists but verb not allowed
     * @throws RouteNotFoundException When no route matches the path/host
     */
    public function match(string $method, string $host, string $path): array
    {
        $method = \strtoupper($method);
        $host = \strtolower($host ?: '*');
        $path = $path === '' ? '/' : $path;
        $bucket = sharded_matcher_file_key_for_path($path, self::SHARD_ROOT);
        $hostGroup = $this->loadGroupFor($host, $bucket);
        $wildcardGroup = null;
        $hasCandidateGroup = $hostGroup !== null;
        $allowed = [];

        if ($hit = $this->tryStatic($hostGroup, $method, $path, $allowed)) {
            return $hit;
        }

        if ($host !== '*') {
            $wildcardGroup = $this->loadGroupFor('*', $bucket);
            $hasCandidateGroup = $hasCandidateGroup || $wildcardGroup !== null;
            if ($hit = $this->tryStatic($wildcardGroup, $method, $path, $allowed)) {
                return $hit;
            }
        }

        $segments = $this->explodePath($path);
        if ($hit = $this->tryDynamic($hostGroup, $method, $segments, $allowed)) {
            return $hit;
        }
        if ($host !== '*' && ($hit = $this->tryDynamic($wildcardGroup, $method, $segments, $allowed))) {
            return $hit;
        }

        if (!$hasCandidateGroup) {
            throw new RouteNotFoundException($method, $path);
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedException($method, $path, \array_keys($allowed));
        }

        throw new RouteNotFoundException($method, $path);
    }

    /** @return list<string> */
    public function middlewareRequirements(): array
    {
        if (!$this->cacheEnabled || !$this->cacheReadable) {
            return \array_keys($this->middlewareRequirements);
        }

        return ShardedCacheGeneration::middlewareRequirements($this->cacheDir, self::CACHE_FORMAT_VERSION);
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
     * @param array<string, array<string, Group>> $shards
     */
    private function accumulateShardRoute(array &$shards, string $bucket, string $verb, CompiledRoute $route): void
    {
        $hostKey = $this->canonicalRouteHost($route->getDomain());
        $shards[$hostKey][$bucket] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];

        if ($route->isDynamic()) {
            /** @var array<string,mixed> $trie */
            $trie = &$shards[$hostKey][$bucket][self::K_TRIE];
            $this->trieInsert($trie, $route, $verb);

            return;
        }

        $path = $route->getPath();
        $shards[$hostKey][$bucket][self::K_STATIC][$path][$verb] = $route;
    }

    /**
     * Compute the canonical alias file path inside the configured cache directory.
     *
     * @return string Path to __aliases.php
     */
    private function aliasFilePath(): string
    {
        return sharded_matcher_alias_file_path($this->cacheStorageDir(), self::F_ALIASES);
    }

    /**
     * Build an in-memory group for dev mode on first request and memoise it.
     *
     * The built group has keys K_STATIC and K_TRIE or null when empty.
     *
     * @return Group|null Group or null when empty
     */
    private function buildDevGroupOnce(string $hostKey, string $bucket): ?array
    {
        if (isset($this->memGroups[$hostKey]) && \array_key_exists($bucket, $this->memGroups[$hostKey])) {
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

    private function cacheStorageDir(): string
    {
        return $this->activeCacheDir ?? $this->cacheDir;
    }

    /**
     * Compute deterministic hash for alias cache payload.
     *
     * @param array<string,array{0:string,1:?string}> $payload Alias payload.
     * @return string xxh128 fingerprint.
     */
    private function computeAliasHash(array $payload): string
    {
        return \hash('xxh128', \serialize($payload));
    }

    /**
     * Compute deterministic hash for shard payload.
     *
     * @param Group $payload Shard payload.
     * @return string xxh128 fingerprint.
     */
    private function computeShardHash(array $payload): string
    {
        return \hash('xxh128', \serialize($payload));
    }

    private function discardBuildState(): void
    {
        [$this->bucketMap, $this->alias, $this->middlewareRequirements, $this->loadedFiles] = [[], [], [], []];
        $this->aliasLoaded = null;
    }

    /* ──────────── alias cache dump ──────────── */
    /**
     * Dump the alias index into the canonical __aliases.php file.
     *
     * @throws \RuntimeException When cache directory cannot be created or file write fails.
     */
    private function dumpAliasFile(): void
    {
        $file = $this->aliasFilePath();
        $payload = $this->alias;
        $this->writeCachePayloadFile($file, $payload);
    }

    /* ──────────── cache dump (per-host *and* per-bucket) ─────────── */
    /**
     * Produce and write all shard files based on the build-time bucket map.
     *
     * Resulting structure: $shards[host][bucket] = ['static'=>..., 'trie'=>...]
     */
    private function dumpCacheFiles(): void
    {
        /** @var array<string, array<string, Group>> $shards */
        $shards = [];
        foreach ($this->bucketMap as $bucket => $byMethod) {
            foreach ($byMethod as $verb => $routes) {
                foreach ($routes as $r) {
                    $this->accumulateShardRoute($shards, $bucket, $verb, $r);
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

    /* ──────────── build-time helpers ─────────── */

    /**
     * Iterate all (verb, route) pairs contained in the build-time bucket map.
     *
     * @return \Generator<array{0:string,1:CompiledRoute}>
     */
    private function iterShardRoutes(string $bucket): \Generator
    {
        foreach (($this->bucketMap[$bucket] ?? []) as $verb => $routes) {
            foreach ($routes as $r) {
                yield [$verb, $r];
            }
        }
    }

    /* ──────────── runtime shard loading ──────────── */

    /**
     * Load a group (hostKey + bucket) either from cache files or build in-memory for dev.
     *
     * @param string $hostKey Canonical host key or '*'
     * @param string $bucket Bucket key
     * @return Group|null Group payload or null when no data exists
     */
    private function loadGroupFor(string $hostKey, string $bucket): ?array
    {
        if ($this->cacheReadable) {
            return $this->loadGroupFromCache($hostKey, $bucket);
        }

        return $this->buildDevGroupOnce($hostKey, $bucket);
    }

    /**
     * Load a shard group from a cache file, memoising the loaded content.
     *
     * Expects the shard file to return an array with keys H_HASH and H_DATA.
     *
     * @return Group|null Loaded group data or null when file missing
     *
     * @throws \RuntimeException When cache file format is invalid or hash mismatches (when verify enabled)
     */
    private function loadGroupFromCache(string $hostKey, string $bucket): ?array
    {
        $file = sharded_matcher_shard_file_path($this->cacheStorageDir(), $hostKey, $bucket, self::WIN_RESERVED);
        if (\array_key_exists($file, $this->loadedFiles)) {
            return $this->loadedFiles[$file];
        }

        if (!\is_file($file)) {
            return $this->loadedFiles[$file] = null;
        }

        /** @var array{_version?:int,_hash?:string,_data?:mixed} $blob */
        $blob = require $file;

        if (($blob[self::H_VERSION] ?? null) !== self::CACHE_FORMAT_VERSION) {
            throw new \RuntimeException("Stale sharded route cache ({$file}). Rebuild the route cache.");
        }

        if (!isset($blob[self::H_DATA])) {
            throw new \RuntimeException("Cache file {$file} missing data payload.");
        }
        $data = sharded_matcher_normalize_group($blob[self::H_DATA]);
        if ($this->verifyCacheOnLoad) {
            if (!isset($blob[self::H_HASH])) {
                throw new \RuntimeException("Cache file {$file} missing Hash.");
            }
            $calc = $this->computeShardHash($data);
            if (!\hash_equals($blob[self::H_HASH], $calc)) {
                throw new \RuntimeException("Cache hash mismatch ($file).");
            }
        }

        return $this->loadedFiles[$file] = $data;
    }

    private function publishGeneration(): void
    {
        [$generation, $this->activeCacheDir] = ShardedCacheGeneration::create($this->cacheDir);
        $this->dumpCacheFiles();
        $this->dumpAliasFile();

        $sentinel = sharded_matcher_shard_file_path(
            $this->cacheStorageDir(),
            '*',
            self::SHARD_ROOT,
            self::WIN_RESERVED,
        );
        if (!\is_file($sentinel) || !\is_file($this->aliasFilePath())) {
            throw new \RuntimeException('Generated sharded cache is incomplete.');
        }

        ShardedCacheGeneration::publish(
            $this->cacheDir,
            self::CACHE_FORMAT_VERSION,
            $generation,
            \array_keys($this->middlewareRequirements),
        );
    }

    private function resolveActiveCacheDir(): ?string
    {
        if ($this->activeCacheDir !== null) {
            return $this->activeCacheDir;
        }

        return $this->activeCacheDir = ShardedCacheGeneration::resolve(
            $this->cacheDir,
            self::CACHE_FORMAT_VERSION,
        );
    }

    /* ──────────────────────── match helpers ───────────────── */

    /**
     * Try dynamic trie descent for a preloaded group.
     *
     * @param Group|null $group Group payload (or null when absent)
     * @param string $method Uppercased HTTP method
     * @param list<string> $segments Pre-split request path segments
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
    private function tryDynamic(?array $group, string $method, array $segments, array &$allowedSet): ?array
    {
        if ($group === null) {
            return null;
        }
        $root = $group[self::K_TRIE];
        if ($root === []) {
            return null;
        }

        $hit = null;
        $params = [];
        if ($this->trieWalkNode($root, $segments, 0, $method, $params, $allowedSet, $hit)) {
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
     * @param Group|null $group Group payload (or null when absent)
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
        $static = $group[self::K_STATIC];
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
     * @param array<mixed> $payload
     */
    private function writeCachePayloadFile(string $file, array $payload): void
    {
        if (!\is_dir($d = \dirname($file)) && !\mkdir($d, 0775, true) && !\is_dir($d)) {
            throw new \RuntimeException("Failed to create cache dir {$d}");
        }

        /** @var array<mixed> $payload */
        $payload = MatcherCachePayloadNormalizer::normalize($payload);
        $crc = \hash('xxh128', \serialize($payload));

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_VERSION . "' => " . self::CACHE_FORMAT_VERSION . ",\n"
            . "    '" . self::H_HASH . "' => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_TS . "' => " . \var_export(date(DATE_ATOM), true) . ",\n"
            . "    '" . self::H_DATA . "' => " . $this->exportArray($payload) . ",\n"
            . "];\n";

        matcher_write_validated_atomic_php_file(
            $file,
            $php,
            function (array $blob) use ($crc): void {
                if (($blob[self::H_VERSION] ?? null) !== self::CACHE_FORMAT_VERSION) {
                    throw new \UnexpectedValueException('Generated sharded cache has an invalid format version.');
                }
                $data = $blob[self::H_DATA] ?? null;
                $hash = $blob[self::H_HASH] ?? null;
                if (!\is_array($data) || !\is_string($hash)) {
                    throw new \UnexpectedValueException('Generated sharded cache has an invalid payload.');
                }
                $computed = \hash('xxh128', \serialize($data));
                if (!\hash_equals($crc, $hash) || !\hash_equals($crc, $computed)) {
                    throw new \UnexpectedValueException('Generated sharded cache hash mismatch.');
                }
            },
        );

        if ($this->shouldWarmOpcache()) {
            \opcache_compile_file($file);
        }
    }

    /**
     * Serialize and write a single shard file.
     *
     * @param string $hostKey Canonical host key or '*' for wildcard
     * @param string $bucket Bucket name for the shard
     * @param Group $payload Data payload to export into PHP array form
     *
     * @throws \RuntimeException When the cache directory cannot be created.
     */
    private function writeShard(string $hostKey, string $bucket, array $payload): void
    {
        $file = sharded_matcher_shard_file_path($this->cacheStorageDir(), $hostKey, $bucket, self::WIN_RESERVED);
        $this->writeCachePayloadFile($file, $payload);
    }
}
