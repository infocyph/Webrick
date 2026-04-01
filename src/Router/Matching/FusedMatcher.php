<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * FusedMatcher
 *
 * Concrete matcher implementation that stores a combined in-memory route table
 * and supports optional single-file cache persistence. Routes are organised
 * per-host into two buckets:
 *  - "static" : exact path -> verb -> CompiledRoute map (fast lookup)
 *  - "trie"   : dynamic route trie for parameterised segments
 *
 * Responsibilities:
 *  - Accept compiled routes and insert them into the appropriate bucket.
 *  - Provide match(method, host, path) to return a matched CompiledRoute and
 *    extracted parameters or throw the appropriate routing exception.
 *  - Lazily reload a single-file PHP cache blob on first match when cache is enabled.
 *
 * Notes:
 *  - Cache files are loaded when present; cache generation is explicitly
 *    enabled only by dedicated cache tooling.
 *  - The matcher enforces that no routes are added after finalize() is called.
 *
 * @package Infocyph\Webrick\Router\Matching
 */
final class FusedMatcher extends AbstractMatcher implements MatcherInterface
{
    /**
     * Alias index mapping route name => [path, domain|null].
     *
     * @var array<string, array{0:string,1:?string}>
     */
    private array $alias = [];

    /**
     * Whether single-file caching has been enabled.
     *
     * @var bool
     */
    private bool $cacheEnabled = false;

    /**
     * Path to the single-file cache when caching is enabled.
     *
     * @var string
     */
    private string $cacheFile = '';

    /**
     * Whether the cache file has been loaded into memory (lazy load).
     *
     * @var bool
     */
    private bool $cacheLoaded = false;

    /**
     * Whether cache file writing is explicitly enabled (tooling-only path).
     *
     * @var bool
     */
    private bool $cacheWriteEnabled = false;

    /**
     * Whether the matcher has been finalized (no further route additions allowed).
     *
     * @var bool
     */
    private bool $finalized = false;
    /**
     * Host-bucket data structure.
     *
     * Shape:
     *   [
     *     'host.name' => [
     *         'static' => array<string, array<string, CompiledRoute>>, // path => [VERB => CompiledRoute]
     *         'trie'   => array // trie root node created by newNode()
     *     ],
     *     '*' => ... // wildcard host
     *   ]
     *
     * @var array<string, array{static: array, trie: array}>
     */
    private array $hosts = [];

    /**
     * Private constructor to enforce factory creation.
     */
    private function __construct()
    {
    }

    /*──────────── factory/config ────────────*/

    /**
     * Create a new FusedMatcher instance.
     *
     * @return self
     */
    public static function make(): self
    {
        return new self();
    }

    /*──────────── registration ────────────*/

    /**
     * Add a CompiledRoute to the matcher.
     *
     * The route is canonicalised by domain and inserted into the host-specific
     * static map or dynamic trie depending on whether it is dynamic.
     *
     * @param CompiledRoute $route Compiled route instance to add
     * @return void
     * @throws \LogicException When attempting to add routes after finalize()
     */
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalRouteHost($route->getDomain());
        $verb = HttpMethodEnum::normalize($route->getMethod());

        // Ensure host bucket exists with both static and trie slots.
        $this->hosts[$host] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];

        if ($route->isDynamic()) {
            $this->insertDynamic($host, $verb, $route);
        } else {
            $this->insertStatic($host, $verb, $route);
        }

        // Record alias (name → [path, domain]) when the route has a name.
        if (($name = $route->getName()) !== null && $name !== '') {
            $this->alias[$name] = [$route->getPath(), $route->getDomain()];
        }
    }

    /*──────────── alias accessors ────────────*/

    /**
     * Return the alias index mapping name => [path, domain].
     *
     * If caching is enabled and not yet loaded, this will lazily load the alias
     * side-data from the cache file.
     *
     * @return array<string, array{0:string,1:?string}>
     */
    public function aliasIndex(): array
    {
        if ($this->cacheEnabled) {
            if (!$this->cacheLoaded && \is_file($this->cacheFile)) {
                $this->loadCacheBlob();
            }
        }
        return $this->alias;
    }

    /**
     * Indicate whether a ready cache file exists such that the matcher can be
     * booted from cache without compiling routes.
     *
     * @return bool True when cache is enabled and the cache file exists
     */
    public function canBootFromCache(): bool
    {
        return $this->cacheEnabled && \is_file($this->cacheFile);
    }

    /**
     * Enable single-file cache output and set the target file path.
     *
     * Runtime behavior:
     *  - if the file exists it can be loaded for cache-boot;
     *  - if the file does not exist matcher continues using in-memory routes.
     *
     * Cache file generation is disabled by default and must be explicitly enabled
     * through cache tooling.
     *
     * @param string $cacheLocation Path to the output cache file
     * @return self Fluent self for chaining
     */
    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $cacheLocation;
        return $this;
    }

    /**
     * Explicitly allow cache-file writes from finalize().
     *
     * This is intentionally opt-in and should only be used by route-cache tooling.
     *
     * @param bool $enable
     * @return self
     */
    public function enableCacheWrite(bool $enable = true): self
    {
        $this->cacheWriteEnabled = $enable;
        return $this;
    }

    /**
     * Finalize the matcher.
     *
     * Behavior:
     *  - In normal runtime mode, this only seals the matcher.
     *  - In tooling mode (cache-write explicitly enabled), this may write the
     *    cache file and clear in-memory tables.
     *
     * This method is idempotent.
     *
     * @return void
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        // Cache writing is tooling-only and explicitly enabled.
        if (
            $this->cacheEnabled
            && $this->cacheWriteEnabled
            && !\is_file($this->cacheFile)
            && $this->hosts !== []
        ) {
            $this->dumpCache();
            // Free memory; tables will be lazy-loaded on first match()
            $this->hosts = [];
            $this->alias = [];
            $this->cacheLoaded = false;
        }
        $this->finalized = true;
    }

    /*──────────── runtime match ────────────*/

    /**
     * Match an incoming request method/host/path to a compiled route.
     *
     * Lazy cache behaviour:
     *  - If caching is enabled and cache wasn't loaded, attempt to require the
     *    cache file and hydrate internal tables when the file exists.
     *
     * Matching order:
     *  1. Static table for host then wildcard '*'
     *  2. Dynamic trie for host then wildcard '*'
     *  3. If candidate verbs exist but none match, throw MethodNotAllowedException
     *  4. Otherwise throw RouteNotFoundException
     *
     * @param string $method HTTP method (any case)
     * @param string $host Host header value (expected ASCII/lowercase)
     * @param string $path Request path
     * @return array{0:CompiledRoute,1:array<string,string>} Tuple [route, params]
     * @throws RouteNotFoundException When no route matches the path/host
     * @throws MethodNotAllowedException When resource exists but verb not allowed
     */
    public function match(string $method, string $host, string $path): array
    {
        if ($this->cacheEnabled) {
            if (!$this->cacheLoaded && \is_file($this->cacheFile)) {
                $this->loadCacheBlob();
            }
        }

        $verb = \strtoupper($method);
        $host = \strtolower($host);

        /** @var array<string,bool> $allowedSet Accumulator for allowed verbs when match fails */
        $allowedSet = [];

        // 1) Try static table (host then wildcard)
        if ($hit = $this->matchStatic($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        // 2) Try dynamic trie descent (host then wildcard)
        if ($hit = $this->matchTrie($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        // 3) No route found — determine appropriate exception
        if ($allowedSet !== []) {
            throw new MethodNotAllowedException($verb, $path, \array_keys($allowedSet));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /**
     * Resolve a named route to its [path, domain] tuple.
     *
     * @param string $name Route name
     * @return array{0:string,1:?string}|null [path, domain] or null when not found
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();
        return $idx[$name] ?? null;
    }

    /**
     * Compute a deterministic hash for cache verification.
     *
     * Uses exported PHP payload text to avoid json_encode() object-loss semantics.
     *
     * @param array $hosts Host routing table payload.
     * @param array $alias Alias map payload.
     * @return string xxh3 fingerprint.
     */
    private function computeCacheHash(array $hosts, array $alias): string
    {
        return \hash('xxh3', $this->exportArray([
            self::H_DATA => $hosts,
            self::H_ALIAS => $alias,
        ]));
    }

    /*──────────── cache export (single file) ────────────*/

    /**
     * Dump the in-memory host and alias tables into the configured cache file.
     *
     * The cache blob contains a checksum (xxh3) and a timestamp to allow basic
     * integrity checks and identifying stale files.
     *
     * @return void
     * @throws \RuntimeException When the cache directory cannot be created
     */
    private function dumpCache(): void
    {
        $dir = \dirname($this->cacheFile);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        $payloadHosts = $this->hosts;
        $payloadAlias = $this->alias;
        $crc = $this->computeCacheHash($payloadHosts, $payloadAlias);

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "'  => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_TS . "'  => " . \var_export(date(DATE_ATOM), true) . ",\n"
            . "    '" . self::H_DATA . "' => " . $this->exportArray($payloadHosts) . ",\n"
            . "    '" . self::H_ALIAS . "' => " . $this->exportArray($payloadAlias) . ",\n"
            . "];\n";

        $tmp = $this->cacheFile . '.' . \uniqid('', true) . '.tmp';
        if (\file_put_contents($tmp, $php, \LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write cache temp file {$tmp}");
        }
        @\chmod($tmp, 0664);
        if (!@\rename($tmp, $this->cacheFile)) {
            @\unlink($tmp);
            throw new \RuntimeException("Failed to move cache file into place {$this->cacheFile}");
        }

        if ($this->shouldWarmOpcache()) {
            @\opcache_compile_file($this->cacheFile);
        }
    }

    /**
     * Insert a dynamic route into the host trie.
     *
     * @param string $host Canonical host key
     * @param string $verb HTTP verb (uppercased)
     * @param CompiledRoute $r Compiled dynamic route
     * @return void
     */
    private function insertDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $node = &$this->hosts[$host][self::K_TRIE];
        $this->trieInsert($node, $r, $verb);
    }

    /**
     * Insert a static (exact path) route into the host static table.
     *
     * @param string $host Canonical host key
     * @param string $verb HTTP verb (uppercased)
     * @param CompiledRoute $r Compiled route being inserted
     * @return void
     * @throws \LogicException On duplicate insertion of the same verb/path
     */
    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        $table = &$this->hosts[$host][self::K_STATIC];

        if (isset($table[$path][$verb])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $table[$path][$verb] = $r;
    }

    /**
     * Load and hydrate matcher tables from the cache file.
     *
     * @return void
     * @throws \RuntimeException When verification is enabled and cache hash is invalid.
     */
    private function loadCacheBlob(): void
    {
        /** @var array{_hash?:string,_data?:array,_alias?:array<string,array{0:string,1:?string}>} $blob */
        $blob = require $this->cacheFile;

        if ($this->verifyCacheOnLoad) {
            if (!isset($blob[self::H_HASH], $blob[self::H_DATA])) {
                throw new \RuntimeException('Route cache missing Hash.');
            }
            $calc = $this->computeCacheHash($blob[self::H_DATA], $blob[self::H_ALIAS] ?? []);
            if (!\hash_equals((string)$blob[self::H_HASH], $calc)) {
                throw new \RuntimeException('Route cache Hash mismatch.');
            }
        }

        $this->hosts = $blob[self::H_DATA] ?? [];
        $this->alias = $blob[self::H_ALIAS] ?? [];
        $this->cacheLoaded = true;

        if ($this->shouldWarmOpcache()) {
            @\opcache_compile_file($this->cacheFile);
        }
    }

    /**
     * Attempt to match against the static tables for a host and wildcard.
     *
     * On success returns [$route, []] (no params). When a path is present but
     * no verb matches the allowedSet is populated.
     *
     * @param string $host Canonical host key
     * @param string $verb Uppercased HTTP verb
     * @param string $path Request path
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
    private function matchStatic(string $host, string $verb, string $path, array &$allowedSet): ?array
    {
        foreach ([$host, '*'] as $h) {
            $map = $this->hosts[$h][self::K_STATIC][$path] ?? null;
            if ($map === null) {
                continue;
            }

            if ($r = $this->pickVerbRoute($map, $verb)) {
                return [$r, []];
            }

            $this->addAllowedFromMap($map, $allowedSet);
        }
        return null;
    }

    /**
     * Attempt to match against the dynamic trie for a host and wildcard.
     *
     * @param string $host Canonical host key
     * @param string $verb Uppercased HTTP verb
     * @param string $path Request path
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
    private function matchTrie(string $host, string $verb, string $path, array &$allowedSet): ?array
    {
        $segments = $this->explodePath($path);
        foreach ([$host, '*'] as $h) {
            $root = $this->hosts[$h][self::K_TRIE] ?? null;
            if (!$root) {
                continue;
            }

            $hit = null;
            $params = [];
            if ($this->trieWalkNode($root, $segments, 0, $verb, $params, $allowedSet, $hit)) {
                return $hit; // [$route, $params]
            }
        }
        return null;
    }

}
